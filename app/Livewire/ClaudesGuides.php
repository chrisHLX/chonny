<?php

namespace App\Livewire;

use App\Http\Services\ArenaLogService;
use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\SpecKitComputer;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Component;

/**
 * "Claude's Guides" — a deliberately isolated, experimental page. See
 * data/claudes-guides/README.md for the full design/rationale; the short version: this reads
 * ONLY from data/claudes-guides/{class}/{spec}.json, and nothing outside that folder writes to
 * it or is modified by it. A guide's prose is AI-synthesized analysis, not a dictated expert
 * module (see each guide's own 'disclaimer' field, always rendered) — but every game FACT a
 * guide references (spell categories, cooldowns, real observed sequences) is resolved live from
 * the same database/precomputed files everything else on the site uses, via the exact same
 * services (never a frozen number baked into the guide's own JSON).
 *
 * Picker only ever lists classes/specs that actually have a guide file — same "don't show
 * something broken" posture as WowComps' preset-comp picker (getPresetsProperty()).
 */
class ClaudesGuides extends Component
{
    public ?string $classSlug = null;

    public ?string $specSlug = null;

    public function mount(?string $classSlug = null, ?string $specSlug = null): void
    {
        $this->classSlug = $classSlug;
        $this->specSlug = $specSlug;

        // Bare page view first, always — an attributed one only fires below if the requested
        // guide actually exists, matching the "never attribute a default/landing value" rule.
        PageViewEvent::log('claudes_guides');

        if ($this->classSlug && $this->specSlug && $this->currentGuide !== null) {
            $class = GameClass::where('slug', $this->classSlug)->first();
            $spec = $class ? Specialization::where('class_id', $class->id)->where('slug', $this->specSlug)->first() : null;
            PageViewEvent::log('claudes_guides', $class?->id, $spec?->id);
        }
    }

    public function selectGuide(string $classSlug, string $specSlug): void
    {
        $this->classSlug = $classSlug;
        $this->specSlug = $specSlug;

        $class = GameClass::where('slug', $classSlug)->first();
        $spec = $class ? Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first() : null;
        PageViewEvent::log('claudes_guides', $class?->id, $spec?->id);
    }

    /**
     * Every guide that actually exists on disk, resolved to real class/spec models — a guide
     * whose class/spec slug doesn't match anything real is silently skipped rather than shown
     * broken, same precedent as WowComps::getPresetsProperty().
     *
     * @return array<int, array{classSlug: string, specSlug: string, class: GameClass, spec: Specialization, title: string}>
     */
    public function getAvailableGuidesProperty(): array
    {
        $files = File::exists(base_path('data/claudes-guides'))
            ? File::glob(base_path('data/claudes-guides/*/*.json'))
            : [];

        $guides = [];
        foreach ($files as $path) {
            $decoded = json_decode(File::get($path), true);
            if (!is_array($decoded) || !isset($decoded['classSlug'], $decoded['specSlug'])) {
                continue;
            }

            $class = GameClass::where('slug', $decoded['classSlug'])->first();
            $spec = $class ? Specialization::where('class_id', $class->id)->where('slug', $decoded['specSlug'])->first() : null;

            if (!$class || !$spec) {
                continue;
            }

            $guides[] = [
                'classSlug' => $decoded['classSlug'],
                'specSlug' => $decoded['specSlug'],
                'class' => $class,
                'spec' => $spec,
                'title' => $decoded['title'] ?? "{$spec->name} {$class->name}",
            ];
        }

        return $guides;
    }

    /** The raw decoded JSON for whichever guide is currently selected, or null. */
    public function getCurrentGuideProperty(): ?array
    {
        if (!$this->classSlug || !$this->specSlug) {
            return null;
        }

        $path = base_path("data/claudes-guides/{$this->classSlug}/{$this->specSlug}.json");
        if (!File::exists($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolves a guide's 'spellGrid' spell_id list to the SAME enriched entries (category,
     * talent-adjusted cooldown/charges) WowComps/SpellExplorer show for this exact spec — reuses
     * today's precomputed spell-kit file when available (SpecKitComputer::tryReadPrecomputed()),
     * falling back to a direct live computation otherwise, exactly like every other consumer of
     * that engine. This guide never computes its own independent numbers; it only ever narrows
     * the same real per-spec kit down to the spell_ids it wants to highlight. An id with no
     * match in the current kit is silently dropped, never shown broken.
     *
     * @param  array<int, int>  $spellIds  external spell_id values, as authored in the guide JSON
     * @return array<int, array> a subset of SpecKitComputer::compute()'s own entry shape
     */
    public function resolveSpellEntries(array $spellIds, string $classSlug, string $specSlug): array
    {
        $class = GameClass::where('slug', $classSlug)->first();
        $spec = $class ? Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first() : null;
        if (!$spec) {
            return [];
        }

        return app(SpecKitComputer::class)->resolveEntriesForSpellIds(
            $spellIds, $spec, app(ModuleSpellReferenceService::class), app(TalentSelectionService::class)
        );
    }

    /**
     * Resolves exactly one spellId — used for a 'rotationBlock' section's ordered `steps`, where
     * each step carries its own optional `tag` annotation that must stay paired with the RIGHT
     * entry. Resolving the whole steps array in one batch (like resolveSpellEntries() does for a
     * spellGrid) would silently misalign that pairing the moment any single id failed to
     * resolve — resolving one at a time trades a little efficiency for guaranteed-correct pairing,
     * which matters more here since a rotation's step ORDER and per-step tag are the entire point.
     */
    public function resolveSingleAbility(int $spellId, string $classSlug, string $specSlug): ?array
    {
        $entries = $this->resolveSpellEntries([$spellId], $classSlug, $specSlug);

        return $entries[0] ?? null;
    }

    /**
     * The real top-DPS window for the guide's spec, read live from the SAME file/resolution path
     * Class Guide and Burst Windows already use — never hand-copied into a guide's own JSON, so
     * it can never drift from what's shown elsewhere on the site for the same spec. Returns null
     * when no rotation file exists yet for this spec (a guide can still render its prose/spell-
     * grid sections without this).
     */
    public function getRealBurstWindowProperty(): ?array
    {
        $guide = $this->currentGuide;
        if (!$guide) {
            return null;
        }

        $spec = Specialization::whereHas('gameClass', fn ($q) => $q->where('slug', $guide['classSlug']))
            ->where('slug', $guide['specSlug'])
            ->first();
        if (!$spec) {
            return null;
        }

        $service = app(ArenaLogService::class);
        $rotation = $service->rotationForSpec($guide['classSlug'], $guide['specSlug']);
        if (!$rotation) {
            return null;
        }

        $length = null;
        foreach ($guide['sections'] as $section) {
            if (($section['type'] ?? null) === 'sequence' && isset($section['burstWindowLength'])) {
                $length = $section['burstWindowLength'];
                break;
            }
        }
        $length ??= 12;

        $window = $rotation['topDpsWindowsByLength'][$length] ?? null;
        if (!$window || empty($window['steps'])) {
            return null;
        }

        $window['steps'] = $service->resolveWindowSteps($window['steps'], $spec->id, app(TalentSelectionService::class));

        return $window;
    }

    public function render()
    {
        $guide = $this->currentGuide;
        $title = $guide['title'] ?? "Claude's Guides";

        return view('livewire.claudes-guides', [
            'availableGuides' => $this->availableGuides,
            'guide' => $guide,
            'burstWindow' => $this->realBurstWindow,
        ])->layout('layouts.app', [
            'title' => "{$title} | Claude's Guides | MindCollector",
            'description' => 'Experimental, AI-synthesized PvP class guides — real game data, Claude\'s own reasoning layered on top, clearly labelled as such.',
        ]);
    }
}
