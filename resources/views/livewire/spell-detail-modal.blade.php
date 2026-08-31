@php
    $categoryBadge = [
        'Crowd Control' => 'badge-blue',
        'Defensive' => 'badge-red',
        'Utility' => 'badge-amber',
        'Offensive' => 'badge-orange',
        'Other' => 'badge-gray',
    ];
    $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
    // Defensive `?? null` reads throughout this file — see the identical note in
    // wow-comps.blade.php for why (a real production incident, 2026-08-31 fix).
    $cooldownDisplay = fn (array $entry) => ($entry['cooldown']['seconds'] ?? null) !== null ? $fmtSeconds($entry['cooldown']['seconds']) : null;
@endphp

<div>
@if ($entry)
    <div class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="$wire.close()"
         @keydown.escape.window="$wire.close()"
         x-data="{ expandedMod: null }">
        <div class="linear-card max-w-md w-full p-5 relative max-h-[85vh] overflow-y-auto" @click.stop>
            <button type="button" wire:click="close" class="absolute top-3 right-3 text-ink-subtle hover:text-ink">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="flex items-start gap-3 mb-3 pr-6">
                <x-spell-icon :spell="$entry['spell']" size="w-11 h-11" class="rounded-lg shrink-0"/>
                <div class="min-w-0">
                    <p class="text-[15px] font-semibold text-ink">{{ $entry['spell']->display_name }}</p>
                    <p class="text-[10px] text-ink-subtle font-mono">#{{ $entry['spell']->spell_id }}</p>
                    <span class="{{ $categoryBadge[$entry['category']] ?? 'badge-gray' }} mt-1">{{ $entry['category'] }}</span>
                </div>
            </div>

            <p class="text-[13px] text-ink-muted leading-relaxed">{{ $entry['description']['text'] ?: 'No description available.' }}</p>

            @if ($entry['description']['uncertain'])
                <p class="text-[10px] text-ink-subtle italic mt-1.5">Some values above vary by condition or aren't fully known — check in-game.</p>
            @endif
            @if ($entry['formulaModifiers']->isNotEmpty())
                <p class="text-[10px] text-ink-subtle mt-1.5"><span class="font-semibold">Scales with:</span> {{ $entry['formulaModifiers']->pluck('display_name')->implode(', ') }}</p>
            @endif

            @if (!$specId)
                <p class="text-[10px] text-gold/70 italic mt-1.5">No spec context — showing base values, not talent-modified.</p>
            @endif

            <div class="flex items-center gap-4 text-[12px] mt-3 pt-3 border-t border-line">
                <div>
                    <span class="text-ink-subtle">Cooldown</span>
                    <span class="text-ink font-semibold ml-1">{{ $cooldownDisplay($entry) ?? '—' }}</span>
                    @if (($entry['cooldown']['seconds'] ?? null) !== null && ($entry['cooldown']['base_seconds'] ?? null) !== null && round($entry['cooldown']['seconds'], 2) !== round($entry['cooldown']['base_seconds'], 2))
                        <span class="text-[10px] text-ink-subtle line-through ml-1">{{ $fmtSeconds($entry['cooldown']['base_seconds']) }}</span>
                    @endif
                </div>
                @if (($entry['charges']['charges'] ?? null) !== null && $entry['charges']['charges'] > 1)
                    <div>
                        <span class="text-ink-subtle">Charges</span>
                        <span class="text-ink font-semibold ml-1">{{ $entry['charges']['charges'] }}</span>
                        @if (($entry['charges']['base_charges'] ?? null) !== null && $entry['charges']['charges'] !== $entry['charges']['base_charges'])
                            <span class="text-[10px] text-ink-subtle line-through ml-1">{{ $entry['charges']['base_charges'] }}</span>
                        @endif
                    </div>
                @endif
            </div>
            @if ($entry['spell']->cooldown_scaling_note)
                <p class="text-[10px] text-ink-subtle italic mt-1.5">{{ $entry['spell']->cooldown_scaling_note }}</p>
            @endif

            @if ($entry['modifiers']['named']->isNotEmpty())
                <div class="mt-3 pt-3 border-t border-line">
                    <p class="text-[10px] uppercase tracking-wide text-ink-subtle font-semibold mb-1.5">Modifies / Enhances</p>
                    @foreach ($entry['modifiers']['named'] as $mod)
                        @php
                            $modId = $mod['spell']->id;
                            $modCooldownDisplay = ($mod['cooldown']['seconds'] ?? null) !== null ? $fmtSeconds($mod['cooldown']['seconds']) : null;
                        @endphp
                        <div class="mb-1 last:mb-0">
                            <button type="button"
                                    @click="expandedMod = expandedMod === {{ $modId }} ? null : {{ $modId }}"
                                    class="w-full flex items-center gap-1.5 text-left py-0.5 -mx-1 px-1 rounded hover:bg-surface-2 transition-colors">
                                <svg class="w-3 h-3 text-ink-subtle flex-shrink-0 transition-transform" :class="expandedMod === {{ $modId }} && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <x-spell-icon :spell="$mod['spell']" size="w-5 h-5" />
                                <span class="text-[12px] text-ink-muted flex-1 truncate">{{ $mod['spell']->display_name }}</span>
                            </button>
                            <div x-show="expandedMod === {{ $modId }}" x-cloak x-collapse
                                 class="ml-[18px] pl-2.5 border-l border-line mt-1 mb-1.5">
                                <span class="{{ $categoryBadge[$mod['category']] ?? 'badge-gray' }} mb-1">{{ $mod['category'] }}</span>
                                <p class="text-[11px] text-ink-muted leading-relaxed mt-1">{{ $mod['description']['text'] ?: 'No description available.' }}</p>
                                @if ($modCooldownDisplay)
                                    <p class="text-[10px] text-ink-subtle mt-1"><span class="font-semibold">Cooldown</span> {{ $modCooldownDisplay }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
</div>
