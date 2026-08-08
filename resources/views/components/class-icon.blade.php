@props(['class', 'size' => 'w-8 h-8'])

@php
    // Host-relative path, not Storage::disk('public')->url() — same reasoning as
    // <x-spell-icon>: resolves against whatever domain actually served the page, immune to
    // an APP_URL/real-serving-domain mismatch (e.g. Herd's .test domains).
    $url = $class->icon_name ? '/storage/class-icons/'.$class->icon_name : null;
    $color = config('wow_classes.colors')[$class->slug] ?? '#8A8A9A';
@endphp

@if ($url)
    <img
        src="{{ $url }}"
        alt="{{ $class->name }}"
        loading="lazy"
        {{ $attributes->merge(['class' => "{$size} rounded-md border object-cover flex-shrink-0"])->merge(['style' => "border-color: {$color}66"]) }}
    >
@else
    {{-- Icons not fetched yet in this environment (see data/spelldata/fetch-class-spec-icons.php)
         — a colored initial badge using the class's real in-game color, never a broken <img>. --}}
    <div
        {{ $attributes->merge(['class' => "{$size} rounded-md border flex items-center justify-center flex-shrink-0 font-display font-bold"]) }}
        style="background-color: {{ $color }}22; border-color: {{ $color }}66; color: {{ $color }};"
    >
        <span style="font-size: 0.6em;">{{ Str::substr($class->name, 0, 1) }}</span>
    </div>
@endif
