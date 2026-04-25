<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-4xl mx-auto space-y-6">

            {{-- Header --}}
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Subjects</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Subjects belong to a category and define what concepts and proficiency tiers are available to modules.</p>
            </div>

            {{-- Flash --}}
            @if(session('success'))
                <div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-[12px] text-emerald-400">
                    {{ session('success') }}
                </div>
            @endif
            @if($errors->any())
                <div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/20 text-[12px] text-red-400">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- Category filter --}}
            <div class="flex items-center gap-2 flex-wrap">
                @foreach($categories as $cat)
                    <a href="{{ route('admin.subjects.index', ['category_id' => $cat->id]) }}"
                       class="px-3 py-1.5 text-[12px] rounded-full border transition-colors
                              {{ $cat->id == $categoryId ? 'bg-accent text-white border-accent' : 'bg-surface-2 text-ink-muted border-line hover:border-accent/50 hover:text-ink' }}">
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>

            {{-- Create form --}}
            <div class="linear-card p-5">
                <p class="text-[12px] font-medium text-ink-muted mb-4 uppercase tracking-wider">
                    New Subject{{ $currentCategory ? " in {$currentCategory->name}" : '' }}
                </p>
                <form action="{{ route('admin.subjects.store') }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="hidden" name="category_id" value="{{ $categoryId }}">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="sub_name" :value="'Name'" />
                            <x-text-input id="sub_name" name="name" type="text" class="mt-1 block w-full"
                                          placeholder="e.g. Organic Chemistry" :value="old('name')" required />
                        </div>
                        <div>
                            <x-input-label for="sub_desc" :value="'Description (optional)'" />
                            <x-text-input id="sub_desc" name="description" type="text" class="mt-1 block w-full"
                                          placeholder="Brief description" :value="old('description')" />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button>Create Subject</x-primary-button>
                    </div>
                </form>
            </div>

            {{-- Subjects list --}}
            @if($subjects->isEmpty())
                <div class="linear-card px-5 py-12 text-center text-[13px] text-ink-subtle">
                    No subjects in this category yet. Create one above.
                </div>
            @else
                <div class="space-y-3">
                    @foreach($subjects as $subject)
                        <div x-data="{ editing: false, profsOpen: false }" class="linear-card overflow-hidden">

                            {{-- View row --}}
                            <div x-show="!editing"
                                 class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-surface-2 transition-colors group">
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
                                    <button @click="profsOpen = !profsOpen"
                                            class="px-2.5 py-1.5 text-[12px] text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors"
                                            :class="profsOpen ? 'text-accent border-accent/30' : ''">
                                        Proficiencies
                                    </button>
                                    <button @click="editing = true"
                                            class="text-[12px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover:opacity-100">
                                        Edit
                                    </button>
                                    <form action="{{ route('admin.subjects.destroy', $subject) }}" method="POST"
                                          onsubmit="return confirm('Delete subject \'{{ addslashes($subject->name) }}\'? This will also delete all its concepts and proficiencies.')">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                class="text-[12px] text-red-400 hover:text-red-300 transition-colors opacity-0 group-hover:opacity-100">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>

                            {{-- Edit row --}}
                            <form x-show="editing" x-cloak
                                  action="{{ route('admin.subjects.update', $subject) }}" method="POST"
                                  class="px-5 py-4 bg-surface-2 space-y-3 border-b border-line">
                                @csrf @method('PUT')
                                <input type="hidden" name="category_id" value="{{ $subject->category_id }}">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <x-input-label :value="'Name'" />
                                        <x-text-input name="name" type="text" class="mt-1 block w-full"
                                                      value="{{ $subject->name }}" required />
                                    </div>
                                    <div>
                                        <x-input-label :value="'Description'" />
                                        <x-text-input name="description" type="text" class="mt-1 block w-full"
                                                      value="{{ $subject->description }}" />
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 justify-end">
                                    <button type="button" @click="editing = false"
                                            class="px-3 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                        Cancel
                                    </button>
                                    <x-primary-button>Save</x-primary-button>
                                </div>
                            </form>

                            {{-- Proficiencies panel --}}
                            <div x-show="profsOpen" x-cloak class="border-t border-line bg-surface-2 px-5 py-4 space-y-3">
                                <p class="text-[11px] font-medium text-ink-muted uppercase tracking-wider">
                                    Proficiency Tiers — ordered by index (0 = beginner)
                                </p>

                                {{-- Existing proficiencies --}}
                                @if($subject->proficiencies->isEmpty())
                                    <p class="text-[12px] text-ink-subtle italic">No proficiency tiers yet.</p>
                                @else
                                    <div class="space-y-1.5">
                                        @foreach($subject->proficiencies as $prof)
                                            <div x-data="{ editingProf: false }">
                                                {{-- View --}}
                                                <div x-show="!editingProf"
                                                     class="flex items-center justify-between gap-3 px-3 py-2 rounded-md bg-surface-3 group/prof">
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
                                                        <button @click="editingProf = true"
                                                                class="text-[11px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover/prof:opacity-100">
                                                            Edit
                                                        </button>
                                                        <form action="{{ route('admin.proficiencies.destroy', $prof) }}" method="POST"
                                                              onsubmit="return confirm('Delete proficiency \'{{ addslashes($prof->name) }}\'?')">
                                                            @csrf @method('DELETE')
                                                            <button type="submit"
                                                                    class="text-[11px] text-red-400 hover:text-red-300 transition-colors opacity-0 group-hover/prof:opacity-100">
                                                                Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                                {{-- Edit --}}
                                                <form x-show="editingProf" x-cloak
                                                      action="{{ route('admin.proficiencies.update', $prof) }}" method="POST"
                                                      class="flex items-end gap-2 px-3 py-2 rounded-md bg-surface-3">
                                                    @csrf @method('PUT')
                                                    <div>
                                                        <x-input-label :value="'Index'" />
                                                        <x-text-input name="index" type="number" class="mt-1 w-16 block"
                                                                      value="{{ $prof->index }}" min="0" required />
                                                    </div>
                                                    <div class="flex-1">
                                                        <x-input-label :value="'Name'" />
                                                        <x-text-input name="name" type="text" class="mt-1 block w-full"
                                                                      value="{{ $prof->name }}" required />
                                                    </div>
                                                    <div class="flex-1">
                                                        <x-input-label :value="'Description'" />
                                                        <x-text-input name="description" type="text" class="mt-1 block w-full"
                                                                      value="{{ $prof->description }}" />
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <button type="button" @click="editingProf = false"
                                                                class="px-2.5 py-1.5 text-[12px] text-ink-muted hover:text-ink transition-colors">
                                                            Cancel
                                                        </button>
                                                        <x-primary-button>Save</x-primary-button>
                                                    </div>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Add proficiency --}}
                                <form action="{{ route('admin.proficiencies.store') }}" method="POST"
                                      class="flex items-end gap-2 pt-2 border-t border-line">
                                    @csrf
                                    <input type="hidden" name="subject_id" value="{{ $subject->id }}">
                                    <div>
                                        <x-input-label :value="'Index'" />
                                        <x-text-input name="index" type="number" class="mt-1 w-16 block"
                                                      placeholder="0" min="0" value="{{ $subject->proficiencies->count() }}" required />
                                    </div>
                                    <div class="flex-1">
                                        <x-input-label :value="'Name'" />
                                        <x-text-input name="name" type="text" class="mt-1 block w-full"
                                                      placeholder="e.g. Beginner" required />
                                    </div>
                                    <div class="flex-1">
                                        <x-input-label :value="'Description (optional)'" />
                                        <x-text-input name="description" type="text" class="mt-1 block w-full"
                                                      placeholder="Optional" />
                                    </div>
                                    <x-primary-button>Add</x-primary-button>
                                </form>
                            </div>

                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
