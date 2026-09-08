@props(['guide', 'classColors' => [], 'showAuthor' => true, 'compact' => false])

{{-- One definition of how a guide is summarised in a listing, shared by the browse page, a guild's
     page and the comp listing on /wow-comps. Three copies of this markup would drift, and a reader
     comparing guides across those pages would be comparing differently-shaped claims. --}}
@php
    $roster = $guide->relationLoaded('members') ? $guide->members : $guide->members()->get();
    $enemy = $guide->relationLoaded('enemies') ? $guide->enemies : $guide->enemies()->get();
    $rating = $guide->rating_avg !== null ? number_format((float) $guide->rating_avg, 1) : null;
@endphp

<a href="{{ $guide->publicUrl() ?? '#' }}" wire:navigate
   class="linear-card {{ $compact ? 'p-3' : 'p-4' }} mb-3 block hover:border-line-gold transition-colors">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h3 class="font-display {{ $compact ? 'text-[15px]' : 'text-[17px]' }} text-ink truncate">
                {{ $guide->title }}
            </h3>

            @if (! $compact && $guide->summary)
                <p class="text-[12.5px] text-ink-muted mt-0.5 line-clamp-2">{{ $guide->summary }}</p>
            @endif

            {{-- The comp, and who it is against. Icons rather than names: this is a listing, and a
                 reader scanning for their own comp recognises the icons faster than the words. --}}
            <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                @foreach ($roster as $m)
                    @if ($m->specialization)
                        <x-spec-icon :spec="$m->specialization" size="w-6 h-6"/>
                    @endif
                @endforeach

                @if ($enemy->isNotEmpty())
                    <span class="text-[11px] text-ink-subtle px-0.5">vs</span>
                    @foreach ($enemy as $e)
                        @if ($e->specialization)
                            <x-spec-icon :spec="$e->specialization" size="w-6 h-6"/>
                        @endif
                    @endforeach
                @endif

                @if ($bracket = $guide->bracket())
                    <span class="badge-gray ml-1">{{ $bracket }}</span>
                @endif
            </div>

            @if ($showAuthor)
                <p class="text-[11px] text-ink-subtle mt-1.5">
                    by {{ $guide->user?->username ?? 'unknown' }}
                    @isset($guide->comments_count)
                        &middot; {{ $guide->comments_count }} {{ Str::plural('comment', $guide->comments_count) }}
                    @endisset
                </p>
            @endif
        </div>

        {{-- Rating, and the count beside it. An average with no count is not a claim anyone can
             weigh — 5.0 from one person is not 5.0 from forty. --}}
        <div class="shrink-0 text-right">
            @if ($rating)
                <p class="font-display text-[17px] text-gold tabular-nums leading-none">{{ $rating }}</p>
                <p class="text-[10.5px] text-ink-subtle mt-0.5 tabular-nums">
                    {{ $guide->rating_count }} {{ Str::plural('rating', $guide->rating_count) }}
                </p>
            @else
                <p class="text-[10.5px] text-ink-subtle">Not rated yet</p>
            @endif
        </div>
    </div>
</a>
