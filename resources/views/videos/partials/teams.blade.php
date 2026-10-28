{{-- Both teams of a game on video by spec icon, the recording player's team first (VideoController).
     $g is YoutubeVideo::game; $size the icon's Tailwind size. --}}
@php
    $colors = config('wow_classes.colors', []);
    $size ??= 'w-7 h-7';
@endphp
<span class="inline-flex items-center gap-1.5 flex-wrap">
    @foreach (['us', 'them'] as $side)
        @if ($side === 'them')<span class="text-[12px] text-ink-subtle font-semibold px-0.5">vs</span>@endif
        @foreach ($g[$side] ?? [] as $p)
            @php $c = $colors[$p['classSlug'] ?? ''] ?? '#8A8A9A'; @endphp
            @if (! empty($p['icon']))
                <img src="{{ $p['icon'] }}" alt="{{ $p['spec'] }}" title="{{ $p['spec'] }}{{ ! empty($p['pov']) ? ' (recording)' : '' }}" loading="lazy"
                     class="{{ $size }} rounded-md border object-cover" style="border-color: {{ ! empty($p['pov']) ? '#C8952C' : $c.'66' }}">
            @else
                <span title="{{ $p['spec'] }}" class="{{ $size }} rounded-md border inline-flex items-center justify-center text-[11px] font-bold"
                      style="background: {{ $c }}22; border-color: {{ $c }}66; color: {{ $c }}">{{ mb_substr($p['spec'], 0, 1) }}</span>
            @endif
        @endforeach
    @endforeach
</span>
