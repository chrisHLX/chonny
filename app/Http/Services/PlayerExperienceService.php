<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * How experienced a player in one of your games is: their highest 3v3 rating and how many seasons
 * they have been Gladiator (and Legend, Rank 1). match-review-operations.md, "Experience: why MMR
 * is not enough": early in a season MMR is deflated, and experience is what says what level a game
 * was really played at.
 *
 * Any public character, by the `Name-Realm-Region` the combat log records, through the site's app
 * token, whether or not they have ever used the site. Parsed by the same methods the character
 * sync uses (BattlenetCharacterSyncService), so the numbers mean the same thing everywhere.
 *
 * - Highest 3v3 rating is PER CHARACTER; Gladiator seasons are PER BATTLE.NET ACCOUNT (Blizzard
 *   shares season titles across an account). An alt shows low exp beside many Gladiator seasons.
 * - Read as the character is NOW, not as it was during the game.
 * - Cached per character: looked up by a queued job at upload (FetchPlayerExperience), never on a
 *   page load, because Blizzard answers each character in about a second.
 */
class PlayerExperienceService
{
    private const TTL_DAYS = 7;

    private const NOT_FOUND_TTL_HOURS = 24;

    public function __construct(private BattlenetClient $client, private BattlenetCharacterSyncService $sync) {}

    /** What is cached for this character, or null if it has not been looked up yet. */
    public function cached(string $fullName): ?array
    {
        return Cache::get($this->key($fullName));
    }

    /**
     * Several characters' cached experience in one cache round trip, keyed by the name given. A
     * name not looked up yet maps to null, as in cached().
     *
     * @param  array<int, string>  $fullNames
     * @return array<string, array|null>
     */
    public function cachedMany(array $fullNames): array
    {
        $fullNames = array_values(array_unique($fullNames));

        if ($fullNames === []) {
            return [];
        }

        $hits = Cache::many(array_map(fn ($n) => $this->key($n), $fullNames));

        return array_combine($fullNames, array_map(fn ($n) => $hits[$this->key($n)] ?? null, $fullNames));
    }

    /** Look the character up now (and cache it), unless it already is. */
    public function lookup(string $fullName): array
    {
        if (($hit = $this->cached($fullName)) !== null) {
            return $hit;
        }

        $result = $this->fetch($fullName);

        // A lookup Blizzard failed to answer (a 5xx, a rate limit, a dropped connection) is not
        // "no profile": it stays uncached, so the next upload asks again rather than the card
        // reading "unknown" for a day.
        if (! ($result['failed'] ?? false)) {
            Cache::put($this->key($fullName), $result, $result['found'] ? now()->addDays(self::TTL_DAYS) : now()->addHours(self::NOT_FOUND_TTL_HOURS));
        }

        return $result;
    }

    private function fetch(string $fullName): array
    {
        [$name, $realm, $region] = array_pad(explode('-', $fullName, 3), 3, null);
        if (! $name || ! $realm || ! $region) {
            return ['found' => false];
        }

        try {
            $r = $this->client->characterMany(strtolower($region), $this->realmSlug($realm, strtolower($region)), $name, [
                'statistics' => '/achievements/statistics',
                'achievements' => '/achievements',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Player experience lookup failed', ['player' => $fullName, 'error' => $e->getMessage()]);

            return ['found' => false, 'failed' => true];
        }

        if (($r['statistics'] ?? null) === null) {
            // No public profile: renamed, transferred or long inactive. Unknown, never zero.
            return ['found' => false];
        }

        $stats = $this->sync->parseStatistics($r['statistics']);
        $titles = $this->sync->parseArenaTitles($r['achievements'] ?? []);
        $rank = $this->sync->parseRankTitle($r['achievements'] ?? []);

        return [
            'found' => true,
            'exp3v3' => $stats['exp_3v3'],
            'gladSeasons' => $titles['3v3']['seasons'] ?? 0,
            'rankOneSeasons' => $titles['3v3']['rank_one_seasons'] ?? 0,
            'legendSeasons' => $titles['shuffle']['seasons'] ?? 0,
            'bestRank' => $rank ? explode(':', $rank['title'])[0] : null,
        ];
    }

    /**
     * Blizzard's realm slug from the log's realm name, which has its spaces and hyphens taken out
     * (BleedingHollow, AzjolNerub, Jubei'Thos).
     *
     * Read from Blizzard's own realm list for the region when there is one: the log name cannot be
     * split back reliably. Splitting at capitals got 24 US and 38 EU realms wrong (2026-10-19:
     * Azjol-Nerub is azjolnerub, Altar of Storms is altar-of-storms, DieewigeWacht is
     * die-ewige-wacht), and a player on one read as having no profile. The capitals rule remains
     * for when the list cannot be had: right for the common realms, measured on the 26 Sep games.
     */
    public function realmSlug(string $realm, ?string $region = null): string
    {
        if ($region !== null && ($slug = $this->realmSlugs($region)[self::realmKey($realm)] ?? null)) {
            return $slug;
        }

        return strtolower(preg_replace(['/(?<=[a-z])(?=[A-Z0-9])/', '/\s+/', "/'-?/"], ['-', '-', ''], $realm));
    }

    /**
     * Every realm in the region, its name as a key (realmKey) to its slug. Cached for a week; a
     * failed fetch is not cached and gives an empty list, so the capitals rule answers until it works.
     *
     * @return array<string, string>
     */
    private function realmSlugs(string $region): array
    {
        $key = 'battlenet:realm_slugs:v1:'.$region;

        if (is_array($hit = Cache::get($key))) {
            return $hit;
        }

        try {
            $realms = $this->client->gameData($region, '/data/wow/realm/index', 'dynamic')['realms'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Realm list lookup failed', ['region' => $region, 'error' => $e->getMessage()]);

            return [];
        }

        $slugs = [];
        foreach ($realms as $realm) {
            if (! empty($realm['name']) && ! empty($realm['slug'])) {
                $slugs[self::realmKey($realm['name'])] = $realm['slug'];
            }
        }

        if ($slugs !== []) {
            Cache::put($key, $slugs, now()->addDays(7));
        }

        return $slugs;
    }

    /** A realm name with everything but its letters and digits taken out, lowercased: the log's name and Blizzard's meet here. */
    private static function realmKey(string $name): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $name));
    }

    private function key(string $fullName): string
    {
        return 'player_experience:v1:'.md5(mb_strtolower($fullName));
    }
}
