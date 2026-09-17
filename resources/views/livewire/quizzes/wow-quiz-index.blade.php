@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-4xl mx-auto px-4 py-8">
    @if (! $spec)
        <div class="mb-6">
            <h1 class="font-display text-3xl text-ink">Class quizzes</h1>
            <p class="text-[14px] text-ink-muted mt-1.5">
                Pick your spec. Each quiz is built from live game data, so it stays right when the game changes.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach ($classes as $class)
                @php $color = $classColors[$class->slug] ?? '#8A8A9A'; @endphp
                <div class="linear-card p-3">
                    <p class="text-[12px] font-semibold uppercase tracking-wider mb-2" style="color: {{ $color }}">{{ $class->name }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($class->specializations as $sp)
                            <a href="{{ route('wow-quiz', ['classSlug' => $class->slug, 'specSlug' => $sp->slug]) }}" wire:navigate
                               class="flex items-center gap-2 px-2 py-1.5 rounded border border-line hover:border-line-gold transition-colors">
                                <x-spec-icon :spec="$sp" :color="$color" size="w-7 h-7"/>
                                <span class="text-[13px] text-ink">{{ $sp->name }}</span>
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
                <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3 {{ $isNext ? 'border-line-gold' : '' }}">
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

        @guest
            <p class="text-[12.5px] text-ink-subtle mt-4">
                <a href="{{ route('register') }}" class="text-gold hover:underline">Create an account</a> to keep your scores.
            </p>
        @endguest
    @endif
</div>
