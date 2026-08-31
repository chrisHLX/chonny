@php
    $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
    $splitUnit = fn (string $label) => str_ends_with($label, 's') ? [substr($label, 0, -1), 's'] : [$label, ''];
    // Same box, same color map as WoW Comps' Crowd Control tab (direct request, 2026-08-31:
    // "can we show the same box for the cc ability as the one in our crowd control tab") — see
    // wow-comps.blade.php's own $drBadge for the source of truth this mirrors.
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
@endphp

<div class="max-w-6xl mx-auto px-4 py-8 space-y-5">
    <div class="linear-card px-6 py-5 flex items-center justify-between gap-3 flex-wrap">
        <div>
            <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Top 10 CC Chains</p>
            <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">The Longest Real CC Chains on File</h1>
        </div>
        @if ($lastUpdated)
            <p class="text-[11px] text-ink-subtle whitespace-nowrap">Updated {{ $lastUpdated->diffForHumans() }}</p>
        @endif
    </div>

    @if (empty($chains))
        <div class="linear-card p-5">
            <p class="text-[12px] text-ink-subtle italic">No CC chain data on file yet.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($chains as $i => $chain)
                @php
                    $healerColor = $chain['healerSpec']
                        ? (config('wow_classes.colors')[$chain['healerSpec']->gameClass?->slug] ?? '#8A8A9A')
                        : '#8A8A9A';
                @endphp
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between gap-3 mb-2 flex-wrap">
                        <div class="flex items-center gap-3">
                            <span class="font-display text-[22px] font-bold text-gold w-8">#{{ $i + 1 }}</span>
                            <div>
                                <p class="text-[11px] text-ink-subtle">The comp that landed it</p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    @forelse ($chain['casters'] as $caster)
                                        @php
                                            $casterColor = config('wow_classes.colors')[$caster['classSlug']] ?? '#8A8A9A';
                                        @endphp
                                        <span class="flex items-center gap-1.5">
                                            @if ($caster['spec'])
                                                <x-spec-icon :spec="$caster['spec']" :color="$casterColor" size="w-6 h-6"/>
                                            @endif
                                            <span class="text-[12px] font-semibold" style="color: {{ $casterColor }}">
                                                {{ $caster['spec']?->name ?? ucfirst($caster['specSlug']) }}
                                                {{ $caster['spec']?->gameClass?->name ?? ucfirst($caster['classSlug']) }}
                                            </span>
                                        </span>
                                    @empty
                                        <span class="text-[12px] text-ink-subtle italic">Unknown casters</span>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] uppercase tracking-wide text-ink-subtle">Time in CC</p>
                            <p class="text-[20px] font-bold text-ink font-display tabular-nums">{{ $fmtSeconds($chain['durationSeconds']) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 mb-4">
                        <p class="text-[10px] text-ink-subtle">vs.</p>
                        @if ($chain['healerSpec'])
                            <x-spec-icon :spec="$chain['healerSpec']" :color="$healerColor" size="w-4 h-4"/>
                        @endif
                        <p class="text-[10px] font-semibold" style="color: {{ $healerColor }}">
                            {{ $chain['healerSpec']?->name ?? ucfirst($chain['healerSpecSlug']) }}
                            {{ $chain['healerSpec']?->gameClass?->name ?? ucfirst($chain['healerClassSlug']) }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2.5">
                        @foreach ($chain['steps'] as $step)
                            @php $stepSpell = $step['spell'] ?? null; @endphp
                            @if ($stepSpell)
                                @php
                                    $casterColor = $step['casterClassId']
                                        ? (config('wow_classes.colors')[\App\Models\GameClass::find($step['casterClassId'])?->slug] ?? null)
                                        : null;
                                    $cdSeconds = $stepSpell->cooldown_seconds;
                                    [$cdValue, $cdUnit] = $cdSeconds !== null ? $splitUnit($fmtSeconds((float) $cdSeconds)) : ['—', ''];
                                    [$durValue, $durUnit] = $splitUnit($fmtSeconds($step['realDurationSeconds']));
                                @endphp
                                <button type="button"
                                        wire:click="$dispatch('show-spell-detail', { spellId: {{ $stepSpell->id }}, classId: {{ $step['casterClassId'] ?? 'null' }}, specId: {{ $step['casterSpecId'] ?? 'null' }} })"
                                        class="linear-card !p-3 w-44 flex-shrink-0 text-left hover:border-gold/40 transition-colors {{ $step['isDrDimmed'] ? 'opacity-60' : '' }}"
                                        @if ($step['isDrDimmed']) title="Repeats a diminishing-returns category already used earlier in this chain" @endif>
                                    <div class="flex items-center gap-2 mb-2">
                                        <x-spell-icon :spell="$stepSpell" size="w-8 h-8"/>
                                        <span class="text-[12px] text-ink font-semibold truncate">{{ $stepSpell->display_name }}</span>
                                    </div>
                                    <span class="flex items-center gap-1 flex-wrap">
                                        <span class="{{ $drBadge[$step['drCategory']] ?? 'badge-gray' }} !text-[9px]">{{ $step['drCategory'] }}</span>
                                        @if ($step['isDrDimmed'])
                                            <span class="badge-gray !text-[8px]">DR'd</span>
                                        @endif
                                    </span>
                                    <div class="flex items-center gap-3 mt-2.5 pt-2.5 border-t border-line">
                                        <div class="flex flex-col leading-none">
                                            <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">CD</span>
                                            <span class="text-[15px] font-bold text-ink tabular-nums">{{ $cdValue }}<span class="text-[10px] font-bold text-ink">{{ $cdUnit }}</span></span>
                                        </div>
                                        <div class="w-px h-7 bg-line"></div>
                                        <div class="flex flex-col leading-none">
                                            <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">Dur</span>
                                            <span class="text-[15px] font-bold text-ink tabular-nums">{{ $durValue }}<span class="text-[10px] font-bold text-ink">{{ $durUnit }}</span></span>
                                        </div>
                                    </div>
                                    <p class="text-[10px] font-semibold truncate mt-2.5" style="{{ $casterColor ? 'color: '.$casterColor : '' }}">{{ $step['source'] ?? '' }}</p>
                                </button>
                            @else
                                <div class="linear-card !p-3 w-44 flex-shrink-0">
                                    <span class="block text-[12px] text-ink font-semibold truncate">{{ $step['name'] ?? 'Unknown ability' }}</span>
                                    <span class="block text-[10px] text-ink-subtle truncate mt-1">{{ $step['source'] ?? '' }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    @if ($chain['distinctCasters'])
                        <p class="text-[10px] text-ink-subtle/70 mt-3">{{ $chain['distinctCasters'] }} distinct caster(s) contributed to this chain.</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <livewire:spell-detail-modal/>
</div>
