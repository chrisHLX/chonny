<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-5xl mx-auto space-y-6">

        {{-- Breadcrumb --}}
        <div class="flex items-center gap-1.5 text-[12px] text-ink-subtle">
            <a href="{{ route('modules.index') }}" class="hover:text-ink transition-colors">Library</a>
            <span class="opacity-40">/</span>
            <span class="text-ink truncate">{{ $module->name }}</span>
        </div>

        {{-- Pipeline progress (shown while module is being generated) --}}
        @if ($activePipelineId)
            <livewire:generation-progress :pipelineId="$activePipelineId" />
            @script
            <script>
                window.addEventListener('questions-generated', () => {
                    setTimeout(() => window.location.reload(), 1200);
                });
            </script>
            @endscript
        @endif

        {{-- Header --}}
        <div class="linear-card p-6">
            <div class="flex items-start justify-between gap-6">
                <div class="flex-1 min-w-0">
                    <p class="text-[11px] text-ink-subtle mb-2 tracking-wide">
                        {{ $module->subject->category->name }}
                        <span class="opacity-40 mx-1">/</span>
                        {{ $module->subject->name }}
                    </p>

                    <h1 class="text-[22px] font-semibold text-ink mb-3 leading-snug">{{ $module->name }}</h1>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($module->proficiencies->first())
                            <span class="badge-gray">{{ $module->proficiencies->first()->name }}</span>
                        @endif

                    </div>

                    @if ($module->description)
                        <p class="text-[13px] text-ink-muted mt-3 leading-relaxed">{{ $module->description }}</p>
                    @endif
                </div>

                {{-- CTA block --}}
                <div class="shrink-0 flex flex-col items-end gap-2">
                    @auth
                        @if ($enrolled && $userModule)
                            @php $status = $userModule['status']; @endphp

                            @if ($status === 'completed')
                                <span class="badge-green">Completed</span>
                            @elseif ($status === 'in_progress')
                                <span class="badge-amber">In Progress</span>
                            @else
                                <span class="badge-gray">Not Started</span>
                            @endif

                            @if ($userModule['score'])
                                <p class="text-[11px] text-ink-subtle">Score: {{ $userModule['score'] }}%</p>
                            @endif

                            @if ($status === 'completed')
                                <a href="{{ route('collection.index') }}"
                                   class="inline-flex items-center px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                                    View Results
                                </a>
                                <button wire:click="retake"
                                        wire:loading.attr="disabled"
                                        wire:target="retake"
                                        class="inline-flex items-center px-4 py-2 text-[13px] font-medium text-ink-muted border border-line hover:border-ink-subtle hover:text-ink rounded-lg transition-colors disabled:opacity-50">
                                    <span wire:loading.remove wire:target="retake">Retake Quiz</span>
                                    <span wire:loading wire:target="retake">Starting…</span>
                                </button>
                            @else
                                <a href="{{ route('questions.quiz.index') }}?moduleId={{ $module->id }}"
                                   class="inline-flex items-center px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                                    {{ $status === 'in_progress' ? 'Continue' : 'Start Training' }}
                                </a>
                            @endif
                            @if ($module->modulePages->isNotEmpty())
                                <a href="{{ route('modules.page', $module) }}"
                                   class="inline-flex items-center px-4 py-2 text-[13px] font-medium text-ink-muted border border-line hover:border-ink-subtle hover:text-ink rounded-lg transition-colors">
                                    Read the Guide
                                </a>
                            @endif
                        @else
                            <button wire:click="enroll"
                                    class="inline-flex items-center px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                                <span wire:loading.remove wire:target="enroll">Add to Training</span>
                                <span wire:loading wire:target="enroll">Adding…</span>
                            </button>
                        @endif
                    @else
                        <a href="{{ route('register') }}"
                           class="inline-flex items-center px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                            Sign up to start training
                        </a>
                        <a href="{{ route('login') }}"
                           class="text-[12px] text-ink-subtle hover:text-ink transition-colors">
                            Already have an account?
                        </a>
                    @endauth
                </div>
            </div>
        </div>

        {{-- Stats row --}}
        @php
            $qCount = $module->questions->count();
            $estMinutes = $qCount > 0 ? max(5, (int) round($qCount * 1.5)) : 0;
            $difficultyLabel = $module->proficiencies->first()?->name ?? '—';
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div class="linear-card p-4 text-center">
                <p class="text-[24px] font-semibold text-ink">{{ $qCount }}</p>
                <p class="text-[11px] text-ink-subtle mt-0.5 uppercase tracking-wide">Questions</p>
            </div>
            <div class="linear-card p-4 text-center">
                <p class="text-[24px] font-semibold text-ink">{{ $estMinutes }}<span class="text-[14px] font-normal text-ink-subtle ml-0.5">min</span></p>
                <p class="text-[11px] text-ink-subtle mt-0.5 uppercase tracking-wide">Est. Time</p>
            </div>
            <div class="linear-card p-4 text-center">
                <p class="text-[13px] font-semibold text-ink mt-2 leading-tight">{{ $difficultyLabel }}</p>
                <p class="text-[11px] text-ink-subtle mt-1 uppercase tracking-wide">Difficulty</p>
            </div>
        </div>

        {{-- Mastery section: radar + concept bars --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Radar chart --}}
            <div class="linear-card p-6">
                <h2 class="page-section-title mb-5">Skill coverage</h2>

                @php $svg = $this->svgData; @endphp

                @if (!$svg['hasChart'])
                    <div class="flex items-center justify-center h-52 text-[13px] text-ink-subtle">
                        Skill breakdown not available for this guide yet.
                    </div>
                @else
                    <div class="relative">
                        <svg viewBox="-25 -15 450 430" class="w-full mx-auto block">

                            {{-- Grid rings --}}
                            @foreach ($svg['gridRings'] as $ring)
                                <polygon points="{{ $ring }}"
                                         fill="none"
                                         stroke="rgba(255,255,255,0.06)"
                                         stroke-width="1"/>
                            @endforeach

                            {{-- Spoke lines from centre to each outer vertex --}}
                            @foreach ($svg['spokeEnds'] as $pt)
                                <line x1="{{ $svg['cx'] }}" y1="{{ $svg['cy'] }}"
                                      x2="{{ $pt[0] }}" y2="{{ $pt[1] }}"
                                      stroke="rgba(255,255,255,0.06)"
                                      stroke-width="1"/>
                            @endforeach

                            {{-- User mastery filled polygon --}}
                            <polygon points="{{ $svg['userPointsStr'] }}"
                                     fill="rgba(129,140,248,0.18)"
                                     stroke="rgba(129,140,248,0.65)"
                                     stroke-width="1.5"
                                     stroke-linejoin="round"/>

                            {{-- Vertex dot markers --}}
                            @foreach ($svg['dots'] as $dot)
                                <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}"
                                        r="3"
                                        fill="#818cf8"
                                        stroke="rgba(255,255,255,0.35)"
                                        stroke-width="1"/>
                            @endforeach

                            {{-- Ring percentage labels --}}
                            @foreach ($svg['ringLabels'] as $rl)
                                <text x="{{ $rl['x'] }}" y="{{ $rl['y'] }}"
                                      font-size="10"
                                      fill="rgba(255,255,255,0.22)"
                                      text-anchor="start">{{ $rl['text'] }}</text>
                            @endforeach

                            {{-- Axis name labels --}}
                            @foreach ($svg['labels'] as $label)
                                <text x="{{ $label['x'] }}" y="{{ $label['y'] }}"
                                      text-anchor="{{ $label['anchor'] }}"
                                      font-size="13"
                                      fill="#9CA3AF"
                                      dominant-baseline="middle">{{ $label['name'] }}</text>
                            @endforeach
                        </svg>

                        {{-- Overlay for guests and unenrolled users --}}
                        @if (!$enrolled)
                            <div class="absolute inset-0 flex items-center justify-center rounded-lg backdrop-blur-[2px] bg-surface-1/50">
                                <p class="text-[12px] text-ink-muted px-4 text-center">
                                    @auth Add to training to see your skill progress here. @else Sign up to start tracking your skills. @endauth
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Axis percentage legend below the chart --}}
                    @if ($enrolled && !empty($this->radarData))
                        <div class="mt-5 space-y-1.5 border-t border-line pt-4">
                            @foreach ($this->radarData as $axis)
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] text-ink-subtle">{{ $axis['name'] }}</span>
                                    <span class="text-[11px] font-medium text-ink tabular-nums">{{ round($axis['mastery']) }}%</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            {{-- Concept mastery bars --}}
            <div class="linear-card p-6">
                <h2 class="page-section-title mb-5">Topic breakdown</h2>

                @if (empty($this->conceptMastery))
                    <div class="flex items-center justify-center h-52 text-[13px] text-ink-subtle">
                        Topic breakdown not available for this guide yet.
                    </div>
                @else
                    <div class="relative space-y-3.5">
                        @foreach ($this->conceptMastery as $cm)
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-[12px] text-ink-muted truncate max-w-[72%]">{{ $cm['name'] }}</span>
                                    <span class="text-[11px] text-ink-subtle shrink-0 tabular-nums">{{ round($cm['mastery']) }}%</span>
                                </div>
                                <div class="w-full bg-surface-3 rounded-full h-1 overflow-hidden">
                                    <div class="h-1 rounded-full transition-all duration-700"
                                         style="width: {{ max(2, $cm['mastery']) }}%; background-color: rgba(129,140,248,{{ number_format(0.4 + ($cm['mastery'] / 100) * 0.55, 2) }})">
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        @if (!$enrolled)
                            <div class="absolute inset-0 flex items-center justify-center rounded-lg backdrop-blur-[2px] bg-surface-1/50">
                                <p class="text-[12px] text-ink-muted px-4 text-center">
                                    @auth Add to training to track your topic progress. @else Sign up to start tracking your progress. @endauth
                                </p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Research content --}}
        @if ($this->subjectResearch->isNotEmpty())
            <div class="linear-card overflow-hidden">
                <div class="px-6 pt-6 pb-4 border-b border-line flex items-center justify-between">
                    <h2 class="page-section-title">Further Reading</h2>
                    <span class="text-[11px] text-ink-subtle">{{ $this->subjectResearch->count() }} {{ Str::plural('entry', $this->subjectResearch->count()) }}</span>
                </div>

                <div class="divide-y divide-line">
                    @foreach ($this->subjectResearch as $research)
                        @php
                            $isPrimaryDiscoveredFormat = isset($research->source_urls['discovered']);
                            $primaryUrl = $isPrimaryDiscoveredFormat ? ($research->source_urls['primary'] ?? null) : null;
                            $discovered = $isPrimaryDiscoveredFormat
                                ? $research->source_urls['discovered']
                                : collect($research->source_urls ?? [])->map(fn($url) => ['uri' => $url, 'title' => $url])->toArray();
                        @endphp
                        <div x-data="{ open: false }">
                            <button
                                x-on:click="open = !open"
                                class="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-surface-2 transition-colors group">
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13px] text-ink font-medium truncate">{{ Str::after($research->title, 'Research: ') }}</p>
                                    <p class="text-[11px] text-ink-subtle mt-0.5">{{ $research->created_at->diffForHumans() }}</p>
                                </div>
                                <svg x-bind:class="open ? 'rotate-180' : ''"
                                     class="w-4 h-4 text-ink-subtle shrink-0 ml-4 transition-transform duration-200"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            <div x-show="open" x-cloak class="px-6 pb-5">
                                <div class="prose prose-sm prose-invert max-w-none
                                            prose-p:text-ink-muted prose-headings:text-ink prose-strong:text-ink
                                            prose-li:text-ink-muted prose-code:text-ink-muted">
                                    {!! Str::markdown(e($research->content), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                                </div>

                                @if(!empty($primaryUrl) || !empty($discovered))
                                    <div class="mt-4 pt-4 border-t border-line">
                                        <p class="text-[11px] text-ink-subtle mb-2 uppercase tracking-wide">Sources</p>
                                        <div class="flex flex-col gap-1">
                                            @if(!empty($primaryUrl))
                                                <div class="flex items-center gap-2">
                                                    <span class="text-[10px] font-semibold text-ink-subtle uppercase tracking-wide shrink-0">Primary</span>
                                                    <a href="{{ $primaryUrl }}" target="_blank" rel="noopener noreferrer"
                                                       class="text-[12px] text-accent hover:text-accent-hover truncate transition-colors">
                                                        {{ $primaryUrl }}
                                                    </a>
                                                </div>
                                            @endif
                                            @foreach($discovered as $source)
                                                <a href="{{ $source['uri'] }}" target="_blank" rel="noopener noreferrer"
                                                   class="text-[12px] text-accent hover:text-accent-hover truncate transition-colors">
                                                    {{ $source['title'] ?? $source['uri'] }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <livewire:content-qa
                                    promptable-type="App\Models\SubjectContent"
                                    :promptable-id="$research->id"
                                    :key="'qa-research-'.$research->id" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Module content pages --}}
        @if (!empty($this->allPagesHtml))
            @php $pages = $this->allPagesHtml; $multiPage = count($pages) > 1; @endphp
            <div class="linear-card overflow-hidden" x-data="{ activeTab: 0 }">

                {{-- Tab bar (only when multiple pages) --}}
                @if ($multiPage)
                    <div class="flex gap-0.5 px-4 pt-4 border-b border-line overflow-x-auto">
                        @foreach ($pages as $i => $page)
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
                    <div class="px-6 pt-6 pb-2">
                        <h2 class="page-section-title">Guide</h2>
                    </div>
                @endif

                {{-- Page content panels --}}
                @foreach ($pages as $i => $page)
                    <div x-show="activeTab === {{ $i }}"
                         x-cloak
                         class="p-6">
                        @if ($multiPage)
                            <h2 class="text-[15px] font-semibold text-ink mb-4">{{ $page['title'] }}</h2>
                        @endif
                        <div class="prose prose-sm prose-invert max-w-none
                                    prose-p:text-ink-muted prose-headings:text-ink prose-strong:text-ink
                                    prose-li:text-ink-muted prose-code:text-ink-muted">
                            {!! $page['html'] !!}
                        </div>

                        <livewire:content-qa
                            promptable-type="App\Models\ModulePage"
                            :promptable-id="$page['id']"
                            :key="'qa-page-'.$page['id']" />
                    </div>
                @endforeach

            </div>
        @endif

    </div>
</div>
