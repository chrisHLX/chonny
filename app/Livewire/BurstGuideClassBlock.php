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
 * #[Lazy] (Livewire 3's built-in deferred-child-component loading — not used anywhere else in
 * this codebase yet, first real use case for it) makes the INITIAL page load just the 13 class
 * headers + a lightweight placeholder each; each class's real content then loads via its own
 * separate, independent follow-up request, fired automatically right after first paint. Same
 * end result (every spec still visible, no scope reduction from what was already decided via
 * AskUserQuestion — "one block per spec, all 38"), but spread across 13 small requests instead
 * of one big blocking one, and the page is interactive long before every class has finished
 * loading. Splitting per-CLASS rather than per-SPEC keeps the child count at a sane 13 (a
 * separate Livewire request per spec would be 38 small requests — more network overhead for
 * comparatively little extra parallelism benefit over 13).
 */
#[Lazy]
class BurstGuideClassBlock extends Component
{
    public string $classSlug;

    public function mount(string $classSlug): void
    {
        $this->classSlug = $classSlug;
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
     * @return array{class: ?GameClass, specs: array<int, array{spec: Specialization, meta: array, steps: array}>}
     */
    public function getGuideProperty(): array
    {
        $talentService = app(TalentSelectionService::class);
        $cacheKey = "burst_guides:class:{$this->classSlug}:v{$talentService->spellCacheVersion()}:{$talentService->deployedCodeFingerprint()}";

        return Cache::remember($cacheKey, now()->addDay(), fn () => $this->computeGuide($talentService));
    }

    /** @return array{class: ?GameClass, specs: array<int, array{spec: Specialization, meta: array, steps: array}>} */
    private function computeGuide(TalentSelectionService $talentService): array
    {
        $class = GameClass::where('slug', $this->classSlug)->first();
        if (!$class) {
            return ['class' => null, 'specs' => []];
        }

        $dir = base_path("data/claudes-guides/burst-guides/{$this->classSlug}");
        $files = File::exists($dir) ? File::glob("{$dir}/*.json") : [];

        $decodedFiles = [];
        $allExternalIds = collect();
        foreach ($files as $path) {
            $decoded = json_decode(File::get($path), true);
            if (!is_array($decoded) || empty($decoded['specSlug']) || empty($decoded['spellIds'])) {
                continue;
            }
            $decodedFiles[] = $decoded;
            $allExternalIds = $allExternalIds->merge($decoded['spellIds']);
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
            if (!$spec) {
                continue;
            }

            $steps = $this->resolveBurstGuideSteps($decoded['spellIds'], $decoded['specSlug'], $spellsByExternalId, $spellRefService, $talentService);
            if (empty($steps)) {
                continue;
            }

            $specs[] = [
                'spec' => $spec,
                'meta' => [
                    'sourceLengthSeconds' => $decoded['sourceLengthSeconds'] ?? null,
                    'truncatedAtRepeat' => $decoded['truncatedAtRepeat'] ?? false,
                    'rawStepCount' => $decoded['rawStepCount'] ?? null,
                    'filteredStepCount' => $decoded['filteredStepCount'] ?? null,
                ],
                'steps' => $steps,
            ];
        }

        usort($specs, fn (array $a, array $b) => $a['spec']->name <=> $b['spec']->name);

        return ['class' => $class, 'specs' => $specs];
    }

    /**
     * A deliberately lightweight stand-in for SpecKitComputer::resolveEntriesForSpellIds() — see
     * that method's own callers elsewhere in the codebase for why it's too expensive to reach
     * for here: SpecKitComputer::fromJsonSafeArray() re-hydrates every spell referenced by EVERY
     * entry in a spec's precomputed kit (up to ~591 spells) WITH eager-loaded effects/
     * incomingRelationships, none of which a burst-guide card needs (only cooldown/charges/
     * category/dr_category/pvp_duration, never a description or "what modifies this" list).
     *
     * Reads the SAME precomputed kit file (data/spell-kits/{class}/{spec}.json) directly as raw
     * JSON — no Eloquent rehydration — for the already-talent-computed cooldown/charges/category
     * of whichever spellIds are "selectable" content. Validates the kit's own
     * spellCacheVersion/codeFingerprint exactly like tryReadPrecomputed() does, so a stale kit is
     * never trusted. An id NOT in the kit (base rotation — e.g. Mutilate/Envenom, no real
     * cooldown) falls back to the spell's own raw cooldown_seconds/charges columns plus
     * ModuleSpellReferenceService::categorize() — identical to resolveEntriesForSpellIds()'s own
     * 'baseline_core' fallback branch, just reached directly instead of via that heavier path.
     *
     * @param  array<int, int>  $spellIds  external spell_id values, in order (duplicates kept)
     * @param  Collection<int, Spell>  $spellsByExternalId  pre-fetched, keyed by spell_id
     * @return array<int, array{spell: Spell, category: string, cooldown: array, charges: array}>
     */
    private function resolveBurstGuideSteps(array $spellIds, string $specSlug, Collection $spellsByExternalId, ModuleSpellReferenceService $spellRefService, TalentSelectionService $talentService): array
    {
        $kitByInternalId = [];
        $kitPath = base_path("data/spell-kits/{$this->classSlug}/{$specSlug}.json");
        if (File::exists($kitPath)) {
            $decodedKit = json_decode(File::get($kitPath), true);
            $kitValid = is_array($decodedKit)
                && isset($decodedKit['entries'], $decodedKit['spellCacheVersion'], $decodedKit['codeFingerprint'])
                && (string) $decodedKit['spellCacheVersion'] === (string) $talentService->spellCacheVersion()
                && $decodedKit['codeFingerprint'] === $talentService->deployedCodeFingerprint();

            if ($kitValid) {
                foreach ($decodedKit['entries'] as $entry) {
                    $kitByInternalId[$entry['spellId']] = $entry; // internal spells.id, per SpecKitComputer::toJsonSafeArray()
                }
            }
        }

        $steps = [];
        foreach ($spellIds as $externalId) {
            $spell = $spellsByExternalId->get($externalId);
            if (!$spell) {
                continue;
            }

            $kitEntry = $kitByInternalId[$spell->id] ?? null;

            $steps[] = $kitEntry !== null
                ? ['spell' => $spell, 'category' => $kitEntry['category'], 'cooldown' => $kitEntry['cooldown'], 'charges' => $kitEntry['charges']]
                : ['spell' => $spell, 'category' => $spellRefService->categorize($spell), 'cooldown' => ['seconds' => $spell->cooldown_seconds], 'charges' => ['charges' => $spell->charges]];
        }

        return $steps;
    }

    public function render()
    {
        return view('livewire.burst-guide-class-block', [
            'guide' => $this->guide,
        ]);
    }
}
