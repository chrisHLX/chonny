<?php

namespace App\Livewire;

use App\Http\Services\SubjectContextService;
use App\Models\SubjectContextDimension;
use App\Models\SubjectContextOption;
use Livewire\Component;

/**
 * Player-declared context (class/race/role/...) for the currently selected subject — see the
 * "Subject Context Dimensions" note in CLAUDE.md. Renders nothing for a subject with zero
 * dimensions (e.g. Poker), by design.
 */
class SubjectContextForm extends Component
{
    public $subjectId;

    // Keyed by dimension_id => selected option_id (string from the <select>, cast on save).
    public array $selections = [];

    public bool $saved = false;

    // When true (only ever set by DiagnosticQuizRunner for a guest taking the diagnostic
    // pre-signup), save() dispatches the raw selections instead of calling
    // SubjectContextService::declare() — there's no user_id yet to persist against.
    // DiagnosticQuizRunner captures the dispatched selections in-memory and, if the guest later
    // registers, RegisteredUserController::claimGuestQuizResults() declares them for real.
    public bool $guestMode = false;

    public function mount($subjectId, $guestMode = false): void
    {
        $this->subjectId = $subjectId;
        $this->guestMode = $guestMode;

        if ($this->guestMode) {
            return;
        }

        $declarations = app(SubjectContextService::class)->declarationsForSubject(auth()->id(), $subjectId);

        foreach ($declarations as $dimensionId => $declaration) {
            $this->selections[$dimensionId] = (string) $declaration->subject_context_option_id;
        }
    }

    public function getDimensionsProperty()
    {
        return SubjectContextDimension::where('subject_id', $this->subjectId)
            ->orderBy('order')
            ->get();
    }

    /**
     * Child-dimension options are filtered to the currently-selected parent option, and are
     * empty (so effectively disabled) until a parent is chosen.
     */
    public function optionsFor(SubjectContextDimension $dimension)
    {
        $query = SubjectContextOption::where('dimension_id', $dimension->id)->orderBy('order');

        if ($dimension->parent_dimension_id) {
            $parentOptionId = $this->selections[$dimension->parent_dimension_id] ?? null;

            if (!$parentOptionId) {
                return collect();
            }

            $query->where('parent_option_id', $parentOptionId);
        }

        return $query->get();
    }

    /**
     * Clearing a parent dimension's selection also clears the form's in-memory selection for
     * every dependent child dimension — mirrors SubjectContextService::declare()'s
     * cascade-clear-children rule client-side, so the UI doesn't keep showing a stale Spec
     * selection after Class changes but before Save is clicked.
     */
    public function updatedSelections($value, $key): void
    {
        $dimensionId = (int) $key;

        $childDimensionIds = SubjectContextDimension::where('parent_dimension_id', $dimensionId)->pluck('id');

        foreach ($childDimensionIds as $childId) {
            unset($this->selections[$childId]);
        }

        $this->saved = false;
    }

    public function save(): void
    {
        $dimensions = $this->dimensions;

        foreach ($dimensions as $dimension) {
            $parentSatisfied = !$dimension->parent_dimension_id
                || !empty($this->selections[$dimension->parent_dimension_id]);

            if ($dimension->required && $parentSatisfied && empty($this->selections[$dimension->id])) {
                $this->addError("selections.{$dimension->id}", "Please select a {$dimension->name}.");
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if ($this->guestMode) {
            $this->saved = true;
            $this->dispatch('subject-context-saved', selections: $this->selections);
            return;
        }

        $service = app(SubjectContextService::class);

        // Parent dimensions must be declared before their children in the same request —
        // declare() clears child declarations whenever a parent changes, which would wipe out a
        // child selection saved earlier in this same loop if the order were reversed. $dimensions
        // is already ordered by 'order', and the seeder always gives a parent a lower order than
        // its children, so this holds without an extra sort here.
        foreach ($dimensions as $dimension) {
            if (!empty($this->selections[$dimension->id])) {
                $service->declare(auth()->id(), $dimension->id, (int) $this->selections[$dimension->id]);
            }
        }

        $this->saved = true;
        $this->dispatch('subject-context-saved');
    }

    public function render()
    {
        return view('livewire.subject-context-form', [
            'dimensions' => $this->dimensions,
        ]);
    }
}
