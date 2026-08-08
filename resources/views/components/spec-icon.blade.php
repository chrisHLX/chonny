@props(['spec', 'color' => null, 'size' => 'w-8 h-8'])

@php
    $url = $spec->icon_name ? '/storage/spec-icons/'.$spec->icon_name : null;
    $color = $color ?? (config('wow_classes.colors')[$spec->gameClass?->slug] ?? '#8A8A9A');
@endphp

@if ($url)
    <img
        src="{{ $url }}"
        alt="{{ $spec->name }}"
        loading="lazy"
        {{ $attributes->merge(['class' => "{$size} rounded-md border object-cover flex-shrink-0"])->merge(['style' => "border-color: {$color}66"]) }}
    >
@else
    {{-- Icons not fetched yet in this environment (see data/spelldata/fetch-class-spec-icons.php)
         — a colored initial badge using the parent class's real in-game color. --}}
    <div
        {{ $attributes->merge(['class' => "{$size} rounded-md border flex items-center justify-center flex-shrink-0 font-display font-bold"]) }}
        style="background-color: {{ $color }}22; border-color: {{ $color }}66; color: {{ $color }};"
    >
        <span style="font-size: 0.55em;">{{ Str::substr($spec->name, 0, 1) }}</span>
    </div>
@endif
