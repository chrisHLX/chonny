{{-- resources/views/components/admin/game-spell-card.blade.php --}}
@props(['spell', 'source', 'context' => null])

@php
    $sourceBadge = match ($source) {
        'Baseline' => 'badge-blue',
        'Class Talent' => 'badge-gold',
        'Spec Talent' => 'badge-green',
        'Hero Talent' => 'badge-gray',
        'PvP Talent' => 'badge-amber',
        default => 'badge-gray',
    };
@endphp

<div class="linear-card p-4" x-data="{ open: false }">
    <div class="flex items-start gap-3">
        {{-- No icon data exists anywhere in this schema — plain placeholder, not a fetched asset. --}}
        <div class="w-9 h-9 rounded bg-surface-2 border border-line flex items-center justify-center shrink-0">
            <x-mc-icon name="icon-scroll" class="w-4 h-4 text-ink-subtle"/>
        </div>

        <div class="flex-1 min-w-0">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[13px] font-semibold text-ink truncate">{{ $spell->name }}</p>
                    <span class="text-[10px] text-ink-subtle font-mono">#{{ $spell->spell_id }}</span>
                </div>
                <button type="button" @click="open = !open" class="text-[11px] text-gold hover:text-gold-light shrink-0 whitespace-nowrap">
                    <span x-text="open ? 'Collapse' : 'Expand'"></span>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2 mt-1.5">
                <span class="{{ $sourceBadge }}">{{ $source }}</span>
                @if ($context)
                    <span class="text-[10px] text-ink-subtle">{{ $context }}</span>
                @endif
            </div>

            <p class="text-[12px] text-ink-muted mt-2 line-clamp-2">{{ $spell->description ?: '— no description —' }}</p>
        </div>
    </div>

    <div x-show="open" x-collapse class="mt-3 pt-3 border-t border-line space-y-3">
        <div>
            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1">Description</p>
            <p class="text-[12px] text-ink-muted whitespace-pre-line">{{ $spell->description ?: '—' }}</p>
        </div>

        <div class="grid grid-cols-2 gap-3 text-[11px]">
            <div><span class="text-ink-subtle">School:</span> <span class="text-ink">{{ $spell->school ?? '—' }}</span></div>
            <div><span class="text-ink-subtle">Effects:</span> <span class="text-ink">{{ $spell->effects->count() }}</span></div>
        </div>

        @if ($spell->effects->isNotEmpty())
            <div>
                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1">Effects</p>
                <div class="space-y-1">
                    @foreach ($spell->effects->sortBy('effect_index') as $effect)
                        <div class="text-[11px] text-ink-muted font-mono">
                            #{{ $effect->effect_index }} {{ $effect->type ?? '—' }}
                            @if ($effect->base_value !== null) base={{ $effect->base_value }} @endif
                            @if ($effect->scaled_value !== null) scaled={{ $effect->scaled_value }} @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1">
                    Modifies ({{ $spell->outgoingRelationships->count() }})
                </p>
                @forelse ($spell->outgoingRelationships as $rel)
                    <div class="text-[11px] text-ink-muted mb-1.5">
                        <span class="text-ink">{{ $rel->targetSpell?->name ?? 'Unknown spell' }}</span>
                        @if ($rel->targetSpell)
                            <span class="text-ink-subtle font-mono">#{{ $rel->targetSpell->spell_id }}</span>
                        @endif
                        @if ($rel->description)
                            <div class="text-ink-subtle">{{ $rel->description }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-[11px] text-ink-subtle">—</p>
                @endforelse
            </div>

            <div>
                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1">
                    Modified By ({{ $spell->incomingRelationships->count() }})
                </p>
                @forelse ($spell->incomingRelationships as $rel)
                    <div class="text-[11px] text-ink-muted mb-1.5">
                        <span class="text-ink">{{ $rel->sourceSpell?->name ?? 'Unknown spell' }}</span>
                        @if ($rel->sourceSpell)
                            <span class="text-ink-subtle font-mono">#{{ $rel->sourceSpell->spell_id }}</span>
                        @endif
                        @if ($rel->description)
                            <div class="text-ink-subtle">{{ $rel->description }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-[11px] text-ink-subtle">—</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
