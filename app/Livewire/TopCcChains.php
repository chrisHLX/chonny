<?php

namespace App\Livewire;

use App\Http\Services\ArenaLogService;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Facades\File;
use Livewire\Component;

/**
 * "Top 10 CC Chains" — the top 10 longest real crowd-control chains on file, by duration, across
 * every healer spec at once. Direct counterpart to Burst Windows' offensive-side story, built
 * 2026-08-31 after a design discussion about how to show real 3v3 CC chains landing on a healer.
 *
 * Two other approaches were considered and rejected before landing on the original design:
 *   - Filtering the existing per-healer chain corpus by "similar spells" — strictly weaker than
 *     filtering by class, since every chain step already carries a real sourceClassSlug/
 *     sourceSpecSlug; going through spell names to re-derive class would throw away a more
 *     precise signal already on hand.
 *   - Filtering by "similar classes" to approximate a specific 3v3 comp (RMP, Jungle, etc.) —
 *     the original 2026-08-31 concern was that a chain's steps only record which classes cast
 *     THAT chain's own pieces, not the full 3-man roster, so a 2-caster chain couldn't be safely
 *     attributed to a specific comp without ALSO resolving matchId -> the match's real roster.
 *     Direct instruction at the time: skip comp-gating entirely, show the flat top 10 by
 *     duration instead. **This roster-resolution step was later built anyway** (2026-09-06, see
 *     below) for a different, narrower reason than reviving comp-gating — see that note.
 *
 * Reads every wow:find-cc-chains --json output already on disk (data/arena-logs/cc-chains/
 * {class}/{spec}.json) — no new archive scan, purely a read+merge+sort over data
 * wow:refresh-match-derived already keeps current. The healer's own class/spec comes from the
 * file path itself (one file per healer spec), not stored per-chain.
 *
 * Deliberately does NOT show the real healer character name stored in each chain's own
 * `healerName` field — same privacy rule already applied to Burst Windows' mechanics card and
 * Class Guide's sample table this same session ("just track the class and specs"). Only
 * `healerClassSlug`/`healerSpecSlug` (derived from the file path) are used. `healerName` IS still
 * read, but only as an internal lookup key (see below) — never returned or rendered.
 *
 * **Comp resolution + uniqueness (added 2026-09-06)** — direct follow-up request: "show unique
 * comps... and always the 3 classes that are in the same team," reporting that the flat top 10
 * had been showing the same comp (by spec) more than once. Two changes, both via
 * `ArenaLogService::resolveOpposingTeamSpecs()`:
 *   1. Each chain's `casters` field is now the REAL, full attacking-team roster (up to 3 real
 *      teammates), resolved from the match's own metadata via `reaction` (the same team-
 *      discriminating field `analyzeKillCausally()`'s roster already relies on) — not just
 *      whoever happened to cast a step recorded in THIS chain. Falls back to the old per-step-
 *      derived list only if roster resolution fails entirely (missing metadata, healer name not
 *      found — a real but rare data gap), so the page never regresses to showing nothing.
 *   2. Chains are walked in duration-descending order and deduped by comp (the resolved roster's
 *      spec set, order-independent) — the first chain seen for a given comp is the longest one,
 *      so a comp that appears many times in the archive still only ever occupies one of the 10
 *      slots, giving the requested variety instead of e.g. the same Shadow Priest/Restoration
 *      Shaman pairing or two separate RMD chains both showing up.
 */
class TopCcChains extends Component
{
    public const TOP_N = 10;

    public function mount(): void
    {
        PageViewEvent::log('top_cc_chains');
    }

    /**
     * @return array<int, array{
     *   healerClassSlug: string, healerSpecSlug: string, healerSpec: ?Specialization,
     *   durationSeconds: float, distinctCasters: ?int,
     *   casters: array<int, array{classSlug: string, specSlug: string, spec: ?Specialization}>,
     *   steps: array
     * }>
     */
    /**
     * The newest mtime across every file this page actually reads — an honest "as of" signal
     * for a page whose whole premise is real match data, replacing a generic static description
     * paragraph (direct instruction, 2026-08-31). Reflects when `wow:find-cc-chains --json` (via
     * `wow:refresh-match-derived`, see CLAUDE.md's "orchestrator every match-data pull must be
     * followed by" note) last actually regenerated this corpus — not when the page was last
     * *deployed*, which could easily be stale relative to the underlying data.
     */
    public function getLastUpdatedProperty(): ?\Carbon\Carbon
    {
        $files = File::glob(base_path('data/arena-logs/cc-chains/*/*.json'));
        if ($files === [] || $files === false) {
            return null;
        }

        $newest = collect($files)->map(fn ($f) => filemtime($f))->filter()->max();

        return $newest ? \Carbon\Carbon::createFromTimestamp($newest) : null;
    }

    public function getChainsProperty(): array
    {
        $patch = Patch::where('is_current', true)->first();
        if (!$patch) {
            return [];
        }

        $files = File::glob(base_path('data/arena-logs/cc-chains/*/*.json'));
        if ($files === [] || $files === false) {
            return [];
        }

        $specsBySlug = Specialization::with('gameClass')->get()
            ->keyBy(fn ($s) => $s->gameClass->slug.'/'.$s->slug);

        $all = collect();
        foreach ($files as $file) {
            $classSlug = basename(dirname($file));
            $specSlug = basename($file, '.json');
            $spec = $specsBySlug->get("{$classSlug}/{$specSlug}");

            $chains = json_decode(File::get($file), true);
            if (!is_array($chains)) {
                continue;
            }

            foreach ($chains as $chain) {
                $chain['healerClassSlug'] = $classSlug;
                $chain['healerSpecSlug'] = $specSlug;
                $chain['healerSpec'] = $spec;
                $all->push($chain);
            }
        }

        $sorted = $all->sortByDesc('durationSeconds')->values();

        // Walk longest-first, resolving each chain's real attacking-team comp only as needed
        // (lazily — most of a healer spec's chain file is never even looked at once TOP_N
        // unique comps are found), and skip any chain whose comp we've already used. This is
        // what "always show the 3 real teammates, and don't repeat a comp" actually means in
        // practice: the FIRST (= longest) chain seen for a given comp wins that comp's slot.
        $arenaLogService = app(ArenaLogService::class);
        $specsByExternalId = Specialization::with('gameClass')->get()->keyBy('external_spec_id');

        $seenCompKeys = [];
        $top = collect();

        foreach ($sorted as $chain) {
            if ($top->count() >= self::TOP_N) {
                break;
            }

            $roster = $arenaLogService->resolveOpposingTeamSpecs($chain['matchId'] ?? '', $chain['healerName'] ?? '', $specsByExternalId);

            $compKey = collect($roster)
                ->map(fn ($r) => "{$r['classSlug']}/{$r['specSlug']}")
                ->sort()
                ->implode('|');

            // An empty compKey means roster resolution found nothing at all (missing metadata,
            // renamed/unmatched healer) — too unreliable to dedupe on, so every such chain is
            // treated as its own unique slot rather than silently collapsed together.
            if ($compKey !== '' && in_array($compKey, $seenCompKeys, true)) {
                continue;
            }
            if ($compKey !== '') {
                $seenCompKeys[] = $compKey;
            }

            $chain['__roster'] = $roster;
            $top->push($chain);
        }

        // Resolve every step's spellId to a real Spell model, direct by spell_id — no talent-
        // linked disambiguation here (unlike resolveWindowSteps() elsewhere in this codebase):
        // every dr_category-tagged CC spell used to build this corpus is already a single,
        // curated, unambiguous spell_id, and a chain routinely mixes casters from several
        // different specs at once, so there's no single "current build" to disambiguate against
        // anyway (same reasoning SpellDetailModal already documents for a "no spec context"
        // page like Admin\CcReview).
        $spellIds = $top->flatMap(fn ($c) => collect($c['steps'])->pluck('spellId'))->unique()->filter();
        $spellsById = $spellIds->isEmpty()
            ? collect()
            : Spell::whereIn('spell_id', $spellIds)->where('patch_id', $patch->id)->get()->keyBy('spell_id');

        return $top->map(function ($chain) use ($spellsById, $specsBySlug) {
            $seenCategories = [];
            $steps = collect($chain['steps'])->map(function ($step) use ($spellsById, $specsBySlug, &$seenCategories) {
                $category = $step['drCategory'] ?? null;
                $isDrDimmed = $category !== null && in_array($category, $seenCategories, true);
                if ($category !== null) {
                    $seenCategories[] = $category;
                }

                // The caster's own class/spec (already recorded per step) gives SpellDetailModal
                // real context to resolve talent-modified values against — unlike the chain as a
                // whole, one step's own caster IS a single, coherent spec.
                $casterSpec = $specsBySlug->get(($step['sourceClassSlug'] ?? null).'/'.($step['sourceSpecSlug'] ?? null));

                $spell = $spellsById->get($step['spellId']);
                $step['spell'] = $spell;
                $step['isDrDimmed'] = $isDrDimmed;
                $step['casterClassId'] = $casterSpec?->class_id;
                $step['casterSpecId'] = $casterSpec?->id;

                // The REAL observed duration of this exact cast (from the chain data itself) —
                // shown on the card's "Dur" stat instead of always showing the generic curated
                // pvp_duration_seconds, since a page about real chains should show what actually
                // happened in THIS match where possible, not just a typical/average value.
                //
                // CAPPED at the curated pvp_duration_seconds when one exists and is exceeded —
                // direct instruction (2026-08-31), after investigating several real overages
                // (e.g. Blinding Sleet measuring 7.45s against a curated 4.0s) as far as
                // possible without guessing: confirmed NOT a bug in this chain-building code
                // (the raw Blizzard combat log genuinely logs these longer spans — single clean
                // APPLIED->REMOVED pairs, no refresh/double-counting), and confirmed NOT Tenacity
                // (checked directly: a clean 3v3, zero "Tenacity" mentions in that match's raw
                // log). But the TRUE cause was never confirmed (a channel-time hypothesis for
                // Blinding Sleet doesn't explain the same pattern on instant-cast Kidney Shot/
                // Hammer of Justice) — rather than label an unverified guess as fact ("extended
                // by X"), the curated value is trusted as the display ceiling and the
                // unexplained real overage is capped away silently. The underlying cc-chains/
                // *.json data itself is UNTOUCHED by this — CcChainStatsService/wow:cc-chain-
                // patterns/CcFormulaService read that same corpus for opener/transition-
                // frequency analysis and should keep seeing the real, uncapped numbers; only
                // this page's own display is capped.
                $real = round($step['end'] - $step['start'], 2);
                $curated = $spell?->pvp_duration_seconds !== null ? (float) $spell->pvp_duration_seconds : null;
                $step['realDurationSeconds'] = $curated !== null ? min($real, $curated) : $real;

                return $step;
            })->all();

            // "The comp that landed the CC" — the REAL, full attacking-team roster resolved by
            // getChainsProperty() above via ArenaLogService::resolveOpposingTeamSpecs() (up to 3
            // real teammates, from the match's own metadata, not just whoever happened to cast a
            // step recorded in THIS one chain). Falls back to the old per-step-derived list only
            // if that resolution came back completely empty (a real but rare data gap — missing
            // metadata, unmatched healer name) so the page never shows zero casters. Never
            // includes a real player name either way.
            $roster = $chain['__roster'] ?? [];
            $casters = !empty($roster)
                ? collect($roster)->map(fn ($r) => [
                    'classSlug' => $r['classSlug'],
                    'specSlug' => $r['specSlug'],
                    'spec' => $specsBySlug->get("{$r['classSlug']}/{$r['specSlug']}"),
                ])->values()->all()
                : collect($chain['steps'])
                    ->filter(fn ($s) => !empty($s['sourceClassSlug']) && !empty($s['sourceSpecSlug']))
                    ->unique(fn ($s) => $s['sourceClassSlug'].'/'.$s['sourceSpecSlug'])
                    ->map(fn ($s) => [
                        'classSlug' => $s['sourceClassSlug'],
                        'specSlug' => $s['sourceSpecSlug'],
                        'spec' => $specsBySlug->get($s['sourceClassSlug'].'/'.$s['sourceSpecSlug']),
                    ])
                    ->values()
                    ->all();

            return [
                'healerClassSlug' => $chain['healerClassSlug'],
                'healerSpecSlug' => $chain['healerSpecSlug'],
                'healerSpec' => $chain['healerSpec'],
                'durationSeconds' => $chain['durationSeconds'],
                'distinctCasters' => $chain['distinctCasters'] ?? null,
                'casters' => $casters,
                'steps' => $steps,
            ];
        })->all();
    }

    public function render()
    {
        return view('livewire.top-cc-chains', [
            'chains' => $this->chains,
            'lastUpdated' => $this->lastUpdated,
        ])->layout('layouts.app', [
            'title' => 'Top 10 CC Chains — Longest Real WoW Arena CC Chains | MindCollector',
            'description' => 'The 10 longest real crowd-control chains on file across every WoW arena healer spec, taken straight from real archived matches.',
        ]);
    }
}
