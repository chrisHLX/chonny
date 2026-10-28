{{-- One line about a game on video: bracket, result, MMR, when, views. $v is a YoutubeVideo. --}}
@php $g = $v->game ?? []; @endphp
<span>{{ $g['bracket'] ?? 'Arena' }}</span>
@if (isset($g['record']))
    &middot; <span class="{{ $g['record'][0] > $g['record'][1] ? 'text-emerald-400' : 'text-red-400' }}">{{ $g['record'][0] }}-{{ $g['record'][1] }}</span>
@elseif (isset($g['won']))
    &middot; <span class="{{ $g['won'] ? 'text-emerald-400' : 'text-red-400' }}">{{ $g['won'] ? 'Won' : 'Lost' }}</span>
@endif
@if (! empty($g['mmr'])) &middot; {{ number_format($g['mmr']) }} MMR @endif
@if ($v->played_at) &middot; {{ $v->played_at->format('j M Y') }} @endif
@if ($v->views !== null) &middot; {{ number_format($v->views) }} {{ $v->views === 1 ? 'view' : 'views' }} @endif
