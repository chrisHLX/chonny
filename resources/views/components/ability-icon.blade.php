{{-- An ability's icon by the Spell row SpellIconIndex found for its name, or, when it has none, a
     tile with its first letter in the class colour: never a broken image or an empty box. --}}
@props(['spell' => null, 'name', 'color' => '#B3B3B3', 'size' => 'w-7 h-7'])

@if ($spell?->icon_name)
    <img src="/storage/spell-icons/{{ $spell->icon_name }}" alt="{{ $name }}" loading="lazy"
         {{ $attributes->merge(['class' => "{$size} rounded-md border border-line object-cover flex-shrink-0"]) }}>
@else
    <span {{ $attributes->merge(['class' => "{$size} rounded-md border flex items-center justify-center flex-shrink-0 font-bold"]) }}
          style="background-color: {{ $color }}22; border-color: {{ $color }}66; color: {{ $color }};" aria-hidden="true">
        <span style="font-size: 0.75em;">{{ mb_substr($name, 0, 1) }}</span>
    </span>
@endif
