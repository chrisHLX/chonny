<?php

namespace App\Http\Services;

use App\Models\BattlenetAccount;
use App\Models\BattlenetCharacter;
use App\Models\GameClass;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a Battle.net sign-in into stored characters, and a character into its exp, ratings, gear
 * and talents.
 *
 * Every response shape parsed below was read off the live API on 2026-09-12 before any of this was
 * written, not assumed from documentation. Two findings shaped it:
 *
 * - "EXP" IS A REAL BLIZZARD NUMBER. Achievement statistics carry "Highest 2v2 personal rating"
 *   (id 370) and "Highest 3v3 personal rating" (id 595) — the lifetime best, which is exactly what
 *   players mean by exp. Matched by id, not by name, so a locale change cannot break it.
 *
 * - TALENT PICKS ARE STORED BY BLIZZARD'S EXTERNAL IDS AND RESOLVED AT RENDER TIME. The
 *   specializations endpoint returns each loadout's picks as {id: node id, rank, tooltip: {talent,
 *   spell}}; node ids matched this database's `external_node_id` 77/77 on a real character, and
 *   talent ids match `external_talent_id`. The loadout's Blizzard export string is kept too, but it
 *   is NOT what the site decodes: BlizzardTalentStringCodec misread that same real string (49 picks,
 *   a wrong class/spec/hero split, 4 unresolvable nodes) — the structured picks are the reliable
 *   source. See CharacterTalentResolver.
 */
class BattlenetCharacterSyncService
{
    /** Blizzard's achievement-statistic ids. Stable across locales and patches. */
    private const STAT_HIGHEST_2V2 = 370;

    private const STAT_HIGHEST_3V3 = 595;

    private const STAT_ARENAS_PLAYED = 838;

    private const STAT_ARENAS_WON = 837;

    /**
     * PvP season rank titles, lowest to highest. Only the ORDER is asserted here, never a rating
     * threshold — the ladder's order is not in dispute, the exact cut-offs move between seasons,
     * and this page never prints a number it did not read from Blizzard.
     *
     * Gladiator, Legend (Solo Shuffle) and Strategist (Blitz) are each their bracket's top title,
     * so they share a tier; ties go to the most recent. The unnumbered names are the older,
     * pre-Shadowlands spellings of the same ranks.
     */
    public const RANK_TIERS = [
        'Combatant I' => 1, 'Combatant' => 1,
        'Combatant II' => 2,
        'Challenger I' => 3, 'Challenger' => 3,
        'Challenger II' => 4,
        'Rival I' => 5, 'Rival' => 5,
        'Rival II' => 6,
        'Duelist' => 7,
        'Elite' => 8,
        'Gladiator' => 9, 'Legend' => 9, 'Strategist' => 9,
    ];

    /** Cosmetic slots with no bearing on a PvP build. */
    private const SKIPPED_SLOTS = ['SHIRT', 'TABARD'];

    public function __construct(private BattlenetClient $client) {}

    // ------------------------------------------------------------------ linking

    /**
     * Link (or re-link) the user's Battle.net account from a fresh user token, and store the
     * account's character list.
     *
     * @throws BattlenetAccountTakenException when another MindCollector account already holds it
     */
    public function linkAccount(User $user, string $userToken): BattlenetAccount
    {
        $info = $this->client->userInfo($userToken);

        $holder = BattlenetAccount::where('battlenet_id', $info['id'])->first();

        if ($holder && $holder->user_id !== $user->id) {
            throw new BattlenetAccountTakenException;
        }

        // Fetched BEFORE anything is written: a failed list read must leave the previous link
        // exactly as it was, not half-replaced.
        $list = $this->client->accountCharacters($userToken);

        return DB::transaction(function () use ($user, $info, $list) {
            $account = BattlenetAccount::updateOrCreate(
                ['user_id' => $user->id],
                ['battlenet_id' => $info['id'], 'battletag' => $info['battletag']],
            );

            $this->storeCharacterList($account, $list);
            $account->forceFill(['characters_synced_at' => now()])->save();

            return $account;
        });
    }

    /**
     * Make the stored characters match the account's own list. The list is authoritative for
     * ownership, so a character no longer on it (deleted, transferred, a different account
     * re-linked) is removed — and any guide attributed to it simply loses the attribution.
     *
     * @param  list<array<string, mixed>>  $list  BattlenetClient::accountCharacters()
     */
    public function storeCharacterList(BattlenetAccount $account, array $list): void
    {
        $classIds = $this->classIdsByBlizzardId();
        $kept = [];

        foreach ($list as $c) {
            $character = BattlenetCharacter::updateOrCreate(
                ['region' => $c['region'], 'blizzard_character_id' => $c['id']],
                [
                    'battlenet_account_id' => $account->id,
                    'name' => $c['name'],
                    'realm_slug' => $c['realm_slug'],
                    'realm_name' => $c['realm_name'],
                    'class_id' => $classIds[$c['class_id']] ?? null,
                    'race' => $c['race'],
                    'faction' => $c['faction'],
                    'level' => $c['level'],
                ],
            );

            $kept[] = $character->id;
        }

        // Not through characters(): that relation is ordered, and SQLite (the test database)
        // rejects ORDER BY on a DELETE.
        BattlenetCharacter::where('battlenet_account_id', $account->id)->whereNotIn('id', $kept)->delete();
    }

    /** Characters on the account worth a detail sync — high enough level to have PvP history. */
    public function detailEligible(BattlenetAccount $account)
    {
        return $account->characters()
            ->where('level', '>=', (int) config('services.battlenet.detail_min_level', 70))
            ->get();
    }

    // ------------------------------------------------------------------ detail sync

    /**
     * Fetch everything about one character and store it. Never throws: a failure is written to
     * `sync_error` so it shows on the character's row, rather than disappearing into a queue log
     * nobody reads (CLAUDE.md: app/Jobs failures are quiet).
     */
    public function syncDetails(BattlenetCharacter $character): bool
    {
        try {
            $this->doSyncDetails($character);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Battle.net character sync failed', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);

            $character->forceFill(['sync_error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return false;
        }
    }

    private function doSyncDetails(BattlenetCharacter $c): void
    {
        // Six independent reads, sent together — see BattlenetClient::characterMany().
        $r = $this->client->characterMany($c->region, $c->realm_slug, $c->name, [
            'summary' => '',
            'statistics' => '/achievements/statistics',
            'achievements' => '/achievements',
            'pvp' => '/pvp-summary',
            'specializations' => '/specializations',
            'equipment' => '/equipment',
        ]);

        $summary = $r['summary'];

        if ($summary === null) {
            // Blizzard serves no public profile for characters that have not logged in for a
            // long time, and for ones mid-transfer. That is a real answer, not an error to retry.
            $c->forceFill([
                'sync_error' => 'Blizzard has no public profile for this character right now — log into it once and refresh.',
                'synced_at' => now(),
            ])->save();

            return;
        }

        $stats = $this->parseStatistics($r['statistics'] ?? []);
        $rank = $this->parseRankTitle($r['achievements'] ?? []);
        $ratings = $this->fetchRatings($c, $r['pvp'] ?? []);
        $talents = $this->parseTalents($r['specializations'] ?? []);
        $equipment = $this->withItemIcons($c->region, $this->parseEquipment($r['equipment'] ?? []));

        $classIds = $this->classIdsByBlizzardId();

        $c->forceFill([
            'name' => $summary['name'] ?? $c->name,
            'level' => (int) ($summary['level'] ?? $c->level),
            'class_id' => $classIds[$summary['character_class']['id'] ?? null] ?? $c->class_id,
            'spec_id' => $this->specIdFor($summary['active_spec']['id'] ?? null) ?? $c->spec_id,
            'race' => $summary['race']['name'] ?? $c->race,
            'faction' => $summary['faction']['name'] ?? $c->faction,
            'item_level' => $summary['equipped_item_level'] ?? null,
            'achievement_points' => $summary['achievement_points'] ?? null,
            'last_login_at' => isset($summary['last_login_timestamp'])
                ? now()->setTimestamp(intdiv((int) $summary['last_login_timestamp'], 1000))
                : null,
            'exp_2v2' => $stats['exp_2v2'],
            'exp_3v3' => $stats['exp_3v3'],
            'arenas_played' => $stats['arenas_played'],
            'arenas_won' => $stats['arenas_won'],
            'pvp_rank_title' => $rank['title'] ?? null,
            'pvp_rank_tier' => $rank['tier'] ?? null,
            'ratings' => $ratings,
            'talents' => $talents,
            'equipment' => $equipment,
            'synced_at' => now(),
            'sync_error' => null,
        ])->save();
    }

    /** Every bracket the character has an entry for, each fetched for its rating. */
    private function fetchRatings(BattlenetCharacter $c, array $pvpSummary): array
    {
        $paths = [];

        foreach ($pvpSummary['brackets'] ?? [] as $b) {
            if (preg_match('#/pvp-bracket/([^?/]+)#', $b['href'] ?? '', $m)) {
                $paths[$m[1]] = '/pvp-bracket/'.$m[1];
            }
        }

        $currentSeason = $this->currentSeasonId($c->region);
        $out = [];

        foreach ($this->client->characterMany($c->region, $c->realm_slug, $c->name, $paths) as $key => $bracket) {
            if ($bracket && ($row = $this->parseBracket((string) $key, $bracket, $currentSeason))) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function currentSeasonId(string $region): ?int
    {
        return Cache::remember("battlenet:pvp_season:{$region}", now()->addHours(6), function () use ($region) {
            try {
                return $this->client->gameData($region, '/data/wow/pvp-season/index', 'dynamic')['current_season']['id'] ?? null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    // ------------------------------------------------------------------ parsers (pure)

    /** @return array{exp_2v2: ?int, exp_3v3: ?int, arenas_played: ?int, arenas_won: ?int} */
    public function parseStatistics(array $response): array
    {
        $byId = [];
        $walk = function (array $categories) use (&$walk, &$byId) {
            foreach ($categories as $cat) {
                foreach ($cat['statistics'] ?? [] as $stat) {
                    if (isset($stat['id'])) {
                        $byId[(int) $stat['id']] = (int) ($stat['quantity'] ?? 0);
                    }
                }
                $walk($cat['sub_categories'] ?? []);
            }
        };
        $walk($response['categories'] ?? []);

        $positive = fn (int $id) => ($byId[$id] ?? 0) > 0 ? $byId[$id] : null;

        return [
            'exp_2v2' => $positive(self::STAT_HIGHEST_2V2),
            'exp_3v3' => $positive(self::STAT_HIGHEST_3V3),
            'arenas_played' => $byId[self::STAT_ARENAS_PLAYED] ?? null,
            'arenas_won' => $byId[self::STAT_ARENAS_WON] ?? null,
        ];
    }

    /**
     * The best completed season rank achievement ("Rival II: Midnight Season 1").
     *
     * The name must be the rank word, an optional I/II, then a colon or the end — which is what
     * keeps "Legend of the Past", "Legendary Research" and "Challenger's Tabard" out. All three are
     * real completed achievements on the character this was built against.
     *
     * @return array{title: string, tier: int}|null
     */
    public function parseRankTitle(array $response): ?array
    {
        $best = null;

        foreach ($response['achievements'] ?? [] as $a) {
            $name = $a['achievement']['name'] ?? '';

            if (empty($a['completed_timestamp'])
                || ! preg_match('/^(Combatant|Challenger|Rival|Duelist|Elite|Gladiator|Legend|Strategist)( I{1,2})?(?::|$)/', $name, $m)) {
                continue;
            }

            $tier = self::RANK_TIERS[$m[1].($m[2] ?? '')] ?? null;

            if ($tier === null) {
                continue;
            }

            $when = (int) $a['completed_timestamp'];

            if ($best === null || $tier > $best['tier'] || ($tier === $best['tier'] && $when > $best['when'])) {
                $best = ['title' => $name, 'tier' => $tier, 'when' => $when];
            }
        }

        return $best ? ['title' => $best['title'], 'tier' => $best['tier']] : null;
    }

    /** @return array<string, mixed>|null */
    public function parseBracket(string $key, array $response, ?int $currentSeasonId): ?array
    {
        $type = $response['bracket']['type'] ?? null;

        $label = match ($type) {
            'ARENA_2v2' => '2v2',
            'ARENA_3v3' => '3v3',
            'BATTLEGROUNDS' => 'Rated BG',
            'SHUFFLE' => 'Solo Shuffle',
            'BLITZ' => 'Blitz',
            default => null,
        };

        if ($label === null) {
            return null;
        }

        $season = $response['season']['id'] ?? null;

        return [
            'key' => $key,
            'label' => $label,
            'spec_external_id' => $response['specialization']['id'] ?? null,
            'spec_name' => $response['specialization']['name'] ?? null,
            'rating' => (int) ($response['rating'] ?? 0),
            'season' => $season,
            // Unknown current season (the lookup failed) is treated as current rather than
            // hiding every rating — the bracket list itself only covers recent seasons.
            'current' => $currentSeasonId === null || $season === $currentSeasonId,
            'played' => (int) ($response['season_match_statistics']['played'] ?? 0),
            'won' => (int) ($response['season_match_statistics']['won'] ?? 0),
            'lost' => (int) ($response['season_match_statistics']['lost'] ?? 0),
        ];
    }

    /**
     * One snapshot per spec: its active loadout's picks and PvP talents, by Blizzard's ids.
     *
     * @return list<array<string, mixed>>
     */
    public function parseTalents(array $response): array
    {
        $activeSpec = $response['active_specialization']['id'] ?? null;
        $out = [];

        foreach ($response['specializations'] ?? [] as $spec) {
            $specId = $spec['specialization']['id'] ?? null;
            $loadouts = collect($spec['loadouts'] ?? []);
            $loadout = $loadouts->firstWhere('is_active', true) ?? $loadouts->first();

            if (! $specId || ! $loadout) {
                continue;
            }

            $picks = [];
            foreach (['class', 'spec', 'hero'] as $tree) {
                foreach ($loadout["selected_{$tree}_talents"] ?? [] as $t) {
                    if (empty($t['id'])) {
                        continue;
                    }

                    $picks[] = [
                        'node' => (int) $t['id'],
                        'talent' => $t['tooltip']['talent']['id'] ?? null,
                        'spell' => $t['tooltip']['spell_tooltip']['spell']['id'] ?? null,
                        'rank' => (int) ($t['rank'] ?? 1),
                        'name' => $t['tooltip']['talent']['name'] ?? $t['tooltip']['spell_tooltip']['spell']['name'] ?? null,
                        'tree' => $tree,
                    ];
                }
            }

            $pvp = [];
            foreach ($spec['pvp_talent_slots'] ?? [] as $slot) {
                if (empty($slot['selected']['talent']['id'])) {
                    continue;
                }

                $pvp[] = [
                    'pvp_talent' => (int) $slot['selected']['talent']['id'],
                    'spell' => $slot['selected']['spell_tooltip']['spell']['id'] ?? null,
                    'name' => $slot['selected']['talent']['name'] ?? null,
                ];
            }

            $out[] = [
                'spec_external_id' => (int) $specId,
                'spec_name' => $spec['specialization']['name'] ?? null,
                'active' => $specId === $activeSpec,
                'hero_tree' => $loadout['selected_hero_talent_tree']['name'] ?? null,
                'loadout_code' => $loadout['talent_loadout_code'] ?? null,
                'picks' => $picks,
                'pvp' => $pvp,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function parseEquipment(array $response): array
    {
        $out = [];

        foreach ($response['equipped_items'] ?? [] as $item) {
            $slot = $item['slot']['type'] ?? null;

            if (! $slot || in_array($slot, self::SKIPPED_SLOTS, true) || empty($item['item']['id'])) {
                continue;
            }

            $out[] = [
                'slot' => $slot,
                'slot_name' => $item['slot']['name'] ?? $slot,
                'item_id' => (int) $item['item']['id'],
                'name' => $item['name'] ?? 'Unknown item',
                'quality' => $item['quality']['type'] ?? 'COMMON',
                'level' => $item['level']['value'] ?? null,
                'enchantments' => collect($item['enchantments'] ?? [])
                    ->map(fn ($e) => self::cleanDisplayString(preg_replace('/^Enchanted:\s*/', '', $e['display_string'] ?? '')))
                    ->filter()->values()->all(),
                'gems' => collect($item['sockets'] ?? [])
                    ->map(fn ($s) => $s['item']['name'] ?? null)
                    ->filter()->values()->all(),
                'set' => $item['set']['item_set']['name'] ?? null,
                'icon' => null,
            ];
        }

        return $out;
    }

    /**
     * Strip WoW's inline UI markup from a tooltip string: `|A:atlas:20:20|a` quality icons,
     * `|T...|t` textures and `|cAARRGGBB...|r` colour codes. What is left is the plain text the
     * game shows beside them.
     */
    public static function cleanDisplayString(string $s): string
    {
        $s = preg_replace('/\|A:[^|]*\|a/', '', $s);
        $s = preg_replace('/\|T[^|]*\|t/', '', $s);
        $s = preg_replace('/\|c[0-9A-Fa-f]{8}(.*?)\|r/s', '$1', $s);

        return trim(preg_replace('/\s{2,}/', ' ', str_replace(['|c', '|r'], '', $s)));
    }

    // ------------------------------------------------------------------ item icons

    /**
     * Self-host each item's icon, the way spell icons are: the file lands in
     * storage/app/public/item-icons/ and only its filename is stored, so no page ever hotlinks
     * Blizzard's CDN. The media lookup is cached per item, and a file already on disk is never
     * downloaded twice. A missing icon renders as a plain placeholder, never a broken image.
     */
    private function withItemIcons(string $region, array $equipment): array
    {
        $disk = Storage::disk('public');

        try {
            // item id => filename, from cache where we have it; the rest looked up in one batch.
            $filenames = [];
            $unknown = [];

            foreach ($equipment as $item) {
                $cached = Cache::get("battlenet:item_icon:{$item['item_id']}");

                if ($cached === null) {
                    $unknown[$item['item_id']] = "/data/wow/media/item/{$item['item_id']}";
                } else {
                    $filenames[$item['item_id']] = $cached; // '' means Blizzard has no icon for it
                }
            }

            foreach ($this->client->gameDataMany($region, $unknown) as $itemId => $media) {
                $url = collect($media['assets'] ?? [])->firstWhere('key', 'icon')['value'] ?? null;
                $filenames[$itemId] = $url ? basename(parse_url($url, PHP_URL_PATH)) : '';
                Cache::put("battlenet:item_icon:{$itemId}", $filenames[$itemId], now()->addDays(30));
            }

            $missing = collect($filenames)
                ->filter(fn ($f) => $f !== '' && ! $disk->exists("item-icons/{$f}"))
                ->unique()
                ->mapWithKeys(fn ($f) => [$f => "https://render.worldofwarcraft.com/us/icons/56/{$f}"])
                ->all();

            foreach ($this->client->downloadMany($missing) as $filename => $bytes) {
                if ($bytes) {
                    $disk->put("item-icons/{$filename}", $bytes);
                }
            }

            foreach ($equipment as $i => $item) {
                $f = $filenames[$item['item_id']] ?? '';
                $equipment[$i]['icon'] = $f !== '' && $disk->exists("item-icons/{$f}") ? $f : null;
            }
        } catch (\Throwable) {
            // An icon is decoration. Never fail a character sync over one — the gear list still
            // stores, and the missing ones render as placeholders.
        }

        return $equipment;
    }

    // ------------------------------------------------------------------ lookups

    /** @return array<int, int> Blizzard playable-class id => classes.id */
    private function classIdsByBlizzardId(): array
    {
        $bySlug = GameClass::pluck('id', 'slug');

        return collect(config('wow_classes.blizzard_ids', []))
            ->mapWithKeys(fn (int $blizzardId, string $slug) => [$blizzardId => $bySlug[$slug] ?? null])
            ->filter()
            ->all();
    }

    private function specIdFor(?int $externalSpecId): ?int
    {
        return $externalSpecId ? Specialization::where('external_spec_id', $externalSpecId)->value('id') : null;
    }
}
