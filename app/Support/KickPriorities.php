<?php

namespace App\Support;

/**
 * Which casts of each spec are worth an interrupt (2026-10-16), and what the archive says about them.
 *
 * Two files in data/kick-priorities/:
 *  - priorities.json: each spec's casts by tier (high, medium, low), drafted from Skill Capped's
 *    per-spec "Important Casts" lists for 12.1. Spell names and tiers only; each spec links its page.
 *  - evidence.json, written by tools/match-review/kickpriority.php: for every listed cast, the spell
 *    ids it is cast under and how often players of the spec began it, finished it and were stopped
 *    on it across the archive, plus the casts stopped often that the list leaves out.
 *
 * A listed cast COUNTS as a chance to kick only when it is high or medium and the archive has seen
 * it stopped at least once. A cast that went out hundreds of times and was never stopped (Penance,
 * Aimed Shot, Fists of Fury) cannot be kicked in practice, so letting it through is not a miss.
 * A cast no one in the archive pressed is not shown at all.
 */
class KickPriorities
{
    /** A cast is seen often enough to call "never stopped" a fact about it from this many casts. */
    public const ENOUGH_CASTS = 30;

    /**
     * Each class's interrupts by name (a pet's included, credited to its owner) and the base cooldown
     * of the first. The same table as tools/match-review/kickread.php. Talents that shorten a cooldown
     * are not read, so a kick is taken as down for longer than it may have been: fewer chances are
     * counted, never more.
     */
    public const KICKS = [
        'hunter' => [['Counter Shot', 'Muzzle'], 24], 'rogue' => [['Kick'], 15], 'warrior' => [['Pummel'], 15],
        'deathknight' => [['Mind Freeze'], 15], 'monk' => [['Spear Hand Strike'], 15], 'paladin' => [['Rebuke'], 15],
        'shaman' => [['Wind Shear'], 12], 'druid' => [['Skull Bash', 'Solar Beam'], 15], 'mage' => [['Counterspell'], 24],
        'demonhunter' => [['Disrupt'], 15], 'evoker' => [['Quell'], 20], 'warlock' => [['Spell Lock', 'Axe Toss'], 24],
        'priest' => [['Silence'], 45],
    ];

    /**
     * Specs whose interrupt every player of them has, and is there to use. Anyone else (a Holy
     * Paladin's Rebuke, a Resto Druid's Skull Bash, a Shadow Priest's Silence) is counted only in a
     * round they pressed it, since otherwise the log cannot tell whether they took the talent. So is
     * a healer whose kick is baseline (a Resto Shaman's Wind Shear, a Preservation Evoker's Quell):
     * a healer's globals go on healing first, and the archive's 62 Preservation rounds held no Quell
     * at all (2026-10-16), so counting every cast against them measured the role, not the player.
     */
    private const ALWAYS_HAS = [
        'rogue/*', 'warrior/*', 'deathknight/*', 'mage/*', 'hunter/*', 'warlock/*', 'demonhunter/*',
        'shaman/elemental', 'shaman/enhancement', 'evoker/devastation', 'evoker/augmentation',
        'monk/windwalker', 'paladin/retribution', 'druid/feral', 'druid/balance',
    ];

    /**
     * Kicks the log writes as crowd control, never as SPELL_INTERRUPT: a Shadow Priest's Silence (303
     * presses across 88 rounds, no interrupt line, 2026-10-16). One pressed on a caster while their
     * cast is under way is the stop.
     */
    public const LOGGED_AS_CONTROL = ['Silence'];

    private static ?array $doc = null;

    private static ?array $evidence = null;

    public static function path(string $file = 'priorities.json'): string
    {
        return base_path('data/kick-priorities/'.$file);
    }

    public static function doc(): array
    {
        return self::$doc ??= json_decode((string) @file_get_contents(self::path()), true) ?: ['specs' => []];
    }

    public static function evidence(): array
    {
        return self::$evidence ??= json_decode((string) @file_get_contents(self::path('evidence.json')), true) ?: ['specs' => []];
    }

    /** For tests: forget what was read. */
    public static function flush(): void
    {
        self::$doc = self::$evidence = null;
        self::$interrupts = [];
    }

    /**
     * For tests: serve these instead of the files (flush() goes back to the files), and optionally a
     * class's interrupts instead of the spell data: ['rogue' => [['name' => 'Kick', 'range' => 5.0, 'cd' => 15.0]]].
     */
    public static function fake(array $doc, array $evidence, array $interrupts = []): void
    {
        self::$doc = $doc;
        self::$evidence = $evidence;
        self::$interrupts = $interrupts;
    }

    /** A content signature, so a page drawn from these files is redrawn when they change. */
    public static function signature(): string
    {
        return substr(md5((string) @filemtime(self::path()).'|'.(string) @filemtime(self::path('evidence.json'))), 0, 8);
    }

    /**
     * Every listed cast of a spec that someone in the archive pressed, in tier order, with what the
     * archive saw: [name, tier, ids, begun, done, stopped, counts, channel].
     *
     * @return list<array{name: string, tier: string, ids: list<int>, casts: int, stopped: int, counts: bool, channel: bool}>
     */
    public static function forSpec(string $specKey): array
    {
        $listed = self::evidence()['specs'][$specKey]['listed'] ?? [];
        $rows = [];
        foreach (['high', 'medium', 'low'] as $tier) {
            foreach (self::doc()['specs'][$specKey][$tier] ?? [] as $name) {
                $e = $listed[$name] ?? null;
                // Only a cast with a cast time can be kicked, so a spell that has one is counted by
                // the casts begun (Radiance's instant charges are not chances); a channel by its casts.
                $casts = $e ? ((int) $e['begun'] > 0 ? (int) $e['begun'] : (int) $e['done']) : 0;
                if ($casts === 0) {
                    continue;
                }
                $rows[] = [
                    'name' => $name,
                    'tier' => $tier,
                    'ids' => array_map('intval', $e['ids'] ?? []),
                    'casts' => $casts,
                    'stopped' => (int) $e['stopped'],
                    'counts' => $tier !== 'low' && (int) $e['stopped'] > 0,
                    // A channel writes no SPELL_CAST_START: it begins at its SPELL_CAST_SUCCESS.
                    'channel' => (int) $e['begun'] === 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * The casts of a spec the archive sees stopped often that the published list leaves out, most
     * stopped first. Observation, not a rank-one tier.
     *
     * @return list<array{name: string, casts: int, stopped: int}>
     */
    public static function alsoKicked(string $specKey): array
    {
        return collect(self::evidence()['specs'][$specKey]['notListed'] ?? [])
            ->map(fn ($e, $name) => ['name' => (string) $name, 'casts' => (int) $e['begun'] > 0 ? (int) $e['begun'] : (int) $e['done'], 'stopped' => (int) $e['stopped']])
            ->sortByDesc('stopped')->values()->all();
    }

    /**
     * The casts of a spec that count as a chance to kick, by spell id.
     *
     * @return array<int, array{name: string, tier: string, channel: bool}>
     */
    public static function countable(string $specKey): array
    {
        $out = [];
        foreach (self::forSpec($specKey) as $row) {
            if ($row['counts']) {
                foreach ($row['ids'] as $id) {
                    $out[$id] = ['name' => $row['name'], 'tier' => $row['tier'], 'channel' => $row['channel']];
                }
            }
        }

        return $out;
    }

    /** @var array<string, list<array{name: string, range: ?float, cd: float}>> */
    private static array $interrupts = [];

    /**
     * A class's interrupts with their range and cooldown FROM THE SPELL DATA: the copy flagged
     * `is_interrupt` with a cooldown, else the one with a cooldown and a real range (one visible
     * ability is often several copies, rule 3; the 100-yard copies are internal). A kick the data
     * does not hold (a warlock pet's Spell Lock and Axe Toss) keeps KICKS' cooldown and no range, so
     * its reach is not judged.
     *
     * @return list<array{name: string, range: ?float, cd: float}>
     */
    public static function interrupts(string $class): array
    {
        if (isset(self::$interrupts[$class])) {
            return self::$interrupts[$class];
        }
        [$names, $fallbackCd] = self::KICKS[$class] ?? [[], 0];
        $out = [];
        foreach ($names as $name) {
            $rows = \Illuminate\Support\Facades\DB::table('spells')->where('name', $name)->whereNotNull('cooldown_seconds')
                ->get(['range_yards', 'cooldown_seconds', 'is_interrupt'])
                ->map(fn ($r) => ['range' => is_numeric($y = (float) $r->range_yards) && $y > 0 && $y < 100 ? $y : null, 'cd' => (float) $r->cooldown_seconds, 'flag' => (bool) $r->is_interrupt])
                ->sortByDesc(fn ($r) => ($r['flag'] ? 2 : 0) + ($r['range'] !== null ? 1 : 0))->values();
            $best = $rows->first();
            $out[] = ['name' => $name, 'range' => $best['range'] ?? null, 'cd' => $best['cd'] ?? (float) $fallbackCd];
        }

        return self::$interrupts[$class] = $out;
    }

    /** Whether every player of this spec has its interrupt without a talent. */
    public static function alwaysHasKick(string $specKey): bool
    {
        [$class] = explode('/', $specKey) + [''];

        return in_array($specKey, self::ALWAYS_HAS, true) || in_array($class.'/*', self::ALWAYS_HAS, true);
    }

    /** The published source for a spec, for a link: [source, url, read, patch]. */
    public static function source(string $specKey): ?array
    {
        $url = self::doc()['specs'][$specKey]['url'] ?? null;

        return $url ? ['source' => self::doc()['source'] ?? '', 'url' => $url, 'read' => self::doc()['read'] ?? '', 'patch' => self::doc()['patch'] ?? ''] : null;
    }

    public static function hasSpec(string $specKey): bool
    {
        return self::forSpec($specKey) !== [] || self::alsoKicked($specKey) !== [];
    }
}
