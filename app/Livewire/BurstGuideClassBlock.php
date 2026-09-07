<?php

namespace App\Livewire;

use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * One class's worth of Burst Guide blocks (2-4 specs) — a lazy-loaded child of BurstGuides,
 * split out 2026-09-04 after a real, confirmed report: the original single-component design
 * rendered all 38 specs (508 total step-cards) synchronously in one Livewire request. Peak
 * memory for that had already been brought down from 430MB to 64MB earlier the same day (see
 * BurstGuides' git history / CLAUDE.md), but the deeper issue the user correctly diagnosed
 * wasn't the per-spell resolution cost — it was the "load all specs at once" shape itself:
 * ~14MB and ~1.4MB of HTML were still being generated and shipped on every single page view,
 * synchronously, before the user saw anything at all.
 *
 * #[Lazy] (Livewire 3's built-in deferred-child-component loading) makes the INITIAL page load
 * just the 13 class headers + a lightweight placeholder each; each class's real content then
 * loads via its own separate, independent follow-up request, fired automatically right after
 * first paint. Splitting per-CLASS rather than per-SPEC keeps the child count at a sane 13 (a
 * separate Livewire request per spec would be 38 small requests — more network overhead for
 * comparatively little extra parallelism benefit over 13).
 *
 * A pure read-and-render layer over data/claudes-guides/burst-guides/{class}/{spec}.json — see
 * App\Http\Services\BurstGuideBuilder for how every number in that file is derived. This class
 * decides nothing about the guide's content; it resolves spell_ids to live game data and groups
 * the already-computed steps by their already-computed phase.
 */
#[Lazy]
class BurstGuideClassBlock extends Component
{
    /** Render order for the phases the builder assigns — a go runs set up, commit, then execute. */
    private const PHASE_ORDER = ['setup', 'commit', 'execute'];

    public string $classSlug;

    /**
     * When set, only this one spec of the class renders. Set by App\Livewire\PvpGuides, whose
     * whole page is scoped to the single spec the viewer picked; the standalone /burst-guides
     * page leaves it null and still renders every spec of the class, unchanged.
     *
     * Deliberately applied in render() rather than inside computeGuide() so the per-class cache
     * entry stays whole and shared between both callers — a spec-scoped view is a filtered read
     * of the same cached class payload, never a second, narrower cache entry keyed per spec.
     */
    public ?string $onlySpecSlug = null;

    public function mount(string $classSlug, ?string $onlySpecSlug = null): void
    {
        $this->classSlug = $classSlug;
        $this->onlySpecSlug = $onlySpecSlug;
    }

    /** Livewire renders this synchronously on initial page load, before the real (deferred) render fires. */
    public function placeholder(): string
    {
        return <<<'BLADE'
            <div class="linear-card px-6 py-5 animate-pulse">
                <div class="h-5 w-40 bg-line rounded mb-4"></div>
                <div class="h-16 w-full bg-line/60 rounded"></div>
            </div>
            BLADE;
    }

    /**
     * This class's own burst-guide files, resolved to live spell entries. Empty (renders
     * nothing) when the class doesn't resolve to a real model or has no usable guide data — same
     * "don't show something broken" precedent as the rest of this feature.
     *
     * Cached per class (not one big all-classes blob), same spellCacheVersion()/
     * deployedCodeFingerprint() invalidation as WoW Comps' wow_spell_references:* — so once any
     * one class has been viewed after a code/data change, every subsequent view of that same
     * class is a cheap cache read, independent of every other class.
     *
     * @return array{class: ?GameClass, specs: array<int, array>}
     */
    public function getGuideProperty(): array
    {
        $talentService = app(TalentSelectionService::class);
        $cacheKey = sprintf(
            'burst_guides:class:%s:v%s:%s:%s',
            $this->classSlug,
            $talentService->spellCacheVersion(),
            $talentService->deployedCodeFingerprint(),
            $this->guideFilesSignature()
        );

        return Cache::remember($cacheKey, now()->addDay(), fn () => $this->computeGuide($talentService));
    }

    /**
     * A cheap signature of this class's own guide files, so rewriting them invalidates this cache
     * on its own.
     *
     * Without it, `wow:build-burst-guides` had to bump the global spell cache version to make its
     * output visible — and that is far too blunt an instrument for writing a few small JSON files.
     * That counter also keys all 40 precomputed spell kits, so bumping it silently invalidated
     * every one of them, dropping WoW Comps and Spell Explorer onto their slow live-compute
     * fallback (measured elsewhere in this codebase at 6,964ms vs 970ms for a 3-spec render) until
     * something regenerated them. That is the same ordering hazard CLAUDE.md already records for
     * deploy.sh, reintroduced from a different direction.
     *
     * Keyed on both, deliberately: the guide files decide WHICH spells and statistics are shown,
     * while the spell cache version covers the live game data they are resolved against, so each
     * source invalidates only what it actually affects.
     */
    private function guideFilesSignature(): string
    {
        $dir = base_path("data/claudes-guides/burst-guides/{$this->classSlug}");
        $files = File::exists($dir) ? (File::glob("{$dir}/*.json") ?: []) : [];

        $newest = 0;
        foreach ($files as $path) {
            $newest = max($newest, (int) File::lastModified($path));
        }

        return count($files).'-'.$newest;
    }

    /** @return array{class: ?GameClass, specs: array<int, array>} */
    private function computeGuide(TalentSelectionService $talentService): array
    {
        $class = GameClass::where('slug', $this->classSlug)->first();
        if (! $class) {
            return ['class' => null, 'specs' => []];
        }

        $dir = base_path("data/claudes-guides/burst-guides/{$this->classSlug}");
        $files = File::exists($dir) ? File::glob("{$dir}/*.json") : [];

        $decodedFiles = [];
        $allExternalIds = collect();
        foreach ($files as $path) {
            $decoded = json_decode(File::get($path), true);
            if (! is_array($decoded) || empty($decoded['specSlug']) || empty($decoded['sequence'])) {
                continue;
            }
            $decodedFiles[] = $decoded;
            foreach (['sequence', 'fill', 'alsoPressed'] as $group) {
                $allExternalIds = $allExternalIds->merge(array_column($decoded[$group] ?? [], 'spellId'));
            }
        }

        if ($decodedFiles === []) {
            return ['class' => $class, 'specs' => []];
        }

        $patchId = Patch::where('is_current', true)->value('id');
        $spellsByExternalId = Spell::whereIn('spell_id', $allExternalIds->unique())
            ->where('patch_id', $patchId)
            ->get()
            ->keyBy('spell_id');

        $spellRefService = app(ModuleSpellReferenceService::class);
        $specs = [];

        foreach ($decodedFiles as $decoded) {
            $spec = Specialization::where('class_id', $class->id)->where('slug', $decoded['specSlug'])->first();
            if (! $spec) {
                continue;
            }

            $kit = $this->readPrecomputedKit($decoded['specSlug'], $talentService);
            $resolve = fn (array $steps) => $this->resolveSteps($steps, $kit, $spellsByExternalId, $spellRefService);

            $sequence = $resolve($decoded['sequence']);
            if ($sequence === []) {
                continue;
            }

            $phases = [];
            foreach (self::PHASE_ORDER as $phase) {
                $inPhase = array_values(array_filter($sequence, fn (array $step) => ($step['phase'] ?? null) === $phase));
                if ($inPhase !== []) {
                    $phases[$phase] = $inPhase;
                }
            }

            $specs[] = [
                'spec' => $spec,
                'window' => $decoded['window'] ?? [],
                'evidence' => $decoded['evidence'] ?? [],
                'phases' => $phases,
                // The one step the whole plan is timed against — every medianOffset in the
                // sequence is relative to it, so the card marks it rather than leaving the
                // reader to infer which cooldown "0.0s" refers to.
                'anchor' => collect($sequence)->firstWhere('role', 'anchor'),
                'fill' => $resolve($decoded['fill'] ?? []),
                'alsoPressed' => $resolve($decoded['alsoPressed'] ?? []),
            ];
        }

        usort($specs, fn (array $a, array $b) => $a['spec']->name <=> $b['spec']->name);

        return ['class' => $class, 'specs' => $specs];
    }

    /**
     * The spec's precomputed kit, keyed by INTERNAL spells.id (per SpecKitComputer::
     * toJsonSafeArray()), or an empty array when there isn't a valid one on disk.
     *
     * A deliberately lightweight stand-in for SpecKitComputer::resolveEntriesForSpellIds() — see
     * that method's own callers elsewhere in the codebase for why it's too expensive to reach
     * for here: SpecKitComputer::fromJsonSafeArray() re-hydrates every spell referenced by EVERY
     * entry in a spec's precomputed kit (up to ~591 spells) WITH eager-loaded effects/
     * incomingRelationships, none of which a burst-guide card needs (only cooldown/charges/
     * category/dr_category, never a description or "what modifies this" list).
     *
     * Validates the kit's own spellCacheVersion/codeFingerprint exactly like
     * tryReadPrecomputed() does, so a stale kit is never trusted.
     *
     * @return array<int, array>
     */
    private function readPrecomputedKit(string $specSlug, TalentSelectionService $talentService): array
    {
        $kitPath = base_path("data/spell-kits/{$this->classSlug}/{$specSlug}.json");
        if (! File::exists($kitPath)) {
            return [];
        }

        $decodedKit = json_decode(File::get($kitPath), true);
        $kitValid = is_array($decodedKit)
            && isset($decodedKit['entries'], $decodedKit['spellCacheVersion'], $decodedKit['codeFingerprint'])
            && (string) $decodedKit['spellCacheVersion'] === (string) $talentService->spellCacheVersion()
            && $decodedKit['codeFingerprint'] === $talentService->deployedCodeFingerprint();

        if (! $kitValid) {
            return [];
        }

        $byInternalId = [];
        foreach ($decodedKit['entries'] as $entry) {
            $byInternalId[$entry['spellId']] = $entry;
        }

        return $byInternalId;
    }

    /**
     * Resolves one group of already-computed steps (sequence / fill / alsoPressed) against live
     * game data, preserving every statistic the builder measured. A spellId with no row in the
     * current patch is dropped rather than rendered as a blank card.
     *
     * An id NOT in the precomputed kit (base rotation — e.g. Mutilate/Envenom, no real cooldown)
     * falls back to the spell's own raw columns plus ModuleSpellReferenceService::categorize(),
     * identical to SpecKitComputer::resolveEntriesForSpellIds()'s own 'baseline_core' branch.
     *
     * @param  array<int, array>  $steps
     * @param  array<int, array>  $kit  keyed by internal spells.id
     * @param  Collection<int, Spell>  $spellsByExternalId
     * @return array<int, array>
     */
    private function resolveSteps(array $steps, array $kit, Collection $spellsByExternalId, ModuleSpellReferenceService $spellRefService): array
    {
        $resolved = [];

        foreach ($steps as $step) {
            $spell = $spellsByExternalId->get($step['spellId'] ?? null);
            if (! $spell) {
                continue;
            }

            $kitEntry = $kit[$spell->id] ?? null;

            $resolved[] = $step + [
                'spell' => $spell,
                // 'drCategory' comes from the kit entry, which already resolved any talent-
                // conditional flip against this spec's build (SpellProfileBuilder::resolveDrCategory);
                // the fallback branch has no build context, so the spell's own base column is the
                // honest answer there — same reasoning as SpecKitComputer's 'baseline_core' branch.
                'category' => $kitEntry['category'] ?? $spellRefService->categorize($spell),
                'drCategory' => $kitEntry['drCategory'] ?? $spell->dr_category,
                'cooldown' => $kitEntry['cooldown'] ?? ['seconds' => $spell->cooldown_seconds],
                'charges' => $kitEntry['charges'] ?? ['charges' => $spell->charges],
            ];
        }

        return $resolved;
    }

    public function render()
    {
        $guide = $this->guide;

        if ($this->onlySpecSlug !== null) {
            $guide['specs'] = array_values(array_filter(
                $guide['specs'],
                fn (array $s) => $s['spec']->slug === $this->onlySpecSlug
            ));
        }

        return view('livewire.burst-guide-class-block', [
            'guide' => $guide,
            // A spec-scoped render is already sitting under PvpGuides' own class/spec header, so
            // repeating the class name + icon above a single spec is pure duplication there.
            'showClassHeader' => $this->onlySpecSlug === null,
        ]);
    }
}
