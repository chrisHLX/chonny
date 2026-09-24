@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-4xl mx-auto px-4 py-8">
    @if (! $spec)
        <div class="mb-6">
            <h1 class="font-display text-3xl text-ink">Class quizzes</h1>
            <p class="text-[14px] text-ink-muted mt-1.5">
                Pick your spec. Each quiz is built from live game data, so it stays right when the game changes.
            </p>
        </div>

        {{-- Your results: every spec you have finished a level of, most recent first. --}}
        @if ($resultSpecs->isNotEmpty())
            <div class="mb-6">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">Your results</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ($resultSpecs as $rs)
                        @php
                            $rsColor = $classColors[$rs->gameClass->slug] ?? '#8A8A9A';
                            $rsLevels = $specResults[$rs->id];
                            $rsPassed = collect($rsLevels)->filter->passed()->count();
                        @endphp
                        <a href="{{ route('wow-quiz', ['classSlug' => $rs->gameClass->slug, 'specSlug' => $rs->slug]) }}" wire:navigate
                           wire:key="result-{{ $rs->id }}"
                           class="linear-card p-3 flex items-center gap-3 {{ $rsPassed === $levelCount ? 'border-gold/60' : 'border-line-gold' }}">
                            <x-spec-icon :spec="$rs" :color="$rsColor" size="w-9 h-9"/>
                            <div class="flex-1 min-w-0">
                                <p class="text-[13.5px] text-ink truncate">
                                    {{ $rs->name }} <span style="color: {{ $rsColor }}">{{ $rs->gameClass->name }}</span>
                                </p>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach (range(1, $levelCount) as $n)
                                        @php $a = $rsLevels[$n] ?? null; @endphp
                                        <span class="text-[11px] tabular-nums px-1.5 py-0.5 rounded border
                                            {{ $a?->passed() ? 'border-green-500/50 text-green-400 bg-green-500/10' : ($a ? 'border-line-strong text-ink-muted' : 'border-line text-ink-subtle') }}"
                                              title="Level {{ $n }}{{ $a ? ': best '.$a->score.' / '.$a->total : ': not taken' }}">
                                            L{{ $n }} {{ $a ? $a->score.'/'.$a->total : '–' }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                            <span class="text-[12px] shrink-0 {{ $rsPassed === $levelCount ? 'text-gold' : 'text-ink-subtle' }}">{{ $rsPassed }}/{{ $levelCount }} passed</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach ($classes as $class)
                @php $color = $classColors[$class->slug] ?? '#8A8A9A'; @endphp
                <div class="linear-card p-3">
                    <p class="text-[12px] font-semibold uppercase tracking-wider mb-2" style="color: {{ $color }}">{{ $class->name }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($class->specializations as $sp)
                            @php
                                // Coloured by progress: gold once every level is passed, green-edged
                                // once any level is passed, gold-edged once any level is finished.
                                $spLevels = $specResults[$sp->id] ?? [];
                                $spPassed = collect($spLevels)->filter->passed()->count();
                                $spClass = match (true) {
                                    $spLevels === [] => 'border-line hover:border-line-gold',
                                    $spPassed === $levelCount => 'border-gold bg-gold-subtle',
                                    $spPassed > 0 => 'border-green-500/50 bg-green-500/5',
                                    default => 'border-line-gold',
                                };
                            @endphp
                            <a href="{{ route('wow-quiz', ['classSlug' => $class->slug, 'specSlug' => $sp->slug]) }}" wire:navigate
                               class="flex items-center gap-2 px-2 py-1.5 rounded border transition-colors {{ $spClass }}">
                                <x-spec-icon :spec="$sp" :color="$color" size="w-7 h-7"/>
                                <span class="text-[13px] text-ink">{{ $sp->name }}</span>
                                @if ($spLevels !== [])
                                    <span class="text-[11px] tabular-nums {{ $spPassed === $levelCount ? 'text-gold' : ($spPassed > 0 ? 'text-green-400' : 'text-ink-subtle') }}"
                                          title="{{ $spPassed }} of {{ $levelCount }} levels passed">
                                        @if ($spPassed === $levelCount) &#10003; @endif{{ $spPassed }}/{{ $levelCount }}
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @else
        @php $color = $classColors[$spec->gameClass->slug] ?? '#8A8A9A'; @endphp

        <a href="{{ route('wow-quiz') }}" wire:navigate class="text-[12.5px] text-ink-subtle hover:text-gold transition-colors">&larr; All specs</a>

        <div class="flex items-center gap-3 mt-3 mb-6">
            <x-spec-icon :spec="$spec" :color="$color" size="w-12 h-12"/>
            <div>
                <h1 class="font-display text-3xl text-ink">{{ $spec->name }} <span style="color: {{ $color }}">{{ $spec->gameClass->name }}</span></h1>
                <p class="text-[13.5px] text-ink-muted mt-0.5">Work through the levels in order, or start wherever you like.</p>
            </div>
        </div>

        <div class="space-y-3">
            @foreach ($levels as $n => $level)
                @php
                    $attempt = $best[$n] ?? null;
                    $isNext = $recommended === $n;
                @endphp
                <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3 {{ $attempt?->passed() ? 'border-green-500/40 bg-green-500/5' : ($isNext ? 'border-line-gold' : '') }}">
                    <div class="font-display text-2xl text-gold w-8 shrink-0">{{ $n }}</div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-[15px] font-medium text-ink">{{ $level->title }}</p>
                            @if ($attempt?->passed())
                                <span class="badge-green">Passed</span>
                            @elseif ($isNext)
                                <span class="badge-gold">Up next</span>
                            @endif
                        </div>
                        <p class="text-[13px] text-ink-muted mt-0.5">{{ $level->description }}</p>
                        @if ($attempt)
                            <p class="text-[12px] text-ink-subtle mt-1 tabular-nums">Best: {{ $attempt->score }} / {{ $attempt->total }}</p>
                        @endif
                    </div>
                    <a href="{{ route('wow-quiz.play', ['classSlug' => $spec->gameClass->slug, 'specSlug' => $spec->slug, 'level' => $n]) }}" wire:navigate
                       class="{{ $isNext ? 'btn-primary' : 'btn-ghost' }} text-[13px] shrink-0 text-center">
                        {{ $attempt ? 'Take again' : 'Start' }}
                    </a>
                </div>
            @endforeach
        </div>

        @if ($concepts->isNotEmpty())
            <div class="mt-10">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Drills</h2>
                <p class="text-[13px] text-ink-muted mt-1 mb-3">
                    One idea at a time, as long as you like. Nothing here is written down anywhere — every question is
                    built from the spell data the moment you start, so the answers are whatever is true this patch.
                </p>

                <div class="space-y-2">
                    @foreach ($concepts as $concept)
                        @php
                            $generable = \App\Learning\ConceptCoverage::isGenerable($concept->name);
                            $record = $drillRecords[$concept->id] ?? null;
                            $sections = \App\Learning\ConceptCoverage::brainSectionsFor($concept->name);
                        @endphp
                        <div wire:key="drill-{{ $concept->id }}"
                             class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3 {{ $generable ? '' : 'opacity-70' }}">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-[15px] font-medium text-ink">{{ $concept->name }}</p>
                                    @if ($generable && $record && ! $record->isEmpty())
                                        <span class="text-[11.5px] text-ink-subtle tabular-nums">
                                            {{ $record->correct }} right of your last {{ $record->asked }}
                                        </span>
                                    @elseif (! $generable)
                                        <span class="badge-gray">Not generated</span>
                                    @endif
                                </div>
                                <p class="text-[13px] text-ink-muted mt-0.5">
                                    {{ $generable ? $concept->description : \App\Learning\ConceptCoverage::unbackedReason($concept->name) }}
                                </p>
                                @if ($sections)
                                    <p class="text-[12px] text-ink-subtle mt-1">
                                        In the model:
                                        @foreach ($sections as $section)
                                            <a href="{{ route('brain') }}#{{ $section }}"
                                               class="text-ink-muted hover:text-gold transition-colors">{{ str_replace('-', ' ', $section) }}</a>{{ $loop->last ? '' : ' · ' }}
                                        @endforeach
                                    </p>
                                @endif
                            </div>
                            @if ($generable)
                                <a href="{{ route('wow-quiz.drill', ['classSlug' => $spec->gameClass->slug, 'specSlug' => $spec->slug, 'conceptSlug' => \Illuminate\Support\Str::slug($concept->name)]) }}"
                                   wire:navigate class="btn-ghost text-[13px] shrink-0 text-center">Drill</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @guest
            <p class="text-[12.5px] text-ink-subtle mt-4">
                <a href="{{ route('register') }}" class="text-gold hover:underline">Create an account</a> to keep your scores.
            </p>
        @endguest
    @endif
</div>
