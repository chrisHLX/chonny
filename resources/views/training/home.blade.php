{{-- Training's home (TrainingController::home). Signed in: today's revisits first (the phone visit),
     then where to continue, the reasoning check and the subjects. Visitors: what Training is. --}}
<x-app-layout title="Training: reasoning for the AI era | MindCollector"
              description="Lessons in feedback loops, causal reasoning, statistics and decisions, built on competitive games. Notes you make come back, so you can see what changed your mind.">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-8">
    @include('training.partials.top')

    <section class="page-hero">
        <p class="page-eyebrow">Training</p>
        <h1 class="page-title max-w-3xl">Learn to steer by evidence.</h1>
        <p class="text-[15px] text-ink-muted mt-4 max-w-2xl">
            AI makes explanations cheap. Judging whether they're true is still your job. Each lesson starts with a real problem,
            asks what you think before teaching anything, then tests whether the idea carries over to a situation you haven't seen.
            Competitive games are where it starts: a small world with a full record of what happened.
        </p>
        <div class="mt-5">@include('training.partials.method')</div>
    </section>

    @guest
        <section class="grid gap-3 sm:grid-cols-3">
            <div class="linear-card p-5 space-y-2"><p class="page-eyebrow">Problem first</p><h3 class="text-ink font-bold">You reason before you're told</h3><p class="text-[14px] text-ink-muted">Every lesson opens with an ambiguous situation. You write your reading of it, then see evidence that may change it.</p></div>
            <div class="linear-card p-5 space-y-2"><p class="page-eyebrow">Notes that come back</p><h3 class="text-ink font-bold">Mark what you don't understand</h3><p class="text-[14px] text-ink-muted">Highlight any passage or add a note. Confusing passages and questions come back for a revisit, where you record whether your understanding moved.</p></div>
            <div class="linear-card p-5 space-y-2"><p class="page-eyebrow">Transfer, not recall</p><h3 class="text-ink font-bold">Measured on new situations</h3><p class="text-[14px] text-ink-muted">Mastery counts only questions set in a different domain, answered right first time. A before-and-after check measures whether your reasoning changed.</p></div>
        </section>
        <div class="flex flex-wrap gap-3"><a class="btn-primary" href="{{ route('register') }}">Create a free account</a><a class="btn-secondary" href="{{ route('login') }}">Sign in</a></div>
    @endguest

    @auth
        <section class="grid gap-3 lg:grid-cols-3">
            <div class="linear-card p-5 space-y-3 lg:col-span-2">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-ink font-bold text-[17px]">Today</h2>
                    <span class="text-[12px] text-ink-subtle">{{ $dueNotes->count() + $dueClaims->count() }} waiting for a revisit</span>
                </div>
                @forelse ($dueNotes->take(4) as $n)
                    <a href="{{ route('training.notebook') }}" class="block rounded-md bg-surface-2/60 hover:bg-surface-2 px-3 py-2.5">
                        <span class="kind kind-{{ $n->kind->value }}">{{ $n->kind->label() }}</span>
                        <span class="text-[13.5px] text-ink-muted ml-2">“{{ Str::limit($n->quote, 90) }}”</span>
                    </a>
                @empty
                    @if ($dueClaims->isEmpty())
                        <p class="text-[14px] text-ink-muted">Nothing to revisit today. Notes you mark <i>Confusing</i> or <i>Question</i> come back the next day; others after three or seven days.</p>
                    @endif
                @endforelse
                @foreach ($dueClaims->take(2) as $c)
                    <a href="{{ route('training.claims.show', $c) }}" class="block rounded-md bg-surface-2/60 hover:bg-surface-2 px-3 py-2.5">
                        <span class="badge-amber">Claim</span>
                        <span class="text-[13.5px] text-ink-muted ml-2">{{ Str::limit($c->claim, 90) }}: what happened?</span>
                    </a>
                @endforeach
                @if ($dueNotes->count() > 4)<a class="text-[13px] text-gold hover:text-gold-light" href="{{ route('training.notebook') }}">All {{ $dueNotes->count() }} in the notebook</a>@endif
            </div>
            <div class="linear-card p-5 space-y-3">
                <p class="page-eyebrow">Course</p>
                <p class="text-3xl font-extrabold text-ink tabular-nums">{{ $progress->completedCount() }} <span class="text-ink-subtle text-xl">/ {{ count($curriculum->lessons()) }}</span></p>
                <p class="text-[12px] text-ink-subtle -mt-2">lessons complete</p>
                <div class="h-1.5 rounded-full bg-surface-3 overflow-hidden"><div class="h-full bg-gold" style="width: {{ round($progress->completedCount() / max(1, count($curriculum->lessons())) * 100) }}%"></div></div>
                @if ($nextLesson)
                    <a class="btn-primary w-full" href="{{ route('training.lesson', $nextLesson['slug']) }}">{{ $progress->isStarted($nextLesson['slug']) ? 'Continue' : 'Start' }}: {{ $nextLesson['title'] }}</a>
                @endif
                <p class="text-[12.5px] text-ink-subtle">
                    @if (! $formA['done'])
                        <a class="text-gold hover:text-gold-light" href="{{ route('training.check', 'A') }}">Take the reasoning check</a> first: six short problems, a baseline to measure against later.
                    @elseif (! $formB['done'])
                        Baseline {{ $formA['score'] }} / {{ $formA['total'] }}. @if ($formBOpen)<a class="text-gold hover:text-gold-light" href="{{ route('training.check', 'B') }}">Take the second check</a>.@else The second check opens after {{ \App\Training\Assessment::B_AFTER_LESSONS }} lessons or {{ \App\Training\Assessment::B_AFTER_DAYS }} days.@endif
                    @else
                        Reasoning check {{ $formA['score'] }} → {{ $formB['score'] }} of {{ $formB['total'] }}. <a class="text-gold hover:text-gold-light" href="{{ route('training.progress') }}">Progress</a>
                    @endif
                </p>
            </div>
        </section>
    @endauth

    <section>
        <div class="flex items-baseline justify-between gap-3 mb-3">
            <h2 class="text-ink font-bold text-xl">Subjects</h2>
            <span class="text-[12px] text-ink-subtle">{{ count($curriculum->subjects()) }} subjects · {{ count($curriculum->lessons()) }} lessons</span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($curriculum->subjects() as $s)
                @php($done = $progress ? collect($s['lessons'])->filter(fn ($l) => $progress->isComplete($l))->count() : 0)
                <a class="linear-card p-5 space-y-2 block" href="{{ auth()->check() ? route('training.subject', $s['slug']) : route('login') }}">
                    <p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">{{ str_pad($s['number'], 2, '0', STR_PAD_LEFT) }} · {{ count($s['lessons']) }} {{ Str::plural('lesson', count($s['lessons'])) }}@auth · {{ $done }} done @endauth</p>
                    <h3 class="text-ink font-bold">{{ $s['title'] }}</h3>
                    <p class="text-[14px] text-ink-muted">{{ $s['summary'] }}</p>
                    <p class="text-[13px] text-ink-subtle italic">{{ $s['question'] }}</p>
                </a>
            @endforeach
        </div>
    </section>

    <section class="linear-card !bg-surface-1 p-6 max-w-3xl">
        <h2 class="text-ink font-bold text-lg">Four questions for any claim</h2>
        <ol class="mt-3 space-y-2 list-decimal pl-5 text-[14.5px] text-ink-muted">
            <li><b class="text-ink">What do we actually know?</b> Separate the observation from the story told about it.</li>
            <li><b class="text-ink">What else could explain it?</b> Other causes, missing information, how the data was selected.</li>
            <li><b class="text-ink">What would change our confidence?</b> The evidence that would support or weaken each explanation, named before you look.</li>
            <li><b class="text-ink">What happens if we act on it?</b> Immediate effects, delayed effects, and how we'd know whether it worked.</li>
        </ol>
        @auth<a class="btn-secondary mt-4" href="{{ route('training.claims.create') }}">Examine a claim with these questions</a>@endauth
    </section>
</div>
</x-app-layout>
