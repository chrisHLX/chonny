<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-5xl mx-auto">

            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-[17px] font-semibold text-ink">Modules</h1>
                    <p class="text-[13px] text-ink-muted mt-0.5">Browse and manage your learning modules.</p>
                </div>
            </div>

            <x-context-bar :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" />

            <div class="linear-card overflow-hidden">
                @forelse ($modules as $module)
                    @php
                        $userModule = $module->users->first();
                        $status = $userModule?->pivot->status;
                    @endphp
                    <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-4 hover:bg-surface-2 transition-colors group">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <h2 class="text-[14px] font-medium text-ink truncate">{{ $module->name }}</h2>
                                    @if($status === 'completed')
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-medium shrink-0">Completed</span>
                                    @elseif($status === 'in_progress')
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 font-medium shrink-0">In Progress</span>
                                    @endif
                                </div>

                                @if($module->proficiencies->first())
                                    <p class="text-[11px] text-ink-subtle mb-1">Level: {{ $module->proficiencies->first()->name }}</p>
                                @endif

                                @if($module->description)
                                    <p class="text-[13px] text-ink-muted line-clamp-2">{{ $module->description }}</p>
                                @endif

                                @if($module->users->isNotEmpty())
                                    <div class="flex flex-wrap gap-1.5 mt-2">
                                        @foreach ($module->users as $user)
                                            <span class="text-[11px] px-2 py-0.5 bg-surface-3 text-ink-muted rounded-full">
                                                {{ $user->name }}: {{ $user->pivot->score }}%
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 shrink-0 mt-0.5">
                                @if ($module->users->isEmpty())
                                    <form action="{{ route('modules.assign', $module) }}" method="POST">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                            Add
                                        </button>
                                    </form>
                                    @if ($module->created_by === Auth::id())
                                        <button class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                                            Delete
                                        </button>
                                    @endif
                                @endif

                                @if ($module->modulePages && !$module->modulePages->isEmpty())
                                    <form action="{{ route('modules.page', $module) }}" method="GET">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors opacity-0 group-hover:opacity-100">
                                            View
                                        </button>
                                    </form>
                                @endif

                                @if($status === 'completed')
                                    <form action="{{ route('modules.next-module', ['moduleId' => $module->id]) }}" method="GET">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-accent bg-accent/10 hover:bg-accent/20 rounded-md transition-colors">
                                            Next
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-16 text-center">
                        <p class="text-[13px] text-ink-subtle">No modules available.</p>
                    </div>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
