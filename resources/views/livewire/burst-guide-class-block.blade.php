@php
    // Same badge maps as WowComps/Claude's Guides/Top 10 CC Chains — reused verbatim so a spell
    // card here looks like every other spell card on the site.
    $categoryBadge = [
        'Crowd Control' => 'badge-blue',
        'Defensive' => 'badge-red',
        'Mobility' => 'badge-green',
        'Utility' => 'badge-amber',
        'Offensive' => 'badge-orange',
        'Other' => 'badge-gray',
    ];
    $drBadge = [
        'Stun' => 'badge-red',
        'Disorient' => 'badge-blue',
        'Incapacitate' => 'badge-amber',
        'Root' => 'badge-green',
        'Silence' => 'badge-gray',
        'Knockback' => 'badge-orange',
        'Disarm' => 'badge-gold',
        'Slow' => 'badge-gray',
    ];
    $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
    $splitUnit = fn (string $s) => [rtrim($s, 's'), 's'];
@endphp

{{-- Livewire requires exactly one persistent root element on every render, even when the
     visible content is entirely conditional (see SpellDetailModal's own precedent for this
     exact pattern) — a class with no resolvable data renders this wrapper empty, not "no root
     tag at all". --}}
<div>
@if ($guide['class'] && !empty($guide['specs']))
    @php $classColor = config('wow_classes.colors')[$guide['class']->slug] ?? '#8A8A9A'; @endphp
    <div class="linear-card px-6 py-5">
        <div class="flex items-center gap-2.5 mb-4">
            <x-class-icon :class="$guide['class']" size="w-7 h-7"/>
            <h2 class="font-display text-[18px] font-bold" style="color: {{ $classColor }}">{{ $guide['class']->name }}</h2>
        </div>

        <div class="space-y-4">
            @foreach ($guide['specs'] as $s)
                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <x-spec-icon :spec="$s['spec']" :color="$classColor" size="w-5 h-5"/>
                        <p class="text-[12.5px] font-semibold text-ink">{{ $s['spec']->name }}</p>
                        @if ($s['meta']['sourceLengthSeconds'])
                            <span class="badge-gray !text-[9px]">from a real {{ $s['meta']['sourceLengthSeconds'] }}s window</span>
                        @endif
                        @if ($s['meta']['truncatedAtRepeat'] ?? false)
                            <span class="badge-gold !text-[9px]" title="Stopped once the sequence started repeating">stops at repeat</span>
                        @endif
                    </div>

                    <div class="overflow-x-auto pb-1">
                        <ol class="flex items-center gap-1.5 w-max">
                            @foreach ($s['steps'] as $entry)
                                @php
                                    $spell = $entry['spell'];
                                    $cdSeconds = $entry['cooldown']['seconds'] ?? null;
                                    [$cdValue, $cdUnit] = $cdSeconds !== null ? $splitUnit($fmtSeconds((float) $cdSeconds)) : ['—', ''];
                                @endphp
                                <li>
                                    <button type="button"
                                            wire:click="$dispatch('show-spell-detail', { spellId: {{ $spell->id }}, classId: {{ $guide['class']->id }}, specId: {{ $s['spec']->id }} })"
                                            class="linear-card !p-2.5 w-36 flex-shrink-0 text-left hover:border-gold/40 transition-colors">
                                        <div class="flex items-center gap-1.5 mb-1.5">
                                            <x-spell-icon :spell="$spell" size="w-6 h-6"/>
                                            <span class="text-[11px] text-ink font-semibold truncate leading-tight">{{ $spell->display_name }}</span>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-1 mb-1.5">
                                            @if ($spell->dr_category)
                                                <span class="{{ $drBadge[$spell->dr_category] ?? 'badge-gray' }} !text-[8px]">{{ $spell->dr_category }}</span>
                                            @else
                                                <span class="{{ $categoryBadge[$entry['category']] ?? 'badge-gray' }} !text-[8px]">{{ $entry['category'] }}</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 pt-1.5 border-t border-line">
                                            <div class="flex flex-col leading-none">
                                                <span class="text-[8px] uppercase tracking-wider text-ink-subtle font-semibold mb-0.5">CD</span>
                                                <span class="text-[12px] font-bold text-ink tabular-nums">{{ $cdValue }}<span class="text-[8px] font-bold text-ink">{{ $cdUnit }}</span></span>
                                            </div>
                                            @if ($spell->pvp_duration_seconds)
                                                <div class="flex flex-col leading-none">
                                                    <span class="text-[8px] uppercase tracking-wider text-ink-subtle font-semibold mb-0.5">Dur</span>
                                                    <span class="text-[12px] font-bold text-ink tabular-nums">{{ rtrim(rtrim(number_format($spell->pvp_duration_seconds, 1), '0'), '.') }}<span class="text-[8px] font-bold text-ink">s</span></span>
                                                </div>
                                            @endif
                                        </div>
                                    </button>
                                </li>
                                @if (!$loop->last)
                                    <li class="text-ink-subtle text-[11px] shrink-0">→</li>
                                @endif
                            @endforeach
                        </ol>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
</div>
