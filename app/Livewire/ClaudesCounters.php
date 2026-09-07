<?php

namespace App\Livewire;

use App\Http\Services\SpellCounterIndexer;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellCounter;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "Spell Counters" — every real CC ability in the game, grouped by class, alongside what actually
 * counters it.
 *
 * Rewritten 2026-09-06 to read the materialized spell_counters index instead of deriving it.
 * This component used to be the ONLY place in the codebase that could answer "what counters X":
 * it rebuilt four in-memory candidate pools (usable-while-CC'd, mechanic-immunity, dodge/parry,
 * school-immunity) on every page load and matched them per CC spell. The matching rules were
 * right — and are preserved verbatim, they now live in SpellCounterIndexer — but they were
 * trapped inside a Livewire component, so the spell detail modal, the /spell/{id} page and
 * SpellFinder structurally could not ask the question at all.
 *
 * Now they all read the same relation, and this page is a grouping-and-rendering component with
 * no spell logic of its own. Two things came for free with that move: the pool-narrowing in
 * SpellCounterIndexer::narrowToPressable() (Kidney Shot's counter list went from 401 rows —
 * including Weakened Soul, Echo of Light and a literal "GGO - Test - Void Blink", plus five
 * duplicate copies of Metamorphosis — down to 41 real abilities), and the high/low confidence
 * split that separates the genuinely noisy `usable_while` signal from the three trustworthy ones.
 */
class ClaudesCounters extends Component
{
    /**
     * When set, only this class's group renders. Set by App\Livewire\PvpGuides, whose whole page
     * is scoped to one class the viewer picked — the page already answers "what does MY class
     * have to deal with", so listing the other twelve there is noise, and skipping them is also
     * most of this page's render cost.
     *
     * Filtered in render(), deliberately NOT inside getCounterableByClassProperty(): that method
     * builds the shared index-backed pool and is the piece most likely to change, so keeping the
     * scoping outside it means the two never have to be kept in step.
     */
    public ?string $onlyClassName = null;

    /**
     * True when this component is a panel inside PvpGuides rather than its own /spell-counters
     * page — suppresses this component's own page header, its footer note and its copy of the
     * shared spell-detail modal. Standalone /spell-counters leaves it false and is untouched.
     */
    public bool $embedded = false;

    public function mount(?string $onlyClassName = null, bool $embedded = false): void
    {
        $this->onlyClassName = $onlyClassName;
        $this->embedded = $embedded;

        // No bare page-view log when embedded: PvpGuides logs its own view, and a second row per
        // landing would inflate this page's count with visits that never opened the tab.
        if (! $embedded) {
            PageViewEvent::log('claudes_counters');
        }
    }

    /**
     * Every counterable CC ability grouped by class, each with its counters bucketed by mechanism.
     *
     * A spell with several availability rows for the same class collapses to one entry (keyed by
     * spell id); a spell available to several classes appears under each, which is intended — the
     * question this page answers is "what does MY class have to deal with".
     *
     * @return Collection<string, Collection<int, array{spell: Spell, buckets: Collection, hasAnyCounter: bool}>>
     */
    public function getCounterableByClassProperty(): Collection
    {
        // The SAME pool the index itself is built from, not a parallel query — see
        // SpellCounterIndexer::pressableCcSpells(). Listing every dr_category-tagged spell here
        // while the index only stores rows for pressable ones is what produced the 2026-09-07
        // report: Frost DK's "Absolute Zero" (a passive-granted freeze aura, not a button) was
        // listed on this page while being correctly absent from WoW Comps' Crowd Control. Sharing
        // one definition is what stops the page and the index disagreeing again.
        //
        // Re-queried by id rather than eager-loading the returned collection: narrowToPressable()
        // groups and maps, so what comes back is a base Collection with no load(), and the
        // whereHas here keeps the "must be available to some class" filter in SQL instead of an
        // exists() per spell.
        $patch = Patch::where('is_current', true)->first();

        if ($patch === null) {
            return collect();
        }

        $pressableIds = app(SpellCounterIndexer::class)->pressableCcSpells($patch)->pluck('id');

        $ccSpells = Spell::whereIn('id', $pressableIds)
            ->whereHas('classAvailability')
            ->with([
                'classAvailability.gameClass',
                'counteredBy.counterSpell',
            ])
            ->orderBy('name')
            ->get();

        $grouped = [];

        foreach ($ccSpells as $spell) {
            // Ordered by MECHANISMS rather than by whatever order rows came back in, so the
            // strongest evidence reads first and the layout is stable between spells.
            $buckets = collect(SpellCounter::MECHANISMS)
                ->mapWithKeys(fn (string $mechanism) => [
                    $mechanism => $spell->counteredBy
                        ->where('mechanism', $mechanism)
                        ->sortBy(fn (SpellCounter $c) => $c->counterSpell?->display_name)
                        ->values(),
                ])
                ->filter(fn (Collection $rows) => $rows->isNotEmpty());

            $row = [
                'spell' => $spell,
                'buckets' => $buckets,
                'hasAnyCounter' => $buckets->isNotEmpty(),
            ];

            foreach ($spell->classAvailability as $availability) {
                $className = $availability->gameClass->name ?? '(unassigned)';
                $grouped[$className][$spell->id] = $row;
            }
        }

        ksort($grouped);

        return collect($grouped)->map(fn (array $rows) => collect($rows)
            ->sortByDesc('hasAnyCounter')
            ->values());
    }

    public function render()
    {
        $byClass = $this->counterableByClass;

        if ($this->onlyClassName !== null) {
            $byClass = $byClass->only([$this->onlyClassName]);
        }

        return view('livewire.claudes-counters', [
            'counterableByClass' => $byClass,
            'embedded' => $this->embedded,
        ])->layout('layouts.app', [
            'title' => 'Spell Counters | MindCollector',
            'description' => 'Every real crowd-control ability in the game, grouped by class, alongside what actually counters it — immunity cooldowns, school immunities, and real dodge/parry counters.',
        ]);
    }
}
