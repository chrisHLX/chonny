@props(['character', 'showRatings' => true])

{{-- A character's identity line, its exp, its best rank title and this season's ratings.
     Used on the owner's own character pages AND on a guide the owner attributed to this
     character, so the two can never describe the same character differently. --}}
@php
    $classColor = config('wow_classes.colors')[$character->gameClass?->slug] ?? '#8A8A9A';
    $exp = collect(['3v3' => $character->exp_3v3, '2v2' => $character->exp_2v2])->filter(fn ($r) => $r > 0);
    $ratings = $showRatings ? $character->currentRatings() : [];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-start gap-3']) }}>
    @if ($character->specialization)
        <x-spec-icon :spec="$character->specialization" size="w-11 h-11"/>
    @elseif ($character->gameClass)
        <x-class-icon :class="$character->gameClass" size="w-11 h-11"/>
    @endif

    <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold leading-tight truncate" style="color: {{ $classColor }}">
            {{ $character->name }}<span class="text-ink-subtle font-normal">-{{ $character->realm_name }}</span>
        </p>
        <p class="text-[12px] text-ink-muted leading-snug mt-0.5">
            Level {{ $character->level }}
            {{ $character->race }}
            {{ $character->specialization?->name }}
            {{ $character->gameClass?->name }}
            <span class="text-ink-subtle">&middot; {{ strtoupper($character->region) }}</span>
            @if ($character->item_level)
                <span class="text-ink-subtle">&middot; {{ $character->item_level }} ilvl</span>
            @endif
        </p>

        @if ($exp->isNotEmpty() || $character->pvp_rank_title)
            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                @foreach ($exp as $bracket => $rating)
                    <span class="inline-flex items-baseline gap-1 px-2 py-0.5 rounded border border-line-gold bg-gold-subtle"
                          title="Highest {{ $bracket }} personal rating ever reached, from Blizzard's own statistics">
                        <span class="text-[13px] font-semibold text-gold tabular-nums">{{ $rating }}</span>
                        <span class="text-[10px] uppercase tracking-wider text-ink-subtle">{{ $bracket }} exp</span>
                    </span>
                @endforeach

                @if ($character->pvp_rank_title)
                    <span class="badge-blue" title="{{ $character->pvp_rank_title }}">{{ $character->rankTitleShort() }}</span>
                @endif
            </div>
        @endif

        @if ($ratings !== [])
            <div class="flex flex-wrap gap-1.5 mt-2">
                @foreach ($ratings as $r)
                    <span class="inline-flex items-baseline gap-1 px-2 py-0.5 rounded border border-line bg-surface-2">
                        <span class="text-[10px] uppercase tracking-wider text-ink-subtle">
                            {{ $r['label'] }}@if ($r['spec_name'] && in_array($r['label'], ['Solo Shuffle', 'Blitz'])) &middot; {{ $r['spec_name'] }}@endif
                        </span>
                        <span class="text-[12.5px] font-semibold text-ink tabular-nums">{{ $r['rating'] }}</span>
                        <span class="text-[10.5px] text-ink-subtle tabular-nums">{{ $r['won'] }}&ndash;{{ $r['lost'] }}</span>
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</div>
