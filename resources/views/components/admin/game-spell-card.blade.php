{{-- resources/views/components/admin/game-spell-card.blade.php --}}
{{-- One <tr> — expects to be rendered inside a <table><tbody> with 4 columns:
     Spell / Description / CD / What Modifies It. --}}
@props(['spell', 'source', 'context' => null])

@php
    $sourceBadge = match ($source) {
        'Baseline' => 'badge-blue',
        'Class Talent' => 'badge-gold',
        'Spec Talent' => 'badge-green',
        'Hero Talent' => 'badge-gray',
        'Talent' => 'badge-gold',
        'PvP Talent' => 'badge-amber',
        default => 'badge-gray',
    };

    // Decimal-cast column — comes back as a string ("180.00"), not a float. Trim to the shortest
    // exact representation (180s, 1.5s) rather than always showing two decimal places.
    $cooldownDisplay = $spell->cooldown_seconds !== null
        ? rtrim(rtrim(number_format((float) $spell->cooldown_seconds, 2), '0'), '.').'s'
        : null;

    $relTypeBadge = fn (string $type) => match ($type) {
        'modifies_charges' => 'badge-amber',
        'replaces' => 'badge-green',
        default => 'badge-blue',
    };
    $relTypeLabel = fn (string $type) => match ($type) {
        'modifies_charges' => 'Charges',
        'replaces' => 'Replaces',
        'modifies' => 'Effect',
        default => \Illuminate\Support\Str::headline($type),
    };
@endphp

<tr class="border-b border-line align-top">
    <td class="pl-5 pr-4 py-3 min-w-[10rem]">
        <p class="text-[13px] font-semibold text-ink">{{ $spell->display_name }}</p>
        <span class="text-[10px] text-ink-subtle font-mono">#{{ $spell->spell_id }}</span>
        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
            <span class="{{ $sourceBadge }}">{{ $source }}</span>
            @if ($context)
                <span class="text-[10px] text-ink-subtle">{{ $context }}</span>
            @endif
        </div>
    </td>
    <td class="pr-4 py-3 text-[12px] text-ink-muted max-w-md">
        {{ $spell->description ?: '—' }}
    </td>
    <td class="pr-4 py-3 text-[12px] text-ink whitespace-nowrap">
        {{ $cooldownDisplay ?? '—' }}
        @if ($spell->charges !== null && $spell->charges > 1)
            <span class="text-ink-subtle">&middot; {{ $spell->charges }} charges</span>
        @endif
    </td>
    <td class="pr-5 py-3 text-[12px]">
        @forelse ($spell->incomingRelationships as $rel)
            <div class="mb-1 last:mb-0 flex items-center gap-1.5 flex-wrap">
                <span class="{{ $relTypeBadge($rel->relationship_type) }}">{{ $relTypeLabel($rel->relationship_type) }}</span>
                <span class="text-ink-muted">{{ $rel->sourceSpell?->name ?? 'Unknown spell' }}</span>
            </div>
        @empty
            <span class="text-ink-subtle">—</span>
        @endforelse
    </td>
</tr>
