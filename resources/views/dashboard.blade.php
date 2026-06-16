{{-- resources/views/dashboard.blade.php --}}
<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-7xl mx-auto space-y-6">

            @php
                $overallMastery = $concepts->count() > 0
                    ? round($concepts->avg(function ($c) { return $c->userConceptMasteries->first()?->mastery_percentage ?? 0; }))
                    : 0;
                $alchemistLevel = $overallMastery < 20 ? 'I' : ($overallMastery < 40 ? 'II' : ($overallMastery < 60 ? 'III' : ($overallMastery < 80 ? 'IV' : 'V')));

                // 6-axis hex radar polygon (center 65,65, outer r=50)
                $radarConcepts = $concepts->take(6)->values();
                $outerVerts    = [[65,15],[108,40],[108,90],[65,115],[22,90],[22,40]];
                $radarPoints   = [];
                foreach ($radarConcepts as $i => $rc) {
                    $m = ($rc->userConceptMasteries->first()?->mastery_percentage ?? 0) / 100;
                    $radarPoints[] = round(65 + ($outerVerts[$i][0] - 65) * max($m, 0.06), 1)
                                   . ',' . round(65 + ($outerVerts[$i][1] - 65) * max($m, 0.06), 1);
                }
                while (count($radarPoints) < 6) { $radarPoints[] = '65,65'; }
                $radarPolygon = implode(' ', $radarPoints);

                $labelPositions = [
                    [65, 8, 'middle'], [114, 37, 'start'], [114, 93, 'start'],
                    [65, 125, 'middle'], [16, 93, 'end'], [16, 37, 'end'],
                ];
            @endphp

            {{-- Page header --}}
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-display text-[20px] font-bold text-ink">Knowledge Atlas</h1>
                    <p class="text-[13px] text-ink-muted mt-0.5">
                        Welcome back, <span class="text-gold">{{ $user->name }}</span>
                    </p>
                </div>
                <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full bg-gold/10 border border-gold/30">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-gold">Alchemist</span>
                    <span class="font-display text-[14px] font-bold text-gold-light">{{ $alchemistLevel }}</span>
                </div>
            </div>

            {{-- Hero: Mastery Overview --}}
            <div class="linear-card p-6 relative overflow-hidden">
                {{-- Sacred geometry bg --}}
                <div class="absolute inset-0 flex items-center justify-end pointer-events-none select-none" aria-hidden="true">
                    <svg class="w-72 h-72 text-gold opacity-[0.05] mr-4" viewBox="0 0 300 300" fill="none">
                        <circle cx="150" cy="150" r="130" stroke="currentColor" stroke-width="0.75"/>
                        <circle cx="150" cy="150" r="100" stroke="currentColor" stroke-width="0.75"/>
                        <circle cx="150" cy="150" r="70"  stroke="currentColor" stroke-width="0.75"/>
                        <polygon points="150,20 262,85 262,215 150,280 38,215 38,85" stroke="currentColor" stroke-width="0.75" fill="none"/>
                        <polygon points="150,20 262,215 38,215" stroke="currentColor" stroke-width="0.5" fill="none"/>
                        <polygon points="150,280 38,85 262,85" stroke="currentColor" stroke-width="0.5" fill="none"/>
                    </svg>
                </div>

                <div class="relative flex flex-col lg:flex-row gap-8">
                    {{-- Left: mastery stats + CTA --}}
                    <div class="flex-1 min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-ink-subtle mb-2">Overall Mastery</p>
                        <div class="flex items-end gap-2 mb-3">
                            <span class="font-display text-[56px] font-bold leading-none text-ink">{{ $overallMastery }}</span>
                            <span class="text-[20px] text-ink-subtle mb-2.5">/100</span>
                        </div>
                        <p class="text-[13px] text-ink-muted mb-5 max-w-sm">
                            @if($overallMastery === 0) Start your first quiz to begin mapping your knowledge.
                            @elseif($overallMastery < 40) Your knowledge map is forming. Keep taking quizzes.
                            @elseif($overallMastery < 70) Good progress — your blind spots are narrowing.
                            @else Strong foundation. Push toward full mastery.
                            @endif
                        </p>

                        @if($heroModule && $heroModule->type === 'diagnostic')
                            @php
                                $heroStatus = $heroModule->pivot->status ?? 'not_started';
                            @endphp
                            <div class="bg-surface-2 border border-line rounded-lg p-4 mb-5 max-w-sm">
                                <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-gold mb-1">
                                    {{ $heroStatus === 'completed' ? 'Recently Completed' : 'Continue Learning' }}
                                </p>
                                <p class="text-[14px] font-semibold text-ink mb-0.5">{{ $heroModule->name }}</p>
                                <p class="text-[11px] text-ink-subtle">Assessment · {{ ucfirst(str_replace('_', ' ', $heroStatus)) }}</p>
                            </div>
                            <a href="{{ route('questions.quiz.index', ['moduleId' => $heroModule->id]) }}"
                               class="btn-primary">
                                {{ $heroStatus === 'completed' ? 'View Profile' : 'Start Assessment' }}
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        @elseif($heroModule)
                            @php
                                $heroStatus = $heroModule->pivot->status ?? 'not_started';
                                $heroScore  = $heroModule->pivot->score ?? 0;
                                $heroLabel  = $heroStatus === 'not_started' ? 'Start' : ($heroStatus === 'completed' ? 'Retake' : 'Resume');
                            @endphp
                            <div class="bg-surface-2 border border-line rounded-lg p-4 mb-5 max-w-sm">
                                <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-gold mb-1">
                                    {{ $heroStatus === 'completed' ? 'Recently Completed' : 'Continue Learning' }}
                                </p>
                                <p class="text-[14px] font-semibold text-ink mb-0.5">{{ $heroModule->name }}</p>
                                <p class="text-[11px] text-ink-subtle">{{ $heroScore }}% · {{ ucfirst(str_replace('_', ' ', $heroStatus)) }}</p>
                            </div>
                            <a href="{{ route('questions.quiz.index', ['moduleId' => $heroModule->id]) }}"
                               class="btn-primary">
                                {{ $heroLabel }} Journey
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        @else
                            <a href="{{ route_with_context('modules.index') }}" class="btn-primary">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                                </svg>
                                Explore the Map
                            </a>
                        @endif
                    </div>

                    {{-- Right: Radar chart --}}
                    <div class="shrink-0 flex flex-col items-center justify-center lg:pr-4">
                        <svg class="w-52 h-52" viewBox="0 0 130 130" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <polygon points="65,15 108,40 108,90 65,115 22,90 22,40"  stroke="#C8952C" stroke-width="0.75" fill="none" opacity="0.2"/>
                            <polygon points="65,28 98,46 98,84 65,103 32,84 32,46"   stroke="#C8952C" stroke-width="0.75" fill="none" opacity="0.13"/>
                            <polygon points="65,40 87,53 87,78 65,90 43,78 43,53"    stroke="#C8952C" stroke-width="0.75" fill="none" opacity="0.13"/>
                            <polygon points="65,53 76,59 76,71 65,78 54,71 54,59"    stroke="#C8952C" stroke-width="0.75" fill="none" opacity="0.08"/>
                            <line x1="65" y1="65" x2="65"  y2="15"  stroke="#C8952C" stroke-width="0.5" opacity="0.2"/>
                            <line x1="65" y1="65" x2="108" y2="40"  stroke="#C8952C" stroke-width="0.5" opacity="0.2"/>
                            <line x1="65" y1="65" x2="108" y2="90"  stroke="#C8952C" stroke-width="0.5" opacity="0.2"/>
                            <line x1="65" y1="65" x2="65"  y2="115" stroke="#C8952C" stroke-width="0.5" opacity="0.2"/>
                            <line x1="65" y1="65" x2="22"  y2="90"  stroke="#C8952C" stroke-width="0.5" opacity="0.2"/>
                            <line x1="65" y1="65" x2="22"  y2="40"  stroke="#C8952C" stroke-width="0.5" opacity="0.2"/>
                            <circle cx="65" cy="15"  r="1.5" fill="#C8952C" opacity="0.4"/>
                            <circle cx="108" cy="40" r="1.5" fill="#C8952C" opacity="0.4"/>
                            <circle cx="108" cy="90" r="1.5" fill="#C8952C" opacity="0.4"/>
                            <circle cx="65" cy="115" r="1.5" fill="#C8952C" opacity="0.4"/>
                            <circle cx="22" cy="90"  r="1.5" fill="#C8952C" opacity="0.4"/>
                            <circle cx="22" cy="40"  r="1.5" fill="#C8952C" opacity="0.4"/>

                            @foreach($radarConcepts as $i => $rc)
                                @php $lp = $labelPositions[$i]; @endphp
                                <text x="{{ $lp[0] }}" y="{{ $lp[1] }}" text-anchor="{{ $lp[2] }}"
                                      font-size="5.5" fill="#C8952C" opacity="0.6" font-family="monospace">{{ mb_substr($rc->name, 0, 6) }}</text>
                            @endforeach

                            @if($concepts->count() > 0)
                                <polygon points="{{ $radarPolygon }}"
                                         fill="#C8952C" fill-opacity="0.2" stroke="#E8B84B" stroke-width="1.5" stroke-opacity="0.85"/>
                                @foreach(explode(' ', $radarPolygon) as $pt)
                                    @php [$px, $py] = array_map('floatval', explode(',', $pt)); @endphp
                                    @if(!(abs($px - 65) < 2 && abs($py - 65) < 2))
                                        <circle cx="{{ $px }}" cy="{{ $py }}" r="2" fill="#E8B84B" opacity="0.75"/>
                                    @endif
                                @endforeach
                            @endif

                            <circle cx="65" cy="65" r="2.5" fill="#E8B84B" opacity="0.9"/>
                        </svg>
                        <p class="text-[10px] text-ink-subtle tracking-wide mt-1">Knowledge Map</p>
                    </div>
                </div>
            </div>

            {{-- Subject context bar --}}
            <x-context-bar :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" />

            {{-- Main grid --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                {{-- Topic Mastery --}}
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[13px] font-semibold text-ink">Topic Mastery</h2>
                        <span class="text-[11px] text-ink-subtle">{{ $concepts->count() }} topics</span>
                    </div>

                    @if (!$hasContentActivity)
                        <div class="linear-card p-4 text-center">
                            <x-mc-icon name="icon-axis-hex" class="w-8 h-8 text-gold/40 mx-auto mb-2"/>
                            <p class="text-[13px] text-ink-muted">Complete a learning guide to start tracking mastery.</p>
                            <p class="text-[12px] text-ink-subtle mt-1">Diagnostic assessments reveal your playstyle but don't contribute to mastery scores.</p>
                        </div>
                    @else
                        @forelse($concepts as $concept)
                            @php
                                $mastery = $concept->userConceptMasteries->first()?->mastery_percentage ?? 0;
                            @endphp
                            <div class="mb-3.5 last:mb-0">
                                <div class="flex justify-between items-center mb-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <x-mc-icon name="icon-axis-hex" class="w-4 h-4 text-gold opacity-60"/>
                                        <span class="text-[12px] font-medium text-ink-muted">{{ $concept->name }}</span>
                                    </div>
                                    <span class="text-[11px] font-semibold tabular-nums
                                        {{ $mastery >= 70 ? 'text-gold-light' : ($mastery >= 40 ? 'text-gold' : 'text-ink-subtle') }}">
                                        {{ $mastery }}%
                                    </span>
                                </div>
                                <div class="w-full bg-surface-3 rounded-full h-1.5 overflow-hidden">
                                    <div class="h-1.5 rounded-full transition-all duration-700"
                                         style="width: {{ $mastery }}%;
                                                background: linear-gradient(90deg, #C8952C, #E8B84B);
                                                box-shadow: {{ $mastery > 5 ? '0 0 6px rgba(200,149,44,0.45)' : 'none' }};">
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-8">
                                <svg class="w-8 h-8 text-ink-subtle mx-auto mb-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                                </svg>
                                <p class="text-[12px] text-ink-subtle">No topics yet. Start a quiz to map your knowledge.</p>
                            </div>
                        @endforelse
                    @endif
                </div>

                {{-- Leaderboard --}}
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[13px] font-semibold text-ink">Leaderboard</h2>
                        <span class="text-[11px] text-ink-subtle">Top scholars</span>
                    </div>

                    @if (!$hasContentActivity)
                        <div class="linear-card p-4 text-center">
                            <x-mc-icon name="icon-axis-hex" class="w-8 h-8 text-gold/40 mx-auto mb-2"/>
                            <p class="text-[13px] text-ink-muted">Complete a learning guide to appear on the leaderboard.</p>
                            <p class="text-[12px] text-ink-subtle mt-1">Diagnostic assessments reveal your playstyle but don't contribute to mastery scores.</p>
                        </div>
                    @elseif($leaderboard->isEmpty())
                        <p class="text-[12px] text-ink-subtle text-center py-8">No rankings yet.</p>
                    @else
                        <div class="space-y-0">
                            @foreach ($leaderboard as $index => $leaderUser)
                                @php $isMe = $leaderUser->id === auth()->id(); @endphp
                                <div class="flex items-center gap-3 py-2.5 {{ !$loop->last ? 'border-b border-line' : '' }}">
                                    {{-- Hex rank badge --}}
                                    <div class="w-6 h-6 shrink-0 flex items-center justify-center">
                                        @if($index === 0)
                                            <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none">
                                                <polygon points="10,1 17.5,5.5 17.5,14.5 10,19 2.5,14.5 2.5,5.5" fill="#E8B84B" fill-opacity="0.15" stroke="#E8B84B" stroke-opacity="0.7" stroke-width="1"/>
                                                <text x="10" y="13.5" text-anchor="middle" font-size="7.5" fill="#E8B84B" font-weight="bold" font-family="monospace">1</text>
                                            </svg>
                                        @elseif($index === 1)
                                            <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none">
                                                <polygon points="10,1 17.5,5.5 17.5,14.5 10,19 2.5,14.5 2.5,5.5" fill="#8A8A9A" fill-opacity="0.1" stroke="#8A8A9A" stroke-opacity="0.45" stroke-width="1"/>
                                                <text x="10" y="13.5" text-anchor="middle" font-size="7.5" fill="#8A8A9A" font-family="monospace">2</text>
                                            </svg>
                                        @elseif($index === 2)
                                            <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none">
                                                <polygon points="10,1 17.5,5.5 17.5,14.5 10,19 2.5,14.5 2.5,5.5" fill="#C8952C" fill-opacity="0.1" stroke="#C8952C" stroke-opacity="0.45" stroke-width="1"/>
                                                <text x="10" y="13.5" text-anchor="middle" font-size="7.5" fill="#C8952C" font-family="monospace">3</text>
                                            </svg>
                                        @else
                                            <span class="text-[11px] text-ink-subtle text-center w-full tabular-nums">{{ $index + 1 }}</span>
                                        @endif
                                    </div>

                                    <span class="text-[13px] flex-1 truncate {{ $isMe ? 'text-gold font-medium' : 'text-ink' }}">
                                        {{ $leaderUser->name }}{{ $isMe ? ' ·you' : '' }}
                                    </span>
                                    <span class="text-[12px] font-semibold tabular-nums
                                        {{ $index === 0 ? 'text-gold-light' : ($index === 1 ? 'text-ink-muted' : ($index === 2 ? 'text-gold' : 'text-ink-subtle')) }}">
                                        {{ round($leaderUser->total_mastery) }}%
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- My Guides --}}
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[13px] font-semibold text-ink">My Guides</h2>
                        <a href="{{ route_with_context('collection.index') }}"
                           class="text-[11px] text-gold hover:text-gold-light transition-colors">View all</a>
                    </div>

                    @if($modules->isEmpty())
                        <div class="text-center py-8">
                            <p class="text-[12px] text-ink-subtle mb-3">No guides yet.</p>
                            <a href="{{ route_with_context('modules.index') }}" class="btn-ghost text-[12px] px-3 py-1.5">
                                Find a Guide
                            </a>
                        </div>
                    @else
                        <div>
                            @foreach($modules as $module)
                                @if ($module->type === 'diagnostic')
                                    @php
                                        $dStatus = $module->pivot->status ?? 'not_started';
                                    @endphp
                                    <div class="py-3 {{ !$loop->last ? 'border-b border-line' : '' }}">
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <span class="text-[13px] text-ink font-medium leading-tight truncate">{{ $module->name }}</span>
                                            @if ($dStatus === 'completed')
                                                <a href="{{ route('questions.quiz.index', ['moduleId' => $module->id]) }}"
                                                   class="shrink-0 text-[11px] font-semibold px-2.5 py-1 rounded transition-all text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line">
                                                    View Profile
                                                </a>
                                            @else
                                                <a href="{{ route('questions.quiz.index', ['moduleId' => $module->id]) }}"
                                                   class="shrink-0 text-[11px] font-semibold px-2.5 py-1 rounded transition-all text-surface-0 bg-gold-gradient hover:shadow-gold-sm">
                                                    Start
                                                </a>
                                            @endif
                                        </div>
                                        <span class="badge-blue">Assessment</span>
                                    </div>
                                @else
                                    @php
                                        $mStatus    = $module->pivot->status ?? 'not_started';
                                        $mScore     = $module->pivot->score ?? 0;
                                        $mLabel     = $mStatus === 'not_started' ? 'Start' : ($mStatus === 'completed' ? 'Retake' : 'Resume');
                                        $filledDots = (int) round($mScore / 10);
                                    @endphp
                                    <div class="py-3 {{ !$loop->last ? 'border-b border-line' : '' }}">
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <span class="text-[13px] text-ink font-medium leading-tight truncate">{{ $module->name }}</span>
                                            <a href="{{ route('questions.quiz.index', ['moduleId' => $module->id]) }}"
                                               class="shrink-0 text-[11px] font-semibold px-2.5 py-1 rounded transition-all
                                                   {{ $mStatus === 'completed'
                                                      ? 'text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line'
                                                      : 'text-surface-0 bg-gold-gradient hover:shadow-gold-sm' }}">
                                                {{ $mLabel }}
                                            </a>
                                        </div>
                                        {{-- Fragment dots --}}
                                        <div class="flex items-center gap-1">
                                            @for($d = 0; $d < 10; $d++)
                                                <div class="w-[6px] h-[6px] rounded-sm transition-all duration-300
                                                    {{ $d < $filledDots ? 'bg-gold' : 'bg-surface-3' }}"
                                                     style="{{ $d < $filledDots ? 'box-shadow: 0 0 3px rgba(200,149,44,0.5)' : '' }}">
                                                </div>
                                            @endfor
                                            <span class="text-[10px] text-ink-subtle ml-1.5 tabular-nums">{{ $mScore }}%</span>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>

            {{-- Supporting Development --}}
            <div class="linear-card p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1.5">
                            <x-mc-icon name="icon-flask" class="w-5 h-5 text-gold"/>
                            <h2 class="text-[13px] font-semibold text-ink">Supporting Development</h2>
                            <span class="badge-gold">Alpha</span>
                        </div>
                        <p class="text-[12px] text-ink-subtle max-w-lg">
                            MindCollector is in alpha. AI and hosting costs are being covered while the platform is tested and improved.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer"
                           class="btn-primary text-[12px] px-3 py-1.5 gap-1.5">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                            </svg>
                            Join Discord
                        </a>
                        <button id="buy-credits" class="btn-ghost text-[12px] px-3 py-1.5">
                            Support Development
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe("{{ config('services.stripe.key') }}");
        document.getElementById('buy-credits').addEventListener('click', async () => {
            const res = await fetch("{{ route('checkout.session') }}", {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
            });
            const data = await res.json();
            await stripe.redirectToCheckout({ sessionId: data.id });
        });
    </script>
</x-app-layout>
