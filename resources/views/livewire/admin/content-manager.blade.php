<div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
    <div class="max-w-4xl mx-auto space-y-6">

        {{-- Header --}}
        <div>
            <h1 class="text-[17px] font-semibold text-ink">Content Manager</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Manage axes, categories, subjects, and concepts.</p>
        </div>

        {{-- Flash --}}
        @if($flashMessage)
            <div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-[12px] text-emerald-400">
                {{ $flashMessage }}
            </div>
        @endif

        @if($errors->any())
            <div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/20 text-[12px] text-red-400">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Section tabs --}}
        <div class="flex items-center gap-1 border-b border-line">
            @foreach(['axes' => 'Axes', 'categories' => 'Categories', 'subjects' => 'Subjects', 'concepts' => 'Concepts'] as $key => $label)
                <button wire:click="$set('section', '{{ $key }}')"
                        class="px-4 py-2.5 text-[13px] font-medium transition-colors border-b-2 -mb-px
                               {{ $section === $key
                                    ? 'text-ink border-accent'
                                    : 'text-ink-muted border-transparent hover:text-ink hover:border-line' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Category filter (shared across tabs) --}}
        @if(in_array($section, ['axes', 'subjects', 'concepts']))
            <div class="space-y-2">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider">Category</p>
                <div class="flex items-center gap-2 flex-wrap">
                    @foreach($this->categories as $cat)
                        <button wire:click="$set('filterCategoryId', {{ $cat->id }})"
                                class="px-3 py-1.5 text-[12px] rounded-full border transition-colors
                                       {{ $cat->id == $filterCategoryId
                                            ? 'bg-accent text-white border-accent'
                                            : 'bg-surface-2 text-ink-muted border-line hover:border-accent/50 hover:text-ink' }}">
                            {{ $cat->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Subject filter (concepts tab only) --}}
        @if($section === 'concepts' && $filterCategoryId)
            <div class="space-y-2">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider">Subject</p>
                <div class="flex items-center gap-2 flex-wrap">
                    @foreach($this->subjects as $sub)
                        <button wire:click="$set('filterSubjectId', {{ $sub->id }})"
                                class="px-3 py-1.5 text-[12px] rounded-full border transition-colors
                                       {{ $sub->id == $filterSubjectId
                                            ? 'bg-surface-3 text-ink border-ink/30'
                                            : 'bg-surface-2 text-ink-muted border-line hover:border-ink/20 hover:text-ink' }}">
                            {{ $sub->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ════════════════════ AXES ════════════════════ --}}
        @if($section === 'axes')

            {{-- Create form --}}
            <div class="linear-card p-5">
                <p class="text-[12px] font-medium text-ink-muted mb-4 uppercase tracking-wider">New Axis</p>
                <div class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <x-input-label :value="'Name'" />
                            <x-text-input wire:model="newAxisName" type="text" class="mt-1 block w-full"
                                          placeholder="e.g. Critical Thinking" />
                        </div>
                        <div>
                            <x-input-label :value="'Description (optional)'" />
                            <x-text-input wire:model="newAxisDescription" type="text" class="mt-1 block w-full"
                                          placeholder="Brief description" />
                        </div>
                        <div>
                            <x-input-label :value="'Category'" />
                            <select wire:model="newAxisCategoryId"
                                    class="mt-1 block w-full rounded-md bg-surface-1 border border-line text-[13px] text-ink focus:outline-none focus:ring-1 focus:ring-accent px-3 py-2">
                                <option value="">Select category</option>
                                @foreach($this->categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button wire:click="createAxis">Create Axis</x-primary-button>
                    </div>
                </div>
            </div>

            {{-- Axes list grouped by category --}}
            @forelse($this->axes->groupBy(fn ($a) => $a->category->name) as $categoryName => $groupedAxes)
                <div class="space-y-1">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider px-1">{{ $categoryName }}</p>
                    <div class="linear-card overflow-hidden">
                        @foreach($groupedAxes as $axis)
                            <div class="{{ !$loop->last ? 'border-b border-line' : '' }}">

                                @if($editingAxisId === $axis->id)
                                    {{-- Edit row --}}
                                    <div class="px-5 py-4 bg-surface-2 space-y-3">
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <div>
                                                <x-input-label :value="'Name'" />
                                                <x-text-input wire:model="editAxisName" type="text" class="mt-1 block w-full" required />
                                            </div>
                                            <div>
                                                <x-input-label :value="'Description'" />
                                                <x-text-input wire:model="editAxisDescription" type="text" class="mt-1 block w-full" />
                                            </div>
                                            <div>
                                                <x-input-label :value="'Category'" />
                                                <select wire:model="editAxisCategoryId"
                                                        class="mt-1 block w-full rounded-md bg-surface-1 border border-line text-[13px] text-ink focus:outline-none focus:ring-1 focus:ring-accent px-3 py-2">
                                                    @foreach($this->categories as $cat)
                                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 justify-end">
                                            <button wire:click="cancelEditAxis"
                                                    class="px-3 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                                Cancel
                                            </button>
                                            <x-primary-button wire:click="updateAxis">Save</x-primary-button>
                                        </div>
                                    </div>
                                @else
                                    {{-- View row --}}
                                    <div class="flex items-center justify-between gap-4 px-5 py-3.5 hover:bg-surface-2 transition-colors group">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[14px] font-medium text-ink">{{ $axis->name }}</p>
                                            @if($axis->description)
                                                <p class="text-[12px] text-ink-subtle mt-0.5 line-clamp-1">{{ $axis->description }}</p>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-3 shrink-0">
                                            <button wire:click="startEditAxis({{ $axis->id }})"
                                                    class="text-[12px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover:opacity-100">
                                                Edit
                                            </button>
                                            <button wire:click="deleteAxis({{ $axis->id }})"
                                                    onclick="return confirm('Delete axis \'{{ addslashes($axis->name) }}\'?')"
                                                    class="text-[12px] text-red-400 hover:text-red-300 transition-colors opacity-0 group-hover:opacity-100">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                @endif

                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="linear-card px-5 py-12 text-center text-[13px] text-ink-subtle">
                    No axes yet. Create one above.
                </div>
            @endforelse

        @endif

        {{-- ════════════════════ CATEGORIES ════════════════════ --}}
        @if($section === 'categories')

            {{-- Create form --}}
            <div class="linear-card p-5">
                <p class="text-[12px] font-medium text-ink-muted mb-4 uppercase tracking-wider">New Category</p>
                <div class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label :value="'Name'" />
                            <x-text-input wire:model="newCatName" type="text" class="mt-1 block w-full"
                                          placeholder="e.g. Science" />
                        </div>
                        <div>
                            <x-input-label :value="'Description (optional)'" />
                            <x-text-input wire:model="newCatDescription" type="text" class="mt-1 block w-full"
                                          placeholder="Brief description" />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button wire:click="createCategory">Create Category</x-primary-button>
                    </div>
                </div>
            </div>

            {{-- Categories list --}}
            <div class="linear-card overflow-hidden">
                @forelse($this->categories as $category)
                    <div class="{{ !$loop->last ? 'border-b border-line' : '' }}">

                        @if($editingCategoryId === $category->id)
                            {{-- Edit row --}}
                            <div class="px-5 py-4 bg-surface-2 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <x-input-label :value="'Name'" />
                                        <x-text-input wire:model="editCatName" type="text" class="mt-1 block w-full" required />
                                    </div>
                                    <div>
                                        <x-input-label :value="'Description'" />
                                        <x-text-input wire:model="editCatDescription" type="text" class="mt-1 block w-full" />
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 justify-end">
                                    <button wire:click="cancelEditCategory"
                                            class="px-3 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                        Cancel
                                    </button>
                                    <x-primary-button wire:click="updateCategory">Save</x-primary-button>
                                </div>
                            </div>
                        @else
                            {{-- View row --}}
                            <div class="flex items-center justify-between gap-4 px-5 py-3.5 hover:bg-surface-2 transition-colors group">
                                <div class="flex-1 min-w-0">
                                    <p class="text-[14px] font-medium text-ink">{{ $category->name }}</p>
                                    @if($category->description)
                                        <p class="text-[12px] text-ink-subtle mt-0.5 line-clamp-1">{{ $category->description }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <span class="text-[11px] text-ink-muted">
                                        {{ $category->subjects_count }} subject{{ $category->subjects_count === 1 ? '' : 's' }}
                                    </span>
                                    <button wire:click="startEditCategory({{ $category->id }})"
                                            class="text-[12px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover:opacity-100">
                                        Edit
                                    </button>
                                    <button wire:click="deleteCategory({{ $category->id }})"
                                            onclick="return confirm('Delete category \'{{ addslashes($category->name) }}\'? This will also delete all its subjects, concepts, and axes.')"
                                            class="text-[12px] text-red-400 hover:text-red-300 transition-colors opacity-0 group-hover:opacity-100">
                                        Delete
                                    </button>
                                </div>
                            </div>
                        @endif

                    </div>
                @empty
                    <div class="px-5 py-12 text-center text-[13px] text-ink-subtle">
                        No categories yet. Create one above.
                    </div>
                @endforelse
            </div>

        @endif

        {{-- ════════════════════ SUBJECTS ════════════════════ --}}
        @if($section === 'subjects')

            @if($filterCategoryId)
                {{-- Create form --}}
                <div class="linear-card p-5">
                    <p class="text-[12px] font-medium text-ink-muted mb-4 uppercase tracking-wider">
                        New Subject
                    </p>
                    <div class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label :value="'Name'" />
                                <x-text-input wire:model="newSubName" type="text" class="mt-1 block w-full"
                                              placeholder="e.g. Organic Chemistry" />
                            </div>
                            <div>
                                <x-input-label :value="'Description (optional)'" />
                                <x-text-input wire:model="newSubDescription" type="text" class="mt-1 block w-full"
                                              placeholder="Brief description" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <x-primary-button wire:click="createSubject">Create Subject</x-primary-button>
                        </div>
                    </div>
                </div>

                {{-- Subjects list --}}
                @if($this->subjects->isEmpty())
                    <div class="linear-card px-5 py-12 text-center text-[13px] text-ink-subtle">
                        No subjects in this category yet. Create one above.
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($this->subjects as $subject)
                            <div class="linear-card overflow-hidden">

                                @if($editingSubjectId === $subject->id)
                                    {{-- Edit row --}}
                                    <div class="px-5 py-4 bg-surface-2 space-y-3">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <x-input-label :value="'Name'" />
                                                <x-text-input wire:model="editSubName" type="text" class="mt-1 block w-full" required />
                                            </div>
                                            <div>
                                                <x-input-label :value="'Description'" />
                                                <x-text-input wire:model="editSubDescription" type="text" class="mt-1 block w-full" />
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 justify-end">
                                            <button wire:click="cancelEditSubject"
                                                    class="px-3 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                                Cancel
                                            </button>
                                            <x-primary-button wire:click="updateSubject">Save</x-primary-button>
                                        </div>
                                    </div>
                                @else
                                    {{-- View row --}}
                                    <div class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-surface-2 transition-colors group">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[14px] font-medium text-ink">{{ $subject->name }}</p>
                                            @if($subject->description)
                                                <p class="text-[12px] text-ink-subtle mt-0.5 line-clamp-1">{{ $subject->description }}</p>
                                            @endif
                                            <div class="flex items-center gap-3 mt-1 text-[11px] text-ink-muted">
                                                <span>{{ $subject->concepts_count }} concept{{ $subject->concepts_count === 1 ? '' : 's' }}</span>
                                                <span class="text-ink-subtle">·</span>
                                                <span>{{ $subject->modules_count }} module{{ $subject->modules_count === 1 ? '' : 's' }}</span>
                                                <span class="text-ink-subtle">·</span>
                                                <span>{{ $subject->proficiencies->count() }} proficiency tier{{ $subject->proficiencies->count() === 1 ? '' : 's' }}</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <button wire:click="toggleProficiencies({{ $subject->id }})"
                                                    class="px-2.5 py-1.5 text-[12px] bg-surface-2 hover:bg-surface-3 border rounded-md transition-colors
                                                           {{ $openProfSubjectId === $subject->id ? 'text-accent border-accent/30' : 'text-ink-muted border-line' }}">
                                                Proficiencies
                                            </button>
                                            <button wire:click="startEditSubject({{ $subject->id }})"
                                                    class="text-[12px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover:opacity-100">
                                                Edit
                                            </button>
                                            <button wire:click="deleteSubject({{ $subject->id }})"
                                                    onclick="return confirm('Delete subject \'{{ addslashes($subject->name) }}\'? This will also delete all its concepts and proficiencies.')"
                                                    class="text-[12px] text-red-400 hover:text-red-300 transition-colors opacity-0 group-hover:opacity-100">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                @endif

                                {{-- Proficiencies panel --}}
                                @if($openProfSubjectId === $subject->id)
                                    <div class="border-t border-line bg-surface-2 px-5 py-4 space-y-3">
                                        <p class="text-[11px] font-medium text-ink-muted uppercase tracking-wider">
                                            Proficiency Tiers — ordered by index (0 = beginner)
                                        </p>

                                        @if($subject->proficiencies->isEmpty())
                                            <p class="text-[12px] text-ink-subtle italic">No proficiency tiers yet.</p>
                                        @else
                                            <div class="space-y-1.5">
                                                @foreach($subject->proficiencies as $prof)
                                                    @if($editingProfId === $prof->id)
                                                        {{-- Edit proficiency --}}
                                                        <div class="flex items-end gap-2 px-3 py-2 rounded-md bg-surface-3">
                                                            <div>
                                                                <x-input-label :value="'Index'" />
                                                                <x-text-input wire:model="editProfIndex" type="number" class="mt-1 w-16 block" min="0" required />
                                                            </div>
                                                            <div class="flex-1">
                                                                <x-input-label :value="'Name'" />
                                                                <x-text-input wire:model="editProfName" type="text" class="mt-1 block w-full" required />
                                                            </div>
                                                            <div class="flex-1">
                                                                <x-input-label :value="'Description'" />
                                                                <x-text-input wire:model="editProfDescription" type="text" class="mt-1 block w-full" />
                                                            </div>
                                                            <div class="flex gap-2">
                                                                <button wire:click="cancelEditProficiency"
                                                                        class="px-2.5 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                                                    Cancel
                                                                </button>
                                                                <x-primary-button wire:click="updateProficiency">Save</x-primary-button>
                                                            </div>
                                                        </div>
                                                    @else
                                                        {{-- View proficiency --}}
                                                        <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md bg-surface-3 group/prof">
                                                            <div class="flex items-center gap-3 min-w-0">
                                                                <span class="text-[10px] font-mono text-ink-subtle bg-surface-0 px-1.5 py-0.5 rounded shrink-0">
                                                                    {{ $prof->index }}
                                                                </span>
                                                                <span class="text-[13px] text-ink">{{ $prof->name }}</span>
                                                                @if($prof->description)
                                                                    <span class="text-[12px] text-ink-subtle truncate">{{ $prof->description }}</span>
                                                                @endif
                                                            </div>
                                                            <div class="flex items-center gap-2 shrink-0">
                                                                <button wire:click="startEditProficiency({{ $prof->id }})"
                                                                        class="text-[11px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover/prof:opacity-100">
                                                                    Edit
                                                                </button>
                                                                <button wire:click="deleteProficiency({{ $prof->id }})"
                                                                        onclick="return confirm('Delete proficiency \'{{ addslashes($prof->name) }}\'?')"
                                                                        class="text-[11px] text-red-400 hover:text-red-300 transition-colors opacity-0 group-hover/prof:opacity-100">
                                                                    Delete
                                                                </button>
                                                            </div>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Add proficiency --}}
                                        <div class="flex items-end gap-2 pt-2 border-t border-line">
                                            <div>
                                                <x-input-label :value="'Index'" />
                                                <x-text-input wire:model="newProfIndex" type="number" class="mt-1 w-16 block" min="0" placeholder="0" />
                                            </div>
                                            <div class="flex-1">
                                                <x-input-label :value="'Name'" />
                                                <x-text-input wire:model="newProfName" type="text" class="mt-1 block w-full" placeholder="e.g. Beginner" />
                                            </div>
                                            <div class="flex-1">
                                                <x-input-label :value="'Description (optional)'" />
                                                <x-text-input wire:model="newProfDescription" type="text" class="mt-1 block w-full" placeholder="Optional" />
                                            </div>
                                            <x-primary-button wire:click="createProficiency({{ $subject->id }})">Add</x-primary-button>
                                        </div>
                                    </div>
                                @endif

                            </div>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="linear-card px-5 py-12 text-center text-[13px] text-ink-subtle">
                    Select a category above to manage its subjects.
                </div>
            @endif

        @endif

        {{-- ════════════════════ CONCEPTS ════════════════════ --}}
        @if($section === 'concepts')

            @if($filterSubjectId)
                {{-- Create form --}}
                <div class="linear-card p-5">
                    <p class="text-[12px] font-medium text-ink-muted mb-4 uppercase tracking-wider">New Concept</p>
                    <div class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label :value="'Name'" />
                                <x-text-input wire:model="newConceptName" type="text" class="mt-1 block w-full"
                                              placeholder="e.g. Photosynthesis" />
                            </div>
                            <div>
                                <x-input-label :value="'Description (optional)'" />
                                <x-text-input wire:model="newConceptDescription" type="text" class="mt-1 block w-full"
                                              placeholder="Brief description" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <x-primary-button wire:click="createConcept">Create Concept</x-primary-button>
                        </div>
                    </div>
                </div>

                {{-- Concepts list --}}
                <div class="linear-card overflow-hidden">
                    @forelse($this->concepts as $concept)
                        <div class="{{ !$loop->last ? 'border-b border-line' : '' }}">

                            @if($editingConceptId === $concept->id)
                                {{-- Edit concept --}}
                                <div class="px-5 py-4 bg-surface-2 space-y-3">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <x-input-label :value="'Name'" />
                                            <x-text-input wire:model="editConceptName" type="text" class="mt-1 block w-full" required />
                                        </div>
                                        <div>
                                            <x-input-label :value="'Description'" />
                                            <x-text-input wire:model="editConceptDescription" type="text" class="mt-1 block w-full" />
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 justify-end">
                                        <button wire:click="cancelEditConcept"
                                                class="px-3 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                            Cancel
                                        </button>
                                        <x-primary-button wire:click="updateConcept">Save</x-primary-button>
                                    </div>
                                </div>

                            @elseif($mappingAxesConceptId === $concept->id)
                                {{-- Axis mapping panel --}}
                                <div class="px-5 py-4 bg-surface-2 space-y-4">
                                    <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">
                                        Map Axes — {{ $concept->name }}
                                    </p>

                                    @if($this->axisOptionsForMapping->isEmpty())
                                        <p class="text-[13px] text-ink-subtle italic">
                                            No axes exist for this category yet. Create some in the Axes tab first.
                                        </p>
                                    @else
                                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                            @foreach($this->axisOptionsForMapping as $axis)
                                                <label class="flex items-center gap-2.5 px-3 py-2 rounded-md bg-surface-3 cursor-pointer hover:bg-surface-3/80 transition-colors">
                                                    <input type="checkbox"
                                                           wire:model="selectedAxisIds"
                                                           value="{{ $axis->id }}"
                                                           class="rounded border-line bg-surface-1 text-accent focus:ring-accent" />
                                                    <span class="text-[13px] text-ink">{{ $axis->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="flex items-center gap-2 justify-end">
                                        <button wire:click="cancelAxisMapping"
                                                class="px-3 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                            Cancel
                                        </button>
                                        <x-primary-button wire:click="saveAxisMapping">Save Mapping</x-primary-button>
                                    </div>
                                </div>

                            @else
                                {{-- View row --}}
                                <div class="flex items-center justify-between gap-4 px-5 py-3.5 hover:bg-surface-2 transition-colors group">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[14px] font-medium text-ink">{{ $concept->name }}</p>
                                        @if($concept->description)
                                            <p class="text-[12px] text-ink-subtle mt-0.5 line-clamp-1">{{ $concept->description }}</p>
                                        @endif
                                        @if($concept->axes->isNotEmpty())
                                            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                                @foreach($concept->axes as $axis)
                                                    <span class="px-1.5 py-0.5 text-[10px] rounded bg-accent/10 text-accent border border-accent/20">
                                                        {{ $axis->name }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3 shrink-0">
                                        <span class="text-[11px] text-ink-muted">
                                            {{ $concept->questions_count }} question{{ $concept->questions_count === 1 ? '' : 's' }}
                                        </span>
                                        <button wire:click="openAxisMapping({{ $concept->id }})"
                                                class="text-[12px] text-ink-muted hover:text-accent transition-colors opacity-0 group-hover:opacity-100">
                                            Axes
                                        </button>
                                        <button wire:click="startEditConcept({{ $concept->id }})"
                                                class="text-[12px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover:opacity-100">
                                            Edit
                                        </button>
                                        <button wire:click="deleteConcept({{ $concept->id }})"
                                                onclick="return confirm('Delete concept \'{{ addslashes($concept->name) }}\'?')"
                                                class="text-[12px] text-red-400 hover:text-red-300 transition-colors opacity-0 group-hover:opacity-100">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            @endif

                        </div>
                    @empty
                        <div class="px-5 py-12 text-center text-[13px] text-ink-subtle">
                            No concepts in this subject yet. Create one above.
                        </div>
                    @endforelse
                </div>

            @elseif($filterCategoryId)
                <div class="linear-card px-5 py-12 text-center text-[13px] text-ink-subtle">
                    Select a subject above to manage its concepts.
                </div>
            @else
                <div class="linear-card px-5 py-12 text-center text-[13px] text-ink-subtle">
                    Select a category and subject above to manage concepts.
                </div>
            @endif

        @endif

    </div>
</div>
