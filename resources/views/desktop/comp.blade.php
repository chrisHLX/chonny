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
    tr.game td { cursor: pointer; }
    tr.game:hover td { background: #1E1E26; }
    tr.more td { padding: 4px 0 12px 22px; }
    .who td { padding: 2px 14px 2px 0; }
    .arrow { color: #52525F; width: 14px; }
    .sub { margin: 2px 0 8px; }
    /* A spell you can click for its tooltip. */
    tr.spell td { cursor: pointer; }
    tr.spell:hover td { background: #18181E; }
    .sp { border-bottom: 1px dotted #52525F; }
    tr.tip td { padding: 0 0 6px 0; border-top: 0 !important; }
    .tipbox { background: #18181E; border: 1px solid #2C2C38; border-radius: 4px; padding: 6px 9px; font-size: 12px; color: #C9C9D2; line-height: 1.45; }
    .tipbox .facts { color: #E8B84B; font-size: 11.5px; margin-top: 3px; }
    /* Their usual go, in sentences. */
    .usual { border-color: #6B4E1A; background: #1E150A; margin-bottom: 12px; }
    .usual .line { margin: 3px 0; }
    /* A crowd-control run: numbered steps, each with whom it landed on. */
    .run { margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #1E1E26; }
    .run .head2 { font-size: 11.5px; color: #8A8A9A; margin-bottom: 2px; }
    .steps td { padding: 3px 0; vertical-align: middle; }
    .steps td.num { width: 20px; color: #E8B84B; font-weight: 600; }
    .steps td.on { text-align: right; white-space: nowrap; padding-left: 10px; }
</style>
<script>
    // A game's detail row opens and closes under it. IE11: no classList on table rows.
    function toggle(id) {
        var row = document.getElementById(id), arrow = document.getElementById(id + '-a');
        var open = row.style.display === 'none';
        row.style.display = open ? '' : 'none';
        if (arrow) { arrow.innerHTML = open ? '&#9662;' : '&#9656;'; }
    }
</script>
</head>
<body>

{{-- A list of label/value rows, with icons where the label is a spell. A spell with a tooltip
     is a clickable row; its tooltip opens in a row under it. --}}
@php
    $tipN = 0;
    // One spell's row (its cells after the name in $after) and its hidden tooltip row.
    $spellRow = function (array $r, string $after, int $cols, string $lead = '') use (&$tipN) {
        $icon = ! empty($r['icon']) ? '<img class="spell-ic" src="'.e($r['icon']).'">' : '';
        if (empty($r['tip'])) {
            return '<tr>'.$lead.'<td>'.$icon.e($r['label']).'</td>'.$after.'</tr>';
        }
        $id = 'tip'.(++$tipN);
        $tip = $r['tip'];

        return '<tr class="spell" onclick="toggle(\''.$id.'\')">'.$lead.'<td>'.$icon.'<span class="sp">'.e($r['label']).'</span></td>'.$after.'</tr>'
            .'<tr class="tip" id="'.$id.'" style="display: none"><td colspan="'.$cols.'"><div class="tipbox">'
            .($tip['text'] ? e($tip['text']) : '').($tip['facts'] ? '<div class="facts">'.e($tip['facts']).'</div>' : '')
            .'</div></td></tr>';
    };
    $list = function (array $rows, string $empty = 'None seen.') use ($spellRow) {
        if (! $rows) {
            return '<div class="muted small">'.e($empty).'</div>';
        }
        $html = '<table class="rows">';
        foreach ($rows as $r) {
            $html .= $spellRow($r, '<td class="v">'.e($r['value']).'</td>', 2);
        }

        return $html.'</table>';
    };
    $roleChip = ['your healer' => 'warn', 'their kill target' => 'bad', 'your other DPS' => 'neutral'];
    $b = fn (array $spells) => implode(' with ', array_map(fn ($s) => '<b>'.e($s).'</b>', $spells));
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
    @php $u = $g['usual']; @endphp
    @if ($g['count'])
        <div class="box usual">
            <div class="label" style="color: #E8B84B">What to expect</div>
            <div class="line">&bull; They go {{ $g['perGame'] == 1 ? 'once' : $g['perGame'].' times' }} a game{{ $g['firstAt'] !== null ? ', the first at about '.$g['firstAt'].'s' : '' }}.</div>
            @if ($u['set'])
                <div class="line">&bull; Their go is usually {!! $b($u['set']['spells']) !!} <span class="muted">({{ $u['set']['n'] }} of {{ $g['count'] }} goes)</span>.</div>
            @endif
            @if ($u['chain'])
                <div class="line">&bull; Their crowd control that repeats most:
                    @foreach ($u['chain']['steps'] as $st)
                        {{ $loop->first ? '' : 'then ' }}<b>{{ $st['label'] }}</b> on {{ $st['on'] }}{{ $loop->last ? '' : ',' }}
                    @endforeach
                    <span class="muted">({{ $u['chain']['n'] }} goes)</span>.</div>
            @endif
            @if ($u['healerCc'])
                <div class="line">&bull; On your healer most: {!! implode(' and ', array_map(fn ($s) => '<b>'.e($s).'</b>', $u['healerCc'])) !!}.</div>
            @endif
            @if ($u['target'])
                <div class="line">&bull; They went on <b>{{ $u['target']['who'] }}</b> most <span class="muted">({{ $u['target']['n'] }} of {{ $g['count'] }})</span>, and {{ $u['kills'] }} of their {{ $g['count'] }} goes killed one of you.</div>
            @endif
            <div class="small muted" style="margin-top: 4px">Click any spell below for what it does.</div>
        </div>
    @endif
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
            <div class="label">Crowd control on you <span class="aside">the same order seen in 2+ of their goes</span></div>
            @forelse ($g['chains'] as $ch)
                <div class="run">
                    <div class="head2">Seen in <b style="color: #F0F0F2">{{ $ch['n'] }} goes</b></div>
                    <table class="steps" style="width: 100%">
                        @foreach ($ch['steps'] as $st)
                            {!! $spellRow($st, '<td class="on"><span class="chip '.($roleChip[$st['on']] ?? 'neutral').'">'.e($st['on']).'</span></td>', 3, '<td class="num">'.$loop->iteration.'</td>') !!}
                        @endforeach
                    </table>
                </div>
            @empty
                <div class="muted small">No order of crowd control repeated in two goes.</div>
            @endforelse
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

{{-- ============================== by their experience --}}
@php $x = $c['byExperience']; @endphp
<div class="section">
    <div class="label">Less against more experienced teams <span class="aside">their Gladiator seasons between them; experience rather than MMR, which is deflated early in a season and missing in Solo Shuffle</span></div>
    <div class="box">
        @if ($x['state'] === 'split')
            <table class="stats">
                <tr>
                    <td class="small muted"></td>
                    <td class="n small muted" style="width: 170px">Under {{ $x['threshold'] }} season{{ $x['threshold'] === 1 ? '' : 's' }}</td>
                    <td class="n small muted" style="width: 170px">{{ $x['threshold'] }} or more</td>
                </tr>
                @foreach ($x['lower'] as $label => $value)
                    <tr><td>{{ $label }}</td><td class="n">{{ $value }}</td><td class="n">{{ $x['higher'][$label] }}</td></tr>
                @endforeach
            </table>
            @if ($x['known'] < 10)
                <div class="line small muted">{{ $x['known'] }} games with experience on file: a lead, not a finding.</div>
            @endif
        @elseif ($x['state'] === 'flat')
            <div class="small muted">Every team of this comp you met had about the same experience, so there is nothing to split yet.</div>
        @else
            <div class="small muted">{{ $x['known'] }} game{{ $x['known'] === 1 ? '' : 's' }} with experience on file; {{ $x['need'] }} more and this compares the less and more experienced teams.</div>
        @endif
    </div>
</div>

{{-- ============================== every game --}}
<div class="section">
    <div class="label">Every game against them <span class="aside">click a game for every player's experience and the game's numbers</span></div>
    <div class="box">
        <table class="stats">
            <tr><td class="arrow"></td><td class="small muted">Played</td><td class="small muted">You</td><td class="small muted">Result</td><td class="small muted">MMR (you / them)</td><td class="small muted">Their Gladiator seasons</td><td class="small muted">First death</td></tr>
            @foreach ($c['list'] as $row)
                <tr class="game" onclick="toggle('{{ $row['id'] }}')">
                    <td class="arrow" id="{{ $row['id'] }}-a">&#9656;</td>
                    <td>{{ $row['when'] }}</td>
                    <td>{{ $row['who'] }}</td>
                    <td><span class="chip {{ $row['won'] ? 'won' : 'lost' }}">{{ $row['won'] ? 'WON' : 'LOST' }}</span></td>
                    <td class="muted">{{ $row['mmr'] ?? 'none in Solo Shuffle' }}</td>
                    <td>{{ $row['glad'] ?? 'not looked up' }}</td>
                    <td class="muted">{{ $row['death'] ?? '-' }}</td>
                </tr>
                <tr class="more" id="{{ $row['id'] }}" style="display: none"><td colspan="7">
                    <table class="two"><tr>
                        <td class="col left">
                            @foreach (['them' => 'Them', 'us' => 'Your team'] as $side => $title)
                                <div class="label" style="margin-top: 6px">{{ $title }}</div>
                                <table class="who">
                                    @foreach ($row['players'][$side] as $pl)
                                        <tr>
                                            <td><span class="name" style="color: {{ $pl['color'] }}">{{ $pl['name'] }}</span>@if ($pl['you'])<span class="you">YOU</span>@endif</td>
                                            <td class="muted small">{{ $pl['spec'] }}</td>
                                            <td class="small">@if ($pl['glad'])<span class="chip glad">{{ $pl['xp'] }}</span>@else<span class="muted">{{ $pl['xp'] }}</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endforeach
                        </td>
                        <td class="col right">
                            <div class="label" style="margin-top: 6px">The game</div>
                            <table class="rows">
                                @foreach ($row['stats'] as $label => $value)
                                    <tr><td class="small">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
                                @endforeach
                            </table>
                        </td>
                    </tr></table>
                </td></tr>
            @endforeach
        </table>
    </div>
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
