<?php

namespace App\Livewire\Admin;

use App\Models\Axis;
use App\Models\Category;
use App\Models\Concept;
use App\Models\Proficiency;
use App\Models\Subject;
use App\Models\UserAxisMastery;
use Livewire\Component;

class ContentManager extends Component
{
    public string $section = 'axes';

    public ?int $filterCategoryId = null;
    public ?int $filterSubjectId  = null;

    public ?string $flashMessage = null;

    // ── Axes ────────────────────────────────────────────────────────────────
    public string $newAxisName        = '';
    public string $newAxisDescription = '';
    public ?int   $newAxisCategoryId  = null;

    public ?int   $editingAxisId       = null;
    public string $editAxisName        = '';
    public string $editAxisDescription = '';
    public int    $editAxisCategoryId  = 0;

    // ── Categories ──────────────────────────────────────────────────────────
    public string $newCatName        = '';
    public string $newCatDescription = '';

    public ?int   $editingCategoryId   = null;
    public string $editCatName         = '';
    public string $editCatDescription  = '';

    // ── Subjects ────────────────────────────────────────────────────────────
    public string $newSubName        = '';
    public string $newSubDescription = '';

    public ?int   $editingSubjectId   = null;
    public string $editSubName        = '';
    public string $editSubDescription = '';

    public ?int   $openProfSubjectId   = null;
    public string $newProfName         = '';
    public string $newProfDescription  = '';
    public int    $newProfIndex        = 0;
    public ?int   $editingProfId       = null;
    public string $editProfName        = '';
    public string $editProfDescription = '';
    public int    $editProfIndex       = 0;

    // ── Outcomes ─────────────────────────────────────────────────────────────
    public ?int   $openOutcomeProfId      = null;
    public string $newOutcomeText         = '';
    public ?int   $editingOutcomeIndex    = null;
    public string $editOutcomeText        = '';

    // ── Concepts ────────────────────────────────────────────────────────────
    public string $newConceptName        = '';
    public string $newConceptDescription = '';

    public ?int   $editingConceptId       = null;
    public string $editConceptName        = '';
    public string $editConceptDescription = '';

    public ?int  $mappingAxesConceptId = null;
    public array $selectedAxisIds      = [];

    // ── Boot ────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $first = Category::orderBy('name')->first();
        $this->filterCategoryId = $first?->id;
        $this->newAxisCategoryId = $first?->id;
    }

    // ── Computed properties ─────────────────────────────────────────────────

    public function getCategoriesProperty()
    {
        return Category::withCount('subjects')->orderBy('name')->get();
    }

    public function getAxesProperty()
    {
        return Axis::with('category')
            ->when($this->filterCategoryId, fn ($q) => $q->where('category_id', $this->filterCategoryId))
            ->orderBy('name')
            ->get();
    }

    public function getSubjectsProperty()
    {
        if (! $this->filterCategoryId) {
            return collect();
        }

        return Subject::where('category_id', $this->filterCategoryId)
            ->withCount(['concepts', 'modules'])
            ->with(['proficiencies' => fn ($q) => $q->orderBy('index')])
            ->orderBy('name')
            ->get();
    }

    public function getConceptsProperty()
    {
        if (! $this->filterSubjectId) {
            return collect();
        }

        return Concept::where('subject_id', $this->filterSubjectId)
            ->withCount('questions')
            ->with('axes')
            ->orderBy('name')
            ->get();
    }

    public function getAxisOptionsForMappingProperty()
    {
        if (! $this->filterSubjectId) {
            return collect();
        }

        $subject = Subject::find($this->filterSubjectId);

        if (! $subject) {
            return collect();
        }

        return Axis::where('category_id', $subject->category_id)->orderBy('name')->get();
    }

    // ── Watchers ─────────────────────────────────────────────────────────────

    public function updatedFilterCategoryId(): void
    {
        $this->filterSubjectId = null;
        $this->newAxisCategoryId = $this->filterCategoryId;
        $this->cancelAll();
    }

    public function updatedFilterSubjectId(): void
    {
        $this->cancelEditConcept();
        $this->cancelAxisMapping();
    }

    public function updatedSection(): void
    {
        $this->cancelAll();
        $this->flashMessage = null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function flash(string $message): void
    {
        $this->flashMessage = $message;
    }

    private function cancelAll(): void
    {
        $this->cancelEditAxis();
        $this->cancelEditCategory();
        $this->cancelEditSubject();
        $this->cancelEditProficiency();
        $this->cancelEditOutcome();
        $this->cancelEditConcept();
        $this->cancelAxisMapping();
        $this->openProfSubjectId  = null;
        $this->openOutcomeProfId  = null;
    }

    // ── Axes ─────────────────────────────────────────────────────────────────

    public function createAxis(): void
    {
        $this->validate([
            'newAxisName'        => 'required|string|max:255',
            'newAxisDescription' => 'nullable|string|max:1000',
            'newAxisCategoryId'  => 'required|exists:categories,id',
        ]);

        $name = $this->newAxisName;

        Axis::create([
            'name'        => $this->newAxisName,
            'description' => $this->newAxisDescription ?: null,
            'category_id' => $this->newAxisCategoryId,
        ]);

        $this->newAxisName = '';
        $this->newAxisDescription = '';
        $this->flash("Axis \"{$name}\" created.");
    }

    public function startEditAxis(int $id): void
    {
        $axis = Axis::findOrFail($id);
        $this->editingAxisId       = $axis->id;
        $this->editAxisName        = $axis->name;
        $this->editAxisDescription = $axis->description ?? '';
        $this->editAxisCategoryId  = $axis->category_id;
        $this->flashMessage        = null;
    }

    public function updateAxis(): void
    {
        $this->validate([
            'editAxisName'        => 'required|string|max:255',
            'editAxisDescription' => 'nullable|string|max:1000',
            'editAxisCategoryId'  => 'required|exists:categories,id',
        ]);

        Axis::findOrFail($this->editingAxisId)->update([
            'name'        => $this->editAxisName,
            'description' => $this->editAxisDescription ?: null,
            'category_id' => $this->editAxisCategoryId,
        ]);

        $this->cancelEditAxis();
        $this->flash('Axis updated.');
    }

    public function cancelEditAxis(): void
    {
        $this->editingAxisId       = null;
        $this->editAxisName        = '';
        $this->editAxisDescription = '';
        $this->editAxisCategoryId  = 0;
    }

    public function deleteAxis(int $id): void
    {
        Axis::findOrFail($id)->delete();
        $this->flash('Axis deleted.');
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public function createCategory(): void
    {
        $this->validate([
            'newCatName'        => 'required|string|max:255|unique:categories,name',
            'newCatDescription' => 'nullable|string|max:1000',
        ]);

        $name = $this->newCatName;

        Category::create([
            'name'        => $this->newCatName,
            'description' => $this->newCatDescription ?: null,
        ]);

        $this->newCatName = '';
        $this->newCatDescription = '';
        $this->flash("Category \"{$name}\" created.");
    }

    public function startEditCategory(int $id): void
    {
        $category = Category::findOrFail($id);
        $this->editingCategoryId  = $category->id;
        $this->editCatName        = $category->name;
        $this->editCatDescription = $category->description ?? '';
        $this->flashMessage       = null;
    }

    public function updateCategory(): void
    {
        $this->validate([
            'editCatName'        => "required|string|max:255|unique:categories,name,{$this->editingCategoryId}",
            'editCatDescription' => 'nullable|string|max:1000',
        ]);

        Category::findOrFail($this->editingCategoryId)->update([
            'name'        => $this->editCatName,
            'description' => $this->editCatDescription ?: null,
        ]);

        $this->cancelEditCategory();
        $this->flash('Category updated.');
    }

    public function cancelEditCategory(): void
    {
        $this->editingCategoryId  = null;
        $this->editCatName        = '';
        $this->editCatDescription = '';
    }

    public function deleteCategory(int $id): void
    {
        Category::findOrFail($id)->delete();

        if ($this->filterCategoryId === $id) {
            $this->filterCategoryId  = Category::orderBy('name')->first()?->id;
            $this->filterSubjectId   = null;
        }

        $this->flash('Category deleted.');
    }

    // ── Subjects ─────────────────────────────────────────────────────────────

    public function createSubject(): void
    {
        $this->validate([
            'newSubName'        => 'required|string|max:255|unique:subjects,name',
            'newSubDescription' => 'nullable|string|max:1000',
            'filterCategoryId'  => 'required|exists:categories,id',
        ]);

        $name = $this->newSubName;

        Subject::create([
            'name'        => $this->newSubName,
            'description' => $this->newSubDescription ?: null,
            'category_id' => $this->filterCategoryId,
        ]);

        $this->newSubName = '';
        $this->newSubDescription = '';
        $this->flash("Subject \"{$name}\" created.");
    }

    public function startEditSubject(int $id): void
    {
        $subject = Subject::findOrFail($id);
        $this->editingSubjectId   = $subject->id;
        $this->editSubName        = $subject->name;
        $this->editSubDescription = $subject->description ?? '';
        $this->flashMessage       = null;
    }

    public function updateSubject(): void
    {
        $this->validate([
            'editSubName'        => "required|string|max:255|unique:subjects,name,{$this->editingSubjectId}",
            'editSubDescription' => 'nullable|string|max:1000',
        ]);

        Subject::findOrFail($this->editingSubjectId)->update([
            'name'        => $this->editSubName,
            'description' => $this->editSubDescription ?: null,
        ]);

        $this->cancelEditSubject();
        $this->flash('Subject updated.');
    }

    public function cancelEditSubject(): void
    {
        $this->editingSubjectId   = null;
        $this->editSubName        = '';
        $this->editSubDescription = '';
    }

    public function deleteSubject(int $id): void
    {
        Subject::findOrFail($id)->delete();
        $this->flash('Subject deleted.');
    }

    public function toggleProficiencies(int $subjectId): void
    {
        if ($this->openProfSubjectId === $subjectId) {
            $this->openProfSubjectId = null;
            $this->cancelEditProficiency();
            return;
        }

        $this->openProfSubjectId = $subjectId;
        $this->cancelEditProficiency();

        $count = Proficiency::where('subject_id', $subjectId)->count();
        $this->newProfIndex = $count;
        $this->newProfName  = '';
        $this->newProfDescription = '';
    }

    public function createProficiency(int $subjectId): void
    {
        $this->validate([
            'newProfName'        => 'required|string|max:255',
            'newProfDescription' => 'nullable|string|max:1000',
            'newProfIndex'       => 'required|integer|min:0',
        ]);

        Proficiency::create([
            'subject_id'  => $subjectId,
            'name'        => $this->newProfName,
            'description' => $this->newProfDescription ?: null,
            'index'       => $this->newProfIndex,
        ]);

        $this->newProfName        = '';
        $this->newProfDescription = '';
        $this->newProfIndex       = Proficiency::where('subject_id', $subjectId)->count();
        $this->flash('Proficiency added.');
    }

    public function startEditProficiency(int $id): void
    {
        $prof = Proficiency::findOrFail($id);
        $this->editingProfId       = $prof->id;
        $this->editProfName        = $prof->name;
        $this->editProfDescription = $prof->description ?? '';
        $this->editProfIndex       = $prof->index;
        $this->flashMessage        = null;
    }

    public function updateProficiency(): void
    {
        $this->validate([
            'editProfName'        => 'required|string|max:255',
            'editProfDescription' => 'nullable|string|max:1000',
            'editProfIndex'       => 'required|integer|min:0',
        ]);

        Proficiency::findOrFail($this->editingProfId)->update([
            'name'        => $this->editProfName,
            'description' => $this->editProfDescription ?: null,
            'index'       => $this->editProfIndex,
        ]);

        $this->cancelEditProficiency();
        $this->flash('Proficiency updated.');
    }

    public function cancelEditProficiency(): void
    {
        $this->editingProfId       = null;
        $this->editProfName        = '';
        $this->editProfDescription = '';
        $this->editProfIndex       = 0;
    }

    public function deleteProficiency(int $id): void
    {
        Proficiency::findOrFail($id)->delete();
        $this->flash('Proficiency deleted.');
    }

    // ── Outcomes ─────────────────────────────────────────────────────────────

    public function toggleOutcomes(int $profId): void
    {
        if ($this->openOutcomeProfId === $profId) {
            $this->openOutcomeProfId = null;
            $this->cancelEditOutcome();
            return;
        }

        $this->openOutcomeProfId   = $profId;
        $this->newOutcomeText      = '';
        $this->cancelEditOutcome();
    }

    public function addOutcome(int $profId): void
    {
        $this->validate(['newOutcomeText' => 'required|string|max:500']);

        $prof     = Proficiency::findOrFail($profId);
        $outcomes = $prof->outcomes ?? [];
        $outcomes[] = trim($this->newOutcomeText);

        $prof->update(['outcomes' => $outcomes]);

        $this->newOutcomeText = '';
        $this->flash('Outcome added.');
    }

    public function startEditOutcome(int $profId, int $index): void
    {
        $prof = Proficiency::findOrFail($profId);
        $outcomes = $prof->outcomes ?? [];

        $this->openOutcomeProfId   = $profId;
        $this->editingOutcomeIndex = $index;
        $this->editOutcomeText     = $outcomes[$index] ?? '';
        $this->flashMessage        = null;
    }

    public function updateOutcome(int $profId): void
    {
        $this->validate(['editOutcomeText' => 'required|string|max:500']);

        $prof     = Proficiency::findOrFail($profId);
        $outcomes = $prof->outcomes ?? [];
        $outcomes[$this->editingOutcomeIndex] = trim($this->editOutcomeText);

        $prof->update(['outcomes' => array_values($outcomes)]);

        $this->cancelEditOutcome();
        $this->flash('Outcome updated.');
    }

    public function deleteOutcome(int $profId, int $index): void
    {
        $prof     = Proficiency::findOrFail($profId);
        $outcomes = $prof->outcomes ?? [];

        array_splice($outcomes, $index, 1);
        $prof->update(['outcomes' => array_values($outcomes)]);

        $this->flash('Outcome deleted.');
    }

    public function cancelEditOutcome(): void
    {
        $this->editingOutcomeIndex = null;
        $this->editOutcomeText     = '';
    }

    // ── Concepts ─────────────────────────────────────────────────────────────

    public function createConcept(): void
    {
        $this->validate([
            'newConceptName'        => 'required|string|max:255',
            'newConceptDescription' => 'nullable|string|max:1000',
            'filterSubjectId'       => 'required|exists:subjects,id',
        ]);

        $name = $this->newConceptName;

        Concept::create([
            'name'        => $this->newConceptName,
            'description' => $this->newConceptDescription ?: null,
            'subject_id'  => $this->filterSubjectId,
        ]);

        $this->newConceptName        = '';
        $this->newConceptDescription = '';
        $this->flash("Concept \"{$name}\" created.");
    }

    public function startEditConcept(int $id): void
    {
        $concept = Concept::findOrFail($id);
        $this->editingConceptId       = $concept->id;
        $this->editConceptName        = $concept->name;
        $this->editConceptDescription = $concept->description ?? '';
        $this->mappingAxesConceptId   = null;
        $this->flashMessage           = null;
    }

    public function updateConcept(): void
    {
        $this->validate([
            'editConceptName'        => 'required|string|max:255',
            'editConceptDescription' => 'nullable|string|max:1000',
        ]);

        Concept::findOrFail($this->editingConceptId)->update([
            'name'        => $this->editConceptName,
            'description' => $this->editConceptDescription ?: null,
        ]);

        $this->cancelEditConcept();
        $this->flash('Concept updated.');
    }

    public function cancelEditConcept(): void
    {
        $this->editingConceptId       = null;
        $this->editConceptName        = '';
        $this->editConceptDescription = '';
    }

    public function deleteConcept(int $id): void
    {
        Concept::findOrFail($id)->delete();
        $this->flash('Concept deleted.');
    }

    public function openAxisMapping(int $conceptId): void
    {
        $concept = Concept::with('axes')->findOrFail($conceptId);
        $this->mappingAxesConceptId = $conceptId;
        $this->selectedAxisIds      = $concept->axes->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        $this->editingConceptId     = null;
        $this->flashMessage         = null;
    }

    public function saveAxisMapping(): void
    {
        $concept = Concept::findOrFail($this->mappingAxesConceptId);
        $concept->axes()->sync($this->selectedAxisIds);
        $this->cancelAxisMapping();
        $this->flash('Axis mapping saved.');
    }

    public function cancelAxisMapping(): void
    {
        $this->mappingAxesConceptId = null;
        $this->selectedAxisIds      = [];
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        return view('livewire.admin.content-manager')
            ->layout('layouts.app');
    }
}
