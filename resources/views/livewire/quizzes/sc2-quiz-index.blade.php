<div class="max-w-4xl mx-auto px-4 py-8">
    @if (! $race)
        <div class="flex items-center gap-3 mb-6">
            <x-mc-icon name="badge-sc2" class="w-12 h-12 shrink-0" style="color: {{ $color }}"/>
            <div>
                <h1 class="font-display text-3xl text-ink">StarCraft II quizzes</h1>
                <p class="text-[14px] text-ink-muted mt-1">
                    Pick your race. Every question is built from the game's own unit data, so the numbers are the ones you play with.
                </p>
            </div>
        </div>

        @if ($diagnosticUrl)
            <a href="{{ $diagnosticUrl }}" class="linear-card p-4 mb-6 flex items-center justify-between gap-4 border-line-gold hover:border-gold transition-colors">
                <span>
                    <span class="block text-[11px] uppercase tracking-widest font-semibold" style="color: {{ $color }}">Start here</span>
                    <span class="block text-[15px] text-ink font-medium mt-0.5">How do you play?</span>
                    <span class="block text-[12.5px] text-ink-muted mt-0.5">Thirteen questions about the calls you make on the ladder. There are no right answers; you get a read on your style.</span>
                </span>
                <span class="text-gold text-[13px] shrink-0">Start &rarr;</span>
            </a>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach ($races as $slug => $name)
                @php
                    $levelsDone = $raceResults[$slug];
                    $passed = collect($levelsDone)->filter->passed()->count();
                @endphp
                <a href="{{ route('sc2-quiz', ['race' => $slug]) }}" wire:navigate wire:key="race-{{ $slug }}"
                   class="linear-card p-5 transition-colors {{ $passed === $levelCount ? 'border-gold' : ($levelsDone ? 'border-line-gold' : 'hover:border-line-gold') }}">
                    <p class="font-display text-2xl text-ink">{{ $name }}</p>
                    <p class="text-[12.5px] text-ink-muted mt-1">{{ $counts[$slug] ?? 0 }} units and buildings, {{ $levelCount }} levels</p>
                    <p class="text-[12px] mt-3 {{ $passed === $levelCount ? 'text-gold' : ($passed ? 'text-green-400' : 'text-ink-subtle') }}">
                        {{ $levelsDone ? "{$passed} of {$levelCount} passed" : 'Not started' }}
                    </p>
                </a>
            @endforeach
        </div>
    @else
        <a href="{{ route('sc2-quiz') }}" wire:navigate class="text-[12.5px] text-ink-subtle hover:text-gold transition-colors">&larr; All races</a>

        <div class="flex items-center gap-3 mt-3 mb-6">
            <x-mc-icon name="badge-sc2" class="w-12 h-12 shrink-0" style="color: {{ $color }}"/>
            <div>
                <h1 class="font-display text-3xl text-ink">{{ $raceName }}</h1>
                <p class="text-[13.5px] text-ink-muted mt-0.5">Work through the levels in order, or start wherever you like.</p>
            </div>
        </div>

        <div class="space-y-3">
            @foreach ($levels as $n => $level)
                @php
                    $attempt = $best[$n] ?? null;
                    $isNext = $recommended === $n;
                @endphp
                <div wire:key="level-{{ $n }}" class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3 {{ $attempt?->passed() ? 'border-green-500/40 bg-green-500/5' : ($isNext ? 'border-line-gold' : '') }}">
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
                    <a href="{{ route('sc2-quiz.play', ['race' => $race, 'level' => $n]) }}" wire:navigate
                       class="{{ $isNext ? 'btn-primary' : 'btn-ghost' }} text-[13px] shrink-0 text-center">
                        {{ $attempt ? 'Take again' : 'Start' }}
                    </a>
                </div>
            @endforeach
        </div>

        @guest
            <p class="text-[12.5px] text-ink-subtle mt-4">
                <a href="{{ route('register') }}" class="text-gold hover:underline">Create an account</a> to keep your scores.
            </p>
        @endguest
    @endif

    {{-- Where the numbers come from, and what was changed by hand to bring them up to the live patch. --}}
    <div class="mt-10 text-[12px] text-ink-subtle leading-relaxed">
        <p>
            Numbers are from StarCraft II's own data, read from the game at patch {{ $extractedVersion }} and brought up to
            {{ $gameVersion }} from Blizzard's patch notes. Times are at Faster speed, as ladder games are played.
        </p>
        @if ($corrections)
            <p class="mt-1">
                Changed since {{ $extractedVersion }}:
                @foreach ($corrections as $c)
                    <a href="{{ $c['source'] }}" target="_blank" rel="noopener" class="text-ink-muted hover:text-gold">{{ $c['label'] }}</a> ({{ $c['note'] }}){{ $loop->last ? '' : ';' }}
                @endforeach
            </p>
        @endif
    </div>
</div>
