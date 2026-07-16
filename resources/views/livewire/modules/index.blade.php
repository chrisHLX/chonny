<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-5xl mx-auto space-y-6">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">{{ $this->category->name }}</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Browse and filter available guides.</p>
        </div>

        <x-context-bar-livewire :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" routeName="modules.index"/>

        {{-- Filters --}}
        <div class="linear-card p-4 space-y-3">
            {{-- Concepts --}}
            <div class="flex flex-wrap gap-1.5">
                <button wire:click="$set('selectedConcepts', [])"
                        class="px-3 py-1 rounded-full text-[12px] font-medium transition-colors
                            {{ empty($selectedConcepts) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-muted hover:bg-surface-3 border border-line' }}">
                    All Topics
                </button>
                @foreach ($this->concepts as $concept)
                    <button wire:click="toggleConcept({{ $concept->id }})"
                            class="px-3 py-1 rounded-full text-[12px] font-medium transition-colors
                                {{ in_array($concept->id, $selectedConcepts) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-muted hover:bg-surface-3 border border-line' }}">
                        {{ $concept->name }}
                    </button>
                @endforeach
            </div>

            {{-- Status + Proficiency row --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex gap-1">
                    <button wire:click="$set('statusFilter', 'all')"
                            class="tab-btn {{ $statusFilter === 'all' ? 'tab-active' : 'tab-inactive' }}">
                        All
                    </button>
                    @auth
                    @foreach(['completed' => 'Completed', 'in_progress' => 'In Progress'] as $val => $label)
                        <button wire:click="$set('statusFilter', '{{ $val }}')"
                                class="tab-btn {{ $statusFilter === $val ? 'tab-active' : 'tab-inactive' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                    @endauth
                </div>

                <select wire:model.live="proficiencyFilter" class="form-select w-48">
                    <option value="">All Difficulty Levels</option>
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

                                @php $moduleConcepts = $module->questions->flatMap->concepts->unique('id')->take(4); @endphp
                                @if($moduleConcepts->isNotEmpty())
                                    <div class="flex flex-wrap gap-1 mb-1.5">
                                        @foreach($moduleConcepts as $concept)
                                            <span class="badge-gray">{{ $concept->name }}</span>
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
                                <a href="{{ route('modules.show', $module) }}"
                                   class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-ink-subtle hover:text-ink bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                                    View Guide
                                </a>

                                @auth
                                @if ($module->status === 'preparing')
                                    <div wire:poll.3s class="flex items-center gap-1.5 text-[12px] text-ink-subtle">
                                        <svg class="animate-spin w-3.5 h-3.5 text-amber-400" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                        </svg>
                                        Preparing
                                    </div>
                                @elseif (!$userModule && $module->status === 'ready')
                                    <form action="{{ route('modules.assign', $module) }}" method="POST">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                            Add to Training
                                        </button>
                                    </form>
                                @endif

                                @if($status === 'not_started' || $status === 'in_progress')
                                    <a href="{{ route('questions.quiz.index', ['category_id' => request('category_id'), 'module_id' => $module->id]) }}"
                                       class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors opacity-0 group-hover:opacity-100">
                                        {{ $status === 'in_progress' ? 'Continue' : 'Start' }}
                                    </a>
                                @endif
                                @else
                                <a href="{{ route('register') }}"
                                   class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                    Sign up to start training
                                </a>
                                @endauth
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="linear-card px-5 py-16 text-center">
                <p class="text-[13px] text-ink-subtle">No guides match those filters. Try clearing a filter or request a new guide.</p>
            </div>
        @endif
    </div>
</div>
