@props(['guide', 'classColors' => [], 'showAuthor' => true, 'compact' => false])

{{-- One definition of how a guide is summarised in a listing, shared by the browse page, a guild's
     page and the comp listing on /wow-comps. Three copies of this markup would drift, and a reader
     comparing guides across those pages would be comparing differently-shaped claims. --}}
@php
    $roster = $guide->relationLoaded('members') ? $guide->members : $guide->members()->get();
    $enemy = $guide->relationLoaded('enemies') ? $guide->enemies : $guide->enemies()->get();
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
                 reader scanning for their own comp recognises the icons faster than the words.

                 The enemy side is LABELLED ("vs" plus the spec names underneath), not just six
                 icons in a row. Unlabelled, a matchup guide reads as a six-person comp, and the
                 half that makes it a matchup — the thing someone is most likely searching for —
                 is the half that was hardest to see. --}}
            <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                @foreach ($roster as $m)
                    @if ($m->specialization)
                        <x-spec-icon :spec="$m->specialization" size="w-6 h-6"/>
                    @endif
                @endforeach

                @if ($enemy->isNotEmpty())
                    <span class="text-[11px] text-ink-subtle px-1 font-medium">vs</span>
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

            @if ($enemy->isNotEmpty())
                <p class="text-[11px] text-ink-subtle mt-1">
                    vs {{ $enemy->map(fn ($e) => trim(($e->specialization?->name ?? '').' '.($e->specialization?->gameClass?->name ?? '')))->filter()->implode(' / ') }}
                </p>
            @endif

            @if ($showAuthor)
                <p class="text-[11px] text-ink-subtle mt-1.5">
                    by {{ $guide->user?->username ?? 'unknown' }}
                    @isset($guide->comments_count)
                        &middot; {{ $guide->comments_count }} {{ Str::plural('comment', $guide->comments_count) }}
                    @endisset
                </p>
            @endif
        </div>

        {{-- Likes and views. Deliberately no "not liked yet" placeholder where "Not rated yet"
             used to sit: on an arena site "rating" reads as Current Rating, so that line looked
             like a claim about the team rather than about the guide. Two plain counts say what
             they mean and cannot be misread. --}}
        <div class="shrink-0 text-right flex sm:flex-col items-center sm:items-end gap-3 sm:gap-0.5">
            <span class="flex items-center gap-1 text-[11px] text-ink-subtle tabular-nums" title="{{ $guide->like_count }} {{ Str::plural('like', $guide->like_count) }}">
                <svg class="w-3.5 h-3.5 {{ $guide->like_count > 0 ? 'text-gold' : '' }}" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"/>
                </svg>
                {{ $guide->like_count }}
            </span>
            <span class="flex items-center gap-1 text-[11px] text-ink-subtle tabular-nums" title="{{ $guide->view_count }} {{ Str::plural('view', $guide->view_count) }}">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                {{ $guide->view_count }}
            </span>
        </div>
    </div>
</a>
