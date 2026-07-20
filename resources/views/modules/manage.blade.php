<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-5xl mx-auto">

            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-[17px] font-semibold text-ink">My Modules</h1>
                    <p class="text-[13px] text-ink-muted mt-0.5">Modules you've created. Click Edit to manage content and questions.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('modules.upload') }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        Upload Module
                    </a>
                    <a href="{{ route('modules.create') }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        New Module
                    </a>
                </div>
            </div>

            <div class="linear-card overflow-hidden">
                @forelse ($modules as $module)
                    <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-4 hover:bg-surface-2 transition-colors group">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <h2 class="text-[14px] font-medium text-ink truncate">{{ $module->name }}</h2>

                                    @if($module->status === 'ready')
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-medium shrink-0">Ready</span>
                                    @elseif($module->status === 'preparing')
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 font-medium shrink-0">Preparing</span>
                                    @else
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-surface-3 text-ink-subtle font-medium shrink-0">Draft</span>
                                    @endif

                                    @if($module->published)
                                        <span class="badge-gold shrink-0">Published</span>
                                    @endif
                                </div>

                                <div class="flex items-center gap-3 text-[12px] text-ink-subtle">
                                    @if($module->subject)
                                        <span>{{ $module->subject->name }}</span>
                                    @endif
                                    @if($module->proficiencies->first())
                                        <span class="text-ink-muted">·</span>
                                        <span>{{ $module->proficiencies->first()->name }}</span>
                                    @endif
                                    <span class="text-ink-muted">·</span>
                                    <span>{{ $module->questions->count() }} question{{ $module->questions->count() === 1 ? '' : 's' }}</span>
                                    <span class="text-ink-muted">·</span>
                                    <span>{{ $module->modulePages->count() }} page{{ $module->modulePages->count() === 1 ? '' : 's' }}</span>
                                </div>

                                @if($module->description)
                                    <p class="text-[13px] text-ink-muted mt-1 line-clamp-1">{{ $module->description }}</p>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <a href="{{ route('modules.show', $module) }}"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Try Module
                                </a>

                                <a href="{{ route('modules.edit', $module) }}"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    Edit
                                </a>

                                <form action="{{ route('modules.destroy', $module) }}" method="POST"
                                      onsubmit="return confirm('Delete {{ addslashes($module->name) }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/20 rounded-md transition-colors opacity-0 group-hover:opacity-100">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-16 text-center">
                        <p class="text-[13px] text-ink-subtle">You haven't created any modules yet.</p>
                        <a href="{{ route('modules.create') }}"
                           class="inline-flex items-center gap-1.5 mt-4 px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                            Create your first module
                        </a>
                    </div>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
