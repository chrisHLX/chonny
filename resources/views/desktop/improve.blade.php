{{--
    "What to work on" for one character, for the MindCollector Logs desktop app's Improve page,
    built by ImprovementService. Shown in Windows' built-in browser control (IE11), like the game
    card: tables only, no CSS variables, grid or flex gaps.
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
@include('desktop.partials.styles')
<style>
    .habit { margin-top: 12px; }
    .habit .ht { font-size: 15px; font-weight: 600; margin-right: 8px; vertical-align: middle; }
    .habit .what { margin: 4px 0 8px; }
    .nums { width: 100%; }
    .nums td { width: 33%; vertical-align: top; padding: 2px 0; }
    .nums .v { font-size: 20px; font-weight: 600; }
    .nums .k { font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #8A8A9A; }
    .habit .caveat { margin-top: 8px; color: #52525F; font-size: 11.5px; }
    .habit .list { margin-top: 8px; }
    .habit .list .def { color: #F0F0F2; }
    .habit .list .def span { color: #8A8A9A; margin-left: 4px; }
</style>
</head>
<body>

@php
    $chip = [
        'behind' => ['bad', 'Behind other '.$m['spec'].'s'],
        'ahead' => ['good', 'Ahead of other '.$m['spec'].'s'],
        'level' => ['neutral', 'About the same as other '.$m['spec'].'s'],
        'lead' => ['warn', 'A lead: under 10 games on one side'],
        'none' => ['neutral', 'Not measured yet'],
    ];
@endphp

<div class="head">
    <span class="title" style="color: {{ $m['color'] }}">{{ $m['name'] }}</span>
    <span class="when muted">{{ $m['spec'] }} &nbsp;&middot;&nbsp; {{ $m['games'] }} game{{ $m['games'] === 1 ? '' : 's' }} measured ({{ $m['record'][0] }}-{{ $m['record'][1] }}; a Solo Shuffle round counts as one) &nbsp;&middot;&nbsp; {{ $m['from'] }} to {{ $m['to'] }}</span>
</div>

@if ($m['byDifficulty'])
    <div class="small" style="margin: -4px 0 14px">
        <span class="muted">Your record against teams that were</span>
        @foreach ($m['byDifficulty'] as $label => $wl)
            &nbsp;<span class="chip {{ $label === 'Harder' ? 'bad' : ($label === 'Easier' ? 'good' : 'neutral') }}">{{ strtolower($label) }}</span> <b>{{ $wl[0] }}-{{ $wl[1] }}</b>
        @endforeach
        <span class="subtle">&nbsp;Harder: their MMR 50+ above yours or 3+ more Gladiator seasons.</span>
    </div>
@endif

<div class="label">What to work on <span class="aside">your games against {{ $m['others'] }} other {{ $m['spec'] }}{{ $m['others'] === 1 ? '' : 's' }} in them ({{ $m['otherGames'] }} games, both teams). Furthest behind first.</span></div>

@if ($m['v6'] < $m['games'])
    <div class="box small muted" style="margin-bottom: 4px">
        Dispels and big defensives are measured from games synced since 3 Oct: {{ $m['v6'] }} of your {{ $m['games'] }} so far.
        To measure the rest, run <span class="gold">php artisan wow:sync --skip-ingest --fresh</span> once.
    </div>
@endif

@foreach ($m['habits'] as $h)
    <div class="box habit">
        <div>
            <span class="ht">{{ $h['title'] }}</span>
            <span class="chip {{ $chip[$h['status']][0] }}">{{ $chip[$h['status']][1] }}</span>
        </div>
        <div class="what muted">{{ $h['what'] }}</div>
        <table class="nums"><tr>
            <td><div class="k">You</div><div class="v">{{ $h['you'] }}</div><div class="small subtle">{{ $h['youN'] }} game{{ $h['youN'] === 1 ? '' : 's' }}</div></td>
            <td><div class="k">Other {{ $m['spec'] }}s</div><div class="v muted">{{ $h['others'] }}</div><div class="small subtle">{{ $h['othersN'] }} game{{ $h['othersN'] === 1 ? '' : 's' }}</div></td>
            <td>
                @if ($h['trend'])
                    <div class="k">Your last {{ $h['trend']['n'] }}</div><div class="v">{{ $h['trend']['recent'] }}</div><div class="small subtle">{{ $h['trend']['earlier'] }} before that</div>
                @endif
            </td>
        </tr></table>
        @foreach ($h['lines'] as $line)
            <div class="line">{{ $line }}</div>
        @endforeach
        @if ($h['list'])
            <div class="list">
                <span class="small muted">{{ $h['list']['label'] }}:</span><br>
                @foreach ($h['list']['rows'] as $r)
                    <span class="def">@if ($r['icon'])<img class="spell-ic" src="{{ $r['icon'] }}" alt="">@else<span class="ph-s"></span>@endif{{ $r['spell'] }}<span>{{ $r['value'] }}</span></span>
                @endforeach
            </div>
        @endif
        <div class="caveat">{{ $h['caveat'] }}</div>
    </div>
@endforeach

<div class="foot">
    Every number is from your own games and the players in them. "Other {{ $m['spec'] }}s" are everyone of your spec you played with or against, so they share your rating range.
    Under 10 games on either side a difference is a lead, not a finding. The log cannot see positioning, line of sight or calls.
</div>

</body>
</html>
