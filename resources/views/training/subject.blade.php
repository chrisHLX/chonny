<x-app-layout :title="$subject['title'].' | Training | MindCollector'" :description="$subject['summary']">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-6">
    @include('training.partials.top')
    <p class="text-[12px] text-ink-subtle"><a href="{{ route('training') }}" class="hover:text-ink">Training</a> / {{ str_pad($subject['number'], 2, '0', STR_PAD_LEFT) }}</p>
    <header class="max-w-3xl space-y-3">
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl sm:text-4xl leading-[1.05]">{{ $subject['title'] }}</h1>
        <p class="text-[15.5px] text-ink-muted">{{ $subject['summary'] }}</p>
        <p class="text-[14px] text-ink-subtle italic">The practical question: {{ $subject['question'] }}</p>
        @if ($subject['slug'] === 'cybernetics')
            <p class="text-[14px] text-ink-muted">The material to read is in the <a class="text-gold hover:text-gold-light" href="{{ route('training.reading', 'steering-by-feedback') }}">field manual, Steering by Feedback</a>. These lessons test the same ideas on situations you haven't seen.</p>
        @endif
    </header>
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($lessons as $l)
            <a class="linear-card p-5 space-y-2 block" href="{{ route('training.lesson', $l['slug']) }}">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">Lesson {{ $l['number'] }} · {{ $l['minutes'] }} min · {{ $l['case']['domain'] }}</span>
                    @if ($progress->isComplete($l['slug']))<span class="badge-green">Complete</span>@elseif ($progress->isStarted($l['slug']))<span class="badge-gray">Started</span>@endif
                </div>
                <h3 class="text-ink font-bold">{{ $l['title'] }}</h3>
                <p class="text-[14px] text-ink-muted">{{ $l['summary'] }}</p>
                <div class="flex flex-wrap gap-1.5">@foreach ($l['concepts'] as $c)<span class="badge-blue">{{ $curriculum->concept($c)['name'] ?? $c }}</span>@endforeach</div>
            </a>
        @endforeach
    </div>
</div>
</x-app-layout>
