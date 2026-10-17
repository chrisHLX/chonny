@php $indexUrl = route('sc2-quiz', ['race' => $race]); @endphp

<div class="max-w-2xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between gap-3 mb-5">
        <a href="{{ $indexUrl }}" wire:navigate class="flex items-center gap-2 min-w-0 group">
            <x-mc-icon name="badge-sc2" class="w-8 h-8 shrink-0" style="color: {{ $color }}"/>
            <div class="min-w-0">
                <p class="text-[13px] text-ink truncate group-hover:text-gold transition-colors">{{ $raceName }}</p>
                <p class="text-[11.5px] text-ink-subtle">Level {{ $level }} &middot; {{ $levelInfo->title }}</p>
            </div>
        </a>
        @if ($attempt && ! $showResults)
            <span class="text-[12.5px] text-ink-muted tabular-nums shrink-0">{{ $index + 1 }} / {{ $attempt->total }}</span>
        @endif
    </div>

    @if (! $attempt)
        <div class="linear-card p-6 text-center">
            <p class="text-[14px] text-ink">This quiz could not be built just now.</p>
            <a href="{{ $indexUrl }}" wire:navigate class="btn-ghost text-[13px] mt-4 inline-block">Back to levels</a>
        </div>
    @elseif ($showResults)
        @php $passed = $attempt->passed(); @endphp
        <div class="linear-card p-6 text-center">
            <p class="text-[12px] uppercase tracking-widest text-ink-subtle">Your score</p>
            <p class="font-display text-5xl mt-2 {{ $passed ? 'text-gold' : 'text-ink' }} tabular-nums">{{ $attempt->score }} / {{ $attempt->total }}</p>
            <p class="text-[14px] text-ink-muted mt-3">
                {{ $passed ? 'Passed. On to the next level.' : 'Get '.(int) ceil($attempt->total * \App\Models\QuizAttempt::PASS_RATIO).' right to pass. Every attempt asks a new mix of questions.' }}
            </p>
            <div class="flex flex-wrap justify-center gap-2 mt-5">
                @if ($passed && $nextLevel)
                    <a href="{{ route('sc2-quiz.play', ['race' => $race, 'level' => $nextLevel->number]) }}" wire:navigate
                       class="btn-primary text-[13px]">Level {{ $nextLevel->number }}: {{ $nextLevel->title }}</a>
                @endif
                <button type="button" wire:click="retry" class="{{ $passed ? 'btn-ghost' : 'btn-primary' }} text-[13px]">Try again</button>
                <a href="{{ $indexUrl }}" wire:navigate class="btn-ghost text-[13px]">All levels</a>
            </div>
            @guest
                <p class="text-[12.5px] text-ink-subtle mt-4">
                    <a href="{{ route('register') }}" class="text-gold hover:underline">Create an account</a> to keep your scores.
                </p>
            @endguest
        </div>
    @elseif ($question)
        @include('livewire.quizzes.partials.question')
    @endif
</div>
