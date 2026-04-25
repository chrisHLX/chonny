<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-3xl mx-auto space-y-6">

            {{-- Header --}}
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Concepts</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Knowledge units within a subject. Questions are tagged to concepts to power mastery tracking.</p>
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
            <div class="space-y-2">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider">Category</p>
                <div class="flex items-center gap-2 flex-wrap">
                    @foreach($categories as $cat)
                        <a href="{{ route('admin.concepts.index', ['category_id' => $cat->id]) }}"
                           class="px-3 py-1.5 text-[12px] rounded-full border transition-colors
                                  {{ $cat->id == $categoryId ? 'bg-accent text-white border-accent' : 'bg-surface-2 text-ink-muted border-line hover:border-accent/50 hover:text-ink' }}">
                            {{ $cat->name }}
                        </a>
                    @endforeach
                </div>

                {{-- Subject filter --}}
                @if($subjects->isNotEmpty())
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider pt-1">Subject</p>
                    <div class="flex items-center gap-2 flex-wrap">
                        @foreach($subjects as $sub)
                            <a href="{{ route('admin.concepts.index', ['category_id' => $categoryId, 'subject_id' => $sub->id]) }}"
                               class="px-3 py-1.5 text-[12px] rounded-full border transition-colors
                                      {{ $sub->id == $subjectId ? 'bg-surface-3 text-ink border-ink/30' : 'bg-surface-2 text-ink-muted border-line hover:border-ink/20 hover:text-ink' }}">
                                {{ $sub->name }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($subjectId)
                {{-- Create form --}}
                <div class="linear-card p-5">
                    <p class="text-[12px] font-medium text-ink-muted mb-4 uppercase tracking-wider">
                        New Concept{{ $currentSubject ? " in {$currentSubject->name}" : '' }}
                    </p>
                    <form action="{{ route('admin.concepts.store') }}" method="POST" class="space-y-3">
                        @csrf
                        <input type="hidden" name="subject_id" value="{{ $subjectId }}">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label for="con_name" :value="'Name'" />
                                <x-text-input id="con_name" name="name" type="text" class="mt-1 block w-full"
                                              placeholder="e.g. Photosynthesis" :value="old('name')" required />
                            </div>
                            <div>
                                <x-input-label for="con_desc" :value="'Description (optional)'" />
                                <x-text-input id="con_desc" name="description" type="text" class="mt-1 block w-full"
                                              placeholder="Brief description" :value="old('description')" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <x-primary-button>Create Concept</x-primary-button>
                        </div>
                    </form>
                </div>

                {{-- Concepts list --}}
                <div class="linear-card overflow-hidden">
                    @forelse($concepts as $concept)
                        <div x-data="{ editing: false }"
                             class="{{ !$loop->last ? 'border-b border-line' : '' }}">

                            {{-- View row --}}
                            <div x-show="!editing"
                                 class="flex items-center justify-between gap-4 px-5 py-3.5 hover:bg-surface-2 transition-colors group">
                                <div class="flex-1 min-w-0">
                                    <p class="text-[14px] font-medium text-ink">{{ $concept->name }}</p>
                                    @if($concept->description)
                                        <p class="text-[12px] text-ink-subtle mt-0.5 line-clamp-1">{{ $concept->description }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <span class="text-[11px] text-ink-muted">
                                        {{ $concept->questions_count }} question{{ $concept->questions_count === 1 ? '' : 's' }}
                                    </span>
                                    <button @click="editing = true"
                                            class="text-[12px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover:opacity-100">
                                        Edit
                                    </button>
                                    <form action="{{ route('admin.concepts.destroy', $concept) }}" method="POST"
                                          onsubmit="return confirm('Delete concept \'{{ addslashes($concept->name) }}\'?')">
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
                                  action="{{ route('admin.concepts.update', $concept) }}" method="POST"
                                  class="px-5 py-4 bg-surface-2 space-y-3">
                                @csrf @method('PUT')
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <x-input-label :value="'Name'" />
                                        <x-text-input name="name" type="text" class="mt-1 block w-full"
                                                      value="{{ $concept->name }}" required />
                                    </div>
                                    <div>
                                        <x-input-label :value="'Description'" />
                                        <x-text-input name="description" type="text" class="mt-1 block w-full"
                                                      value="{{ $concept->description }}" />
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

                        </div>
                    @empty
                        <div class="px-5 py-12 text-center text-[13px] text-ink-subtle">
                            No concepts in this subject yet. Create one above.
                        </div>
                    @endforelse
                </div>
            @else
                <div class="linear-card px-5 py-12 text-center text-[13px] text-ink-subtle">
                    Select a category and subject above to manage its concepts.
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
