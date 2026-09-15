@props(['titles', 'compact' => false])

{{-- A character's (or account's) highest 3v3 and Solo Shuffle titles — BattlenetCharacter::
     bracketTitles(). One definition, so a character's page and a guide byline cannot describe
     the same player differently.

     Compact (bylines, listings) shows only LIFETIME titles — Rank 1, Gladiator, Legend. A
     this-season rank beside a name in a listing reads as a permanent title, and the gap between
     "Duelist this season" and "Duelist" is exactly the part a scanning reader would miss. --}}
@php
    $entries = collect($titles)
        ->reject(fn ($t) => $compact && $t['this_season'])
        ->map(function ($t) {
            $count = $t['count'] > 1 ? " \u{00D7}{$t['count']}" : '';

            return $t + [
                'mark' => $t['rank_one'] ? 'Rank 1'.$count : ($t['this_season'] ? '' : trim($count)),
                'where' => $t['bracket'] === 'Solo Shuffle' ? 'Shuffle' : $t['bracket'],
                'when' => $t['this_season'] ? trim(($t['spec'] ?? '').' this season') : '',
            ];
        });
@endphp

@if ($compact)
    @foreach ($entries as $t)
        <span class="text-gold" title="{{ $t['tooltip'] }}">&middot; {{ $t['title'] }}
            @if ($t['mark'])
                <span class="{{ $t['rank_one'] ? 'text-violet font-semibold' : 'tabular-nums' }}">{{ $t['rank_one'] ? '(Rank 1)' : $t['mark'] }}</span>
            @endif
        </span>
    @endforeach
@else
    @foreach ($entries as $t)
        <span class="inline-flex items-baseline gap-1 px-2 py-0.5 rounded border {{ $t['this_season'] ? 'border-line bg-surface-2' : 'border-line-gold bg-gold-subtle' }}"
              title="{{ $t['tooltip'] }}">
            <span class="text-[12.5px] font-semibold {{ $t['this_season'] ? 'text-ink' : 'text-gold' }}">{{ $t['title'] }}</span>
            @if ($t['mark'])
                <span class="text-[10.5px] tabular-nums {{ $t['rank_one'] ? 'font-semibold uppercase tracking-wider text-violet' : 'text-gold' }}">{{ $t['mark'] }}</span>
            @endif
            <span class="text-[10px] uppercase tracking-wider text-ink-subtle">{{ $t['where'] }}</span>
            @if ($t['when'])
                <span class="text-[10.5px] text-ink-subtle">{{ $t['when'] }}</span>
            @endif
        </span>
    @endforeach
@endif
