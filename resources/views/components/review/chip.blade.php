@props(['chip', 'icons' => []])

@php
    // An ability in a pattern: its icon, its name, how often, and (for crowd control) who it landed
    // on. Data: MatchAnalysisService::patterns().
    $roleLabel = ['healer' => 'on their healer', 'target' => 'on the target', 'cross' => 'cross CC'];
@endphp

<div class="flex items-center gap-2.5 p-2 rounded border border-line bg-surface-2 min-w-0">
    <x-spell-icon :spell="$icons[$chip['spell']] ?? (object) ['icon_name' => null, 'display_name' => $chip['spell']]" size="w-9 h-9"/>
    <div class="flex-1 min-w-0">
        <p class="text-[12.5px] text-ink leading-tight truncate">{{ $chip['spell'] }}</p>
        @if ($chip['role'])
            <p class="text-[10.5px] {{ $chip['role'] === 'healer' ? 'text-gold' : 'text-ink-subtle' }} leading-tight">{{ $roleLabel[$chip['role']] ?? '' }}</p>
        @elseif ($chip['hint'])
            <p class="text-[10.5px] text-ink-subtle leading-tight">{{ $chip['hint'] }}</p>
        @endif
    </div>
    <div class="text-right shrink-0">
        <p class="text-[14px] font-semibold text-ink tabular-nums leading-tight">{{ $chip['value'] }}</p>
        @if ($chip['role'] && $chip['hint'])
            <p class="text-[10px] text-ink-subtle leading-tight">{{ $chip['hint'] }}</p>
        @endif
    </div>
</div>
