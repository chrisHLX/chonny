<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Builds one spec's Burst Guide — a phased, mechanically-grounded plan for executing a go,
 * aggregated across EVERY real archived burst window for that spec.
 *
 * Replaced the original 2026-09-04 implementation (ArenaLogService::buildBurstGuideSequence(),
 * deleted) on 2026-09-06 after direct user feedback that it "didn't really understand the
 * mechanics and relied on examples only." Both halves of that are what this class exists to fix:
 *
 *  - "Examples only" — the old builder replayed the single highest-damage 30-second window from
 *    ONE match, verbatim, minus defensives. One anecdote, one player, one game. Whatever that
 *    player happened to press (including a mid-burst re-stealth) became "the guide," and a naive
 *    "stop once a 2+ block repeats" truncation produced junk tails — Assassination Rogue's
 *    committed output ended `Ambush, Ambush, Ambush, Ambush`, a period-1 repeat the rule
 *    deliberately did not catch. This class instead streams the archive's full per-window corpus
 *    ({archive}/rotations/{class}/{spec}.jsonl — 200-1900 real windows per spec across dozens of
 *    matches) and reports what is TYPICAL: per-ability presence rate, median timing, casts per
 *    window. A one-off press can no longer become a guide step.
 *
 *  - "Didn't understand the mechanics" — the old output was a flat icon strip with no notion of
 *    what a burst actually is. A go has structure: you set up (land control so the damage cannot
 *    simply be healed or walked away from), you commit (stack your cooldowns), then you execute
 *    (spend the window). This class derives that structure rather than asserting it — see PHASES
 *    below — plus the two numbers that make a burst plan actionable at all: how long the window
 *    is, and how many globals fit inside it.
 *
 * NOTHING HERE IS HAND-AUTHORED PER SPEC. Every number is measured from the corpus or read from
 * this project's own curated spell data; every rule below is applied identically to all 38 specs.
 *
 * ---------------------------------------------------------------------------------------------
 * GCD FLOOR — globals are the real currency of a burst, so the guide has to know how long one
 * costs. Derived empirically per spec by the method already documented in
 * data/arena-logs/GCD and Go Analysis.md (Method 1): histogram the gaps between consecutive
 * casts across every window, take the tightest modal bucket. Measured rather than assumed
 * because it moves with real haste — and validated against that document's own independently
 * derived figures (Subtlety Rogue ~1.00s, Frost Mage ~1.15s): this class computes 1.00s and
 * 1.10s for those specs from the full corpus. Clamped to [0.75, 1.50]: 1.5s is WoW's base GCD
 * and 0.75s its hard hasted floor, so anything outside that is a measurement artifact rather
 * than a real cadence (Devourer Demon Hunter's raw histogram peaks at 0.50s, which no real
 * player achieved).
 *
 * ANCHOR — the t=0 reference every offset in the guide is measured against: the spec's biggest
 * real offensive commitment. Offensive-classified (via the arena-log-verified
 * data/arena-logs/spell-classification/*.json, the same source WoW Comps' Cooldowns tabs use),
 * cooldown >= MAJOR_ANCHOR_COOLDOWN_SECONDS, present in at least ANCHOR_MIN_PRESENCE of windows.
 * That 45s bar is not a new invention — it is the same "MAJOR anchor" definition
 * wow-arena-archive/scripts/offensive-rotations.php already uses to decide which cooldowns
 * define a go at all. Ties break on longest buff duration, then presence.
 *
 * ANALYSIS WINDOW — deliberately uniform ([-PRE, +POST] around the anchor cast), NOT the
 * anchor's own duration. Bounding the analysis by the anchor's duration was tried first and is
 * wrong: `duration_seconds` does not consistently mean "how long your damage is amplified." It
 * is 20s for Avenging Wrath (correct) but 2s for The Hunt (a leap's root), 4s for Ray of Frost
 * (a channel) and 30s for Army of the Dead (a summon) — anchoring Havoc's analysis to 2s
 * truncated its guide to a single ability and Frost Mage's to four. A fixed window cannot
 * distort a spec that way, and the go length is derived separately from what the data shows.
 *
 * GO LENGTH / GLOBALS — measured, not assumed: the GO_LENGTH_PERCENTILE of the offsets at which
 * committed presses (anchor, cooldowns, control) actually happen, i.e. the point by which a real
 * go is essentially spent. Globals = go length / GCD floor. Where the anchor happens to be a
 * buff with a real duration, that is reported alongside as a separate, clearly labelled fact
 * rather than substituted for the measurement.
 *
 * PHASES — assigned from each ability's MEDIAN offset relative to the anchor, so they describe
 * real observed sequencing rather than an assumed template. The split recovers exactly the
 * structure a player would recognise, without being told to look for it: Assassination Rogue's
 * Kidney Shot lands at -2.0s and Deathmark at -1.0s (set up, then commit); Retribution Paladin's
 * Hammer of Justice at -1.3s ahead of Avenging Wrath and Wake of Ashes, both at 0.0s; Frost
 * Mage's Flurry ahead of Ice Lance — the real shatter pairing, recovered from timing alone.
 *
 * CONTROL PLACEMENT is the one piece of genuine game mechanics applied on top of the
 * measurement, and it is this project's OWN already-verified rule, not a fresh assertion: see
 * CLAUDE.md's "`chain_target` rule articulated" — crowd control that breaks on damage cannot be
 * used on the target you are damaging. Stun and Silence survive damage and belong on the kill
 * target; everything else (Incapacitate, Disorient, Root, Slow, Knockback, Disarm) does not, so
 * it belongs on the healer or is peel/positioning. Derived per ability from `spells.dr_category`.
 *
 * Note this is a statement about where a control ability CAN go, not an observation of where it
 * actually went — the same limitation the previous implementation carried, and it is unchanged.
 * The corpus records each cast's spell and timestamp but not its destination, because the raw-log
 * extraction upstream (wow-arena-archive's offensive-rotations.php) never captures per-cast
 * destGUID — only the window's overall damage target. So a control step appearing inside a window
 * is PRESUMED, not proven, to be part of that go. Closing this for real needs that script's regex
 * extended; recorded here per this project's "flag, don't guess" discipline rather than quietly
 * presenting the rule as a measurement.
 *
 * INCLUSION — an ability reaches the sequence only if it is genuinely part of dealing damage or
 * enabling it: it deals damage (checked across same-named sibling spell_id copies, the standard
 * recovery in this codebase — several real abilities carry their damage effect only on a sibling
 * record), or carries a `dr_category`, or is an interrupt, or is offensively classified.
 * Anything else that shows up often in real windows (mobility, defensives, utility) is NOT
 * silently dropped — it is reported separately under `alsoPressed`, the same "surface the gap,
 * don't hide it" posture as the Synergies tab's unclassified list. Purely defensive abilities
 * are excluded from the sequence on purpose: the GCD analysis doc's own finding is that weaving
 * defensives into your own go is what the LOW-rated players in that study did.
 *
 * Stores spell_ids only — never a frozen name, cooldown or duration — matching the discipline of
 * every other file under data/claudes-guides/ (see that folder's README).
 */
class BurstGuideBuilder
{
    /** Matches wow-arena-archive/scripts/offensive-rotations.php's own "MAJOR anchor" bar. */
    private const MAJOR_ANCHOR_COOLDOWN_SECONDS = 45.0;

    /** Second tier of that same script's progressive fallback — see pickAnchor(). */
    private const FALLBACK_ANCHOR_COOLDOWN_SECONDS = 30.0;

    /** An anchor must open this share of a spec's windows to be trusted as the go-defining cooldown. */
    private const ANCHOR_MIN_PRESENCE = 0.20;

    /** Uniform analysis window around the anchor cast — see the class docblock on why this is not the anchor's duration. */
    private const PRE_WINDOW_SECONDS = 6.0;

    private const POST_WINDOW_SECONDS = 20.0;

    /** Two log entries for one ability this close apart are one press (dual-wield / aura-copy double logging). */
    private const SAME_ABILITY_COLLAPSE_SECONDS = 0.2;

    /** Latency/log jitter absorbed by the greedy GCD gate (Method 2 in GCD and Go Analysis.md). */
    private const GCD_GATE_TOLERANCE = 0.15;

    private const GCD_MIN_SECONDS = 0.75;

    private const GCD_MAX_SECONDS = 1.5;

    private const GCD_MIN_SAMPLES = 30;

    /** Below this many anchored windows the aggregate is an anecdote again — publish nothing. */
    private const MIN_ANCHORED_WINDOWS = 20;

    /** An ability must appear in this share of anchored windows to be a guide step. */
    private const STEP_MIN_PRESENCE = 0.25;

    /** Median offset below which an ability is setup (pressed before committing). */
    private const SETUP_MAX_OFFSET = -0.5;

    /** Median offset up to which an ability is part of the commit (stacked with the anchor). */
    private const COMMIT_MAX_OFFSET = 1.5;

    /** At or above this rate an ability usually lands inside another press's global — effectively free. */
    private const STACKED_RATE = 0.5;

    /** A cooldown this short is rotational, not something you plan a go around. */
    private const FILLER_MAX_COOLDOWN_SECONDS = 3.0;

    /**
     * Measured healer-targeting rates, as multiples of chance — see bucketObservedTarget().
     * Calibrated against the real distribution across 689 archived matches: Freezing Trap 2.69,
     * Intimidation 2.39, Hammer of Justice 1.92, Solar Beam 1.80, Cyclone 1.49, Polymorph 1.43
     * all sit above the healer bar; Kidney Shot 0.89, Cheap Shot 0.79 and Binding Shot 1.14 fall
     * between the bars as genuinely dual-purpose (and are independently curated `both`); Garrote
     * 0.68, Shockwave 0.45 and Rake 0.66 sit below it.
     */
    private const HEALER_PREFERENCE_RATIO = 1.35;

    private const KILL_TARGET_PREFERENCE_RATIO = 0.75;

    /**
     * `spell_effects.type` fragments that mean "this ability damages its target".
     *
     * Deliberately matched on these specific fragments rather than a bare "%Damage%", which would
     * sweep in the large families that describe damage without dealing any — `Modify Damage
     * Taken%`, `Modify Damage Done%`, `Absorb Damage`, `Modify Auto Attack Damage Done%` and
     * friends account for far more rows than the real damage types do.
     *
     * `Health Leech` is here because it is genuine damage that also heals the caster, and
     * omitting it was a real miss rather than a hypothetical one: Shadow Word: Madness carries
     * ONLY `Health Leech (9)` / `Periodic Health Leech (53)`, so at 89% presence in Shadow
     * Priest's windows it was being pushed out of the damage plan entirely.
     */
    private const DAMAGE_EFFECT_TYPES = [
        'School Damage',
        'Weapon Damage',
        'Periodic Damage',
        'Direct Damage',
        'Health Leech',
        'Health% Damage',
    ];

    /** Where the committed presses have essentially finished — the measured length of a go. */
    private const GO_LENGTH_PERCENTILE = 0.9;

    private const GO_LENGTH_MIN_SECONDS = 4.0;

    /** No real damage-amplification window in this game runs past 20s — see resolveWindow(). */
    private const GO_LENGTH_MAX_SECONDS = 20.0;

    private const MAX_SEQUENCE_STEPS = 14;

    private const MAX_FILL = 4;

    private const MAX_ALSO_PRESSED = 6;

    public function __construct(
        private ArenaLogService $arenaLog,
        private CcTargetingAnalyzer $ccTargeting
    ) {}

    /**
     * Path to the per-window corpus this builder aggregates. Lives in the arena archive
     * (ARENA_LOG_ARCHIVE_PATH), not in this repo — it is hundreds of megabytes across 38 specs,
     * which is exactly why only the small computed result is committed here.
     */
    public function corpusPath(string $classSlug, string $specSlug): string
    {
        return rtrim((string) config('arena_logs.archive_path'), '/\\')."/rotations/{$classSlug}/{$specSlug}.jsonl";
    }

    /**
     * Whether this spec can be built at all. Requires BOTH the archive corpus AND this repo's own
     * promoted rotation file: the promoted file is what RefreshMatchDerived deletes when a spec
     * stops producing windows (see its promoteRotations()), so gating on it is what stops a spec
     * whose matches were culled from being silently rebuilt out of a stale archive corpus the
     * archive itself no longer regenerates.
     */
    public function isBuildable(string $classSlug, string $specSlug): bool
    {
        return File::exists($this->corpusPath($classSlug, $specSlug))
            && File::exists(base_path("data/arena-logs/rotations/{$classSlug}/{$specSlug}.json"));
    }

    /**
     * @return array{
     *     evidence: array{windows:int, anchoredWindows:int, matches:int, killWindows:int, gcdSeconds:float, gcdMeasured:bool},
     *     window: array{anchorSpellId:int, goLengthSeconds:float, globals:int, anchorBuffSeconds:?float, anchorCooldownSeconds:?float},
     *     sequence: array<int, array>,
     *     fill: array<int, array>,
     *     alsoPressed: array<int, array>
     * }|null  null when the spec has no corpus, no usable anchor, or too few anchored windows
     */
    public function build(string $classSlug, string $specSlug): ?array
    {
        if (! $this->isBuildable($classSlug, $specSlug)) {
            return null;
        }

        $path = $this->corpusPath($classSlug, $specSlug);

        $scan = $this->scanCorpus($path);
        if ($scan['windows'] === 0 || $scan['spellIds'] === []) {
            return null;
        }

        $patchId = Patch::where('is_current', true)->value('id');
        if (! $patchId) {
            return null;
        }

        $canonical = $this->canonicalSpellsByName($scan['spellIds'], $patchId);
        if ($canonical->isEmpty()) {
            return null;
        }

        $nameForSpellId = $this->nameLookup($scan['spellIds'], $patchId);
        $classification = $this->arenaLog->offensiveDefensiveClassification();
        $dealsDamage = $this->damageCapableNames($canonical, $patchId);
        $gcd = $this->gcdFloor($scan['gapBuckets']);

        $anchorName = $this->pickAnchor($path, $canonical, $nameForSpellId, $classification, $scan['windows']);
        if ($anchorName === null) {
            return null;
        }

        $stats = $this->aggregateAroundAnchor($path, $anchorName, $nameForSpellId, $gcd['seconds']);
        if ($stats['windows'] < self::MIN_ANCHORED_WINDOWS) {
            return null;
        }

        return $this->compose($anchorName, $stats, $canonical, $classification, $dealsDamage, $gcd, $scan, $this->ccTargeting->load());
    }

    /**
     * One streaming pass: window/kill/match counts, every spell_id seen, and the GCD gap
     * histogram. Streamed line by line rather than decoded in bulk — a single spec's corpus is
     * tens of megabytes, and holding even one spec's decoded windows exhausted PHP's default
     * 128MB limit during development.
     *
     * @return array{windows:int, killWindows:int, matches:int, spellIds:array<int,int>, gapBuckets:array<int,int>}
     */
    private function scanCorpus(string $path): array
    {
        $empty = ['windows' => 0, 'killWindows' => 0, 'matches' => 0, 'spellIds' => [], 'gapBuckets' => []];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $empty;
        }

        $spellIds = [];
        $gapBuckets = [];
        $matches = [];
        $windows = 0;
        $kills = 0;

        while (($line = fgets($handle)) !== false) {
            $window = json_decode($line, true);
            if (! is_array($window) || empty($window['sequence'])) {
                continue;
            }

            $windows++;
            if ($window['killed'] ?? false) {
                $kills++;
            }
            if (! empty($window['matchId'])) {
                $matches[$window['matchId']] = true;
            }

            $times = [];
            foreach ($window['sequence'] as $cast) {
                $spellIds[(int) $cast['spellId']] = true;
                $times[] = (float) $cast['t'];
            }

            sort($times);
            for ($i = 1; $i < count($times); $i++) {
                $gap = $times[$i] - $times[$i - 1];
                if ($gap <= 0.05 || $gap >= 2.0) {
                    continue;
                }
                // Integer bucket index, never a raw float key — PHP truncates float array keys,
                // which silently collided 0.05 with 1.05 the first time a histogram like this was
                // written here (see data/arena-logs/GCD and Go Analysis.md).
                $bucket = (int) round($gap / 0.05);
                $gapBuckets[$bucket] = ($gapBuckets[$bucket] ?? 0) + 1;
            }
        }
        fclose($handle);

        return [
            'windows' => $windows,
            'killWindows' => $kills,
            'matches' => count($matches),
            'spellIds' => array_keys($spellIds),
            'gapBuckets' => $gapBuckets,
        ];
    }

    /**
     * Tightest modal gap bucket, clamped to real GCD bounds — see the class docblock. Returns
     * `measured => false` (and WoW's base 1.5s GCD) when the corpus is too thin or the peak falls
     * outside those bounds, rather than publishing a number the data does not support.
     *
     * @return array{seconds: float, measured: bool}
     */
    private function gcdFloor(array $gapBuckets): array
    {
        if ($gapBuckets === [] || array_sum($gapBuckets) < self::GCD_MIN_SAMPLES) {
            return ['seconds' => self::GCD_MAX_SECONDS, 'measured' => false];
        }

        $peak = max($gapBuckets);
        $tightest = min(array_keys($gapBuckets, $peak));
        $seconds = round($tightest * 0.05, 2);

        // Out of range means the modal gap is not a global cooldown at all — Devourer Demon
        // Hunter's histogram peaks at 0.50s, below the game's own hard hasted floor, so its
        // densest recurring cadence is something other than back-to-back presses. Fall back to
        // WoW's base GCD and say so, rather than clamping an artifact into range and presenting
        // it as a measurement.
        if ($seconds < self::GCD_MIN_SECONDS || $seconds > self::GCD_MAX_SECONDS) {
            return ['seconds' => self::GCD_MAX_SECONDS, 'measured' => false];
        }

        return ['seconds' => $seconds, 'measured' => true];
    }

    /**
     * One representative Spell per display name. Real logs record several internal spell_id
     * copies of one visible ability (the pattern documented throughout CLAUDE.md), and the copy
     * carrying usable cooldown/duration data is not always the one the log happened to report.
     * Same preference order as TalentSelectionService::preferSelectedPerName() — visible and
     * castable first, then whichever copy actually has cooldown data.
     *
     * @param  array<int,int>  $spellIds
     * @return Collection<string, Spell>
     */
    private function canonicalSpellsByName(array $spellIds, int $patchId): Collection
    {
        return Spell::where('patch_id', $patchId)
            ->whereIn('spell_id', $spellIds)
            ->get()
            ->groupBy(fn (Spell $spell) => $spell->display_name)
            ->map(fn (Collection $copies) => $copies->sortBy([
                fn (Spell $a, Spell $b) => ((int) $a->is_passive) <=> ((int) $b->is_passive),
                fn (Spell $a, Spell $b) => ((int) $a->not_in_spellbook) <=> ((int) $b->not_in_spellbook),
                fn (Spell $a, Spell $b) => ($b->cooldown_seconds !== null ? 1 : 0) <=> ($a->cooldown_seconds !== null ? 1 : 0),
                fn (Spell $a, Spell $b) => $a->spell_id <=> $b->spell_id,
            ])->first());
    }

    /** @return array<int, string> external spell_id => display name */
    private function nameLookup(array $spellIds, int $patchId): array
    {
        return Spell::where('patch_id', $patchId)
            ->whereIn('spell_id', $spellIds)
            ->get(['spell_id', 'name'])
            ->mapWithKeys(fn (Spell $spell) => [(int) $spell->spell_id => $spell->display_name])
            ->all();
    }

    /**
     * Display names that deal damage, checked across EVERY same-named spell_id copy rather than
     * only the canonical one — the same sibling recovery this codebase already applies for
     * cooldowns, descriptions, categorization and icons. Not optional here: Mutilate, Moonfire,
     * Arcane Missiles, Frost Strike and Void Volley all carry their damage effect on a sibling
     * record and read as non-damaging if only their own record is checked.
     *
     * This is what keeps healing and mobility out of the damage plan — verified against a
     * deliberately mixed sample: every real damage ability tested resolves true, while Word of
     * Glory, Renewing Mist, Soothing Mist, Enveloping Mist, Flash Heal, Power Word: Shield,
     * Eternal Flame, Glide, Roll, Blink and Feint all resolve false.
     *
     * @param  Collection<string, Spell>  $canonical
     * @return array<string, true>
     */
    private function damageCapableNames(Collection $canonical, int $patchId): array
    {
        $names = $canonical->keys()->all();
        if ($names === []) {
            return [];
        }

        $copies = Spell::where('patch_id', $patchId)
            ->where(function ($query) use ($names) {
                foreach ($names as $name) {
                    $query->orWhere('name', $name)->orWhere('name', 'LIKE', $name.' (desc=%');
                }
            })
            ->get(['id', 'name']);

        if ($copies->isEmpty()) {
            return [];
        }

        $damagingIds = DB::table('spell_effects')
            ->whereIn('spell_id', $copies->pluck('id'))
            ->where(function ($query) {
                foreach (self::DAMAGE_EFFECT_TYPES as $type) {
                    $query->orWhere('type', 'LIKE', "%{$type}%");
                }
            })
            ->pluck('spell_id')
            ->flip();

        $result = [];
        foreach ($copies as $copy) {
            if ($damagingIds->has($copy->id)) {
                $result[$copy->display_name] = true;
            }
        }

        return $result;
    }

    /**
     * The go-defining cooldown, used as the t=0 reference for every offset in the guide. See the
     * class docblock for the selection rule and why it is not simply "most-used cooldown".
     *
     * @param  Collection<string, Spell>  $canonical
     * @param  array<int, string>  $nameForSpellId
     */
    private function pickAnchor(string $path, Collection $canonical, array $nameForSpellId, array $classification, int $totalWindows): ?string
    {
        $offensive = [];
        foreach ($canonical as $name => $spell) {
            $class = $this->classify($spell, $classification);
            if (! $class || ! $class['offensive']) {
                continue;
            }
            $cooldown = (float) ($spell->cooldown_seconds ?? 0);
            if ($cooldown > 0) {
                $offensive[$name] = ['spell' => $spell, 'cooldown' => $cooldown];
            }
        }

        if ($offensive === []) {
            return null;
        }

        // Progressive fallback, mirroring wow-arena-archive/scripts/offensive-rotations.php's own
        // major-anchor selection rather than inventing a second rule: prefer a real long
        // cooldown, then a medium one, then whatever the spec's longest is. Without the fallback,
        // three specs whose kit simply has no 45s+ offensive cooldown with cast evidence
        // (Restoration Druid, Restoration Shaman, Demonology Warlock — longest 10s/30s/30s) get
        // no guide at all rather than a thin, honestly-labelled one.
        $candidates = [];
        foreach ([self::MAJOR_ANCHOR_COOLDOWN_SECONDS, self::FALLBACK_ANCHOR_COOLDOWN_SECONDS] as $bar) {
            foreach ($offensive as $name => $row) {
                if ($row['cooldown'] >= $bar) {
                    $candidates[$name] = $row['spell'];
                }
            }
            if ($candidates !== []) {
                break;
            }
        }
        if ($candidates === []) {
            $longest = max(array_column($offensive, 'cooldown'));
            foreach ($offensive as $name => $row) {
                if ($row['cooldown'] >= $longest) {
                    $candidates[$name] = $row['spell'];
                }
            }
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        $presence = [];
        while (($line = fgets($handle)) !== false) {
            $window = json_decode($line, true);
            if (! is_array($window) || empty($window['sequence'])) {
                continue;
            }
            $inWindow = [];
            foreach ($window['sequence'] as $cast) {
                $name = $nameForSpellId[(int) $cast['spellId']] ?? null;
                if ($name !== null && isset($candidates[$name])) {
                    $inWindow[$name] = true;
                }
            }
            foreach (array_keys($inWindow) as $name) {
                $presence[$name] = ($presence[$name] ?? 0) + 1;
            }
        }
        fclose($handle);

        if ($presence === []) {
            return null;
        }

        $ranked = [];
        foreach ($presence as $name => $count) {
            if ($totalWindows > 0 && $count / $totalWindows < self::ANCHOR_MIN_PRESENCE) {
                continue;
            }
            $spell = $candidates[$name];
            $ranked[$name] = [(float) $spell->cooldown_seconds, (float) ($spell->duration_seconds ?? 0), $count];
        }

        if ($ranked === []) {
            // Nothing clears the presence bar — fall back to the most-used candidate rather than
            // dropping the spec from the page entirely.
            arsort($presence);

            return array_key_first($presence);
        }

        uasort($ranked, fn (array $a, array $b) => [$b[0], $b[1], $b[2]] <=> [$a[0], $a[1], $a[2]]);

        return array_key_first($ranked);
    }

    /**
     * Streams the corpus a final time, aligning every window on its anchor cast and recording,
     * per ability: how many windows it appears in, how many times per window, every offset (for
     * the median), and how often it landed inside another press's global.
     *
     * Two corrections apply per window before anything is counted, both of which change the
     * result materially:
     *  - same-ability casts within SAME_ABILITY_COLLAPSE_SECONDS collapse to one press. Real logs
     *    emit two entries at an identical timestamp for one press of a dual-wield ability
     *    (Assassination's Mutilate) and for abilities whose aura copy is logged alongside the
     *    cast. Without this, one press counts twice AND the duplicate is then mis-read as a press
     *    that cost no global.
     *  - the greedy GCD gate (Method 2 in GCD and Go Analysis.md) then runs over the collapsed,
     *    time-ordered stream, so "landed inside another global" is measured per ability rather
     *    than assumed.
     *
     * @param  array<int, string>  $nameForSpellId
     * @return array{windows:int, abilities:array<string, array{windows:int, casts:int, offsets:array<int,float>, stacked:int}>}
     */
    private function aggregateAroundAnchor(string $path, string $anchorName, array $nameForSpellId, float $gcd): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['windows' => 0, 'abilities' => []];
        }

        $abilities = [];
        $windows = 0;

        while (($line = fgets($handle)) !== false) {
            $window = json_decode($line, true);
            if (! is_array($window) || empty($window['sequence'])) {
                continue;
            }

            $anchorTime = null;
            foreach ($window['sequence'] as $cast) {
                if (($nameForSpellId[(int) $cast['spellId']] ?? null) === $anchorName) {
                    $anchorTime = (float) $cast['t'];
                    break;
                }
            }
            if ($anchorTime === null) {
                continue;
            }
            $windows++;

            $stream = [];
            $lastSeen = [];
            foreach ($window['sequence'] as $cast) {
                $name = $nameForSpellId[(int) $cast['spellId']] ?? null;
                if ($name === null) {
                    continue;
                }
                $time = (float) $cast['t'];
                if ($time < $anchorTime - self::PRE_WINDOW_SECONDS || $time > $anchorTime + self::POST_WINDOW_SECONDS) {
                    continue;
                }
                if (isset($lastSeen[$name]) && $time - $lastSeen[$name] < self::SAME_ABILITY_COLLAPSE_SECONDS) {
                    continue;
                }
                $lastSeen[$name] = $time;
                $stream[] = ['t' => $time, 'name' => $name];
            }

            usort($stream, fn (array $a, array $b) => $a['t'] <=> $b['t']);

            $nextGlobal = -INF;
            $inThisWindow = [];
            foreach ($stream as $cast) {
                $consumesGlobal = $cast['t'] >= $nextGlobal - self::GCD_GATE_TOLERANCE;
                if ($consumesGlobal) {
                    $nextGlobal = $cast['t'] + $gcd;
                }

                $name = $cast['name'];
                $abilities[$name]['casts'] = ($abilities[$name]['casts'] ?? 0) + 1;
                $abilities[$name]['offsets'][] = round($cast['t'] - $anchorTime, 2);
                $abilities[$name]['stacked'] = ($abilities[$name]['stacked'] ?? 0) + ($consumesGlobal ? 0 : 1);
                $inThisWindow[$name] = true;
            }

            foreach (array_keys($inThisWindow) as $name) {
                $abilities[$name]['windows'] = ($abilities[$name]['windows'] ?? 0) + 1;
            }
        }
        fclose($handle);

        return ['windows' => $windows, 'abilities' => $abilities];
    }

    /**
     * Turns the aggregate into the phased guide. Every field written here is either measured
     * above or read from curated spell data — nothing is authored per spec.
     *
     * @param  Collection<string, Spell>  $canonical
     * @param  array<string, true>  $dealsDamage
     * @param  array<int, array>  $ccTargeting  measured control targeting, keyed by external spell_id
     */
    private function compose(
        string $anchorName,
        array $stats,
        Collection $canonical,
        array $classification,
        array $dealsDamage,
        array $gcd,
        array $scan,
        array $ccTargeting
    ): array {
        $windows = $stats['windows'];
        $sequence = [];
        $fill = [];
        $alsoPressed = [];
        $openerDurations = [];

        foreach ($stats['abilities'] as $name => $data) {
            $spell = $canonical[$name] ?? null;
            if (! $spell) {
                continue;
            }

            $presence = ($data['windows'] ?? 0) / $windows;
            if ($presence < self::STEP_MIN_PRESENCE) {
                continue;
            }

            $offsets = $data['offsets'];
            sort($offsets);
            $median = $offsets[intdiv(count($offsets), 2)];
            $castsPerWindow = round($data['casts'] / $windows, 2);
            $class = $this->classify($spell, $classification);
            $cooldown = $spell->cooldown_seconds !== null ? (float) $spell->cooldown_seconds : null;
            $damaging = isset($dealsDamage[$name]);

            $entry = [
                'spellId' => (int) $spell->spell_id,
                'presence' => round($presence, 3),
                'castsPerWindow' => $castsPerWindow,
                'medianOffset' => $median,
                'stacksOnAnotherGlobal' => $data['casts'] > 0 && ($data['stacked'] / $data['casts']) >= self::STACKED_RATE,
                'classification' => $class['label'] ?? null,
            ];

            // Purely defensive presses are not part of a go — see the class docblock.
            $purelyDefensive = $class && $class['defensive'] && ! $class['offensive'] && ! $spell->dr_category;
            $relevant = ! $purelyDefensive
                && ($damaging || $spell->dr_category || $spell->is_interrupt || ($class && $class['offensive']));

            if (! $relevant) {
                $alsoPressed[] = $entry + ['role' => 'other'];

                continue;
            }

            if ($name === $anchorName) {
                $entry['role'] = 'anchor';
            } elseif ($spell->dr_category) {
                $entry['role'] = 'control';
                $entry += $this->controlTargetFor($spell, $ccTargeting);
            } elseif ($spell->is_interrupt) {
                $entry['role'] = 'interrupt';
            } elseif ($cooldown !== null && $cooldown > self::FILLER_MAX_COOLDOWN_SECONDS) {
                $entry['role'] = 'cooldown';
            } elseif ($damaging) {
                // No real cooldown, so it cannot be a cooldown to plan around however rarely it
                // is used — it is a filler press. Frequency decides where it ranks in the fill
                // list, never whether it is one: gating on frequency here labelled Frost Mage's
                // Frostbolt a "cooldown", which it plainly is not.
                $entry['role'] = 'fill';
            } else {
                $alsoPressed[] = $entry + ['role' => 'other'];

                continue;
            }

            if ($entry['role'] === 'fill') {
                $fill[] = $entry;

                continue;
            }

            $entry['phase'] = $this->phaseFor($median, $name === $anchorName);

            // Durations of the amplifiers actually stacked at the start of the go — the pool
            // resolveWindow() picks the window length from. Restricted to setup/commit because a
            // cooldown pressed late does not extend the window measured from the anchor.
            if (in_array($entry['role'], ['anchor', 'cooldown'], true)
                && in_array($entry['phase'], ['setup', 'commit'], true)
                && $spell->duration_seconds !== null) {
                $openerDurations[] = (float) $spell->duration_seconds;
            }

            $sequence[] = $entry;
        }

        usort($sequence, fn (array $a, array $b) => $a['medianOffset'] <=> $b['medianOffset']);
        usort($fill, fn (array $a, array $b) => $b['castsPerWindow'] <=> $a['castsPerWindow']);
        usort($alsoPressed, fn (array $a, array $b) => $b['presence'] <=> $a['presence']);

        $sequence = array_slice($sequence, 0, self::MAX_SEQUENCE_STEPS);
        $fill = array_slice($fill, 0, self::MAX_FILL);
        $alsoPressed = array_slice($alsoPressed, 0, self::MAX_ALSO_PRESSED);

        $anchorSpell = $canonical[$anchorName];
        $commitSpread = $this->commitSpread($sequence);
        $window = $this->resolveWindow($openerDurations, $commitSpread, $gcd['seconds']);

        return [
            'evidence' => [
                'windows' => $scan['windows'],
                'anchoredWindows' => $windows,
                'matches' => $scan['matches'],
                'killWindows' => $scan['killWindows'],
                'gcdSeconds' => $gcd['seconds'],
                'gcdMeasured' => $gcd['measured'],
            ],
            'window' => $window + [
                'anchorSpellId' => (int) $anchorSpell->spell_id,
                'commitSpreadSeconds' => $commitSpread,
                'anchorBuffSeconds' => $anchorSpell->duration_seconds !== null ? (float) $anchorSpell->duration_seconds : null,
                'anchorCooldownSeconds' => $anchorSpell->cooldown_seconds !== null ? (float) $anchorSpell->cooldown_seconds : null,
            ],
            'sequence' => array_values($sequence),
            'fill' => array_values($fill),
            'alsoPressed' => array_values($alsoPressed),
        ];
    }

    /**
     * Where a control ability is actually used, in a three-tier order of evidence quality.
     *
     * 1. MEASURED, from real matches (App\Http\Services\CcTargetingAnalyzer): how often this
     *    ability lands on the enemy healer rather than a damage dealer, relative to what an
     *    ability with no preference would hit. This is the primary source, it covers 80 of the 132
     *    CC-tagged spells with no curation at all, and it answers the actual question — who is
     *    this used on — instead of the constraint question the tiers below answer.
     * 2. CURATED `spells.chain_target`, where the measurement has no usable sample. Hand-verified,
     *    but only 20 spells deep.
     * 3. INFERRED from `dr_category`, for anything neither covers.
     *
     * The measured share travels with the answer so the page can show its evidence rather than a
     * bare verdict — "on their healer, 90% of the time" is checkable in a way "healer" is not.
     *
     * @param  array<int, array>  $ccTargeting  keyed by external spell_id
     * @return array{controlTarget: string, controlTargetSource: string, healerShare?: float, targetRatio?: float, targetObservations?: int}
     */
    private function controlTargetFor(Spell $spell, array $ccTargeting): array
    {
        $observed = $ccTargeting[(int) $spell->spell_id] ?? null;

        if ($observed !== null) {
            return [
                'controlTarget' => $this->bucketObservedTarget((float) $observed['ratio'], $spell->dr_category),
                'controlTargetSource' => 'measured',
                'healerShare' => (float) $observed['healerShare'],
                'targetRatio' => (float) $observed['ratio'],
                'targetObservations' => (int) $observed['observations'],
            ];
        }

        if ($spell->chain_target !== null) {
            return ['controlTarget' => $spell->chain_target, 'controlTargetSource' => 'curated'];
        }

        return [
            'controlTarget' => $this->inferControlTarget($spell->dr_category),
            'controlTargetSource' => 'inferred',
        ];
    }

    /**
     * Turns a measured healer-preference ratio into a target label.
     *
     * The ratio is already normalised against chance, which is the only way this reads correctly:
     * a 3v3 team is one healer and two damage dealers, so an ability spread evenly lands on the
     * healer a third of the time. "27% of Kidney Shots hit a healer" therefore means it is used on
     * the healer LESS than at random, not that it is a healer ability used badly.
     *
     * `dr_category` still constrains the answer, because where something LANDS is not the same
     * question as where it can usefully be held. Control that breaks on damage cannot lock the
     * target you are bursting (CLAUDE.md, "`chain_target` rule articulated"), so a low healer
     * ratio there means it is catching people incidentally — an AoE cone sweeping whoever is in
     * front — and the honest label is peel, never "use this on your kill target". Root, Slow,
     * Knockback and Disarm are movement control rather than a lock at all, so they are only ever
     * healer-directed or peel.
     */
    public function bucketObservedTarget(float $ratio, ?string $drCategory): string
    {
        if ($ratio >= self::HEALER_PREFERENCE_RATIO) {
            return 'healer';
        }

        if (! in_array($drCategory, ['Stun', 'Silence'], true)) {
            return 'peel';
        }

        return $ratio <= self::KILL_TARGET_PREFERENCE_RATIO ? 'kill_target' : 'both';
    }

    /**
     * LAST-RESORT FALLBACK — only for control with neither a measurement nor a curated value.
     *
     * Reading the rule correctly matters here, and the first version of this got it wrong.
     * CLAUDE.md's "`chain_target` rule articulated" says control that BREAKS ON DAMAGE cannot be
     * used on the target you are damaging, so only Stun and Silence can EVER be kill-target. That
     * is a necessary condition, not a sufficient one — it says which control is *eligible* for the
     * kill target, never that it *belongs* there. Treating it as positive guidance produced two
     * reported errors: Solar Beam (a Silence) labelled "on the kill target" when it is a healer
     * silence, and Hunter's Intimidation (a Stun) labelled the same when it is routinely used to
     * set up a trap on the healer. Real measurement later put those at 60% and 80% healer — 1.8x
     * and 2.4x chance — confirming both reports.
     *
     * So for damage-surviving categories this states only what it can defend, "either target".
     * Narrowing them to the healer would just invert the same mistake: Garrote is a real Rogue
     * silence used on the kill target in an opener, and measures at 23% healer.
     */
    private function inferControlTarget(string $drCategory): string
    {
        return match ($drCategory) {
            'Stun', 'Silence' => 'both',
            // Breaks the moment your damage lands, so it cannot sit on the kill target.
            'Incapacitate', 'Disorient' => 'healer',
            default => 'peel',
        };
    }

    /** @see self::SETUP_MAX_OFFSET / self::COMMIT_MAX_OFFSET and the class docblock on PHASES. */
    private function phaseFor(float $medianOffset, bool $isAnchor): string
    {
        if ($isAnchor) {
            return 'commit';
        }
        if ($medianOffset < self::SETUP_MAX_OFFSET) {
            return 'setup';
        }
        if ($medianOffset <= self::COMMIT_MAX_OFFSET) {
            return 'commit';
        }

        return 'execute';
    }

    /**
     * How long after committing it takes to get every cooldown out — the last committed press,
     * counted from the anchor. This is the "your cooldowns are all spent by here" number, NOT the
     * length of the damage window itself (see resolveWindow()).
     *
     * Only offsets at or after the anchor count. Setup presses land at negative offsets by
     * definition — they happen BEFORE the window opens, so including them measured the wrong
     * span entirely: Unholy Death Knight's five setup presses (down to -3.0s) dragged its
     * reported go down to 4.0s while its sequence held 11 steps, a self-evidently incoherent
     * "11 abilities in 3 globals".
     *
     * Reads the maximum rather than a percentile: these values are already per-ability medians
     * across hundreds of windows, so they are robust on their own, and "all your cooldowns are
     * out by here" is a claim about the last one, not about most of them.
     */
    private function commitSpread(array $sequence): float
    {
        $offsets = array_filter(
            array_map(fn (array $step) => (float) $step['medianOffset'], $sequence),
            fn (float $offset) => $offset >= 0.0
        );

        if ($offsets === []) {
            return self::GO_LENGTH_MIN_SECONDS;
        }

        return round(max(self::GO_LENGTH_MIN_SECONDS, min(self::GO_LENGTH_MAX_SECONDS, max($offsets))), 1);
    }

    /**
     * The headline "you have N seconds and M globals" figure, and — deliberately — where it came
     * from, so the page can say so rather than presenting two different kinds of number
     * identically.
     *
     * Prefers a real buff duration from the cooldowns stacked at the start of the go, because
     * when a cooldown really is a damage amplifier its duration IS the window as a matter of game
     * mechanics, and that is strictly better than any measurement of it (Deathmark 16s, Avenging
     * Wrath 20s, Combustion 10s, Incarnation 20s, Power Infusion 15s — all correct). Reporting
     * the measured commit spread instead claimed a 6.6s window for a Deathmark go, materially
     * understating how long the player is actually amplified for.
     *
     * Considers every opener, not just the anchor, because the anchor is chosen as the biggest
     * COMMITMENT (longest cooldown) and that is not always the longest BUFF. Unholy Death
     * Knight's anchor is Army of the Dead, whose 30s is a summon's lifetime rather than an
     * amplification window and is correctly rejected below — but Dark Transformation, stacked
     * alongside it at the same instant, is a real 15s amplifier, and that is the honest length of
     * an Unholy go. Taking the longest plausible duration among the openers finds it.
     *
     * Two bounds stop that preference from firing where `duration_seconds` does not mean
     * "amplification window" — the same trap that made it unusable as the analysis bound (see the
     * class docblock). The duration is only believed when it is:
     *
     *  - NOT SHORTER than what was actually observed. A duration below the measured commit spread
     *    is contradicted by the spec's own data, which excludes The Hunt's 2s leap-root and Ray
     *    of Frost's 4s channel from being read as the length of a go.
     *  - NOT LONGER than GO_LENGTH_MAX_SECONDS. No real damage-amplification window in this game
     *    runs past 20s (Avenging Wrath and Incarnation, the longest, are exactly 20s), so a
     *    longer duration is measuring something else — Army of the Dead's 30s is a summon's
     *    lifetime, not a window during which Unholy hits harder.
     *
     * Deliberately does NOT additionally require the cooldown to be classified as a buff rather
     * than a damage cooldown. That split comes from effect-signal heuristics and is demonstrably
     * unreliable at this granularity in both directions — Shadow Blades (a genuine 16s
     * amplification buff) is labelled "Offensive Spell", while Blade of Justice and Hammer of
     * Wrath (plain damage abilities) are labelled "Offensive Buff". Gating on it cost Subtlety
     * its real 16s window and reported a 4s go instead. The two bounds above do the same job
     * without depending on that signal.
     *
     * @param  array<int, float>  $openerDurations  durations of cooldowns pressed in setup/commit
     * @return array{goLengthSeconds: float, goLengthBasis: string, globals: int}
     */
    private function resolveWindow(array $openerDurations, float $commitSpread, float $gcd): array
    {
        $plausible = array_filter(
            $openerDurations,
            fn (float $duration) => $duration >= $commitSpread && $duration <= self::GO_LENGTH_MAX_SECONDS
        );

        $useBuff = $plausible !== [];
        $length = $useBuff ? max($plausible) : $commitSpread;

        return [
            'goLengthSeconds' => round($length, 1),
            'goLengthBasis' => $useBuff ? 'buff-duration' : 'measured',
            'globals' => max(1, (int) round($length / $gcd)),
        ];
    }

    /** @return array{offensive:bool, defensive:bool, label:string}|null */
    private function classify(Spell $spell, array $classification): ?array
    {
        return $classification['bySpellId'][$spell->spell_id]
            ?? $classification['byName'][$spell->name]
            ?? null;
    }
}
