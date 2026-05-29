<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-5xl mx-auto space-y-6">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">{{ $this->category->name }}</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Browse and filter available modules.</p>
        </div>

        <x-context-bar-livewire :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" routeName="modules.index"/>

        {{-- Explore --}}
        @php
            $subjectProficiencies = $this->exploreSubjects->mapWithKeys(fn ($s) => [
                $s->id => $s->proficiencies->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->toArray(),
            ])->toArray();
        @endphp
        <div class="linear-card p-5">
            <h2 class="text-[13px] font-semibold text-ink mb-1">Explore a topic</h2>
            <p class="text-[12px] text-ink-muted mb-4">Generate a module from the latest research. Give Gemini a topic and tell it what to do.</p>
            <form method="POST" action="{{ route('modules.explore') }}" class="flex flex-col gap-3">
                @csrf
                <div
                    x-data="{
                        sourceUrl: '',
                        youtubeMode: 'transcript',
                        subjectId: '',
                        proficiencyId: '',
                        allProficiencies: {{ Js::from($subjectProficiencies) }},
                        get proficiencies() { return this.allProficiencies[this.subjectId] || [] },
                        onSubjectChange() {
                            const profs = this.proficiencies;
                            this.proficiencyId = profs.length ? profs[0].id : '';
                        }
                    }"
                    class="flex flex-col gap-3"
                >
                    <input
                        type="text"
                        name="intent"
                        placeholder="Topic or module title (e.g. WW Monk major cooldowns)"
                        maxlength="500"
                        required
                        class="form-input"
                    />
                    <textarea
                        name="instructions"
                        placeholder="What do you want me to do? (e.g. Generate an up-to-date list of Windwalker's major cooldowns with cooldown timers and when to use each)"
                        maxlength="1000"
                        rows="3"
                        class="form-textarea"
                    ></textarea>
                    <input
                        type="url"
                        name="source_url"
                        x-model="sourceUrl"
                        placeholder="Primary source URL (optional) — paste a webpage, patch notes, or article for Gemini to use as authoritative source"
                        maxlength="2000"
                        class="form-input"
                    />
                    <input type="hidden" name="youtube_mode" :value="youtubeMode">
                    <div x-show="sourceUrl.includes('youtube.com') || sourceUrl.includes('youtu.be')" x-cloak class="space-y-1.5">
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide">YouTube Processing Mode</p>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" x-model="youtubeMode" value="transcript" class="text-accent">
                                <span class="text-[13px] text-ink">Transcript only</span>
                                <span class="text-[11px] text-ink-subtle">(fast, cheap)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" x-model="youtubeMode" value="video" class="text-accent">
                                <span class="text-[13px] text-ink">Full video</span>
                                <span class="text-[11px] text-amber-400">(~$0.13 per video)</span>
                            </label>
                        </div>
                        <p class="text-[11px] text-ink-subtle">Transcript reads captions. Full video lets Gemini watch gameplay and visuals.</p>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <select
                            name="subject_id"
                            x-model="subjectId"
                            @change="onSubjectChange()"
                            required
                            class="form-select">
                            <option value="">Select a subject…</option>
                            @foreach ($this->exploreSubjects as $subject)
                                <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                            @endforeach
                        </select>
                        <select
                            name="proficiency_id"
                            x-model="proficiencyId"
                            required
                            class="form-select">
                            <option value="" disabled>Proficiency…</option>
                            <template x-for="p in proficiencies" :key="p.id">
                                <option :value="p.id" x-text="p.name"></option>
                            </template>
                        </select>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors shrink-0">
                            Explore
                        </button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Filters --}}
        <div class="linear-card p-4 space-y-3">
            {{-- Concepts --}}
            <div class="flex flex-wrap gap-1.5">
                <button wire:click="$set('selectedConcepts', [])"
                        class="px-3 py-1 rounded-full text-[12px] font-medium transition-colors
                            {{ empty($selectedConcepts) ? 'bg-accent text-white' : 'bg-surface-2 text-ink-muted hover:bg-surface-3 border border-line' }}">
                    All Concepts
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
                                    Details
                                </a>

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
