{{-- The loss rules' items: your side's buttons first, then what the other team brought. --}}
@php
    $ours = array_values(array_filter($items, fn ($i) => ! $i['them']));
    $theirs = array_values(array_filter($items, fn ($i) => $i['them']));
@endphp

@if ($items === [])
    <div class="muted">Nothing the rules can pin on a button. The log cannot see positioning or calls.</div>
@endif

<table class="look" style="width:100%">
    @foreach (array_merge($ours, $theirs) as $i)
        <tr>
            <td class="ic">
                @if ($i['icon'])
                    <img class="spell-ic" src="{{ $i['icon'] }}">
                @else
                    <span class="dot" style="background: {{ $i['color'] }}"></span>
                @endif
            </td>
            <td>
                <b style="color: {{ $i['color'] }}">{{ $i['owner'] }}</b>
                <span class="{{ $i['them'] ? 'muted' : '' }}">&nbsp;{{ $i['text'] }}</span>
            </td>
            <td class="count">@if ($i['count'] > 1)&times;{{ $i['count'] }}@endif</td>
        </tr>
    @endforeach
</table>
