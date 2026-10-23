<x-app-layout title="Second check | Training | MindCollector">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-5">
    @include('training.partials.top')
    <div class="max-w-2xl space-y-3">
        <p class="page-eyebrow">Reasoning check · form B</p>
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl">Not open yet</h1>
        @if (! $formA['done'])
            <p class="text-[15px] text-ink-muted">Take the <a class="text-gold hover:text-gold-light" href="{{ route('training.check', 'A') }}">baseline check</a> first. Form B only means something compared with it.</p>
        @else
            <p class="text-[15px] text-ink-muted">The second check opens after you complete {{ \App\Training\Assessment::B_AFTER_LESSONS }} lessons, or {{ \App\Training\Assessment::B_AFTER_DAYS }} days after the baseline. Testing too soon mostly measures memory of the lessons, not whether the ideas have settled.</p>
        @endif
    </div>
</div>
</x-app-layout>
