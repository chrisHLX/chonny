@props([
    'entry',
    'classId',
    'specId',
    'categoryBadge' => [],
    'drBadge' => [],
    'controlTargetLabel' => [],
    'controlTargetHint' => [],
])

@php
    $spell = $entry['spell'];
    $cdSeconds = $entry['cooldown']['seconds'] ?? null;
    $cd = $cdSeconds !== null ? rtrim(rtrim(number_format((float) $cdSeconds, 2), '0'), '.') : null;
    $isFill = ($entry['role'] ?? null) === 'fill';
    $isAnchor = ($entry['role'] ?? null) === 'anchor';
    $presence = isset($entry['presence']) ? round($entry['presence'] * 100) : null;
@endphp

<button type="button"
        wire:click="$dispatch('show-spell-detail', { spellId: {{ $spell->id }}, classId: {{ $classId }}, specId: {{ $specId }} })"
        class="linear-card !p-2.5 w-40 h-full flex flex-col text-left transition-colors {{ $isAnchor ? 'border-gold/50 hover:border-gold' : 'hover:border-gold/40' }}">

    <div class="flex items-center gap-1.5 mb-1.5">
        <x-spell-icon :spell="$spell" size="w-6 h-6"/>
        <span class="text-[11px] text-ink font-semibold truncate leading-tight">{{ $spell->display_name }}</span>
    </div>

    <div class="flex flex-wrap items-center gap-1 mb-1.5">
        @if ($isAnchor)
            <span class="badge-gold !text-[8px]" title="The cooldown this whole plan is built around — everything else is timed against it.">Anchor</span>
        @endif
        @if ($entry['drCategory'] ?? null)
            <span class="{{ $drBadge[$entry['drCategory']] ?? 'badge-gray' }} !text-[8px]">{{ $entry['drCategory'] }}</span>
        @elseif (($entry['role'] ?? null) === 'interrupt')
            <span class="badge-blue !text-[8px]">Interrupt</span>
        @elseif (!$isAnchor && ($entry['category'] ?? null))
            <span class="{{ $categoryBadge[$entry['category']] ?? 'badge-gray' }} !text-[8px]">{{ $entry['category'] }}</span>
        @endif
        @if ($entry['stacksOnAnotherGlobal'] ?? false)
            <span class="badge-green !text-[8px]" title="In real windows this usually lands inside another ability's global — it costs you no tempo to press.">Free</span>
        @endif
    </div>

    {{-- Where this control is actually used. Measured from real matches wherever there is a
         sample (and then the measurement is shown, not just its verdict); curated or inferred
         only as fallbacks, which are marked so they don't read as equally certain. --}}
    @if (!empty($entry['controlTarget']))
        @php
            $source = $entry['controlTargetSource'] ?? null;
            $measured = $source === 'measured';
            $share = $measured && isset($entry['healerShare']) ? round($entry['healerShare'] * 100) : null;
            $hint = $controlTargetHint[$entry['controlTarget']] ?? '';
            $hint .= match ($source) {
                'measured' => sprintf(
                    ' Measured across %s real applications in archived matches: lands on the enemy healer %d%% of the time, %sx what an evenly-spread ability would hit.',
                    number_format($entry['targetObservations'] ?? 0),
                    $share,
                    number_format((float) ($entry['targetRatio'] ?? 0), 2)
                ),
                'curated' => ' Hand-verified for this ability.',
                default => ' Inferred from its DR category — no match data for this ability yet.',
            };
        @endphp
        <p class="text-[9px] mb-1.5 leading-tight {{ $source === 'inferred' ? 'text-ink-muted' : 'text-gold-light' }}" title="{{ $hint }}">
            {{ $controlTargetLabel[$entry['controlTarget']] ?? $entry['controlTarget'] }}{{ $source === 'inferred' ? '*' : '' }}
            @if ($share !== null)
                <span class="text-ink-subtle">· healer {{ $share }}%</span>
            @endif
        </p>
    @endif

    <div class="flex items-center gap-2 pt-1.5 mt-auto border-t border-line">
        @if ($isFill)
            <div class="flex flex-col leading-none">
                <span class="text-[8px] uppercase tracking-wider text-ink-subtle font-semibold mb-0.5">Per window</span>
                <span class="text-[12px] font-bold text-ink tabular-nums">&times;{{ number_format((float) ($entry['castsPerWindow'] ?? 0), 1) }}</span>
            </div>
        @else
            <div class="flex flex-col leading-none">
                <span class="text-[8px] uppercase tracking-wider text-ink-subtle font-semibold mb-0.5">CD</span>
                <span class="text-[12px] font-bold text-ink tabular-nums">{{ $cd ?? '—' }}<span class="text-[8px] font-bold text-ink">{{ $cd !== null ? 's' : '' }}</span></span>
            </div>
            @if (isset($entry['medianOffset']) && !$isAnchor)
                <div class="flex flex-col leading-none">
                    <span class="text-[8px] uppercase tracking-wider text-ink-subtle font-semibold mb-0.5">At</span>
                    <span class="text-[12px] font-bold text-ink tabular-nums">{{ $entry['medianOffset'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $entry['medianOffset'], 1), '0'), '.') }}<span class="text-[8px] font-bold text-ink">s</span></span>
                </div>
            @endif
        @endif
        @if ($presence !== null)
            <div class="flex flex-col leading-none ml-auto" title="Share of this spec's real burst windows that contain this press.">
                <span class="text-[8px] uppercase tracking-wider text-ink-subtle font-semibold mb-0.5">Used</span>
                <span class="text-[12px] font-bold text-ink-muted tabular-nums">{{ $presence }}<span class="text-[8px] font-bold text-ink-muted">%</span></span>
            </div>
        @endif
    </div>
</button>
