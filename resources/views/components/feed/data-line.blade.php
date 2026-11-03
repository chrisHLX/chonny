{{-- The newest game-data update as one line on the signed-in home page (2026-11-03), in place of a
     feed item for every import: five "Tooltips updated" items once led the page. Numeric changes
     (cooldown, charges, duration) are named, up to three; reworded tooltips are a count. --}}
@props(['item'])

@php
    $changes = $item['changes'];
    $abilityCount = $changes->pluck('spell_id')->unique()->count();
@endphp

<p {{ $attributes->merge(['class' => 'text-[12.5px] text-ink-subtle mb-3']) }}>
    <span class="text-violet font-medium">Game data update</span>
    @if ($item['update']->build_version) &middot; build {{ $item['update']->build_version }}@endif
    &middot; {{ $item['at']->diffForHumans() }}:
    @if ($abilityCount > 0)
        {{ $abilityCount }} {{ Str::plural('ability', $abilityCount) }} changed
        @foreach ($changes->take(3) as $change)
            <a href="{{ route('spell.show', $change->spell_id) }}" wire:navigate class="text-ink-muted hover:text-gold" wire:key="chg-{{ $change->id }}">{{ $change->spell->display_name }}
                {{ $change->label() }} <span class="tabular-nums">{{ $change->display($change->old_value) }}</span> &rarr; <span class="text-gold tabular-nums">{{ $change->display($change->new_value) }}</span></a>@if (! $loop->last),@endif
        @endforeach
        @if ($changes->count() > 3) and {{ $changes->count() - 3 }} more @endif
    @elseif ($item['tooltips'] > 0)
        tooltip text changed on {{ $item['tooltips'] }} {{ Str::plural('ability', $item['tooltips']) }}
    @endif
</p>
