@php
    $color = config('wow_classes.colors')[$spec->gameClass->slug] ?? '#8A8A9A';
    $indexUrl = route('wow-quiz', ['classSlug' => $spec->gameClass->slug, 'specSlug' => $spec->slug]);
@endphp

<div class="max-w-2xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between gap-3 mb-5">
        <a href="{{ $indexUrl }}" wire:navigate class="flex items-center gap-2 min-w-0 group">
            <x-spec-icon :spec="$spec" :color="$color" size="w-8 h-8"/>
            <div class="min-w-0">
                <p class="text-[13px] text-ink truncate group-hover:text-gold transition-colors">{{ $specLabel }}</p>
                <p class="text-[11.5px] text-ink-subtle">Drill &middot; {{ $concept->name }}</p>
            </div>
        </a>
        @if ($attempt && ! $showResults)
            <span class="text-[12.5px] text-ink-muted tabular-nums shrink-0">{{ $index + 1 }} / {{ $attempt->total }}</span>
        @endif
    </div>

    @if (! $attempt)
        <div class="linear-card p-6 text-center">
            <p class="text-[14px] text-ink">There isn't enough verified data to drill {{ $concept->name }} as {{ $specLabel }} yet.</p>
            <a href="{{ $indexUrl }}" wire:navigate class="btn-ghost text-[13px] mt-4 inline-block">Back to {{ $specLabel }}</a>
        </div>
    @elseif ($showResults)
        <div class="linear-card p-6 text-center">
            <p class="text-[12px] uppercase tracking-widest text-ink-subtle">This drill</p>
            <p class="font-display text-5xl mt-2 text-gold tabular-nums">{{ $attempt->score }} / {{ $attempt->total }}</p>
            <p class="text-[14px] text-ink-muted mt-3">
                Every drill asks a new mix, built from the spell data as it is right now. There is no pass mark and
                nothing here is called mastered.
            </p>
            <div class="flex flex-wrap justify-center gap-2 mt-5">
                <button type="button" wire:click="retry" class="btn-primary text-[13px]">Drill again</button>
                <a href="{{ $indexUrl }}" wire:navigate class="btn-ghost text-[13px]">All drills</a>
            </div>
            @if ($brainSections)
                <p class="text-[12.5px] text-ink-subtle mt-5 pt-4 border-t border-line">
                    Why {{ strtolower($concept->name) }} matters is argued in the
                    <a href="{{ route('brain') }}#{{ $brainSections[0] }}" class="text-gold hover:underline">arena model</a>,
                    where you can disagree with it.
                </p>
            @endif
            @guest
                <p class="text-[12.5px] text-ink-subtle mt-3">
                    <a href="{{ route('register') }}" class="text-gold hover:underline">Create an account</a> to keep your record.
                </p>
            @endguest
        </div>
    @elseif ($question)
        <div class="linear-card p-5" wire:key="q-{{ $attempt->id }}-{{ $index }}">
            @if ($question->subject)
                <div class="flex items-center gap-3 mb-4">
                    @if ($question->subject['icon'])
                        <img src="{{ $question->subject['icon'] }}" alt="" class="w-12 h-12 rounded border border-line-gold">
                    @endif
                    <p class="text-[15px] font-medium text-ink">{{ $question->subject['label'] }}</p>
                </div>
            @endif

            <p class="text-[17px] text-ink leading-snug">{{ $question->prompt }}</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4">
                @foreach ($question->options as $option)
                    @php
                        // The right answer is only marked once the player has chosen.
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
                            {{ $index + 1 >= $attempt->total ? 'See your score' : 'Next question' }}
                        </button>
                    </div>
                </div>
            @endif
        </div>

        <div class="h-1 rounded-full bg-surface-2 mt-4 overflow-hidden">
            <div class="h-full bg-gold transition-all" style="width: {{ round(($index + ($chosen !== null ? 1 : 0)) / $attempt->total * 100) }}%"></div>
        </div>
    @endif
</div>
