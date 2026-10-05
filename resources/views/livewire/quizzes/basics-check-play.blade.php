@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-2xl mx-auto px-4 py-8">
    @if (! $spec)
        {{-- Pick the spec you play: the questions use your own buttons where the data allows. --}}
        <div class="mb-6">
            <p class="text-[11px] uppercase tracking-widest text-gold font-semibold">New to arena?</p>
            <h1 class="font-display text-3xl text-ink mt-1">Arena basics check</h1>
            <p class="text-[14px] text-ink-muted mt-2">
                Eight questions, one for each basic of arena: goes, pressing cooldowns together, their healer first,
                diminishing returns, defensives, swapping, kicks and line of sight. Pick the spec you play, and the
                questions use your own buttons. At the end you'll see which basics you have and which to work on.
            </p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach ($classes as $class)
                @php $color = $classColors[$class->slug] ?? '#8A8A9A'; @endphp
                <div class="linear-card p-3">
                    <p class="text-[12px] font-semibold uppercase tracking-wider mb-2" style="color: {{ $color }}">{{ $class->name }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($class->specializations as $sp)
                            <a href="{{ route('wow-basics', ['classSlug' => $class->slug, 'specSlug' => $sp->slug]) }}" wire:navigate
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
        <div class="flex items-center justify-between gap-3 mb-5">
            <a href="{{ route('wow-basics') }}" wire:navigate class="flex items-center gap-2 min-w-0 group">
                <x-spec-icon :spec="$spec" :color="$color" size="w-8 h-8"/>
                <div class="min-w-0">
                    <p class="text-[13px] text-ink truncate group-hover:text-gold transition-colors">{{ $specLabel }}</p>
                    <p class="text-[11.5px] text-ink-subtle">Arena basics check</p>
                </div>
            </a>
            @if ($attempt && ! $showResults)
                <span class="text-[12.5px] text-ink-muted tabular-nums shrink-0">{{ $index + 1 }} / {{ $attempt->total }}</span>
            @endif
        </div>

        @if (! $attempt)
            <div class="linear-card p-6 text-center">
                <p class="text-[14px] text-ink">The basics check could not be built for {{ $specLabel }}.</p>
                <a href="{{ route('wow-basics') }}" wire:navigate class="btn-ghost text-[13px] mt-4 inline-block">Pick another spec</a>
            </div>
        @elseif ($showResults)
            @php
                $have = collect($results)->where('right', true);
                $missed = collect($results)->where('right', false);
            @endphp
            <div class="linear-card p-6">
                <p class="text-[12px] uppercase tracking-widest text-ink-subtle text-center">Your basics</p>
                <p class="font-display text-5xl mt-2 text-gold tabular-nums text-center">{{ $have->count() }} / {{ count($results) }}</p>
                <p class="text-[14px] text-ink-muted mt-3 text-center">
                    @if ($missed->isEmpty())
                        You have every basic. The next step is doing them in a game, under pressure.
                    @else
                        {{ $missed->count() === 1 ? 'One basic' : $missed->count().' basics' }} to work on. Each is one short idea; read it, then look for it in your next games.
                    @endif
                </p>

                @if ($missed->isNotEmpty())
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-gold font-semibold mt-6 mb-2">Work on</h2>
                    <div class="space-y-2">
                        @foreach ($missed as $r)
                            <div class="rounded border border-red-500/40 bg-red-500/5 p-3">
                                <p class="text-[14px] text-ink font-medium">{{ $r['title'] }}</p>
                                <p class="text-[13px] text-ink-muted mt-1">{{ $r['lesson'] }}</p>
                                <a href="{{ route('brain') }}#{{ $r['brain'] }}" class="text-[12px] text-gold hover:underline mt-1 inline-block">Why, in the arena model &rarr;</a>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($have->isNotEmpty())
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mt-6 mb-2">You have</h2>
                    <ul class="space-y-1">
                        @foreach ($have as $r)
                            <li class="flex items-center gap-2 text-[13.5px] text-ink-muted">
                                <span class="text-green-400">&#10003;</span>{{ $r['title'] }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-6 pt-5 border-t border-line space-y-2 text-[13px] text-ink-muted">
                    <p>
                        <a href="{{ route('wow-comps') }}" wire:navigate class="text-gold hover:underline">Pick your team on the comps page</a>
                        and open <span class="text-ink">How to play it</span>: the same basics, for your three specs.
                    </p>
                    <p>
                        Knowing a basic and doing it mid-game are different things.
                        @auth
                            <a href="{{ route('coach') }}" class="text-gold hover:underline">Your coach</a> shows what you do in your own games.
                        @else
                            <a href="{{ route('register') }}" class="text-gold hover:underline">Make an account</a> and your games can show what you do.
                        @endauth
                    </p>
                </div>

                <div class="flex flex-wrap justify-center gap-2 mt-6">
                    <button type="button" wire:click="retry" class="btn-primary text-[13px]">Take it again</button>
                    <a href="{{ route('wow-quiz', ['classSlug' => $spec->gameClass->slug, 'specSlug' => $spec->slug]) }}" wire:navigate class="btn-ghost text-[13px]">{{ $specLabel }} quizzes</a>
                </div>
            </div>
        @elseif ($question)
            <div class="linear-card p-5" wire:key="q-{{ $index }}">
                @php $basic = \App\Quiz\Wow\BasicsCheck::BASICS[\App\Quiz\Wow\BasicsCheck::basicOf($question->type)] ?? null; @endphp
                @if ($basic)
                    <p class="text-[11px] uppercase tracking-widest text-ink-subtle mb-3">{{ $basic['title'] }}</p>
                @endif
                @if ($question->subject)
                    <div class="flex items-center gap-3 mb-4">
                        @if ($question->subject['icon'])
                            <img src="{{ $question->subject['icon'] }}" alt="" class="w-12 h-12 rounded border border-line-gold">
                        @endif
                        <p class="text-[15px] font-medium text-ink">{{ $question->subject['label'] }}</p>
                    </div>
                @endif

                <p class="text-[17px] text-ink leading-snug">{{ $question->prompt }}</p>

                <div class="grid grid-cols-1 gap-2 mt-4">
                    @foreach ($question->options as $option)
                        @php
                            $state = match (true) {
                                $chosen === null => 'open',
                                $option['key'] === $question->correctKey => 'correct',
                                $option['key'] === $chosen => 'wrong',
                                default => 'other',
                            };
                        @endphp
                        <button type="button"
                                @if ($chosen === null) wire:click="answer(@js($option['key']))" @else disabled @endif
                                wire:loading.attr="disabled"
                                class="flex items-center gap-3 p-3 rounded border text-left transition-colors
                                    {{ match ($state) {
                                        'open' => 'border-line hover:border-line-gold bg-surface-2',
                                        'correct' => 'border-green-500/70 bg-green-500/10',
                                        'wrong' => 'border-red-500/70 bg-red-500/10',
                                        default => 'border-line bg-surface-2 opacity-60',
                                    } }}">
                            @if ($option['icon'])
                                <img src="{{ $option['icon'] }}" alt="" class="w-9 h-9 rounded border border-line shrink-0">
                            @endif
                            <span class="text-[14px] text-ink">{{ $option['label'] }}</span>
                        </button>
                    @endforeach
                </div>

                @if ($chosen !== null)
                    <div class="mt-4 pt-4 border-t border-line">
                        <p class="text-[14px] font-medium {{ $chosen === $question->correctKey ? 'text-green-400' : 'text-red-400' }}">
                            {{ $chosen === $question->correctKey ? 'Correct' : 'Not quite' }}
                        </p>
                        <p class="text-[13.5px] text-ink-muted mt-1">{{ $question->explanation }}</p>
                        <div class="flex justify-end mt-4">
                            <button type="button" wire:click="next" class="btn-primary text-[13px]">
                                {{ $index + 1 >= $attempt->total ? 'See your basics' : 'Next question' }}
                            </button>
                        </div>
                    </div>
                @endif
            </div>

            <div class="h-1 rounded-full bg-surface-2 mt-4 overflow-hidden">
                <div class="h-full bg-gold transition-all" style="width: {{ round(($index + ($chosen !== null ? 1 : 0)) / $attempt->total * 100) }}%"></div>
            </div>
        @endif
    @endif
</div>
