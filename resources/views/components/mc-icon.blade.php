@props(['name'])

@php
    $path = public_path("images/icons/{$name}.svg");
    $svg  = file_exists($path) ? file_get_contents($path) : '';
    $attrs = $attributes->toHtml();
    if ($svg && $attrs) {
        $svg = preg_replace('/<svg\b/', '<svg ' . $attrs, $svg, 1);
    }
@endphp

{!! $svg !!}
