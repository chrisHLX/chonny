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
    .nums td { width: 22%; vertical-align: top; padding: 2px 0; }
    .nums td.chart { width: 34%; }
    .nums .v { font-size: 20px; font-weight: 600; }
    .nums .k { font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #B3B3B3; }
    .habit .caveat { margin-top: 8px; color: #6A6A6A; font-size: 11.5px; }
    .habit .why { margin-top: 8px; padding: 8px 10px; background: #1E150A; }
    .rot td { padding: 3px 14px 3px 0; }
    .habit .list { margin-top: 8px; }
    .habit .list .def { color: #FFFFFF; }
    .habit .list .def span { color: #B3B3B3; margin-left: 4px; }
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

{{-- ============================== the one focus --}}
@php $f = $m['focus']; @endphp
<div class="box" style="border-color: #6B4E1A; background: #1E150A; margin-bottom: 16px">
    <div class="label" style="color: #E8B84B">Your focus</div>
    @if ($f)
        <table style="width: 100%"><tr>
        <td style="vertical-align: top">
            <div><span class="ht" style="font-size: 17px">{{ $f['title'] }}</span>
                <span class="muted">&nbsp;you {{ $f['you'] }} against {{ $f['others'] }} for other {{ $m['spec'] }}s</span></div>
            <div class="small" style="margin-top: 4px">{{ $f['what'] }}</div>
            @if ($s = $f['session'])
                <div class="line">
                    Last session ({{ $s['day'] }}, {{ $s['n'] }} game{{ $s['n'] === 1 ? '' : 's' }}): <b>{{ $s['value'] }}</b>.
                    @if ($s['moved'])
                        Your {{ $s['sessions'] }} sessions of 3+ games before it ran {{ $s['range'][0] }} to {{ $s['range'][1] }}.
                        <span class="chip {{ ['best' => 'good', 'worst' => 'bad', 'within' => 'neutral'][$s['moved']] }}">{{ ['best' => 'your best session yet', 'worst' => 'your worst session yet', 'within' => 'within your usual range'][$s['moved']] }}</span>
                    @elseif ($s['range'])
                        Your sessions before it ran {{ $s['range'][0] }} to {{ $s['range'][1] }}; under 3 games is too few to read on its own.
                    @else
                        Against {{ $s['before'] }} before it: too few sessions of 3+ games yet to say whether it stands out.
                    @endif
                </div>
            @endif
            <div class="small muted" style="margin-top: 6px">One thing at a time: of what decides games most ({{ $m['healer'] ? 'defensives, crowd control, then healing' : 'damage, defensives, then crowd control' }}), the first where you are behind the players you meet, on 10+ games on both sides. The detail is below.</div>
        </td>
        @if ($f['chartWide'])
            <td style="width: 310px; vertical-align: top; padding-left: 12px">
                <div class="small subtle" style="margin-bottom: 2px">By session, {{ $f['chartWide']['from'] }} to {{ $f['chartWide']['to'] }}</div>
                @include('desktop.partials.spark', ['s' => $f['chartWide']])
                <div class="small subtle" style="margin-top: 2px">Dashed: other {{ $m['spec'] }}s</div>
            </td>
        @endif
        </tr></table>
    @else
        @if (! empty($m['smallerBehind']))
            <div class="small">On what decides games most ({{ $m['healer'] ? 'defensives, crowd control and healing' : 'damage, defensives and crowd control' }}), you are level with or ahead of other {{ $m['spec'] }}s on 10 or more games.
                Smaller gaps, lower down: {{ implode(', ', $m['smallerBehind']) }}.</div>
        @else
            <div class="small">Nothing measured puts you behind other {{ $m['spec'] }}s on 10 or more games. Keep playing: the habits below say where you stand.</div>
        @endif
    @endif
</div>

<div class="label">Where you stand <span class="aside">your games against {{ $m['others'] }} other {{ $m['spec'] }}{{ $m['others'] === 1 ? '' : 's' }} in them ({{ $m['otherGames'] }} games, both teams). What decides games first: {{ $m['healer'] ? 'defensives, crowd control, then healing' : 'damage, defensives, then crowd control' }}.</span></div>

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
            {{-- Measured for you with nothing to set it against (your own failed casts: WoW logs no one
                 else's) reads against your own sessions, not as "not measured". --}}
            @php $c = $h['status'] === 'none' && $h['youN'] > 0 && $h['othersN'] === 0 ? ['neutral', 'Yours only: read it against your sessions'] : $chip[$h['status']]; @endphp
            <span class="chip {{ $c[0] }}">{{ $c[1] }}</span>
        </div>
        <div class="what muted">{{ $h['what'] }}</div>
        @if (! empty($h['evidence']))
            <div class="small subtle" style="margin-top: 2px">Across the archive: {{ $h['evidence'] }}</div>
        @endif
        <table class="nums"><tr>
            <td><div class="k">You</div><div class="v">{{ $h['you'] }}</div><div class="small subtle">{{ $h['youN'] }} game{{ $h['youN'] === 1 ? '' : 's' }}</div></td>
            <td><div class="k">Other {{ $m['spec'] }}s</div><div class="v muted">{{ $h['others'] }}</div><div class="small subtle">{{ $h['othersN'] }} game{{ $h['othersN'] === 1 ? '' : 's' }}</div></td>
            <td>
                @if ($h['trend'])
                    <div class="k">Your last {{ $h['trend']['n'] }}</div><div class="v">{{ $h['trend']['recent'] }}</div><div class="small subtle">{{ $h['trend']['earlier'] }} before that</div>
                @endif
            </td>
            <td class="chart">
                @if ($h['chart'])
                    <div class="k">By session</div>
                    @include('desktop.partials.spark', ['s' => $h['chart']])
                    <div class="small subtle">{{ $h['chart']['from'] }} to {{ $h['chart']['to'] }} &middot; dashed: others</div>
                @endif
            </td>
        </tr></table>
        @foreach ($h['lines'] as $line)
            <div class="line">{{ $line }}</div>
        @endforeach
        @if ($s = $h['session'])
            <div class="line small muted">{{ $s['line'] }}
                @if ($s['moved'] === 'best' || $s['moved'] === 'worst')
                    <span class="chip {{ $s['moved'] === 'best' ? 'good' : 'bad' }}">{{ $s['moved'] === 'best' ? 'your best session yet' : 'your worst session yet' }}</span>
                @endif
            </div>
        @endif
        @if (! empty($h['why']))
            <div class="why">
                <div class="small" style="color: #E8B84B; font-weight: 600">Why yours is lower</div>
                @foreach ($h['why'] as $w)
                    <div class="line">{{ $w }}</div>
                @endforeach
            </div>
        @endif
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
@if ($m['archiveGames'] ?? null)
<div class="small subtle" style="margin: 4px 0 12px">"Across the archive" lines are every player in {{ number_format($m['archiveGames'] ?? 0) }} recorded games, each set against the middle of their own spec. They show what goes with winning, not what causes it: a team that is winning also has more room to deal damage.</div>
@endif

@if (! empty($m['rotation']['rows']))
    <div class="label" style="margin-top: 18px">Your rotation <span class="aside">presses per minute you were free to act, against the other {{ $m['spec'] }}s in your games and the middle {{ $m['spec'] }} of every game measured</span></div>
    <div class="box">
        <table class="stats rot">
            <tr><td></td><td class="small muted">You</td><td class="small muted">In your games</td><td class="small muted">Every {{ $m['spec'] }}</td><td></td></tr>
            @foreach ($m['rotation']['rows'] as $r)
                <tr>
                    <td>@if ($r['icon'])<img class="spell-ic" src="{{ $r['icon'] }}" alt="" style="margin-right: 5px">@endif{{ $r['spell'] }}</td>
                    <td><b>{{ $r['you'] }}</b></td>
                    <td class="muted">{{ $r['others'] }}</td>
                    <td class="muted">{{ $r['norm'] ?? '-' }}</td>
                    <td>@if ($r['mark'])<span class="chip {{ $r['mark'] === 'less' ? 'warn' : 'neutral' }}">{{ $r['mark'] === 'less' ? 'you press it less' : 'you press it more' }}</span>@endif</td>
                </tr>
            @endforeach
        </table>
        @if (! $m['rotation']['enough'])
            <div class="small subtle" style="margin-top: 6px">Under 10 games on either side, so nothing is marked yet.</div>
        @endif
    </div>
    @if ($o = $m['rotation']['opening'])
        <div class="label" style="margin-top: 14px">The start of their goes <span class="aside">what you pressed in the first 4 seconds of their goes, per go: {{ $o['goes'] }} of yours, {{ $o['theirGoes'] }} for the others</span></div>
        <div class="box">
            <table class="stats rot">
                <tr><td></td><td class="small muted">You</td><td class="small muted">Other {{ $m['spec'] }}s</td></tr>
                @foreach ($o['rows'] as $r)
                    <tr>
                        <td>@if ($r['icon'])<img class="spell-ic" src="{{ $r['icon'] }}" alt="" style="margin-right: 5px">@endif{{ $r['spell'] }}</td>
                        <td><b>{{ $r['you'] }}</b></td>
                        <td class="muted">{{ $r['others'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="small subtle" style="margin-top: 6px">A press counts once per go. Over 100% means more than one press of it per go. Each game's Moments tab shows these goes one by one.</div>
        </div>
    @endif
@endif

<div class="foot">
    Every number is from your own games and the players in them. "Other {{ $m['spec'] }}s" are everyone of your spec you played with or against, so they share your rating range.
    Under 10 games on either side a difference is a lead, not a finding. The log cannot see positioning, line of sight or calls.
</div>

</body>
</html>
