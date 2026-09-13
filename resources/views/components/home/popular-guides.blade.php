{{-- Home's "popular player guides" list. A component only because Home places it in one of two
     spots — above the friends block on a first visit (a new account has no friends yet, and a real
     plan is the most useful thing to look at), below it otherwise. --}}
@props(['guides', 'classColors' => [], 'heading' => 'Popular player guides', 'intro' => null])

@if ($guides->isNotEmpty())
    <section>
        <div class="flex items-baseline justify-between gap-4 {{ $intro ? 'mb-1' : 'mb-3' }}">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">{{ $heading }}</h2>
            <a href="{{ route('guides.browse') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">Browse all &rarr;</a>
        </div>
        @if ($intro)
            <p class="text-[12px] text-ink-subtle mb-3">{{ $intro }}</p>
        @endif
        @foreach ($guides as $guide)
            <x-guides.card :guide="$guide" :class-colors="$classColors" :compact="true" wire:key="pop-{{ $guide->id }}"/>
        @endforeach
    </section>
@endif
