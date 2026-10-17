{{-- One question, its options and, once answered, whether it was right and why. Shared by every
     game's quiz page: the right answer is only marked after the player has chosen. Needs
     $question, $chosen, $index and $attempt. --}}
<div class="linear-card p-5" wire:key="q-{{ $index }}">
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
