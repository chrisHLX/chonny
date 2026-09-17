@props(['rows'])

{{-- The class quiz leaderboard: promotes the quizzes and ranks signed-in players by questions
     answered. Shared by the front page and Home. Data from QuizService::leaderboard(). --}}
<div {{ $attributes->merge(['class' => 'rounded-lg border border-line-gold bg-gold-subtle/40 p-4']) }}>
    <div class="flex items-baseline justify-between gap-3">
        <h2 class="text-[11px] uppercase tracking-[0.13em] text-gold font-semibold">Class quizzes</h2>
        <a href="{{ route('wow-quiz') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">All specs &rarr;</a>
    </div>
    <p class="text-[12.5px] text-ink-muted mt-1.5">
        How well do you know your kit? Cooldowns, crowd control and DR, built from live game data.
    </p>

    <h3 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mt-4 mb-1.5">Most questions answered</h3>

    @if ($rows->isEmpty())
        <p class="text-[12.5px] text-ink-subtle">Nobody's on the board yet. Take a quiz and be first.</p>
    @else
        <ol class="space-y-1">
            @foreach ($rows as $i => $row)
                @php $isMe = auth()->id() === $row->user->id; @endphp
                <li class="flex items-center gap-2 text-[13px] {{ $isMe ? 'text-gold' : 'text-ink-muted' }}">
                    <span class="w-5 text-right tabular-nums shrink-0 {{ $i < 3 ? 'text-gold font-semibold' : 'text-ink-subtle' }}">{{ $i + 1 }}</span>
                    <span class="flex-1 min-w-0 truncate">&#64;{{ $row->user->handle() }}</span>
                    <span class="tabular-nums shrink-0" title="{{ $row->correct }} correct">{{ number_format($row->answered) }}</span>
                </li>
            @endforeach
        </ol>
    @endif

    <a href="{{ route('wow-quiz') }}" wire:navigate class="btn-primary text-[13px] w-full justify-center mt-4">Take a quiz</a>

    @guest
        <p class="text-[11.5px] text-ink-subtle mt-2 text-center">
            <a href="{{ route('register') }}" class="text-gold hover:underline">Create an account</a> to get on the board.
        </p>
    @endguest
</div>
