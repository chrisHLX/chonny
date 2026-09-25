<?php

namespace App\Http\Services;

use App\Models\ArenaReview;
use App\Models\BattlenetAccount;
use App\Models\BattlenetCharacter;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Assembles one reviewable game — a Solo Shuffle lobby's six rounds — and reads it back for the
 * page.
 *
 * WHAT A REVIEW ANSWERS. It came out of a real question — "I went 5-1, was my healing better than
 * the other Disc Priest, and was it talents or gear?" — which nothing here could answer. The
 * comparison that makes that tractable is a MIRROR: the same spec on both sides has the identical
 * kit, so every difference left is build, gear or play. `mirrors` is auto-detected; a review with
 * no mirror is still written, it just has an empty one.
 *
 * REVIEWS ARE USER DATA IN THE DATABASE, NOT FILES. They were briefly committed to the repo,
 * which published them. Everything the page reads is an `arena_reviews` row scoped to
 * `auth()->id()`. This also answers CLAUDE.md rule 14 better than the committed artifact did: a
 * row is present in production by definition, so there is no gitignored-input trap to fall into.
 *
 * ASSEMBLY IS INPUT-AGNOSTIC ON PURPOSE. {@see assemble()} takes already-derived rounds and knows
 * nothing about where they came from, because they arrive two completely different ways: from the
 * local archive via `wow:review-lobby`, and from a player's browser upload via
 * ArenaReviewIngestService. One assembler, so a mirror comparison cannot mean two different things
 * depending on the route it took.
 *
 * WHAT IT DELIBERATELY DOES NOT SAY. See limitations() — one method, same reasoning as rule 33's
 * `MatchupLab::limitations()`: honest-limits copy that lives in one place cannot be trimmed a line
 * at a time by a later layout change. The big one is that throughput is not normalised for
 * pressure. A healer who gets focused heals more, and this cannot tell "healed more" from "was
 * attacked more" — which is exactly why the mirror's damage-taken figures are carried alongside.
 */
class LobbyReviewService
{
    public function __construct(
        private ArenaLogService $arena,
        private CombatantThroughputService $throughput,
        private CombatLogIngestService $ingest,
    ) {}

    // ---------------------------------------------------------------- reading (page-safe)

    /**
     * One player's own reviews, newest game first. Always scoped to a user — there is no
     * unscoped read, deliberately.
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user): array
    {
        return ArenaReview::query()
            ->where('user_id', $user->id)
            ->orderByDesc('played_at')
            ->get()
            ->map(fn (ArenaReview $r) => [
                'id' => $r->lobby_id,
                'bracket' => $r->bracket,
                'playedAt' => $r->played_at?->toIso8601String(),
                'record' => ['won' => $r->rounds_won, 'lost' => $r->rounds_lost],
                'rounds' => $r->rounds,
                'you' => $r->character_name,
                'youSpec' => $r->payload['players'] ? collect($r->payload['players'])
                    ->firstWhere('isYou', true)['spec']['label'] ?? null : null,
                'mirrors' => $r->mirrors,
            ])
            ->all();
    }

    /** One of this player's reviews, or null. Never reads another player's row. */
    public function load(User $user, string $lobbyId): ?array
    {
        $row = ArenaReview::query()
            ->where('user_id', $user->id)
            ->where('lobby_id', $lobbyId)
            ->first();

        return $row?->payload;
    }

    /**
     * Saves an assembled review for a player, replacing any earlier copy of the same lobby so a
     * re-upload or a re-assembly after a parser fix updates in place.
     */
    public function store(User $user, array $review): ArenaReview
    {
        $you = collect($review['players'] ?? [])->firstWhere('isYou', true);

        return ArenaReview::updateOrCreate(
            ['user_id' => $user->id, 'lobby_id' => $review['id']],
            [
                'battlenet_character_id' => $this->characterIdFor($user, $you['name'] ?? null),
                'character_name' => $you['name'] ?? null,
                'bracket' => $review['bracket'],
                'played_at' => $review['playedAt'] ?? null,
                'rounds' => count($review['rounds'] ?? []),
                'rounds_won' => $review['record']['won'] ?? 0,
                'rounds_lost' => $review['record']['lost'] ?? 0,
                'mirrors' => count($review['mirrors'] ?? []),
                'payload' => $review,
            ]
        );
    }

    /**
     * Links a game to one of the player's own characters by the name the combat log carries.
     *
     * The log writes `Skylake-Frostmourne-US`; the account's synced characters carry name and
     * realm separately, with the realm as a slug. Matching is on name plus realm with punctuation
     * dropped, because a realm like "Moon Guard" is `moon-guard` as a slug and `MoonGuard` in the
     * log. Returns null rather than guessing when nothing matches — a review whose character
     * cannot be identified is still that player's game, and still worth keeping.
     */
    private function characterIdFor(User $user, ?string $logName): ?int
    {
        if ($logName === null || ! str_contains($logName, '-')) {
            return null;
        }

        $parts = explode('-', $logName);
        $name = $parts[0];
        $realm = $parts[1] ?? '';
        $normalise = fn (?string $v) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $v));

        $accountIds = BattlenetAccount::where('user_id', $user->id)->pluck('id');

        if ($accountIds->isEmpty()) {
            return null;
        }

        return BattlenetCharacter::query()
            ->whereIn('battlenet_account_id', $accountIds)
            ->where('name', $name)
            ->get()
            ->first(fn (BattlenetCharacter $c) => $normalise($c->realm_slug) === $normalise($realm)
                || $normalise($c->realm_name) === $normalise($realm))
            ?->id;
    }

    // ---------------------------------------------------------------- building (console only)

    /**
     * Every reviewable game in the archive: one entry per Solo Shuffle lobby.
     *
     * SHUFFLE ONLY, ON PURPOSE. Two reasons, and the second is the important one. A shuffle
     * reliably produces the mirror this page is built around — two healers who swap sides every
     * round — where a 3v3 only sometimes does. And the 16 oldest matches in the archive came from
     * the wowarenalogs feed, so they are **other people's games**: they have no place on a page
     * that is one signed-in player's record of their own matches. Restricting to the round-based
     * bracket excludes them by construction rather than by a list of ids somebody has to maintain.
     *
     * When 3v3 comes back it needs an owner recorded at ingest, not a bracket check.
     *
     * @return array<int, array{id: string, matchIds: array<int, string>, bracket: string, startTime: int}>
     */
    public function reviewable(): array
    {
        $groups = [];

        foreach (File::glob($this->arena->metadataPath('*')) as $path) {
            $m = json_decode(File::get($path), true);

            if (! is_array($m) || ! isset($m['id'])) {
                continue;
            }

            if (! $this->ingest->isRoundBased($m['startInfo']['bracket'] ?? '')) {
                continue;
            }

            $key = $m['lobbyId'] ?? $m['id'];

            $groups[$key] ??= [
                'id' => $key,
                'matchIds' => [],
                'bracket' => $m['startInfo']['bracket'] ?? 'unknown',
                'startTime' => $m['startTime'] ?? 0,
            ];

            $groups[$key]['matchIds'][] = $m['id'];
            $groups[$key]['startTime'] = min($groups[$key]['startTime'] ?: PHP_INT_MAX, $m['startTime'] ?? PHP_INT_MAX);
        }

        $rows = array_values($groups);
        usort($rows, fn ($a, $b) => $b['startTime'] <=> $a['startTime']);

        return $rows;
    }

    /**
     * Derives ONE round from its own raw log lines: the metadata, every player's throughput, and
     * every player's COMBATANT_INFO build/gear/stats.
     *
     * This is the only place raw log text is needed, and it is deliberately the boundary. A
     * browser-uploaded round is derived here once and the raw text is then thrown away, so what
     * this returns is everything a review can ever be rebuilt from.
     *
     * @param  array<int, string>  $lines
     * @return array{metadata: array, throughput: array, combatants: array<string, array>}|null
     */
    public function deriveRound(array $lines, ?string $lobbyFirstLine = null): ?array
    {
        $startLine = null;
        $endLine = null;

        foreach ($lines as $line) {
            $body = trim(explode('  ', $line, 2)[1] ?? '');

            if (str_starts_with($body, 'ARENA_MATCH_START,')) {
                $startLine = $body;
            } elseif (str_starts_with($body, 'ARENA_MATCH_END,')) {
                $endLine = $body;
            }
        }

        if ($startLine === null) {
            return null;
        }

        $metadata = $this->ingest->deriveMetadata($lines, $startLine, $endLine, 1, $lobbyFirstLine);

        if (! $this->ingest->isRoundBased($metadata['startInfo']['bracket'] ?? '')) {
            return null;
        }

        $raw = implode("\n", $lines);
        $combatants = [];

        foreach ($metadata['units'] as $unit) {
            if (! str_starts_with($unit['id'], 'Player-') || ($unit['spec'] ?? '0') === '0') {
                continue;
            }

            $ci = $this->arena->extractCombatantInfoFromLog($raw, $unit['id']);

            if ($ci !== null) {
                $combatants[$unit['id']] = $ci;
            }
        }

        return [
            'metadata' => $metadata,
            'throughput' => $this->throughput->measure($lines),
            'combatants' => $combatants,
        ];
    }

    /**
     * Assembles a review from already-derived rounds.
     *
     * KNOWS NOTHING ABOUT WHERE THEY CAME FROM. Rounds reach this two ways — read out of the local
     * archive by `wow:review-lobby`, or uploaded from a player's browser — and a mirror comparison
     * must not mean two different things depending on which. That is why gathering lives in
     * buildFromArchive() and ArenaReviewIngestService rather than in here.
     *
     * @param  array<int, array{metadata: array, throughput: array, combatants: array}>  $derived
     */
    public function assemble(string $id, array $derived): ?array
    {
        if ($derived === []) {
            return null;
        }

        $rounds = [];
        $players = [];
        $specCache = [];
        $bracket = null;
        $first = null;
        $startTime = null;

        // Rounds can arrive in any order from an upload, so the sequence decides, not the array.
        usort($derived, fn ($a, $b) => ($a['metadata']['sequenceNumber'] ?? 1) <=> ($b['metadata']['sequenceNumber'] ?? 1));

        foreach ($derived as $round) {
            $meta = $round['metadata'];
            $measured = $round['throughput'] ?? ['players' => [], 'unattributedPetDamage' => 0, 'unparsed' => 0];
            $sequence = $meta['sequenceNumber'] ?? 1;
            $bracket ??= $meta['startInfo']['bracket'] ?? 'unknown';
            $first ??= $meta;
            $names = [];

            if (isset($meta['startTime'])) {
                $startTime = $startTime === null ? $meta['startTime'] : min($startTime, $meta['startTime']);
            }

            foreach ($meta['units'] as $unit) {
                if (! str_starts_with($unit['id'], 'Player-') || ($unit['spec'] ?? '0') === '0') {
                    continue;
                }

                $guid = $unit['id'];
                $names[$guid] = $unit['name'];

                $players[$guid] ??= [
                    'guid' => $guid,
                    'name' => $unit['name'],
                    'isYou' => ($unit['affiliation'] ?? null) === 1,
                    'spec' => $this->specLabel((string) $unit['spec'], $specCache),
                    'gear' => null,
                    'stats' => null,
                    'build' => null,
                    'rawTalentSignature' => null,
                    'buildChangedMidGame' => false,
                    'perRound' => [],
                    'totals' => array_fill_keys(
                        ['healingEffective', 'healingOverheal', 'absorbDone', 'damageDone', 'damageTaken', 'deaths', 'feigns'],
                        0
                    ),
                    'roundsPlayed' => 0,
                    'teamByRound' => [],
                ];

                // Build, gear and stats are taken from the first round the player appears in.
                // Later rounds are compared only far enough to notice a change: a shuffle lets you
                // respec between rounds, and a review that silently showed round one's build for
                // all six would be wrong in exactly the case worth knowing about.
                $ci = $round['combatants'][$guid] ?? null;

                if ($ci) {
                    $signature = md5(json_encode($ci['talents'] ?? []).json_encode($ci['pvpTalentIds'] ?? []));

                    if ($players[$guid]['rawTalentSignature'] === null) {
                        $players[$guid]['rawTalentSignature'] = $signature;
                        $players[$guid]['gear'] = $ci['gear'] ?? null;
                        $players[$guid]['stats'] = $ci['stats'] ?? null;
                        $players[$guid]['build'] = $this->resolveBuild($ci, $players[$guid]['spec']['id']);
                    } elseif ($players[$guid]['rawTalentSignature'] !== $signature) {
                        $players[$guid]['buildChangedMidGame'] = true;
                    }
                }

                $t = $measured['players'][$guid] ?? [];
                $perRound = array_fill_keys(array_keys($players[$guid]['totals']), 0);

                foreach ($perRound as $k => $_) {
                    $perRound[$k] = (int) ($t[$k] ?? 0);
                    $players[$guid]['totals'][$k] += $perRound[$k];
                }

                $players[$guid]['perRound'][$sequence] = $perRound;
                $players[$guid]['roundsPlayed']++;
                $players[$guid]['teamByRound'][$sequence] = $this->teamOf($meta, $guid);
            }

            $rounds[] = [
                'sequence' => $sequence,
                'matchId' => $meta['id'] ?? null,
                'durationSeconds' => $meta['durationInSeconds'] ?? 0,
                'result' => match ($meta['result'] ?? null) {
                    CombatLogIngestService::RESULT_WIN => 'won',
                    CombatLogIngestService::RESULT_LOSS => 'lost',
                    default => null,
                },
                'winningTeamId' => $meta['winningTeamId'] ?? null,
                'killedUnitId' => $meta['killedUnitId'] ?? null,
                'killedName' => $names[$meta['killedUnitId'] ?? ''] ?? null,
                'unattributedPetDamage' => $measured['unattributedPetDamage'] ?? 0,
                'unparsedEvents' => $measured['unparsed'] ?? 0,
            ];
        }

        return [
            'id' => $id,
            'bracket' => $bracket,
            'zoneId' => $first['startInfo']['zoneId'] ?? null,
            'isRanked' => $first['startInfo']['isRanked'] ?? null,
            'playedAt' => $startTime ? date('c', (int) ($startTime / 1000)) : null,
            'record' => [
                'won' => count(array_filter($rounds, fn ($r) => $r['result'] === 'won')),
                'lost' => count(array_filter($rounds, fn ($r) => $r['result'] === 'lost')),
            ],
            'rounds' => $rounds,
            'players' => array_values($players),
            'mirrors' => $this->mirrors($players),
            'limitations' => $this->limitations(),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Gathers a lobby's rounds out of the LOCAL archive and assembles them. Console only — the
     * archive is gitignored, so this path does not exist in production.
     */
    public function buildFromArchive(string $id): ?array
    {
        $group = collect($this->reviewable())->firstWhere('id', $id);

        if (! $group) {
            return null;
        }

        $derived = [];

        foreach ($group['matchIds'] as $matchId) {
            $meta = json_decode(File::get($this->arena->metadataPath($matchId)), true);

            if (! is_array($meta)) {
                continue;
            }

            $combatants = [];

            foreach ($meta['units'] as $unit) {
                if (! str_starts_with($unit['id'], 'Player-') || ($unit['spec'] ?? '0') === '0') {
                    continue;
                }

                $ci = $this->arena->extractCombatantInfo($matchId, $unit['id']);

                if ($ci !== null) {
                    $combatants[$unit['id']] = $ci;
                }
            }

            $derived[] = [
                'metadata' => $meta,
                'throughput' => $this->throughput->forMatch($matchId) ?? [],
                'combatants' => $combatants,
            ];
        }

        return $this->assemble($id, $derived);
    }

    // ---------------------------------------------------------------- the comparison

    /**
     * Same spec on both sides — the only comparison where every difference left is build, gear or
     * play, because the kit is identical.
     *
     * Pairs are only worth reporting when the two were actually opposed at some point, which in a
     * shuffle they nearly always are and in 3v3 is the definition of a mirror. `roundsOpposed` and
     * `roundsTogether` are carried so a reader can tell a real head-to-head from two players who
     * happened to share a team.
     */
    private function mirrors(array $players): array
    {
        $bySpec = [];

        foreach ($players as $p) {
            $bySpec[$p['spec']['externalId']][] = $p;
        }

        $mirrors = [];

        foreach ($bySpec as $group) {
            if (count($group) < 2) {
                continue;
            }

            // Your own pairing first, so the page leads with the comparison you came for.
            usort($group, fn ($a, $b) => ($b['isYou'] ? 1 : 0) <=> ($a['isYou'] ? 1 : 0));

            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    $mirrors[] = $this->comparePair($group[$i], $group[$j]);
                }
            }
        }

        usort($mirrors, fn ($a, $b) => ($b['involvesYou'] ? 1 : 0) <=> ($a['involvesYou'] ? 1 : 0));

        return $mirrors;
    }

    private function comparePair(array $a, array $b): array
    {
        $opposed = 0;
        $together = 0;

        foreach ($a['teamByRound'] as $seq => $team) {
            $other = $b['teamByRound'][$seq] ?? null;

            if ($other === null || $team === null) {
                continue;
            }

            $team === $other ? $together++ : $opposed++;
        }

        $metric = $this->primaryMetric($a, $b);

        return [
            'specLabel' => $a['spec']['label'],
            'specExternalId' => $a['spec']['externalId'],
            'involvesYou' => $a['isYou'] || $b['isYou'],
            'a' => $this->side($a),
            'b' => $this->side($b),
            'roundsOpposed' => $opposed,
            'roundsTogether' => $together,
            'primaryMetric' => $metric['key'],
            'primaryMetricLabel' => $metric['label'],
            'primaryDeltaPercent' => $metric['deltaPercent'],
            'talentDiff' => $this->talentDiff($a['build'], $b['build']),
            'pvpTalentDiff' => $this->pvpDiff($a['build'], $b['build']),
            'statDiff' => $this->statDiff($a['stats'], $b['stats']),
            'gearDiff' => $this->gearDiff($a['gear'], $b['gear']),
        ];
    }

    /**
     * Healing for a healer, damage for anything else — decided by which the pair actually did
     * more of, rather than by a hardcoded list of healer spec ids that would rot on a patch.
     */
    private function primaryMetric(array $a, array $b): array
    {
        $heal = $a['totals']['healingEffective'] + $a['totals']['absorbDone']
            + $b['totals']['healingEffective'] + $b['totals']['absorbDone'];
        $dmg = $a['totals']['damageDone'] + $b['totals']['damageDone'];

        if ($heal >= $dmg) {
            $va = $a['totals']['healingEffective'] + $a['totals']['absorbDone'];
            $vb = $b['totals']['healingEffective'] + $b['totals']['absorbDone'];
            $key = 'healingAndAbsorbs';
            $label = 'Effective healing + absorbs';
        } else {
            $va = $a['totals']['damageDone'];
            $vb = $b['totals']['damageDone'];
            $key = 'damageDone';
            $label = 'Damage done';
        }

        $base = max(1, min($va, $vb));

        return [
            'key' => $key,
            'label' => $label,
            'deltaPercent' => round(100 * ($va - $vb) / $base, 1),
        ];
    }

    private function side(array $p): array
    {
        return [
            'guid' => $p['guid'],
            'name' => $p['name'],
            'isYou' => $p['isYou'],
            'totals' => $p['totals'],
            'perRound' => $p['perRound'],
            'roundsPlayed' => $p['roundsPlayed'],
            'buildChangedMidGame' => $p['buildChangedMidGame'],
        ];
    }

    /**
     * Talent names one took and the other did not, plus the choice nodes where both spent a point
     * on different sides. Compared by resolved spell name, not by id: the log's own entry id is a
     * different Blizzard id space from our `external_talent_id`, which
     * ArenaLogService::resolveCombatantTalents() already reconciles — so by the time it reaches
     * here the honest key is the name it resolved to.
     */
    private function talentDiff(?array $a, ?array $b): array
    {
        if (! $a || ! $b) {
            return ['onlyA' => [], 'onlyB' => [], 'unavailable' => true];
        }

        $nameRank = function (array $build) {
            $out = [];

            foreach ($build['talents'] ?? [] as $t) {
                $out[$t['name']] = $t['rank'];
            }

            return $out;
        };

        $ta = $nameRank($a);
        $tb = $nameRank($b);

        $onlyA = [];
        $onlyB = [];
        $ranks = [];

        foreach ($ta as $name => $rank) {
            if (! isset($tb[$name])) {
                $onlyA[] = ['name' => $name, 'rank' => $rank];
            } elseif ($tb[$name] !== $rank) {
                $ranks[] = ['name' => $name, 'aRank' => $rank, 'bRank' => $tb[$name]];
            }
        }

        foreach ($tb as $name => $rank) {
            if (! isset($ta[$name])) {
                $onlyB[] = ['name' => $name, 'rank' => $rank];
            }
        }

        return [
            'onlyA' => $onlyA,
            'onlyB' => $onlyB,
            'differentRank' => $ranks,
            'unavailable' => false,
        ];
    }

    private function pvpDiff(?array $a, ?array $b): array
    {
        $names = fn (?array $build) => collect($build['pvpTalents'] ?? [])->pluck('name')->all();

        $na = $names($a);
        $nb = $names($b);

        return [
            'shared' => array_values(array_intersect($na, $nb)),
            'onlyA' => array_values(array_diff($na, $nb)),
            'onlyB' => array_values(array_diff($nb, $na)),
        ];
    }

    /**
     * Only the stats whose position this project can vouch for — see
     * ArenaLogService::extractCombatantStatsAndGear(), which refuses to label the block at all
     * unless its haste and versatility triples verify. Ratings, not percentages.
     */
    private function statDiff(?array $a, ?array $b): array
    {
        if (! ($a['aligned'] ?? false) || ! ($b['aligned'] ?? false)) {
            return ['unavailable' => true, 'rows' => []];
        }

        $rows = [];

        foreach (['intellect', 'haste', 'mastery', 'versatility', 'armor', 'secondaryTotal'] as $k) {
            $rows[] = ['stat' => $k, 'a' => $a[$k] ?? null, 'b' => $b[$k] ?? null];
        }

        return ['unavailable' => false, 'rows' => $rows];
    }

    private function gearDiff(?array $a, ?array $b): array
    {
        if (! $a || ! $b) {
            return ['unavailable' => true];
        }

        return [
            'unavailable' => false,
            'a' => ['items' => $a['items'], 'median' => $a['median'], 'max' => $a['max'], 'min' => $a['min']],
            'b' => ['items' => $b['items'], 'median' => $b['median'], 'max' => $b['max'], 'min' => $b['min']],
            'medianDelta' => $a['median'] - $b['median'],
        ];
    }

    // ---------------------------------------------------------------- support

    private function teamOf(array $meta, string $guid): ?string
    {
        // The metadata does not carry the per-round arena team, but `reaction` is the logging
        // player's own side, which is all a same-team / opposite-team test needs. It is NOT the
        // arena team id and must never be used as one — see CombatLogIngestService's docblock.
        foreach ($meta['units'] as $u) {
            if ($u['id'] === $guid) {
                return isset($u['reaction']) ? 'side'.$u['reaction'] : null;
            }
        }

        return null;
    }

    private function specLabel(string $externalSpecId, array &$cache): array
    {
        if (! array_key_exists($externalSpecId, $cache)) {
            $cache[$externalSpecId] = Specialization::with('gameClass')
                ->where('external_spec_id', $externalSpecId)
                ->first();
        }

        $spec = $cache[$externalSpecId];

        return [
            'externalId' => (int) $externalSpecId,
            'id' => $spec?->id,
            'name' => $spec?->name,
            'classSlug' => $spec?->gameClass?->slug,
            'specSlug' => $spec?->slug,
            'label' => $spec ? trim(($spec->name ?? '').' '.($spec->gameClass->name ?? '')) : "spec {$externalSpecId}",
        ];
    }

    private function resolveBuild(array $combatantInfo, ?int $specId): ?array
    {
        if ($specId === null) {
            return null;
        }

        return $this->arena->resolveCombatantTalents($combatantInfo, $specId);
    }

    /**
     * What a review cannot tell you. One method on purpose (rule 33's reasoning): honest limits
     * spread through a blade get trimmed away one line at a time by the next layout change.
     *
     * @return array<int, string>
     */
    public function limitations(): array
    {
        return [
            'Throughput is not adjusted for pressure. A healer who gets focused heals more, and nothing here can separate "healed more" from "was attacked more" — which is why damage taken sits next to it.',
            'Only a mirror is a fair output comparison. Two different specs doing different amounts of healing or damage is their kit, not their play.',
            'Overheal is not simply waste. Pre-shielding and topping before a go are deliberate, and a build that front-loads absorbs will always read as wasting more.',
            'Stat figures are ratings, not percentages, and are only comparable between characters of the same level.',
            'Item level is read from the equipped list, so a shirt or tabard at ilvl 1 pulls the mean down — the median is the number to read.',
            'A pet whose summon happened before the round began cannot be credited to its owner; that damage is reported per round as unattributed rather than assigned.',
            'Solo Shuffle rounds carry no rating, because the log\'s two figures are per-round team averages of a roster that reshuffles every round.',
            'Six rounds is a very small sample, and who you were given each round moves the result more than anything measured here.',
        ];
    }
}
