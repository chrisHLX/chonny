@props(['event', 'colours', 'links' => [], 'drBadge' => []])

@php
    // One go, rendered the way a guide sequence renders a step: the time in the number column,
    // then the abilities with their icons, then what it cost them. Deliberately the same shape as
    // <x-guides.section-steps> so a reader only has to learn it once.
    $colour = $colours[$event['side']] ?? '#8A8A9A';
    $side = $event['side'] === 'a' ? 'Team A' : 'Team B';
    $clock = sprintf('%d:%02d', intdiv((int) $event['t'], 60), (int) $event['t'] % 60);

    // Profiles carry EXTERNAL spell ids; /wow/spell/{id} wants the internal one. An id the
    // lookup could not resolve renders as plain text rather than a link to the wrong spell.
    $link = fn (?int $externalId) => $externalId && isset($links[$externalId])
        ? route('spell.show', ['spellId' => $links[$externalId]])
        : null;
@endphp

<li @class([
        'flex items-start gap-3 p-2.5 rounded border bg-surface-2',
        'border-line' => ! $event['killWindow'],
        'border-gold/60 bg-gold-subtle' => $event['killWindow'],
    ])>

    <span class="font-display text-[14px] tabular-nums pt-0.5 w-9 shrink-0" style="color: {{ $colour }}">{{ $clock }}</span>

    <div class="flex-1 min-w-0 space-y-1.5">
        <div class="flex items-center gap-1.5 flex-wrap text-[11px]">
            <span class="font-semibold" style="color: {{ $colour }}">{{ $side }}</span>
            @if ($event['target'])
                <span class="text-ink-subtle">onto</span>
                <span class="text-ink font-medium">{{ $event['target'] }}</span>
            @else
                <span class="text-ink-subtle">nobody reachable</span>
            @endif
        </div>

        @if ($event['controlSpent'] !== [])
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                @foreach ($event['controlSpent'] as $control)
                    <span class="inline-flex items-center gap-1.5">
                        <x-spell-icon :spell="(object) ['icon_name' => $control['icon'], 'display_name' => $control['spell']]" size="w-6 h-6"/>
                        @php $href = $link($control['spellId'] ?? null); @endphp
                        @if ($href)
                            <a href="{{ $href }}" wire:navigate class="text-[11.5px] text-ink hover:text-gold transition-colors">{{ $control['spell'] }}</a>
                        @else
                            <span class="text-[11.5px] text-ink">{{ $control['spell'] }}</span>
                        @endif
                        @if ($control['category'])
                            <span class="{{ $drBadge[$control['category']] ?? 'badge-gray' }} !text-[9px]">{{ $control['category'] }}</span>
                        @endif
                        <span class="text-[10px] text-ink-subtle tabular-nums">{{ $control['seconds'] }}s</span>
                        @if ($control['onRole'] === 'healer')
                            <span class="text-[10px] text-ink-subtle">on healer</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif

        @if ($event['burst'] !== [])
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                @foreach ($event['burst'] as $burst)
                    <span class="inline-flex items-center gap-1.5">
                        <x-spell-icon :spell="(object) ['icon_name' => $burst['icon'], 'display_name' => $burst['spell']]" size="w-6 h-6"/>
                        @php $href = $link($burst['spellId'] ?? null); @endphp
                        @if ($href)
                            <a href="{{ $href }}" wire:navigate class="text-[11.5px] text-ink hover:text-gold transition-colors">{{ $burst['spell'] }}</a>
                        @else
                            <span class="text-[11.5px] text-ink">{{ $burst['spell'] }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif

        @if ($event['spent'] !== [])
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 pt-0.5 border-t border-line/60">
                <span class="text-[10px] uppercase tracking-wide text-ink-subtle">Forced</span>
                @foreach ($event['spent'] as $spent)
                    <span class="inline-flex items-center gap-1.5">
                        <x-spell-icon :spell="(object) ['icon_name' => $spent['icon'], 'display_name' => $spent['spell']]" size="w-6 h-6"/>
                        @php $href = $link($spent['spellId'] ?? null); @endphp
                        @if ($href)
                            <a href="{{ $href }}" wire:navigate class="text-[11.5px] text-ink-muted hover:text-gold transition-colors">{{ $spent['spell'] }}</a>
                        @else
                            <span class="text-[11.5px] text-ink-muted">{{ $spent['spell'] }}</span>
                        @endif
                        @if ($spent['kind'] === 'trinket')
                            <span class="badge-gold !text-[9px]">trinket</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @elseif ($event['peeledBy'])
            <p class="text-[11px] text-ink-subtle pt-0.5 border-t border-line/60">
                Peeled off by {{ $event['peeledBy']['spell'] }} — nothing spent.
            </p>
        @endif
    </div>

    <span class="shrink-0 self-start">
        @if ($event['killWindow'])
            <span class="badge-gold">Window opens</span>
        @elseif ($event['lockedOut'])
            <span class="badge-amber">Still empty</span>
        @elseif ($event['target'])
            <span class="text-[10px] text-ink-subtle tabular-nums whitespace-nowrap">{{ $event['remaining'] }} left</span>
        @endif
    </span>
</li>
