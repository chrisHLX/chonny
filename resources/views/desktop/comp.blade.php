{{--
    One enemy comp in the player's own games, for the MindCollector Logs desktop app's Comps page,
    built by CompLibraryService. Shown in Windows' built-in browser control (IE11), like the cards:
    tables only, no CSS variables, grid or flex gaps.
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
@include('desktop.partials.styles')
<style>
    .two { width: 100%; }
    .two td.col { width: 50%; vertical-align: top; padding: 0; }
    .two td.col.left { padding-right: 6px; }
    .two td.col.right { padding-left: 6px; }
    .rows { width: 100%; }
    .rows td { padding: 3px 0; vertical-align: middle; }
    .rows tr + tr td { border-top: 1px solid #1E1E26; }
    .rows .v { text-align: right; white-space: nowrap; color: #8A8A9A; padding-left: 10px; }
    .big { font-size: 20px; font-weight: 600; }
    .sub { margin: 2px 0 8px; }
</style>
</head>
<body>

{{-- A list of label/value rows, with icons where the label is a spell. --}}
@php
    $list = function (array $rows, string $empty = 'None seen.') {
        if (! $rows) {
            return '<div class="muted small">'.e($empty).'</div>';
        }
        $html = '<table class="rows">';
        foreach ($rows as $r) {
            $icon = ! empty($r['icon']) ? '<img class="spell-ic" src="'.e($r['icon']).'">' : '';
            $html .= '<tr><td>'.$icon.e($r['label']).'</td><td class="v">'.e($r['value']).'</td></tr>';
        }

        return $html.'</table>';
    };
    $g = $c['goes'];
    $d = $c['deaths'];
    $t = $c['trading'];
@endphp

<div class="head">
    @if ($c['nick'])<span class="chip glad">{{ $c['nick'] }}</span>@endif
    <span class="title">{{ $c['name'] }}</span>
    <span class="when muted">{{ $c['games'] }} game{{ $c['games'] === 1 ? '' : 's' }} ({{ $c['record'][0] }}-{{ $c['record'][1] }}) &nbsp;&middot;&nbsp; {{ $c['from'] }} to {{ $c['to'] }}</span>
</div>

<div class="small sub">
    <span class="muted">You played them on</span>
    @foreach ($c['byCharacter'] as $ch)
        <b>{{ $ch['name'] }}</b> {{ $ch['won'] }}-{{ $ch['lost'] }}@if (! $loop->last),@endif
    @endforeach
    <span class="muted">&nbsp;&middot;&nbsp; healed by</span>
    @foreach ($c['healers'] as $h)
        {{ $h['spec'] }} <span class="muted">({{ $h['games'] }})</span>@if (! $loop->last),@endif
    @endforeach
    @if ($c['difficulty'])
        <span class="muted">&nbsp;&middot;&nbsp; games that were</span>
        @foreach ($c['difficulty'] as $label => $n)
            {{ strtolower($label) }} <span class="muted">{{ $n }}</span>@if (! $loop->last),@endif
        @endforeach
    @endif
</div>
@if ($c['lead'])
    <div class="box small muted">Fewer than 10 games: every pattern on this page is a lead from these games, not a rule about the comp.</div>
@endif

{{-- ============================== their goes --}}
<div class="section">
    <div class="label">Their goes <span class="aside">{{ $g['count'] }} in all, {{ $g['perGame'] }} a game{{ $g['firstAt'] !== null ? ', the first at '.$g['firstAt'].'s (median)' : '' }}. Killed one of you: {{ $g['killed'] }}</span></div>
    <table class="two"><tr>
        <td class="col left"><div class="box">
            <div class="label">Offensive cooldowns <span class="aside">share of their goes with it</span></div>
            {!! $list($g['offensive']) !!}
            @if ($g['sets'])
                <div class="label" style="margin-top: 10px">Pressed together</div>
                {!! $list($g['sets']) !!}
            @endif
        </div></td>
        <td class="col right"><div class="box">
            <div class="label">Crowd control on you <span class="aside">runs seen in 2+ goes</span></div>
            {!! $list($g['chains'], 'No chain repeated in two goes.') !!}
            <div class="label" style="margin-top: 10px">On your healer</div>
            {!! $list($g['onHealer']) !!}
            <div class="label" style="margin-top: 10px">Whom their goes were on</div>
            {!! $list($g['targets']) !!}
        </div></td>
    </tr></table>
</div>

{{-- ============================== who dies --}}
<div class="section">
    <div class="label">Who dies</div>
    <table class="two"><tr>
        <td class="col left"><div class="box">
            <div class="label">Yours, in {{ $d['losses'] }} loss{{ $d['losses'] === 1 ? '' : 'es' }}</div>
            {!! $list($d['who']) !!}
            @if ($d['losses'])
                <div class="line small">Your healer locked out at it: {{ $d['healerLocked'] }}.@if ($d['after'] !== null) Their go had been running {{ $d['after'] }}s (median).@endif</div>
                <div class="label" style="margin-top: 10px">Killing blows</div>
                {!! $list($d['blows']) !!}
            @endif
        </div></td>
        <td class="col right"><div class="box">
            <div class="label">Theirs, in {{ $d['wins'] }} win{{ $d['wins'] === 1 ? '' : 's' }}</div>
            {!! $list($d['theirs']) !!}
        </div></td>
    </tr></table>
</div>

{{-- ============================== defensives traded --}}
<div class="section">
    <div class="label">Defensives traded</div>
    <table class="two"><tr>
        <td class="col left"><div class="box">
            <div class="label">What they answer your goes with <span class="aside">{{ $t['perOurGo'] }} a go, over {{ $t['ourGoes'] }}</span></div>
            {!! $list($t['theirAnswers']) !!}
            <div class="line small">Your goes led to a kill {{ $t['ourKill'] }}.
                With 0-1 of their defensives already down: {{ $t['drain']['low'][0] }} of {{ $t['drain']['low'][1] }}; with 2 or more: {{ $t['drain']['high'][0] }} of {{ $t['drain']['high'][1] }}.</div>
        </div></td>
        <td class="col right"><div class="box">
            <div class="label">What their goes force from you <span class="aside">{{ $t['perTheirGo'] }} a go</span></div>
            {!! $list($t['ourAnswers']) !!}
            @if ($t['cover'])
                <div class="line small">Their go killed one of you with none of your big defensives down {{ $t['cover']['none'] }}, one down {{ $t['cover']['one'] }}, two or more {{ $t['cover']['two'] }}.</div>
            @endif
        </div></td>
    </tr></table>
</div>

@if ($c['unused']['losses'])
    <div class="section">
        <div class="label">Ready and never pressed when they killed <span class="aside">your team's defensives and Medallions, in {{ $c['unused']['losses'] }} loss{{ $c['unused']['losses'] === 1 ? '' : 'es' }} with an answer sheet</span></div>
        <div class="box">{!! $list($c['unused']['rows']) !!}</div>
    </div>
@endif

<div class="foot">
    Every number is from your own games against this pair of DPS specs, with any healer. It is what these teams did against you, not what the comp always does.
    The log cannot see positioning, line of sight or calls.
</div>

</body>
</html>
