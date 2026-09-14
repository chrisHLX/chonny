{{-- A game-data update in the Home feed: abilities whose cooldown, charges or duration changed in an
     import. Built by Home::dataUpdates() from SpellChangeRecorder's rows.

     Only numeric changes are listed one by one. Reworded tooltips are a count at most, because a
     parser change in this codebase can rewrite descriptions without the game changing; Home drops
     the count entirely above a hundred rather than present that as a patch. --}}
@props(['item'])

@php
    $changes = $item['changes'];
    $shown = $changes->take(8);
    $abilityCount = $changes->pluck('spell_id')->unique()->count();
@endphp

<article {{ $attributes->merge(['class' => 'py-5 border-b border-line']) }}>
    <p class="text-[12px] text-ink-subtle">
        <span class="text-violet font-medium">Game data update</span>
        @if ($item['update']->build_version)
            &middot; build {{ $item['update']->build_version }}
        @endif
        &middot; {{ $item['at']->diffForHumans() }}
    </p>

    <h3 class="font-display text-[19px] text-ink mt-1.5 leading-snug">
        @if ($abilityCount > 0)
            {{ $abilityCount }} {{ Str::plural('ability', $abilityCount) }} changed
        @else
            Tooltips updated
        @endif
    </h3>

    @if ($shown->isNotEmpty())
        <div class="mt-3 space-y-1.5">
            @foreach ($shown as $change)
                <a href="{{ route('spell.show', $change->spell_id) }}" wire:navigate
                   class="flex items-center gap-2.5 group" wire:key="chg-{{ $change->id }}">
                    <x-spell-icon :spell="$change->spell" size="w-6 h-6"/>
                    <span class="text-[13px] text-ink group-hover:text-gold truncate">{{ $change->spell->display_name }}</span>
                    <span class="text-[12px] text-ink-subtle shrink-0">
                        {{ $change->label() }}
                        <span class="text-ink-muted tabular-nums">{{ $change->display($change->old_value) }}</span>
                        &rarr;
                        <span class="text-gold tabular-nums">{{ $change->display($change->new_value) }}</span>
                    </span>
                </a>
            @endforeach
        </div>
        @if ($changes->count() > $shown->count())
            <p class="text-[11.5px] text-ink-subtle mt-2">and {{ $changes->count() - $shown->count() }} more {{ Str::plural('change', $changes->count() - $shown->count()) }}</p>
        @endif
    @endif

    @if ($item['tooltips'] > 0)
        <p class="text-[12px] text-ink-subtle mt-2">
            Tooltip text changed on {{ $item['tooltips'] }} {{ Str::plural('ability', $item['tooltips']) }}.
        </p>
    @endif
</article>
