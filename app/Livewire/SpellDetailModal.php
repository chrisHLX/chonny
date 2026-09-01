<?php

namespace App\Livewire;

use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\TalentSelectionService;
use App\Models\ModuleGameBuild;
use App\Models\Spell;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Site-wide, spell-ID-driven spell detail modal — extracted 2026-08-11 after WowComps' own
 * version (see that component's blade docblock: "simplest correct approach for a shape-check
 * page... not meant to scale to hundreds of modals forever") was about to be copy-pasted a
 * third time onto the CC Review page. Mount ONCE per host page:
 *
 *   <livewire:spell-detail-modal/>
 *
 * then from anywhere on that page, open it by dispatching a browser event with the spell's
 * internal id (Spell::$id, not the external spell_id) and optional class/spec context:
 *
 *   wire:click="$dispatch('show-spell-detail', { spellId: {{ $spell->id }}, classId: ..., specId: ... })"
 *
 * Unlike WowComps' original version, this is genuinely lazy — only the ONE currently-open
 * spell's description/modifiers/cooldown ever gets computed, not one hidden block per spell on
 * the page. classId/specId are optional: when given, cooldown/charges/modifiers are computed
 * against that spec's resolved talent build (admin default, same as everywhere else in this
 * pipeline); when omitted (e.g. the CC Review page, which isn't scoped to one spec), the modal
 * shows base/unmodified values with an empty selection — never guesses which build to use.
 */
class SpellDetailModal extends Component
{
    public ?int $spellId = null;

    public ?int $classId = null;

    public ?int $specId = null;

    #[On('show-spell-detail')]
    public function show(int $spellId, ?int $classId = null, ?int $specId = null): void
    {
        $this->spellId = $spellId;
        $this->classId = $classId;
        $this->specId = $specId;
    }

    public function close(): void
    {
        $this->spellId = null;
        $this->classId = null;
        $this->specId = null;
    }

    /**
     * @return ?array{spell: Spell, category: string, description: array, formulaModifiers: Collection, cooldown: array, charges: array, modifiers: array}
     */
    public function getEntryProperty(): ?array
    {
        if ($this->spellId === null) {
            return null;
        }

        $spell = Spell::with(['effects', 'incomingRelationships.sourceSpell.effects'])->find($this->spellId);
        if (!$spell) {
            return null;
        }

        $service = app(ModuleSpellReferenceService::class);
        $talentService = app(TalentSelectionService::class);

        $selected = new Collection();
        $ranks = new Collection();

        if ($this->specId !== null) {
            $build = $talentService->resolveActiveBuild(auth()->user(), $this->specId);
            if ($build->exists) {
                $selected = $talentService->selectedSpellIds($build);
                $ranks = $talentService->selectedRanks($build);
            }
        }

        $gameBuild = new ModuleGameBuild([
            'class_id' => $this->classId,
            'specialization_id' => $this->specId,
        ]);

        $description = $service->resolveDescription($spell, $gameBuild);
        $modifiers = $service->modifiersFor($spell, $gameBuild, $selected, $ranks);

        return [
            'spell' => $spell,
            'category' => $service->categorize($spell),
            'description' => $description,
            'formulaModifiers' => $description['uncertain'] ? $service->variablesModifiers($spell) : new Collection(),
            'cooldown' => $service->effectiveCooldown($spell, $gameBuild, $selected, $ranks),
            'charges' => $service->effectiveCharges($spell, $gameBuild, $selected, $ranks),
            'modifiers' => [
                'named' => $this->enrichModifiers($modifiers['named'], $service, $gameBuild, $selected, $ranks),
                'baseline' => $modifiers['baseline'],
                // 'Could be improved by...' — real, structurally-confirmed modifiers whose
                // talent isn't currently selected (see ModuleSpellReferenceService::
                // modifiersFor()'s docblock, 2026-09-01). Only meaningful once a spec context
                // exists — with no specId there's no resolved build to be "not selected" in, so
                // 'potential' and 'named' would be indistinguishable; that case is handled by
                // the blade simply not rendering the section rather than by hiding it here.
                'potential' => $this->enrichModifiers($modifiers['potential'], $service, $gameBuild, $selected, $ranks),
            ],
        ];
    }

    /** Same enrichment WowComps::enrichModifiers() already does — modifiersFor()'s raw output has no description/category/cooldown per modifier, only the modifying spell itself and its magnitude. */
    private function enrichModifiers(Collection $modifiers, ModuleSpellReferenceService $service, ModuleGameBuild $build, Collection $selected, Collection $ranks): Collection
    {
        return $modifiers->map(function (array $mod) use ($service, $build, $selected, $ranks) {
            $mod['description'] = $service->resolveDescription($mod['spell'], $build);
            $mod['category'] = $service->categorize($mod['spell']);
            $mod['cooldown'] = $service->effectiveCooldown($mod['spell'], $build, $selected, $ranks);

            return $mod;
        });
    }

    public function render()
    {
        return view('livewire.spell-detail-modal', [
            'entry' => $this->entry,
            'specId' => $this->specId,
        ]);
    }
}
