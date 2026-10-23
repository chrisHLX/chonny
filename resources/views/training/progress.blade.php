{{-- Progress (TrainingController::progress): the reasoning check before and after, mastery on new
     situations, calibration from claims, and the journal of first readings against reflections. --}}
<x-app-layout title="Progress | Training | MindCollector">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-10">
    @include('training.partials.top')
    <header class="max-w-3xl space-y-3">
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl sm:text-4xl leading-[1.05]">Is your reasoning changing?</h1>
        <p class="text-[15px] text-ink-muted">Three kinds of evidence, from weakest to strongest: following the lessons, applying ideas to new situations, and doing better on problems you've never seen.</p>
    </header>

    <section class="space-y-3">
        <h2 class="text-ink font-bold text-xl">Reasoning check</h2>
        @if (! $formA['done'])
            <p class="text-[14px] text-ink-muted">You haven't taken the baseline yet. <a class="text-gold hover:text-gold-light" href="{{ route('training.check', 'A') }}">Take it now</a>, before more lessons, so there's something to compare against.</p>
        @else
            <div class="overflow-x-auto max-w-2xl"><table class="t-tbl">
                <thead><tr><th>Concept</th><th>Baseline (A)</th><th>Second check (B)</th></tr></thead>
                <tbody>
                @foreach ($formA['byConcept'] as $concept => $right)
                    <tr><td>{{ $curriculum->concept($concept)['name'] ?? $concept }}</td><td>{{ $right ? 'Right' : 'Wrong' }}</td><td>@if ($formB['done']){{ ($formB['byConcept'][$concept] ?? false) ? 'Right' : 'Wrong' }}@else<span class="text-ink-subtle">not yet</span>@endif</td></tr>
                @endforeach
                <tr><td><b class="text-ink">Total</b></td><td><b class="text-ink">{{ $formA['score'] }} / {{ $formA['total'] }}</b></td><td>@if ($formB['done'])<b class="text-ink">{{ $formB['score'] }} / {{ $formB['total'] }}</b>@elseif ($formBOpen)<a class="text-gold" href="{{ route('training.check', 'B') }}">Take it</a>@else<span class="text-ink-subtle">not open yet</span>@endif</td></tr>
                </tbody>
            </table></div>
            <p class="text-[13px] text-ink-subtle max-w-2xl">One person and six questions per check is a small sample: one lucky guess moves the score. Read a change per concept as a hint, and the pattern across many players as the evidence.</p>
        @endif
    </section>

    <section class="space-y-3">
        <h2 class="text-ink font-bold text-xl">Mastery by concept</h2>
        <p class="text-[13.5px] text-ink-muted max-w-3xl">The violet bar counts each lesson's <b class="text-ink">transfer</b> question (a different domain), right on the first attempt. The grey bar is the in-lesson question for comparison. A gap between them means the idea hasn't moved beyond its first example yet.</p>
        <div class="space-y-2 max-w-3xl">
            @foreach ($mastery as $m)
                @continue($m['attempted'] === 0 && $m['caseAttempted'] === 0)
                <div class="bar-row">
                    <span>{{ $m['name'] }}</span>
                    <span class="t"><i class="case" style="width:{{ $m['caseAttempted'] ? round($m['caseFirstTry'] / $m['caseAttempted'] * 100) : 0 }}%"></i><i style="width:{{ $m['attempted'] ? round($m['firstTry'] / $m['attempted'] * 100) : 0 }}%;top:25%;bottom:25%"></i></span>
                    <span class="v">{{ $m['firstTry'] }} / {{ $m['attempted'] }}</span>
                </div>
            @endforeach
        </div>
        @if (collect($mastery)->sum('attempted') === 0)<p class="text-[14px] text-ink-muted">No transfer questions answered yet.</p>@endif
    </section>

    <section class="space-y-3">
        <h2 class="text-ink font-bold text-xl">Calibration</h2>
        @php($hi = $calibration['high'])
        @php($lo = $calibration['low'])
        @if ($hi->isEmpty() && $lo->isEmpty())
            <p class="text-[14px] text-ink-muted max-w-3xl">When you examine a claim you give a confidence. When you come back and record what happened, this shows whether your confidence matched reality. <a class="text-gold hover:text-gold-light" href="{{ route('training.claims.create') }}">Examine a claim</a>.</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 max-w-2xl">
                <div class="linear-card p-4"><p class="text-[12px] text-ink-subtle">Claims you were 70% or more sure of</p><p class="text-2xl font-extrabold text-ink tabular-nums mt-1">{{ $hi->where('outcome', 'held')->count() }} / {{ $hi->whereIn('outcome', ['held', 'failed'])->count() }} <span class="text-[13px] font-medium text-ink-subtle">held up</span></p></div>
                <div class="linear-card p-4"><p class="text-[12px] text-ink-subtle">Claims you were under 70% sure of</p><p class="text-2xl font-extrabold text-ink tabular-nums mt-1">{{ $lo->where('outcome', 'held')->count() }} / {{ $lo->whereIn('outcome', ['held', 'failed'])->count() }} <span class="text-[13px] font-medium text-ink-subtle">held up</span></p></div>
            </div>
            <p class="text-[13px] text-ink-subtle max-w-2xl">Well calibrated means your confident claims hold up more often than your unsure ones, and about as often as you said. "Still unclear" outcomes are left out.</p>
        @endif
    </section>

    <section class="space-y-3">
        <h2 class="text-ink font-bold text-xl">Journal: first reading and reflection</h2>
        @forelse ($journal as $j)
            <article class="linear-card !bg-surface-1 p-4">
                <p class="text-[12px] text-ink-subtle"><a class="text-gold hover:text-gold-light" href="{{ route('training.lesson', $j['lesson']['slug']) }}">{{ $j['lesson']['title'] }}</a> · {{ $j['firstAt']->format('j M Y') }}</p>
                <div class="grid gap-4 sm:grid-cols-2 mt-2 text-[14px]">
                    <div><p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">Before the lesson</p><p class="whitespace-pre-wrap text-ink-muted mt-1">{{ $j['first'] }}</p></div>
                    <div><p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">After</p><p class="whitespace-pre-wrap text-ink-muted mt-1">{{ $j['reflection'] ?? '—' }}</p></div>
                </div>
            </article>
        @empty
            <p class="text-[14px] text-ink-muted">Your first readings appear here as you start lessons.</p>
        @endforelse
    </section>
</div>
</x-app-layout>
