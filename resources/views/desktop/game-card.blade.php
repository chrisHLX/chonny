{{--
    One game's card for the MindCollector Logs desktop app (tools/log-manager), built by
    GameCardService. Shown in Windows' built-in browser control, which renders as IE11: no CSS
    variables, no grid, no flex gap. Colours are the site's tokens written out (tailwind.config.js).
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<style>
    html, body { margin: 0; padding: 0; background: #111116; color: #F0F0F2; }
    /* IE's own scrollbar properties: the app's browser control is IE11. */
    html { scrollbar-face-color: #2C2C38; scrollbar-track-color: #111116; scrollbar-arrow-color: #8A8A9A;
        scrollbar-shadow-color: #2C2C38; scrollbar-highlight-color: #2C2C38; scrollbar-3dlight-color: #111116; scrollbar-darkshadow-color: #111116; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; line-height: 1.45; padding: 14px 16px 24px; }
    table { border-collapse: collapse; }
    img { border: 0; }
    .muted { color: #8A8A9A; }
    .subtle { color: #52525F; }
    .gold { color: #C8952C; }
    .small { font-size: 11.5px; }
    .head { margin-bottom: 12px; }
    .head .title { font-size: 18px; font-weight: 600; margin: 0 8px 0 6px; vertical-align: middle; }
    .head .when { vertical-align: middle; }
    .head .mmr { float: right; margin-top: 4px; }
    .chip { display: inline-block; padding: 1px 9px; border-radius: 10px; font-size: 11.5px; font-weight: 700; letter-spacing: .04em; vertical-align: middle; }
    .won { background: #12301d; color: #86efac; }
    .lost { background: #3a1416; color: #fca5a5; }
    .good { background: #12301d; color: #86efac; }
    .bad { background: #3a1416; color: #fca5a5; }
    .warn { background: #33270c; color: #fcd34d; }
    .neutral { background: #1E1E26; color: #8A8A9A; }
    .glad { background: #1E150A; color: #E8B84B; border: 1px solid #6B4E1A; }
    .section { margin-top: 18px; }
    .label { font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #8A8A9A; margin-bottom: 6px; }
    .label .aside { font-weight: 400; letter-spacing: 0; text-transform: none; color: #52525F; margin-left: 6px; }
    .box { background: #18181E; border: 1px solid #2C2C38; border-radius: 8px; padding: 10px 12px; }
    .teams { width: 100%; }
    .teams td.col { width: 50%; vertical-align: top; padding: 0; }
    .teams td.col.left { padding-right: 6px; }
    .teams td.col.right { padding-left: 6px; }
    .roster { width: 100%; }
    .roster td { padding: 4px 0; vertical-align: middle; }
    .roster tr + tr td { border-top: 1px solid #1E1E26; }
    .roster .ic { width: 30px; }
    .roster .xp { text-align: right; white-space: nowrap; }
    .spec-ic { width: 24px; height: 24px; border-radius: 5px; vertical-align: middle; }
    .spell-ic { width: 20px; height: 20px; border-radius: 4px; vertical-align: middle; margin-right: 6px; }
    .ph { display: inline-block; width: 24px; height: 24px; border-radius: 5px; background: #1E1E26; vertical-align: middle; }
    .ph-s { display: inline-block; width: 20px; height: 20px; border-radius: 4px; background: #1E1E26; vertical-align: middle; margin-right: 6px; }
    .name { font-weight: 600; }
    .you { font-size: 10.5px; color: #C8952C; margin-left: 4px; font-weight: 600; }
    .death { margin-bottom: 8px; }
    .death .who { font-size: 14px; }
    .line { margin-top: 6px; }
    .bar { width: 100%; height: 8px; border-radius: 4px; background: #1E1E26; overflow: hidden; font-size: 0; margin: 4px 0 2px; white-space: nowrap; }
    .bar span { display: inline-block; height: 8px; }
    .legend span { margin-right: 10px; }
    .def { display: inline-block; margin: 2px 10px 2px 0; white-space: nowrap; }
    .stats { width: 100%; }
    .stats td { padding: 5px 0; }
    .stats tr + tr td { border-top: 1px solid #1E1E26; }
    .stats .n { width: 70px; text-align: right; font-weight: 600; }
    .stats .better { color: #86efac; }
    .stats .worse { color: #fca5a5; }
    .look td { padding: 5px 0; vertical-align: top; }
    .look .ic { width: 28px; }
    .look .count { color: #8A8A9A; white-space: nowrap; padding-left: 8px; }
    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 4px; margin: 6px 6px 0 6px; }
    .note { padding: 4px 0; }
    .note .t { display: inline-block; width: 48px; color: #8A8A9A; }
    .flag { color: #C8952C; font-weight: 600; }
    .round { margin-top: 10px; }
    .round .rh { margin-bottom: 4px; }
    .round .rh b { font-size: 14px; margin-right: 8px; }
    .foot { margin-top: 20px; color: #52525F; font-size: 11.5px; }
    .tabs { margin: 0 0 12px; border-bottom: 1px solid #2C2C38; }
    .tabs a { display: inline-block; padding: 6px 12px; margin-right: 2px; color: #8A8A9A; text-decoration: none; font-weight: 600; border-bottom: 2px solid #111116; cursor: pointer; }
    .tabs a.on { color: #C8952C; border-bottom-color: #C8952C; }
    .tabs a .cnt { color: #52525F; font-weight: 400; }
    .tab .section:first-child { margin-top: 0; }
    .bd tr.pl td { cursor: pointer; }
    .bd tr.pl:hover td { background: #1E1E26; }
    .bd td.abil { padding: 2px 0 10px 26px; }
    .ab { width: 100%; }
    .ab td { padding: 2px 0; }
    .ab .sp { white-space: nowrap; padding-right: 10px; }
    .ab .sh { width: 30%; }
    .ab .n { width: 64px; text-align: right; padding-left: 8px; white-space: nowrap; }
</style>
<script>
    // Tabs. The app reads the open tab from the title and opens the same one on the next card.
    function showTab(name) {
        var tabs = document.getElementsByClassName('tab'), found = false, i;
        for (i = 0; i < tabs.length; i++) { if (tabs[i].id === 't-' + name) { found = true; } }
        if (!found && tabs.length) { name = tabs[0].id.substring(2); }
        for (i = 0; i < tabs.length; i++) {
            var id = tabs[i].id.substring(2), b = document.getElementById('b-' + id);
            tabs[i].style.display = id === name ? '' : 'none';
            if (b) { b.className = id === name ? 'on' : ''; }
        }
        document.title = 'tab:' + name;
    }
</script>
</head>
<body onload="showTab('summary')">

{{-- ============================== header --}}
<div class="head">
    @if ($g['kind'] === 'shuffle')
        <span class="chip {{ $g['won'] ? 'won' : 'lost' }}">{{ $g['record'][0] }}-{{ $g['record'][1] }}</span>
    @else
        <span class="chip {{ $g['won'] ? 'won' : 'lost' }}">{{ $g['won'] ? 'WON' : 'LOST' }}</span>
    @endif
    <span class="title">{{ $g['bracket'] }}</span>
    <span class="when muted">{{ $g['playedAt'] }}@if ($g['kind'] === 'game') &nbsp;&middot;&nbsp; {{ $g['duration'] }}@endif</span>
    @if ($g['kind'] === 'game' && ($g['mmr']['us'] ?? null))
        <span class="mmr muted">MMR <b style="color:#F0F0F2">{{ $g['mmr']['us'] }}</b> you &nbsp;&middot;&nbsp; <b style="color:#F0F0F2">{{ $g['mmr']['them'] ?? '?' }}</b> them</span>
    @endif
</div>

@if ($g['kind'] === 'game')

    @php $noteCount = count($g['notes']) + count($g['gameNotes']); @endphp
    <div class="tabs">
        <a id="b-summary" onclick="showTab('summary')">Summary</a>
        <a id="b-damage" onclick="showTab('damage')">Damage &amp; healing</a>
        <a id="b-numbers" onclick="showTab('numbers')">Numbers</a>
        <a id="b-notes" onclick="showTab('notes')">Notes @if ($noteCount)<span class="cnt">{{ $noteCount }}</span>@endif</a>
    </div>

    <div class="tab" id="t-summary">
    {{-- ============================== teams --}}
    <table class="teams"><tr>
        @foreach (['them' => 'Them', 'us' => 'Your team'] as $side => $title)
            <td class="col {{ $side === 'them' ? 'left' : 'right' }}">
                <div class="label">{{ $title }} <span class="aside">{{ $g['glad'][$side] }} Gladiator season{{ $g['glad'][$side] === 1 ? '' : 's' }}</span></div>
                <div class="box">
                    @include('desktop.partials.roster', ['players' => $g['teams'][$side], 'showCc' => true])
                </div>
            </td>
        @endforeach
    </tr></table>

    {{-- ============================== deaths --}}
    <div class="section">
        <div class="label">How it ended</div>
        @forelse ($g['deaths'] as $d)
            <div class="box death">@include('desktop.partials.death', ['d' => $d])</div>
        @empty
            <div class="box muted">No one died; the game ended another way.</div>
        @endforelse
    </div>

    <div class="section">
        <div class="label">Checks <span class="aside">what the log shows by itself, for your team</span></div>
        <div class="box">@include('desktop.partials.checks', ['checks' => $g['checks']])</div>
    </div>
    </div>

    <div class="tab" id="t-damage">
        @include('desktop.partials.breakdown', ['b' => $g['breakdown']])
    </div>

    <div class="tab" id="t-numbers">
    {{-- ============================== stats --}}
    <div class="section">
        <div class="label">The game in numbers</div>
        <div class="box">
            <table class="stats">
                <tr><td class="small muted"></td><td class="n small muted">You</td><td class="n small muted">Them</td></tr>
                @foreach ($g['stats'] as [$label, $us, $them, $moreIsBetter])
                    @php
                        // Green where you came out ahead, red where they did; neutral rows stay plain.
                        $usClass = '';
                        if ($moreIsBetter !== null && $us !== $them) {
                            $usClass = ($moreIsBetter ? $us > $them : $us < $them) ? 'better' : 'worse';
                        }
                    @endphp
                    <tr><td>{{ $label }}</td><td class="n {{ $usClass }}">{{ $us }}</td><td class="n">{{ $them }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>

    @if ($g['look'] !== null)
        <div class="section">
            <div class="label">Worth a look <span class="aside">rules applied to the log: an estimate, not a verdict</span></div>
            <div class="box">@include('desktop.partials.look', ['items' => $g['look']])</div>
        </div>
    @endif

    </div>

    <div class="tab" id="t-notes">
        @include('desktop.partials.game-notes', ['notes' => $g['gameNotes']])
        @include('desktop.partials.notes', ['notes' => $g['notes']])
    </div>

@else

    {{-- ============================== a shuffle lobby --}}
    @php $noteCount = count($g['gameNotes']) + collect($g['rounds'])->sum(fn ($r) => count($r['notes'])); @endphp
    <div class="tabs">
        <a id="b-summary" onclick="showTab('summary')">Rounds</a>
        <a id="b-damage" onclick="showTab('damage')">Damage &amp; healing</a>
        <a id="b-notes" onclick="showTab('notes')">Notes @if ($noteCount)<span class="cnt">{{ $noteCount }}</span>@endif</a>
    </div>

    <div class="tab" id="t-summary">
    <div class="label">Players <span class="aside">teams are re-dealt every round</span></div>
    <div class="box">
        <table class="teams"><tr>
            @foreach (array_chunk($g['roster'], 3) as $i => $half)
                <td class="col {{ $i === 0 ? 'left' : 'right' }}">@include('desktop.partials.roster', ['players' => $half, 'showCc' => false])</td>
            @endforeach
        </tr></table>
    </div>

    @foreach ($g['rounds'] as $r)
        <div class="box round">
            <div class="rh">
                <b>Round {{ $r['n'] }}</b>
                <span class="chip {{ $r['won'] ? 'won' : 'lost' }}">{{ $r['won'] ? 'WON' : 'LOST' }}</span>
                <span class="muted">&nbsp;{{ $r['duration'] }}</span>
            </div>
            <div class="small">
                <span class="muted">with</span>
                @forelse ($r['with'] as $p)
                    <span class="name" style="color: {{ $p['color'] }}">{{ $p['name'] }}</span>@if (! $loop->last), @endif
                @empty
                    <span class="muted">no one</span>
                @endforelse
                <span class="muted">&nbsp;against</span>
                @foreach ($r['against'] as $p)
                    <span class="name" style="color: {{ $p['color'] }}">{{ $p['name'] }}</span>@if (! $loop->last), @endif
                @endforeach
            </div>
            @if ($r['death'])
                <div class="line">@include('desktop.partials.death', ['d' => $r['death']])</div>
            @endif
            @if ($r['look'])
                <div class="line"><div class="label" style="margin-top:8px">Your buttons <span class="aside">an estimate, not a verdict</span></div>
                @include('desktop.partials.look', ['items' => $r['look']])</div>
            @endif
            @if ($r['checks'])
                <div class="line"><div class="label" style="margin-top:8px">Checks <span class="aside">your buttons, from the log alone</span></div>
                @include('desktop.partials.checks', ['checks' => $r['checks']])</div>
            @endif
            @if ($r['notes'])
                <div class="line small gold">{{ count($r['notes']) }} note{{ count($r['notes']) === 1 ? '' : 's' }} on this round, under Notes</div>
            @endif
        </div>
    @endforeach

    </div>

    <div class="tab" id="t-damage">
        @include('desktop.partials.breakdown', ['b' => $g['breakdown']])
    </div>

    <div class="tab" id="t-notes">
        @include('desktop.partials.game-notes', ['notes' => $g['gameNotes']])
        @foreach ($g['rounds'] as $r)
            @if ($r['notes'])
                <div class="section"><div class="label">Round {{ $r['n'] }}</div>
                <div class="box">@include('desktop.partials.note-rows', ['notes' => $r['notes']])</div></div>
            @endif
        @endforeach
        @if (! $noteCount)
            <div class="box muted">No notes on this lobby. Write one in the box below the card.</div>
        @endif
    </div>

@endif

<div class="foot">Experience is each character as it is now; Gladiator seasons count across their whole account.
@if ($g['kind'] === 'game' && $g['missingXp'] > 0) {{ $g['missingXp'] }} player(s) have no experience on file and count as none.@endif</div>

</body>
</html>
