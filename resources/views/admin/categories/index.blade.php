<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-3xl mx-auto space-y-6">

            {{-- Header --}}
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-[17px] font-semibold text-ink">Categories</h1>
                    <p class="text-[13px] text-ink-muted mt-0.5">Top-level groupings. Each category contains subjects.</p>
                </div>
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

            {{-- Create form --}}
            <div class="linear-card p-5">
                <p class="text-[12px] font-medium text-ink-muted mb-4 uppercase tracking-wider">New Category</p>
                <form action="{{ route('admin.categories.store') }}" method="POST" class="space-y-3">
                    @csrf
                    <div>
                        <x-input-label for="cat_name" :value="'Name'" />
                        <x-text-input id="cat_name" name="name" type="text" class="mt-1 block w-full"
                                      placeholder="e.g. Science" :value="old('name')" required />
                    </div>
                    <div>
                        <x-input-label for="cat_desc" :value="'Description (optional)'" />
                        <textarea id="cat_desc" name="description" rows="2"
                                  class="mt-1 block w-full rounded-md bg-surface-1 border border-line text-[13px] text-ink placeholder-ink-subtle focus:outline-none focus:ring-1 focus:ring-accent px-3 py-2"
                                  placeholder="Brief description of this category">{{ old('description') }}</textarea>
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button>Create Category</x-primary-button>
                    </div>
                </form>
            </div>

            {{-- List --}}
            <div class="linear-card overflow-hidden">
                @forelse($categories as $category)
                    <div x-data="{ editing: false }"
                         class="{{ !$loop->last ? 'border-b border-line' : '' }}">

                        {{-- View row --}}
                        <div x-show="!editing" class="flex items-center justify-between gap-4 px-5 py-3.5 hover:bg-surface-2 transition-colors group">
                            <div class="flex-1 min-w-0">
                                <p class="text-[14px] font-medium text-ink">{{ $category->name }}</p>
                                @if($category->description)
                                    <p class="text-[12px] text-ink-subtle mt-0.5 line-clamp-1">{{ $category->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span class="text-[11px] text-ink-muted">{{ $category->subjects_count }} subject{{ $category->subjects_count === 1 ? '' : 's' }}</span>
                                <button @click="editing = true"
                                        class="text-[12px] text-ink-muted hover:text-ink transition-colors opacity-0 group-hover:opacity-100">
                                    Edit
                                </button>
                                <form action="{{ route('admin.categories.destroy', $category) }}" method="POST"
                                      onsubmit="return confirm('Delete category \'{{ addslashes($category->name) }}\'? This will also delete all its subjects, concepts and proficiencies.')">
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
                              action="{{ route('admin.categories.update', $category) }}" method="POST"
                              class="px-5 py-4 bg-surface-2 space-y-3">
                            @csrf @method('PUT')
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label :value="'Name'" />
                                    <x-text-input name="name" type="text" class="mt-1 block w-full"
                                                  value="{{ $category->name }}" required />
                                </div>
                                <div>
                                    <x-input-label :value="'Description'" />
                                    <x-text-input name="description" type="text" class="mt-1 block w-full"
                                                  value="{{ $category->description }}" />
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
                        No categories yet. Create one above.
                    </div>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
