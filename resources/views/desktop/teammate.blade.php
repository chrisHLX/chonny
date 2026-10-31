{{--
    One teammate: how it has gone with them, what they play and which comps you ran together. Built
    by TeammatesService for the Teammates page in the app and on /wow/coach.
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@include('desktop.partials.styles')
@include('desktop.partials.teammate-styles')
</head>
<body>

<div class="head">
    <span class="title" style="color: {{ $m['color'] }}">{{ $m['name'] }}</span>
    <span class="when muted">{{ $m['realm'] }} &middot; last played {{ $m['last'] }}</span>
    <a class="jump" href="{{ $m['url'] }}" target="_blank" rel="noopener">Player page</a>
</div>

<div class="section card">
    <div class="tiles">
        <div class="tile"><p class="muted small">Together</p><p class="big">{{ $m['won'] }}-{{ $m['lost'] }}</p></div>
        <div class="tile"><p class="muted small">Win rate</p><p class="big">@include('desktop.partials.win-rate', ['r' => $m])</p></div>
        <div class="tile"><p class="muted small">Current rating</p><p class="big">{{ $m['rating'] ? $m['rating']['rating'] : 'Not known' }}</p>
            @if ($m['rating'])<p class="muted small">{{ $m['rating']['bracket'] }}</p>@endif
        </div>
    </div>
    @if ($m['exp'] || $m['titles'])
        <p style="margin-top: 10px">
            @if ($m['exp'])<span class="tag">{{ $m['exp']['rating'] }} exp {{ $m['exp']['bracket'] }}</span>@endif
            @foreach ($m['titles'] as $t)
                <span class="tag" title="{{ $t['tip'] }}">{{ $t['text'] }} {{ $t['where'] }}</span>
            @endforeach
        </p>
    @endif
    @unless ($m['stored'])
        <p class="muted small" style="margin-top: 8px">Open their player page once to add their titles and exp here.</p>
    @endunless
</div>

<div class="section card">
    <p class="kicker">Comps together</p>
    @include('desktop.partials.comp-records', ['comps' => $m['comps']])
</div>

<div class="section card">
    <p class="kicker">What they played with you</p>
    <table class="rec">
        @foreach ($m['specs'] as $label => $s)
            <tr>
                <td>@if ($s['icon'])<img class="spell-ic" src="{{ $s['icon'] }}" alt="">@endif {{ $label }}</td>
                <td class="n">{{ $s['games'] }} game{{ $s['games'] === 1 ? '' : 's' }}</td>
            </tr>
        @endforeach
    </table>
    @if (count($m['yours']) > 1)
        <p class="muted small" style="margin-top: 8px">On your characters: {{ implode(', ', array_map(fn ($n, $g) => "{$n} ({$g})", array_keys($m['yours']), $m['yours'])) }}</p>
    @endif
</div>

</body>
</html>
