<?php

namespace App\Http\Services;

use App\Models\GameClass;
use App\Models\Spell;
use App\Models\Specialization;
use Illuminate\Support\Facades\File;

/**
 * Computes rating-band comparisons (damage, spell-cast rate, CC landed, interrupts, deaths,
 * win/loss-controlled survivability, kill-window composition) for one spec, purely from combat
 * logs already on file under data/arena-logs/ — no network calls, no new fetching. Pulling more
 * matches is still a separate, deliberate step via the existing wow:pull-low-rated-spec /
 * wow:discover-spec-spells / wow:pull-scarce-specs commands; this service only ever reads what's
 * already there, same "fetch and analyze are separate concerns" split ArenaLogService itself
 * already follows (search/fetch/store vs. extractCastSpellsByPlayer/findPreKillWindow).
 *
 * Built 2026-08-15, porting a set of one-off Node.js scratchpad scripts used earlier the same
 * session to compare Havoc Demon Hunter across rating tiers (2800+ vs ~1900 avg, then 2400+ vs
 * 2100-2400) into a real, repeatable, per-project artifact — the user directly asked whether this
 * process was automated (it wasn't) and to make it a command. Every number this service produces
 * was independently verified by hand against real matches before being ported here (field offsets
 * for damage amounts under advanced combat logging, the win/loss-from-last-death heuristic, CC
 * spell ID list) — see the session's own findings, now encoded as the constants/methods below.
 *
 * Output shape mirrors the existing file-based conventions in data/arena-logs/ (spell-usage/*.txt,
 * kill-sequences/*.jsonl) rather than a DB table — per direct instruction, this is still a
 * research/exploration phase. A JSON blob (not JSONL) was chosen for the output file specifically
 * because this data is a structured, regenerate-from-scratch-each-run aggregate object (like
 * comp-index.json/spec-index.json), not an append-forever event log — see
 * wow:analyze-rating-tiers's docblock for the exact output path and shape.
 */
class RatingTierAnalysisService
{
    /**
     * Known Demon Hunter CC spell IDs, carried over directly from the session's manual DB
     * lookups (dr_category/mechanic queries against `spells`) — see arena-log-api.md and this
     * session's own findings for how these were resolved. Generalizing this per-class is future
     * work; for now this only classifies CC accurately for classes it's been extended to cover.
     *
     * @var array<string, string[]>
     */
    private const CC_SPELL_IDS_BY_CLASS = [
        'demonhunter' => [
            '179057', // Chaos Nova
            '207684', '207685', // Sigil of Misery
            '202137', '204490', // Sigil of Silence
            '217832', // Imprison
            '211881', // Fel Eruption
            '205630', // Illidan's Grasp
            '205596', // Detainment
        ],
    ];

    private const PRE_KILL_WINDOW_SECONDS = 20;

    /**
     * Per-class hero-tree node-id sets, built once per analyzeSpec() call and reused across every
     * match/band — see heroTreeNodeSets() for how it's built and detectHeroTree() for how it's
     * used to classify one match's COMBATANT_INFO talent picks.
     *
     * @var array<string, array<int, true>>
     */
    private array $heroTreeNodeSets = [];

    /**
     * @param  array<int, array{label: string, min: int, max: int}>  $bands  min inclusive, max exclusive (use a large number like 99999 for an open-ended top band)
     * @return array{specExternalId: int, classSlug: string, specSlug: string, generatedAt: string, bands: array<int, array>}
     */
    public function analyzeSpec(int $specExternalId, string $bracket, array $bands): array
    {
        $spec = Specialization::where('external_spec_id', $specExternalId)->first();
        $class = $spec ? GameClass::find($spec->class_id) : null;
        $classSlug = $class?->slug ?? 'unknown';
        $specSlug = $spec?->slug ?? 'unknown';
        $ccSpellIds = self::CC_SPELL_IDS_BY_CLASS[$classSlug] ?? [];

        $this->heroTreeNodeSets = $class ? $this->buildHeroTreeNodeSets($class->id) : [];

        $matches = $this->findMatchesForSpec($specExternalId, $bracket);

        $bandResults = [];

        foreach ($bands as $band) {
            $inBand = array_values(array_filter(
                $matches,
                fn ($m) => $m['rating'] >= $band['min'] && $m['rating'] < $band['max']
            ));

            $bandResults[] = $this->analyzeBand($band, $inBand, $classSlug, $specSlug, $ccSpellIds);
        }

        return [
            'specExternalId' => $specExternalId,
            'classSlug' => $classSlug,
            'specSlug' => $specSlug,
            'bracket' => $bracket,
            'generatedAt' => now()->toIso8601String(),
            'heroTreesDetected' => array_keys($this->heroTreeNodeSets),
            'bands' => $bandResults,
        ];
    }

    /**
     * Builds { heroTreeName => set of external_node_id } for every hero-type talent tree
     * belonging to this class — the ground truth this player's COMBATANT_INFO talent picks get
     * matched against in detectHeroTree(). Scoped by class_id (not just type='hero') so a node-id
     * intersection can never cross into another class's hero trees.
     *
     * @return array<string, array<int, true>>
     */
    private function buildHeroTreeNodeSets(int $classId): array
    {
        $rows = \App\Models\TalentNode::query()
            ->join('talent_trees', 'talent_nodes.talent_tree_id', '=', 'talent_trees.id')
            ->where('talent_trees.class_id', $classId)
            ->where('talent_trees.type', 'hero')
            ->select('talent_trees.name as tree_name', 'talent_nodes.external_node_id')
            ->get();

        $sets = [];
        foreach ($rows as $row) {
            $sets[$row->tree_name][(int) $row->external_node_id] = true;
        }

        return $sets;
    }

    /**
     * Parses the target player's own COMBATANT_INFO line for this match and determines which
     * hero tree (if any) their talent picks belong to. COMBATANT_INFO's talent list is a plain,
     * uncompressed "[(nodeExternalId,entryExternalId,rank),...]" array — NOT the compact base64
     * export-string format BlizzardTalentStringCodec decodes, so no bitstream parsing is needed
     * here. Verified by hand this session: the FIRST number of each triple matches
     * talent_nodes.external_node_id exactly (19/19 on a real sample); the second number does NOT
     * match talent_node_entries.external_talent_id under any tried interpretation, so entry/rank
     * resolution is left unused — node-id presence alone is sufficient to identify which hero
     * tree was picked, since a player can only have one hero tree's nodes active at a time.
     *
     * Returns the hero tree with the most matched node ids (ties broken by whichever appears
     * first in PHP's iteration order) or null if this class has no hero trees, the
     * COMBATANT_INFO line wasn't found, or no node id matched any known hero tree (e.g. a very
     * old snapshot pre-dating hero talents).
     *
     * REAL BUG found and fixed 2026-08-15, confirmed by hand against real failing lines before
     * shipping (not guessed): the bracket capture originally required the WHOLE contents to
     * match a strict repeating "(id,id,rank)," group — but a real, non-trivial fraction of
     * COMBATANT_INFO lines (confirmed on Hunter specifically, but nothing ties this to one
     * class) have a stray leading comma right after the opening bracket
     * ("[,(94960,117557,1),(94961,...") rather than starting directly with "(". That single
     * malformed-looking leading element made the strict pattern reject the ENTIRE bracket,
     * silently producing a null/unknown result for an otherwise perfectly good 77-entry talent
     * list — this was the root cause of the large "unknown" fraction reported live on the
     * Rating Tiers tab for Hunter (roughly half of all Marksmanship performances). Fixed by
     * capturing loosely (anything up to the closing "]") and extracting tuples from within that
     * span via a separate, tolerant pass — this doesn't care about stray commas/whitespace
     * between entries, only that each real entry itself is a clean "(digits,digits,digits)".
     * Verified: the same real failing match now extracts all 77 node ids instead of 0.
     */
    private function detectHeroTree(string $raw, string $guid): ?string
    {
        if ($this->heroTreeNodeSets === []) {
            return null;
        }

        $guidQ = preg_quote($guid, '/');

        if (!preg_match('/COMBATANT_INFO,'.$guidQ.',.*?\[([^\]]*)\]/', $raw, $m)) {
            return null;
        }

        preg_match_all('/\((\d+),\d+,\d+\)/', $m[1], $nodeMatches);
        $nodeIds = array_map('intval', $nodeMatches[1]);

        if ($nodeIds === []) {
            return null;
        }

        $bestTree = null;
        $bestCount = 0;

        foreach ($this->heroTreeNodeSets as $treeName => $nodeSet) {
            $count = 0;
            foreach ($nodeIds as $nodeId) {
                if (isset($nodeSet[$nodeId])) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestTree = $treeName;
            }
        }

        return $bestTree;
    }

    /**
     * Scans every metadata file on disk for a real player of the given spec — same brute-force
     * glob-and-check approach used ad hoc this session (there is no index of "which matches
     * contain which spec" beyond re-reading every file; acceptable at the current ~200-file
     * scale, worth revisiting if this directory grows an order of magnitude).
     *
     * @return array<int, array{matchId: string, rating: int, playerName: string, playerGuid: string}>
     */
    private function findMatchesForSpec(int $specExternalId, string $bracket): array
    {
        $results = [];

        foreach (File::glob(base_path('data/arena-logs/metadata/*.json')) as $path) {
            $meta = json_decode(File::get($path), true);

            if (!$meta || ($meta['startInfo']['bracket'] ?? null) !== $bracket) {
                continue;
            }

            foreach ($meta['units'] ?? [] as $unit) {
                if (!str_starts_with($unit['id'], 'Player-') || (int) ($unit['spec'] ?? 0) !== $specExternalId) {
                    continue;
                }

                $matchId = pathinfo($path, PATHINFO_FILENAME);

                if (!File::exists(base_path("data/arena-logs/raw/{$matchId}.log.gz"))) {
                    continue;
                }

                $results[] = [
                    'matchId' => $matchId,
                    'rating' => (int) ($meta['playerTeamRating'] ?? 0),
                    'duration' => (int) ($meta['durationInSeconds'] ?? 0),
                    'playerName' => $unit['name'],
                    'playerGuid' => $unit['id'],
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array{matchId: string, rating: int, duration: int, playerName: string, playerGuid: string}>  $matchesInBand
     * @param  string[]  $ccSpellIds
     */
    private function analyzeBand(array $band, array $matchesInBand, string $classSlug, string $specSlug, array $ccSpellIds): array
    {
        if ($matchesInBand === []) {
            return [
                'label' => $band['label'], 'min' => $band['min'], 'max' => $band['max'],
                'n' => 0, 'players' => 0, 'performances' => [],
            ];
        }

        // dedupe: the same real match can legitimately be found more than once if a search
        // window overlapped a previous pull — a real performance is unique per (matchId, guid).
        // Each performance carries its originating $m (matchId/rating/duration/playerName/
        // playerGuid) alongside it under '_meta' so the hero-tree subgroup split below can
        // rebuild a matches-list for killWindowStats() without a second, error-prone join.
        $seen = [];
        $performances = [];

        foreach ($matchesInBand as $m) {
            $key = $m['matchId'].'|'.$m['playerGuid'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $performance = $this->analyzeOneMatch($m);
            $performance['_meta'] = $m;
            $performances[] = $performance;
        }

        $summary = $this->summarizePerformances($band, $performances, $matchesInBand, $classSlug, $specSlug);

        // Hero-tree breakdown: same summarizer, re-run per hero-tree subgroup, so every stat
        // (DPS, cast rate, CC, win/loss, kill window) is comparable build-for-build, not just
        // averaged across whichever hero trees happened to be picked in this band's sample.
        // Performances with no resolvable hero tree (detectHeroTree() returned null — a class
        // with no hero trees, or a COMBATANT_INFO line that didn't parse) are grouped under
        // 'unknown' rather than silently dropped, so the sample-size accounting still adds up.
        // Only reported when there's an actual split worth showing (skip for classes with no
        // hero trees at all, where every performance would trivially land in 'unknown').
        $byHeroTree = [];
        foreach ($performances as $p) {
            $treeKey = $p['heroTree'] ?? 'unknown';
            $byHeroTree[$treeKey][] = $p;
        }

        $heroTreeBreakdown = [];
        if (!(count($byHeroTree) === 1 && isset($byHeroTree['unknown']))) {
            foreach ($byHeroTree as $treeName => $treePerformances) {
                $treeMatchesInBand = array_map(fn ($p) => $p['_meta'], $treePerformances);

                $heroTreeBreakdown[$treeName] = $this->summarizePerformances(
                    ['label' => $treeName, 'min' => $band['min'], 'max' => $band['max']],
                    $treePerformances,
                    $treeMatchesInBand,
                    $classSlug,
                    $specSlug,
                );
            }
        }

        $summary['heroTreeBreakdown'] = $heroTreeBreakdown;

        return $summary;
    }

    /**
     * Shared aggregation core — computes every stat (DPS, casts/min, CC, interrupts, deaths,
     * win/loss, spell rates, damage share, kill window) from an already-parsed performance list.
     * Called once for a band's full sample and again per hero-tree subgroup, so both use
     * identical math and are directly comparable.
     */
    private function summarizePerformances(array $band, array $performances, array $matchesForKillWindow, string $classSlug, string $specSlug): array
    {
        $n = count($performances);

        if ($n === 0) {
            return [
                'label' => $band['label'], 'min' => $band['min'], 'max' => $band['max'],
                'n' => 0, 'players' => 0,
            ];
        }

        $avg = fn (callable $f) => array_sum(array_map($f, $performances)) / $n;

        $spellIdNames = $this->resolveSpellNames($performances);

        $castAvg = $this->aggregateRatePerMinute($performances, 'casts', $spellIdNames, $n);
        $ccAvg = $this->aggregateRatePerMinute($performances, 'cc', $spellIdNames, $n);
        $dmgShare = $this->aggregateDamageShare($performances, $spellIdNames);

        $wins = array_filter($performances, fn ($p) => $p['won'] === true);
        $losses = array_filter($performances, fn ($p) => $p['won'] === false);
        $unresolved = array_filter($performances, fn ($p) => $p['won'] === null);

        $playerCounts = [];
        foreach ($performances as $p) {
            $playerCounts[$p['playerName']] = ($playerCounts[$p['playerName']] ?? 0) + 1;
        }
        arsort($playerCounts);

        $killWindow = $this->killWindowStats($classSlug, $specSlug, $matchesForKillWindow);

        return [
            'label' => $band['label'], 'min' => $band['min'], 'max' => $band['max'],
            'n' => $n,
            'players' => count($playerCounts),
            'playerBreakdown' => $playerCounts,
            'avgRating' => round($avg(fn ($p) => $p['rating']), 1),
            'avgDps' => round($avg(fn ($p) => $p['dps']), 1),
            'avgCastsPerMin' => round($avg(fn ($p) => $p['castsPerMin']), 2),
            'avgCcTargetsHit' => round($avg(fn ($p) => $p['ccUniqueTargetsHit']), 2),
            'avgInterruptsPerMin' => round($avg(fn ($p) => $p['interruptsPerMin']), 2),
            'avgDeaths' => round($avg(fn ($p) => $p['deaths']), 2),
            'avgDuration' => round($avg(fn ($p) => $p['duration']), 1),
            'winRate' => (count($wins) + count($losses)) > 0 ? round(count($wins) / (count($wins) + count($losses)) * 100, 1) : null,
            'winLossResolved' => count($wins) + count($losses),
            'winLossUnresolved' => count($unresolved),
            'avgDeathsInWins' => count($wins) ? round(array_sum(array_map(fn ($p) => $p['deaths'], $wins)) / count($wins), 2) : null,
            'avgDeathsInLosses' => count($losses) ? round(array_sum(array_map(fn ($p) => $p['deaths'], $losses)) / count($losses), 2) : null,
            'castsPerMinBySpell' => $castAvg,
            'ccPerMinBySpell' => $ccAvg,
            'damageSharePct' => $dmgShare,
            'killWindow' => $killWindow,
        ];
    }

    /**
     * Parses one match's raw log for the target player's casts, damage, CC landed, interrupts,
     * and deaths, plus a win/loss determination — same field-offset logic and win/loss heuristic
     * verified by hand against real matches this session (advanced-combat-logging amount offset,
     * last-real-player-death-decides-the-loser).
     */
    private function analyzeOneMatch(array $m): array
    {
        $raw = gzdecode(File::get(base_path("data/arena-logs/raw/{$m['matchId']}.log.gz")));
        $meta = json_decode(File::get(base_path("data/arena-logs/metadata/{$m['matchId']}.json")), true);
        $guid = $m['playerGuid'];
        $guidQ = preg_quote($guid, '/');

        $casts = []; // spellId => ['name' => ..., 'count' => n]
        $damageBySpell = []; // spellId => ['name' => ..., 'amount' => n]
        $ccApplied = [];
        $ccTargets = [];
        $totalCastCount = 0;
        $totalDamage = 0;
        $interrupts = 0;
        $deaths = 0;

        foreach (explode("\n", $raw) as $line) {
            if ($line === '' || strpos($line, $guid) === false) {
                continue;
            }

            if (preg_match('/SPELL_CAST_SUCCESS,'.$guidQ.',"[^"]*",[^,]*,[^,]*,[^,]*,[^,]*,[^,]*,[^,]*,(\d+),"([^"]*)"/', $line, $mm)) {
                $sid = $mm[1];
                $casts[$sid] = $casts[$sid] ?? ['name' => $mm[2], 'count' => 0];
                $casts[$sid]['count']++;
                $totalCastCount++;
            }

            if (preg_match('/(SPELL_DAMAGE|SPELL_PERIODIC_DAMAGE|DAMAGE_SPLIT),'.$guidQ.',"[^"]*",[^,]*,[^,]*,[^,]*,[^,]*,[^,]*,[^,]*,(\d+),"([^"]*)",[^,]*,(.*)$/', $line, $mm)) {
                $sid = $mm[2];
                $name = $mm[3];
                $tail = explode(',', $mm[4]);
                $amount = $this->extractDamageAmount($tail);
                $damageBySpell[$sid] = $damageBySpell[$sid] ?? ['name' => $name, 'amount' => 0];
                $damageBySpell[$sid]['amount'] += $amount;
                $totalDamage += $amount;
            }

            if (preg_match('/(SWING_DAMAGE_LANDED|SWING_DAMAGE),'.$guidQ.',"[^"]*",[^,]*,[^,]*,[^,]*,[^,]*,[^,]*,[^,]*,(.*)$/', $line, $mm)) {
                $tail = explode(',', $mm[2]);
                $amount = $this->extractSwingAmount($tail);
                $damageBySpell['melee'] = $damageBySpell['melee'] ?? ['name' => '(melee swing)', 'amount' => 0];
                $damageBySpell['melee']['amount'] += $amount;
                $totalDamage += $amount;
            }

            if (preg_match('/(SPELL_AURA_APPLIED|SPELL_AURA_APPLIED_DOSE),'.$guidQ.',"[^"]*",[^,]*,[^,]*,(Player-[^,]+),"[^"]*",[^,]*,[^,]*,(\d+),"([^"]*)"/', $line, $mm)) {
                $destGuid = $mm[2];
                $sid = $mm[3];
                if ($destGuid !== $guid && in_array($sid, [
                    '179057', '207684', '207685', '202137', '204490', '217832', '211881', '205630', '205596',
                ], true)) {
                    $ccApplied[$sid] = $ccApplied[$sid] ?? ['name' => $mm[4], 'count' => 0];
                    $ccApplied[$sid]['count']++;
                    $ccTargets[$destGuid] = true;
                }
            }

            if (preg_match('/SPELL_INTERRUPT,'.$guidQ.',/', $line)) {
                $interrupts++;
            }

            if (preg_match('/UNIT_DIED,[^,]*,[^,]*,[^,]*,[^,]*,'.$guidQ.',/', $line)) {
                $deaths++;
            }
        }

        $won = $this->didPlayerWin($raw, $meta, $guid);
        $heroTree = $this->detectHeroTree($raw, $guid);

        return [
            'matchId' => $m['matchId'], 'rating' => $m['rating'], 'duration' => max($m['duration'], 1),
            'playerName' => $m['playerName'],
            'casts' => $casts, 'totalCastCount' => $totalCastCount,
            'damageBySpell' => $damageBySpell, 'totalDamage' => $totalDamage,
            'dps' => $totalDamage / max($m['duration'], 1),
            'castsPerMin' => ($totalCastCount / max($m['duration'], 1)) * 60,
            'ccApplied' => $ccApplied, 'ccUniqueTargetsHit' => count($ccTargets),
            'interruptsPerMin' => ($interrupts / max($m['duration'], 1)) * 60,
            'deaths' => $deaths,
            'won' => $won,
            'heroTree' => $heroTree,
        ];
    }

    /**
     * Advanced-combat-logging amount offset, verified by hand this session against real
     * SPELL_DAMAGE lines: prefix(11 fields already consumed by the outer regex) + a 19-field
     * advanced-info block (when present) puts `amount` at tail index 19 (0-based) of the
     * post-spellSchool tail; without advanced logging, amount is tail index 0. Detected by tail
     * length rather than assumed, since not every match is guaranteed to have advanced logging
     * on (see arena-log-api.md).
     */
    private function extractDamageAmount(array $tail): int
    {
        if (count($tail) >= 20) {
            return (int) ($tail[19] ?? 0);
        }

        return (int) ($tail[0] ?? 0);
    }

    /**
     * Same advanced-info-block logic as extractDamageAmount(), but SWING_* events have no
     * spellId/name/school prefix — the advanced block (when present) starts right after the 8
     * source+dest fields already consumed by the outer regex, so amount lands at tail index 19
     * too once the block is present (8 fields already stripped by the regex + 19 advanced fields
     * = same relative offset as the spell-prefixed case, verified separately against a real
     * SWING_DAMAGE_LANDED line this session).
     */
    private function extractSwingAmount(array $tail): int
    {
        if (count($tail) >= 20) {
            return (int) ($tail[19] ?? 0);
        }

        return (int) ($tail[0] ?? 0);
    }

    /**
     * Mirrors ArenaLogService::findPreKillWindow()'s win/loss logic: the LAST real-player death
     * in the log determines the losing side (that unit's `reaction`); the target player won iff
     * their own `reaction` differs from the loser's. Returns null when no resolvable death is
     * found (e.g. a match that ended in a draw/disconnect with no clean kill).
     */
    private function didPlayerWin(string $raw, array $meta, string $playerGuid): ?bool
    {
        if (!preg_match_all('/^([\d\/: .-]+)\s+(?:PARTY_KILL|UNIT_DIED),[^,]*,[^,]*,[^,]*,[^,]*,(Player-[^,]+),"([^"]*)"/m', $raw, $matches, PREG_SET_ORDER)) {
            return null;
        }
        if ($matches === []) {
            return null;
        }

        $last = end($matches);
        $killedGuid = $last[2];

        $killedUnit = collect($meta['units'] ?? [])->firstWhere('id', $killedGuid);
        $playerUnit = collect($meta['units'] ?? [])->firstWhere('id', $playerGuid);

        if (!$killedUnit || !$playerUnit) {
            return null;
        }

        return $playerUnit['reaction'] !== $killedUnit['reaction'];
    }

    /**
     * Reads the already-recorded kill-sequences file (built by
     * ArenaLogService::recordKillSequence(), same file WowComps' Kill Sequence tab reads) and
     * computes: % of this band's winning kills containing each ability, plus average cast
     * count/distinct-spell count in the pre-kill window. Restricted to matchIds actually in this
     * band — the jsonl file itself is not rating-scoped, so filtering happens here.
     */
    private function killWindowStats(string $classSlug, string $specSlug, array $matchesInBand): array
    {
        $path = base_path("data/arena-logs/kill-sequences/{$classSlug}/{$specSlug}.jsonl");

        if (!File::exists($path)) {
            return ['n' => 0, 'spellPct' => [], 'avgCasts' => null, 'avgDistinctSpells' => null];
        }

        $matchIdsInBand = array_flip(array_column($matchesInBand, 'matchId'));

        $records = [];
        foreach (File::lines($path) as $line) {
            $decoded = json_decode($line, true);
            if ($decoded && isset($matchIdsInBand[$decoded['matchId']])) {
                $records[] = $decoded;
            }
        }

        $n = count($records);
        if ($n === 0) {
            return ['n' => 0, 'spellPct' => [], 'avgCasts' => null, 'avgDistinctSpells' => null];
        }

        $matchCount = [];
        $nameBySpell = [];
        $totalCasts = 0;
        $totalDistinct = 0;

        foreach ($records as $r) {
            $seen = [];
            foreach ($r['sequence'] as $cast) {
                $id = $cast['spellId'];
                if (!isset($nameBySpell[$id]) || (preg_match('/[^\x00-\x7F]/', $nameBySpell[$id]) && !preg_match('/[^\x00-\x7F]/', $cast['name']))) {
                    $nameBySpell[$id] = $cast['name'];
                }
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $matchCount[$id] = ($matchCount[$id] ?? 0) + 1;
                }
            }
            $totalCasts += count($r['sequence']);
            $totalDistinct += count($seen);
        }

        $spellPct = [];
        arsort($matchCount);
        foreach (array_slice($matchCount, 0, 15, true) as $id => $count) {
            $spellPct[$nameBySpell[$id]] = round($count / $n * 100, 1);
        }

        return [
            'n' => $n,
            'spellPct' => $spellPct,
            'avgCasts' => round($totalCasts / $n, 2),
            'avgDistinctSpells' => round($totalDistinct / $n, 2),
        ];
    }

    /**
     * Resolves every spell_id seen across a band's performances to a canonical English name via
     * the `spells` table (same "don't trust the raw log's own locale-dependent name" discipline
     * as ArenaLogService::recordKillSequence() — several matches this session came from
     * non-English clients). Falls back to the raw log's own embedded name for a spell_id not yet
     * in our imported data.
     *
     * @return array<string, string>
     */
    private function resolveSpellNames(array $performances): array
    {
        $ids = [];
        foreach ($performances as $p) {
            foreach (array_keys($p['casts']) as $id) {
                $ids[$id] = true;
            }
            foreach (array_keys($p['damageBySpell']) as $id) {
                if ($id !== 'melee') {
                    $ids[$id] = true;
                }
            }
        }
        $ids = array_keys($ids);

        if ($ids === []) {
            return [];
        }

        $rows = Spell::whereIn('spell_id', $ids)
            ->where('not_in_spellbook', false)
            ->orderByDesc('cooldown_seconds')
            ->get(['spell_id', 'name']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->spell_id] ??= $row->name;
        }

        $missing = array_diff($ids, array_keys($map));
        if ($missing !== []) {
            foreach (Spell::whereIn('spell_id', $missing)->get(['spell_id', 'name']) as $row) {
                $map[(string) $row->spell_id] ??= $row->name;
            }
        }

        // fall back to each performance's own raw-log name for anything DB lookup never covered
        foreach ($performances as $p) {
            foreach ($p['casts'] as $sid => $data) {
                $map[$sid] ??= $data['name'];
            }
        }

        return $map;
    }

    /**
     * @return array<string, float>
     */
    private function aggregateRatePerMinute(array $performances, string $field, array $spellIdNames, int $n): array
    {
        $sums = [];
        foreach ($performances as $p) {
            foreach ($p[$field === 'casts' ? 'casts' : 'ccApplied'] as $sid => $data) {
                $name = $spellIdNames[$sid] ?? $data['name'];
                $rate = ($data['count'] / $p['duration']) * 60;
                $sums[$name] = ($sums[$name] ?? 0) + $rate;
            }
        }
        $avg = array_map(fn ($sum) => round($sum / $n, 2), $sums);
        arsort($avg);

        return $avg;
    }

    /**
     * @return array<string, float>
     */
    private function aggregateDamageShare(array $performances, array $spellIdNames): array
    {
        $totals = [];
        $grand = 0;
        foreach ($performances as $p) {
            foreach ($p['damageBySpell'] as $sid => $data) {
                $name = $sid === 'melee' ? $data['name'] : ($spellIdNames[$sid] ?? $data['name']);
                $totals[$name] = ($totals[$name] ?? 0) + $data['amount'];
                $grand += $data['amount'];
            }
        }
        if ($grand === 0) {
            return [];
        }
        $share = array_map(fn ($amt) => round($amt / $grand * 100, 2), $totals);
        arsort($share);

        return array_slice($share, 0, 15, true);
    }
}
