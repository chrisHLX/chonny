{{-- resources/views/components/dashboard/current-focus.blade.php --}}
@props(['profile' => null])

@php
    if (!$profile) return;

    $growthArea         = $profile['primary_growth_area'] ?? null;
    $growthAreaName     = is_array($growthArea) ? ($growthArea['name'] ?? '') : ($profile['growth_area'] ?? '');
    $growthAreaPattern  = $profile['growth_area_pattern'] ?? '';
    // Fall back to the old archetype-level field only for profiles generated before growth_area_pattern existed.
    $displayPattern     = $growthAreaPattern ?: ($profile['likely_in_game_pattern'] ?? '');
@endphp

@if ($growthAreaName)
    <div class="linear-card p-5 relative overflow-hidden border border-violet/20">
        <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-violet/20"/>
        <p class="text-[10px] font-semibold text-violet uppercase tracking-widest mb-2">Current Focus</p>
        <p class="text-[14px] font-medium text-ink mb-2">{{ $growthAreaName }}</p>
        @if ($displayPattern)
            <p class="text-[12px] text-ink-muted italic leading-relaxed">{{ $displayPattern }}</p>
        @endif
        <p class="text-[11px] text-ink-subtle mt-3">Mindcollector currently thinks this is worth investigating.</p>
    </div>
@endif
