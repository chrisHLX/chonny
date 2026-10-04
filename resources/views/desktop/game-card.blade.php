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
@include('desktop.partials.styles')
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
    @if ($g['kind'] === 'game' && ! empty($g['difficulty']))
        <div class="small" style="margin-top: 6px"><span class="chip {{ $g['difficulty']['tone'] }}">{{ $g['difficulty']['label'] }}</span> <span class="muted">&nbsp;{{ $g['difficulty']['text'] }}</span></div>
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
