{{--
    One player you played against, for the MindCollector Logs desktop app's Classes page, built by
    ClassLibraryService: what they pressed, beside the median player of the spec and beside you.
    Shown in Windows' built-in browser control (IE11), like the cards: tables only, no CSS
    variables, grid or flex gaps.
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
    .sub { margin: 2px 0 8px; }
    tr.spell td { cursor: pointer; }
    tr.spell:hover td { background: #18181E; }
    .sp { border-bottom: 1px dotted #52525F; }
    tr.tip td { padding: 0 0 6px 0; border-top: 0 !important; }
    .tipbox { background: #18181E; border: 1px solid #2C2C38; border-radius: 4px; padding: 6px 9px; font-size: 12px; color: #C9C9D2; line-height: 1.45; }
    .tipbox .facts { color: #E8B84B; font-size: 11.5px; margin-top: 3px; }
    /* What they press: a spell, its bars, three numbers. */
    .press { width: 100%; }
    .press td { padding: 3px 0; vertical-align: middle; }
    .press tr + tr td { border-top: 1px solid #1E1E26; }
    .press td.nm { width: 34%; white-space: nowrap; }
    .press td.bars { padding: 0 12px; }
    .press td.n { width: 58px; text-align: right; white-space: nowrap; }
    .press td.vs { width: 118px; text-align: right; white-space: nowrap; }
    .press tr.hd td { color: #52525F; font-size: 11px; border-top: 0; }
    .press tr.g td { border-top: 0; padding-top: 8px; }
    .press .bar { margin: 1px 0; }
    .press .bar.m { height: 4px; }
    .press .bar.m span { height: 4px; background: #52525F; }
    .grp { margin: 10px 0 3px; color: #E8B84B; font-size: 11.5px; font-weight: 600; }
    .grp:first-child { margin-top: 0; }
    .pick { margin-right: 6px; }
</style>
<script>
    function toggle(id) {
        var row = document.getElementById(id);
        row.style.display = row.style.display === 'none' ? '' : 'none';
    }
</script>
</head>
<body>

@php
    $tipN = 0;
    // One spell's row (its cells after the name in $after) and its hidden tooltip row.
    $spellRow = function (array $r, string $after, int $cols, string $nameClass = '') use (&$tipN) {
        $icon = ! empty($r['icon']) ? '<img class="spell-ic" src="'.e($r['icon']).'">' : '<span class="ph-s"></span>';
        $cls = $nameClass ? ' class="'.$nameClass.'"' : '';
        if (empty($r['tip'])) {
            return '<tr><td'.$cls.'>'.$icon.e($r['label']).'</td>'.$after.'</tr>';
        }
        $id = 'tip'.(++$tipN);
        $tip = $r['tip'];

        return '<tr class="spell" onclick="toggle(\''.$id.'\')"><td'.$cls.'>'.$icon.'<span class="sp">'.e($r['label']).'</span></td>'.$after.'</tr>'
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
    $rate = fn (?float $v) => $v === null ? '-' : ($v >= 10 ? (string) round($v) : number_format($v, 1));
    $specShort = $m['norm']['label'] ?? $m['spec'];
@endphp

<div class="head">
    @if ($m['icon'])<img class="spec-ic" src="{{ $m['icon'] }}" style="border:1px solid {{ $m['color'] }}">@else<span class="ph" style="border:1px solid {{ $m['color'] }}"></span>@endif
    <span class="title" style="color: {{ $m['color'] }}">{{ $m['name'] }}</span>
    <span class="when muted">{{ $m['spec'] }} &nbsp;&middot;&nbsp; {{ $m['realm'] }}</span>
</div>

<div class="sub">
    @if ($m['picks']['rated'])
        <span class="chip glad pick">Highest rated {{ $specShort }} you met: {{ $m['picks']['rated']['mmr'] }} {{ $m['picks']['rated']['bracket'] }} MMR</span>
    @endif
    @if ($m['picks']['experienced'])
        <span class="chip glad pick">Most experienced {{ $specShort }} you met</span>
    @endif
</div>
<div class="small sub">
    @if ($m['glad'])<b class="gold">{{ $m['xp'] }}</b>@else<span class="muted">{{ $m['xp'] }}</span>@endif
    <span class="muted">&nbsp;&middot;&nbsp; you played them {{ $m['record'][0] + $m['record'][1] }} round{{ $m['record'][0] + $m['record'][1] === 1 ? '' : 's' }} ({{ $m['record'][0] }}-{{ $m['record'][1] }}), {{ $m['from'] }} to {{ $m['to'] }}, on</span>
    @foreach ($m['byCharacter'] as $ch)
        <b>{{ $ch['name'] }}</b> {{ $ch['won'] }}-{{ $ch['lost'] }}@if (! $loop->last),@endif
    @endforeach
</div>
@if ($m['measured'] < 3)
    <div class="box small muted">Measured over {{ $m['measured'] }} round{{ $m['measured'] === 1 ? '' : 's' }}: a glimpse of how they play, not a pattern.</div>
@endif

{{-- ============================== what they press --}}
<div class="section">
    <div class="label">What they press
        <span class="aside">a minute free to act (alive and not crowd-controlled), over {{ $m['measured'] }} round{{ $m['measured'] === 1 ? '' : 's' }}, {{ $m['freeMinutes'] }} min.
        @if ($m['norm']) Most: the median {{ $m['norm']['label'] }} who pressed it, {{ $m['norm']['rounds'] }} rounds in the archive.@endif
        @if ($m['youPlay']) You: your own {{ $m['youPlay']['rounds'] }} round{{ $m['youPlay']['rounds'] === 1 ? '' : 's' }} of the spec.@endif</span>
    </div>
    <div class="box">
        @if ($m['presses'])
            {{-- One table for every group, so the columns line up down the page. --}}
            <table class="press">
                <tr class="hd"><td class="nm"></td><td class="bars">them, and the median player under it</td><td class="n">Them</td><td class="n">Most</td><td class="n">You</td><td class="vs"></td></tr>
        @endif
        @forelse ($m['presses'] as $grp)
                <tr class="g"><td colspan="6"><div class="grp">{{ $grp['title'] }}</div></td></tr>
                @foreach ($grp['rows'] as $r)
                    @php
                        $bars = '<td class="bars"><div class="bar"><span style="width: '.max(1, $r['bar']).'%; background: '.e($m['color']).'"></span></div>'
                            .($r['barMedian'] !== null ? '<div class="bar m"><span style="width: '.max(1, $r['barMedian']).'%"></span></div>' : '').'</td>';
                        $vs = $r['vs'] === 'more' ? '<span class="chip glad">more than most</span>' : ($r['vs'] === 'less' ? '<span class="chip neutral">less than most</span>' : '');
                        $after = $bars.'<td class="n"><b>'.$rate($r['theirs']).'</b></td><td class="n muted">'.$rate($r['median']).'</td><td class="n muted">'.($m['youPlay'] ? $rate($r['yours']) : '-').'</td><td class="vs">'.$vs.'</td>';
                    @endphp
                    {!! $spellRow($r, $after, 6, 'nm') !!}
                @endforeach
        @empty
            <span class="muted small">Not measured yet: presses are stored from analysis version 10 (6 Oct 2026). Run a sync with <b>--fresh</b> to measure older games again.</span>
        @endforelse
        @if ($m['presses'])
            </table>
        @endif
        <div class="small subtle" style="margin-top: 8px">Click a spell for what it does. A count includes every cast the log records, so a spell that fires by itself shows here too. "More" or "less than most" is a difference to read, not a fault: talents, the comp and the game decide a lot of it.</div>
    </div>
</div>

{{-- ============================== output --}}
@if ($m['output'])
    <div class="section">
        <div class="label">Their output and habits <span class="aside">against the median {{ $specShort }} in the archive{{ $m['youPlay'] ? ', and you on the spec' : '' }}</span></div>
        <div class="box">
            <table class="stats">
                <tr><td class="small muted"></td><td class="n small muted" style="width: 90px">Them</td><td class="n small muted" style="width: 90px">Most</td><td class="n small muted" style="width: 90px">You</td></tr>
                @foreach ($m['output'] as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="n">{{ $row['theirs'] }}</td><td class="n muted">{{ $row['median'] }}</td><td class="n muted">{{ $m['youPlay'] ? $row['yours'] : '-' }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>
@endif

{{-- ============================== goes and defensives --}}
<div class="section">
    <table class="two"><tr>
        <td class="col left"><div class="box">
            <div class="label">In their team's goes <span class="aside">{{ $m['goes']['count'] }} go{{ $m['goes']['count'] === 1 ? '' : 'es' }}; they pressed an offensive cooldown in {{ $m['goes']['pressedIn'] }}; killed one of you {{ $m['goes']['killed'] }}</span></div>
            {!! $list($m['goes']['offensive'], 'No offensive cooldown of theirs in a go.') !!}
            <div class="label" style="margin-top: 10px">Their crowd control on you in those goes</div>
            {!! $list($m['goes']['control'], 'None of their crowd control was part of a go.') !!}
        </div></td>
        <td class="col right"><div class="box">
            @php $d = $m['defensives']; @endphp
            <div class="label">Their defensives <span class="aside">{{ $d['count'] }} before the first death, {{ $d['perRound'] }} a round{{ $d['hp'] !== null ? ', at '.$d['hp'].'% health (median)' : '' }}; {{ $d['outside'] }} outside your goes</span></div>
            {!! $list($d['rows'], 'None pressed before the first death.') !!}
            <div class="label" style="margin-top: 10px">Their casts your team kicked</div>
            {!! $list($m['kickedOf'], 'None kicked.') !!}
        </div></td>
    </tr></table>
</div>

{{-- ============================== damage and healing --}}
@if ($m['breakdown'])
    <div class="section">
        <div class="label">Where their output came from <span class="aside">share of their total onto players, every round summed</span></div>
        <table class="two"><tr>
            @foreach ($m['breakdown'] as $i => $bd)
                <td class="col {{ $i === 0 ? 'left' : 'right' }}"><div class="box">
                    <div class="label">{{ $bd['title'] }}</div>
                    {!! $list($bd['rows']) !!}
                </div></td>
            @endforeach
            @if (count($m['breakdown']) === 1)<td class="col right"></td>@endif
        </tr></table>
    </div>
@endif

{{-- ============================== every round --}}
<div class="section">
    <div class="label">Every round against them</div>
    <div class="box">
        <table class="stats">
            <tr><td class="small muted">Played</td><td class="small muted">Bracket</td><td class="small muted">You</td><td class="small muted">Result</td><td class="small muted">MMR (you / them)</td><td class="small muted">They died at</td></tr>
            @foreach ($m['list'] as $row)
                <tr>
                    <td>{{ $row['when'] }}</td>
                    <td class="muted">{{ $row['bracket'] }}</td>
                    <td>{{ $row['who'] }}</td>
                    <td><span class="chip {{ $row['won'] ? 'won' : 'lost' }}">{{ $row['won'] ? 'WON' : 'LOST' }}</span></td>
                    <td class="muted">{{ $row['mmr'] ?? 'none in Solo Shuffle' }}</td>
                    <td class="muted">{{ $row['died'] ?? '-' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="foot">
    Every number is from your own games against this player. It is what they pressed in those games, not their talents or their reasons, and the log cannot see positioning, line of sight or calls.
    The best players' habits are the level they play at, not a recipe: across the archive, kicking more than the other team did not decide a game, while crowd control on the healer in a go, defensives drawn and answers held did.
</div>

</body>
</html>
