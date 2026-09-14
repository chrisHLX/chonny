{{-- One guide in the Home feed. Built by Home::guideItem(): who did what, the guide, and a preview
     of its first sequence as ability icons in order.

     Unboxed, separated by rules rather than cards: the old dashboard's problem was a dozen bordered
     boxes of equal weight, and a feed reads as one stream only if the items are not each a box.

     The preview is the point. A MindCollector post is not "had a good session" — it is Kidney
     into Polymorph into Cyclone — so the feed shows the plan, not just its title. --}}
@props(['item', 'classColors' => []])

@php
    $guide = $item['guide'];
    $url = $guide->publicUrl();
    $actor = $item['actor'];
    $authorExp = $guide->battlenet_character_id ? $guide->authorCharacter?->bestExp() : null;
    $preview = $item['preview'] ?? null;
@endphp

<article {{ $attributes->merge(['class' => 'py-5 border-b border-line']) }}>
    <p class="text-[12px] text-ink-subtle">
        <span class="text-ink-muted font-medium">&#64;{{ $actor?->handle() ?? 'someone' }}</span>
        {{ $item['verb'] }}
        @if ($item['byCollaborator'])
            &#64;{{ $guide->user?->handle() }}&rsquo;s guide
        @else
            a guide
        @endif
        &middot; {{ $item['at']->diffForHumans() }}
    </p>

    <a href="{{ $url ?? '#' }}" wire:navigate class="block group mt-1.5">
        <h3 class="font-display text-[19px] text-ink group-hover:text-gold transition-colors leading-snug">{{ $guide->title }}</h3>

        <div class="flex items-center gap-1.5 mt-2 flex-wrap">
            @foreach ($guide->members as $m)
                @if ($m->specialization)
                    <x-spec-icon :spec="$m->specialization" size="w-6 h-6"/>
                @endif
            @endforeach
            @if ($guide->enemies->isNotEmpty())
                <span class="text-[11px] text-ink-subtle px-1 font-medium">vs</span>
                @foreach ($guide->enemies as $e)
                    @if ($e->specialization)
                        <x-spec-icon :spec="$e->specialization" size="w-6 h-6"/>
                    @endif
                @endforeach
            @endif
            @if ($bracket = $guide->bracket())
                <span class="badge-gray ml-1">{{ $bracket }}</span>
            @elseif ($guide->isClassGuide())
                <span class="badge-gray ml-1">Class guide</span>
            @endif
        </div>

        @if ($guide->summary)
            <p class="text-[13px] text-ink-muted mt-2 line-clamp-2 max-w-prose">{{ $guide->summary }}</p>
        @endif

        @if ($preview && ($preview['spells'] ?? collect())->isNotEmpty())
            <div class="mt-3">
                <p class="text-[10.5px] uppercase tracking-[0.12em] text-ink-subtle mb-1.5">{{ $preview['title'] }}</p>
                <div class="flex items-center gap-1 flex-wrap">
                    @foreach ($preview['spells'] as $spell)
                        @unless ($loop->first)
                            <svg class="w-3 h-3 text-ink-subtle shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        @endunless
                        <x-spell-icon :spell="$spell" size="w-7 h-7" title="{{ $spell->display_name }}"/>
                    @endforeach
                    @if ($preview['more'] > 0)
                        <span class="text-[11px] text-ink-subtle ml-1">+{{ $preview['more'] }} more</span>
                    @endif
                </div>
            </div>
        @endif
    </a>

    <div class="flex items-center gap-4 mt-3 text-[11.5px] text-ink-subtle">
        <span>
            by <span @if ($c = $guide->authorColor()) style="color: {{ $c }}" @endif>{{ $guide->authorLabel() }}</span>
            @if ($authorExp)
                <span class="text-gold tabular-nums">&middot; {{ $authorExp['rating'] }} exp</span>
            @endif
        </span>
        <span class="flex items-center gap-1 tabular-nums" title="Likes">
            <svg class="w-3.5 h-3.5 {{ $guide->like_count > 0 ? 'text-gold' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"/></svg>
            {{ $guide->like_count }}
        </span>
        <span class="flex items-center gap-1 tabular-nums" title="Views">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            {{ $guide->view_count }}
        </span>
    </div>
</article>
