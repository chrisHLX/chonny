@props([
    'position' => 'tl', // tl | tr | bl | br
])

@php
    $rotate = match($position) {
        'tr' => 'rotate-90',
        'br' => 'rotate-180',
        'bl' => '-rotate-90',
        default => 'rotate-0', // tl
    };
@endphp

<svg {{ $attributes->merge(['class' => "absolute pointer-events-none select-none {$rotate}"]) }}
     viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg"
     aria-hidden="true">
    {{-- Corner bracket lines --}}
    <path d="M2 30 L2 2 L30 2" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"/>
    {{-- Inner ornament lines --}}
    <path d="M8 20 L8 8 L20 8" stroke="currentColor" stroke-width="0.75" stroke-linecap="round" stroke-linejoin="round" opacity="0.6"/>
    {{-- Corner diamond --}}
    <rect x="0" y="0" width="4" height="4" transform="translate(0,0) rotate(45 2 2)" fill="currentColor" opacity="0.8"/>
</svg>
