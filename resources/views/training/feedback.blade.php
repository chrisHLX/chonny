{{-- Course feedback (admin): where the lessons aren't working. The course's own feedback loop. --}}
<x-app-layout title="Course feedback | Training | MindCollector">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-8">
    @include('training.partials.top')
    <header class="max-w-3xl space-y-3">
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl leading-[1.05]">Where the lessons aren't working</h1>
        <p class="text-[15px] text-ink-muted">Passages players marked confusing, questioned or disagreed with, and the wrong answers they chose first. {{ $learners }} {{ Str::plural('player', $learners) }} have used Training so far.</p>
    </header>
    @forelse ($rows as $row)
        <section class="space-y-3">
            <h2 class="text-ink font-bold text-lg"><a class="hover:text-gold" href="{{ route('training.lesson', $row['lesson']['slug']) }}">{{ $row['lesson']['title'] }}</a></h2>
            @if ($row['passages'])
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($row['passages'] as $p)
                        <div class="n-item">
                            <div class="meta"><span>{{ $p['learners'] }} {{ Str::plural('player', $p['learners']) }}</span>@foreach ($p['kinds'] as $k => $n)<span class="kind kind-{{ $k }}">{{ $k }} × {{ $n }}</span>@endforeach<span>{{ $p['block'] }}</span></div>
                            <div class="quote">“{{ $p['quote'] }}”</div>
                            @foreach ($p['bodies'] as $b)<div class="body text-[13px] text-ink-muted">— {{ $b }}</div>@endforeach
                        </div>
                    @endforeach
                </div>
            @endif
            @foreach ($row['questions'] as $q)
                <div class="linear-card !bg-surface-1 p-4 space-y-2">
                    <p class="text-[12px] text-ink-subtle">{{ $q['exercise'] === 'transfer' ? 'Transfer question' : 'Case question' }} · {{ $q['rightFirst'] }} of {{ $q['n'] }} right first time</p>
                    <p class="text-[14.5px] text-ink font-semibold">{{ $q['question'] }}</p>
                    @foreach ($q['options'] as $i => $o)
                        <div class="bar-row"><span class="text-[13px]">{{ chr(65 + $i) }}. {{ Str::limit($o['text'], 70) }} @if ($o['correct'])<b class="text-emerald-400">✓</b>@endif</span><span class="t"><i style="width:{{ round($o['chosen'] / max(1, $q['n']) * 100) }}%;{{ $o['correct'] ? '' : 'background:var(--t-r)' }}"></i></span><span class="v">{{ $o['chosen'] }}</span></div>
                    @endforeach
                </div>
            @endforeach
        </section>
    @empty
        <p class="text-[14px] text-ink-muted">No flags or answers yet.</p>
    @endforelse
</div>
</x-app-layout>
