<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-5xl mx-auto space-y-6">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">{{ $this->category->name }}</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Browse and filter available modules.</p>
        </div>

        <x-context-bar-livewire :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" routeName="modules.index"/>

        {{-- Filters --}}
        <div class="linear-card p-4 space-y-3">
            {{-- Tags --}}
            <div class="flex flex-wrap gap-1.5">
                <button wire:click="$set('tagFilter', [])"
                        class="px-3 py-1 rounded-full text-[12px] font-medium transition-colors
                            {{ empty($tagFilter) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-muted hover:bg-surface-3 border border-line' }}">
                    All Tags
                </button>
                @foreach ($this->tags as $tag)
                    <button wire:click="toggleTag('{{ $tag->name }}')"
                            class="px-3 py-1 rounded-full text-[12px] font-medium transition-colors
                                {{ in_array($tag->name, $tagFilter) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-muted hover:bg-surface-3 border border-line' }}">
                        {{ $tag->name }}
                    </button>
                @endforeach
            </div>

            {{-- Status + Proficiency row --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex gap-1">
                    @foreach(['all' => 'All', 'completed' => 'Completed', 'in_progress' => 'In Progress'] as $val => $label)
                        <button wire:click="$set('statusFilter', '{{ $val }}')"
                                class="tab-btn {{ $statusFilter === $val ? 'tab-active' : 'tab-inactive' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <select wire:model.live="proficiencyFilter" class="form-select w-48">
                    <option value="">All Proficiencies</option>
                    @foreach($this->proficiencies as $prof)
                        <option value="{{ $prof->id }}">{{ $prof->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Module list --}}
        @if (!$this->modules->isEmpty())
            <div class="linear-card overflow-hidden">
                @foreach ($this->modules as $module)
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
                                        <span class="badge-green shrink-0">Completed</span>
                                    @elseif($status === 'in_progress')
                                        <span class="badge-amber shrink-0">In Progress</span>
                                    @elseif($status === 'not_started')
                                        <span class="badge-gray shrink-0">Not Started</span>
                                    @endif
                                    @if($module->status === 'preparing')
                                        <span class="badge-amber shrink-0">Preparing</span>
                                    @endif
                                </div>

                                @if($module->proficiencies->first())
                                    <p class="text-[11px] text-ink-subtle mb-1">{{ $module->proficiencies->first()->name }}</p>
                                @endif

                                @if($module->tags->isNotEmpty())
                                    <div class="flex flex-wrap gap-1 mb-1.5">
                                        @foreach($module->tags as $tag)
                                            <span class="badge-gray">{{ $tag->name }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                @if($module->description)
                                    <p class="text-[12px] text-ink-muted line-clamp-2">{{ $module->description }}</p>
                                @endif

                                @if($userModule && $userModule->pivot->score)
                                    <p class="text-[11px] text-ink-subtle mt-1">Score: {{ $userModule->pivot->score }}%</p>
                                @endif
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-2 shrink-0">
                                @if ($module->status === 'preparing')
                                    <div wire:poll.3s class="flex items-center gap-1.5 text-[12px] text-ink-subtle">
                                        <svg class="animate-spin w-3.5 h-3.5 text-amber-400" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                        </svg>
                                        Preparing
                                    </div>
                                @elseif (!$userModule && $module->status === 'ready')
                                    <form action="{{ route('modules.assign', $module->id) }}" method="POST">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                            Add
                                        </button>
                                    </form>
                                @endif

                                @if($status === 'completed')
                                    <a href="{{ route('modules.next-module', ['moduleId' => $module->id]) }}"
                                       class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-accent bg-accent/10 hover:bg-accent/20 rounded-md transition-colors">
                                        Next
                                    </a>
                                @endif

                                @if($status === 'not_started' || $status === 'in_progress')
                                    <a href="{{ route('questions.quiz.index', ['category_id' => request('category_id'), 'module_id' => $module->id]) }}"
                                       class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors opacity-0 group-hover:opacity-100">
                                        Quiz
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="linear-card px-5 py-16 text-center">
                <p class="text-[13px] text-ink-subtle">No modules match the selected filters.</p>
            </div>
        @endif
    </div>
</div>
