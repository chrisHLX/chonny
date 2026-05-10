<div class="py-6 max-w-3xl mx-auto space-y-6" x-transition>

    <div>
        <h2 class="text-[17px] font-semibold text-ink">Select a Module</h2>
        <p class="text-[13px] text-ink-muted mt-0.5">Choose a module to start the quiz.</p>
    </div>

    @if($subjects->count() > 1)
        <div class="flex flex-wrap gap-2">
            @foreach($subjects as $subject)
                <button wire:click="$set('selectedSubject', {{ $subject->id }})"
                        class="px-3 py-1.5 rounded-full text-[12px] font-medium transition-colors
                            {{ $selectedSubject == $subject->id
                                ? 'bg-accent text-white'
                                : 'bg-surface-2 text-ink-muted hover:bg-surface-3 border border-line' }}">
                    {{ $subject->name }}
                </button>
            @endforeach
        </div>
    @endif

    @if($selectedModuleData && $selectedModuleModel)

        {{-- Stats + radar row --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

            {{-- Left: concept polygon + axis coverage --}}
            <div class="linear-card p-4 flex flex-col items-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="-25 -25 250 250" class="rounded">
                    <defs>
                        <linearGradient id="qpg-grad-{{ $selectedModule }}" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%"   stop-color="#1C1C1F"/>
                            <stop offset="100%" stop-color="#242428"/>
                        </linearGradient>
                        <radialGradient id="qpg-glow-{{ $selectedModule }}" cx="50%" cy="50%" r="50%">
                            <stop offset="0%"   stop-color="#818cf8" stop-opacity="0.08"/>
                            <stop offset="100%" stop-color="#818cf8" stop-opacity="0"/>
                        </radialGradient>
                        <pattern id="qpg-dots-{{ $selectedModule }}" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                            <circle cx="1" cy="1" r="0.8" fill="#818cf8" opacity="0.15"/>
                        </pattern>
                    </defs>
                    <rect width="200" height="200" fill="url(#qpg-grad-{{ $selectedModule }})"/>
                    <rect width="200" height="200" fill="url(#qpg-dots-{{ $selectedModule }})"/>
                    <rect width="200" height="200" fill="url(#qpg-glow-{{ $selectedModule }})"/>

                    @if(!empty($this->selectedModuleRadarSvg['gridRings']))
                        @foreach($this->selectedModuleRadarSvg['gridRings'] as $ring)
                            <polygon points="{{ $ring }}"
                                     fill="none"
                                     stroke="rgba(255,255,255,0.06)"
                                     stroke-width="1"/>
                        @endforeach

                        @foreach($this->selectedModuleRadarSvg['spokeEnds'] as $pt)
                            <line x1="100" y1="100"
                                  x2="{{ $pt[0] }}" y2="{{ $pt[1] }}"
                                  stroke="rgba(255,255,255,0.06)"
                                  stroke-width="1"/>
                        @endforeach
                    @endif

                    <x-concept-polygon
                        :module="$selectedModuleModel"
                        :id="'qpg-' . $selectedModule"
                        accent="#818cf8"
                        :width="200"
                        :height="200"
                    />

                    @if(!empty($this->selectedModuleRadarSvg['labels']))
                        @foreach($this->selectedModuleRadarSvg['labels'] as $label)
                            <text x="{{ $label['x'] }}"
                                  y="{{ $label['y'] }}"
                                  text-anchor="{{ $label['anchor'] }}"
                                  font-size="10"
                                  fill="#9CA3AF"
                                  dominant-baseline="middle">{{ $label['name'] }}</text>
                        @endforeach
                    @endif
                </svg>

                @if(!empty($this->selectedModuleAxisData))
                    <div class="mt-3 w-full border-t border-line pt-3 space-y-1.5">
                        @foreach($this->selectedModuleAxisData as $axis)
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] text-ink-subtle">{{ $axis['name'] }} <span class="text-ink-subtle/60">({{ strtoupper(substr($axis['name'], 0, 3)) }})</span></span>
                                <span class="text-[11px] font-medium text-ink tabular-nums">{{ $axis['coverage'] }}%</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Right: stats + concept bars --}}
            <div class="linear-card p-4 flex flex-col gap-4">

                <div class="flex flex-wrap gap-1.5">
                    <span class="badge-gray">{{ $selectedModuleData['total'] }} questions</span>
                    <span class="badge-blue">{{ $selectedModuleData['skill_counts']['recall'] }} recall</span>
                    <span class="badge-amber">{{ $selectedModuleData['skill_counts']['analysis'] }} analysis</span>
                    <span class="badge-green">{{ $selectedModuleData['skill_counts']['application'] }} application</span>
                </div>

                @if(!empty($selectedModuleData['concepts']))
                    <div class="space-y-2.5">
                        @foreach($selectedModuleData['concepts'] as $concept)
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-[12px] text-ink-muted truncate max-w-[72%]">{{ $concept['name'] }}</span>
                                    <span class="text-[11px] text-ink-subtle shrink-0 tabular-nums">{{ $concept['coverage'] }}%</span>
                                </div>
                                <div class="w-full bg-surface-3 rounded-full h-1 overflow-hidden">
                                    <div class="h-1 rounded-full transition-all duration-700"
                                         style="width: {{ max(2, $concept['coverage']) }}%; background-color: rgba(129,140,248,{{ number_format(0.4 + ($concept['coverage'] / 100) * 0.55, 2) }})">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

            </div>
        </div>

        {{-- Full-width content pages --}}
        @if(!empty($selectedModuleData['pages']))
            @php $qsPages = $selectedModuleData['pages']; $qsMulti = count($qsPages) > 1; @endphp
            <div class="linear-card overflow-hidden" x-data="{ activeTab: 0 }">

                @if($qsMulti)
                    <div class="flex gap-0.5 px-4 pt-4 border-b border-line overflow-x-auto">
                        @foreach($qsPages as $i => $page)
                            <button
                                x-on:click="activeTab = {{ $i }}"
                                :class="activeTab === {{ $i }}
                                    ? 'border-b-2 border-accent text-ink bg-surface-2'
                                    : 'border-b-2 border-transparent text-ink-subtle hover:text-ink-muted'"
                                class="px-4 py-2.5 text-[12px] font-medium transition-colors whitespace-nowrap rounded-t-md -mb-px">
                                {{ $page['title'] }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <div class="px-5 pt-5 pb-2">
                        <h3 class="text-[13px] font-semibold text-ink">Content</h3>
                    </div>
                @endif

                @foreach($qsPages as $i => $page)
                    <div x-show="activeTab === {{ $i }}"
                         x-cloak
                         class="p-5">
                        @if($qsMulti)
                            <h3 class="text-[13px] font-semibold text-ink mb-3">{{ $page['title'] }}</h3>
                        @endif
                        <div class="prose prose-sm prose-invert max-w-none max-h-56 overflow-y-auto
                                    prose-p:text-ink-muted prose-headings:text-ink prose-strong:text-ink
                                    prose-li:text-ink-muted prose-code:text-ink-muted">
                            {!! $page['html'] !!}
                        </div>
                    </div>
                @endforeach

            </div>
        @endif

    @endif

    <div class="space-y-4">
        @foreach($subjects as $subject)
            @if(!$selectedSubject || $selectedSubject == $subject->id)
                <div class="linear-card p-5">
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">{{ $subject->name }}</p>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach($this->modules->where('subject_id', $subject->id) as $module)
                            <button wire:click="$set('selectedModule', {{ $module->id }})"
                                    class="text-left p-3.5 border rounded-lg transition-colors
                                        {{ $selectedModule == $module->id
                                            ? 'border-accent bg-accent/5'
                                            : 'border-line hover:bg-surface-2 hover:border-line-strong' }}">
                                <p class="text-[13px] font-medium {{ $selectedModule == $module->id ? 'text-ink' : 'text-ink-muted' }} truncate">{{ $module->name }}</p>
                                <p class="text-[11px] text-ink-subtle mt-0.5">{{ $module->proficiencies->first()->name ?? '—' }}</p>
                                <div class="mt-1.5">
                                    @if(optional($module->pivot)->status === 'completed')
                                        <span class="badge-green">Completed</span>
                                    @elseif(optional($module->pivot)->status === 'in_progress')
                                        <span class="badge-amber">In Progress</span>
                                    @else
                                        <span class="badge-gray">Not started</span>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div>
        @if($selectedModule && $this->modules->where('id', $selectedModule)->first()?->pivot->status === 'completed')
            <a href="{{ route('collection.index') }}"
               class="w-full inline-flex items-center justify-center py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                View Progress
            </a>
        @else
            <button wire:click="startQuiz"
                    class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    @disabled(!$selectedModule)>
                Start Quiz
            </button>
        @endif
    </div>
</div>
