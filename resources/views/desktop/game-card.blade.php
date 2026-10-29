{{--
    One game's card for the MindCollector Logs desktop app (tools/log-manager), built by
    GameCardService. Shown in the app (WebView2) and on the site; the layout uses grid and flex since
    2026-10-29 (desktop.partials.styles). Colours are the site's tokens written out (tailwind.config.js).
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
{{-- A phone renders a page with no viewport at ~980px and scales it down, which inside /wow/coach's frame
     made every number too small to read. IE11 in the desktop app ignores this tag. --}}
<meta name="viewport" content="width=device-width, initial-scale=1">
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
{{-- The teams open first (2026-10-29): who was in the game, their titles and ratings, is what players
     come for. Moments sit right beside it, ahead of the rest. --}}
<body onload="showTab('summary')">

{{-- ============================== header (2026-10-29, the dashboard layout): your side on the left with
     its line-up, the other side and the buttons in a box on the right. --}}
@php
    $lineup = $g['kind'] === 'game' ? $g['teams']['us'] : array_values(array_filter($g['roster'], fn ($p) => $p['you']));
    $lead = collect($lineup)->firstWhere('you', true) ?? ($lineup[0] ?? null);
@endphp
<div class="head {{ $g['won'] ? 'head-won' : 'head-lost' }}">
<div class="hero">
    <div class="hero-main">
        @if ($lead && $lead['icon'])<img class="hero-ic" src="{{ $lead['icon'] }}" alt="">@endif
        <div style="min-width: 0">
            <div class="eyebrow">{{ $g['playedAt'] }}@if ($g['kind'] === 'game') &nbsp;&middot;&nbsp; {{ $g['duration'] }}@endif</div>
            <div style="margin-top: 2px">
                <span class="title">{{ $g['bracket'] }}</span>
                @if ($g['kind'] === 'shuffle')
                    <span class="chip {{ $g['won'] ? 'won' : 'lost' }}">{{ $g['record'][0] }}-{{ $g['record'][1] }}</span>
                @else
                    <span class="chip {{ $g['won'] ? 'won' : 'lost' }}">{{ $g['won'] ? 'WON' : 'LOST' }}</span>
                @endif
                {{-- Both sides' MMR on the title line (2026-10-29, Chriso: the opposition's rating visible
                     without scrolling): the Opponent box wraps below the line-up in a narrow window. --}}
                @if ($g['kind'] === 'game' && (($g['mmr']['us'] ?? null) || ($g['mmr']['them'] ?? null)))
                    <span class="big">{{ $g['mmr']['us'] ?? '?' }} <span class="small muted" style="font-weight: 400; letter-spacing: 0">vs</span> {{ $g['mmr']['them'] ?? '?' }}<span class="small muted" style="font-weight: 400; letter-spacing: 0">MMR</span></span>
                @endif
            </div>
            @if ($lineup)
                <div class="lineup">
                    @foreach ($lineup as $p)
                        <span class="p">@if ($p['icon'])<img src="{{ $p['icon'] }}" alt="">@endif<span class="name" style="color: {{ $p['color'] }}">{{ $p['name'] }}</span>@if ($p['you'])<span class="you">YOU</span>@endif @if ($p['spec'] !== $p['name'])<span class="muted">&nbsp;{{ $p['spec'] }}</span>@endif</span>
                    @endforeach
                </div>
            @endif
            @if (! empty($g['pool']))
                {{-- The website's comp board (GameCardService::pooled()): someone else's game, no one named. --}}
                <div class="small muted" style="margin-top: 6px">Read as <b style="color: #FFFFFF">{{ $g['pool']['us'] }}</b>, against {{ $g['pool']['them'] }}: "your team" is {{ $g['pool']['us'] }}. No player is named.</div>
            @endif
        </div>
    </div>
    <div class="opp">
        @if ($g['kind'] === 'game')
            {{-- Each opponent's Gladiator seasons first (2026-10-30, Chriso: "the glad xp of the opponents
                 needs to be one of the primary things people see"), then their MMR. --}}
            <div class="k">Opponents &middot; {{ $g['mmr']['them'] ?? '?' }} MMR</div>
            <table class="opp-list">
                @foreach ($g['teams']['them'] as $p)
                    <tr>
                        <td class="who">@if ($p['icon'])<img src="{{ $p['icon'] }}" alt="">@endif<span style="color: {{ $p['color'] }}">{{ $p['name'] }}</span></td>
                        <td class="gl">
                            @if ($p['xp']['state'] === 'ok')
                                @if ($p['xp']['glad'] > 0)<span class="chip glad">{{ $p['xp']['glad'] }}&times; Glad</span>@else<span class="small muted">no Glad</span>@endif
                            @elseif ($p['xp']['state'] === 'none')
                                <span class="small subtle">no profile</span>
                            @else
                                <span class="small subtle">not looked up</span>
                            @endif
                        </td>
                        <td class="ex small">@if (($p['xp']['state'] === 'ok') && $p['xp']['exp']){{ $p['xp']['exp'] }} <span class="subtle">exp</span>@endif</td>
                    </tr>
                @endforeach
            </table>
            <div class="small muted" style="margin-top: 4px">{{ $g['glad']['them'] }} Gladiator season{{ $g['glad']['them'] === 1 ? '' : 's' }} between them, your team {{ $g['glad']['us'] }}</div>
            @if (! empty($g['difficulty']))
                <div class="small" style="margin-top: 6px"><span class="chip {{ $g['difficulty']['tone'] }}">{{ $g['difficulty']['label'] }}</span> <span class="muted">&nbsp;{{ $g['difficulty']['text'] }}</span></div>
            @endif
        @else
            <div class="k">Solo Shuffle</div>
            <div class="v">{{ $g['record'][0] }} won, {{ $g['record'][1] }} lost</div>
        @endif
        @if (! empty($g['watch']) || ! empty($g['youtube']))
            <div class="actions">
                @if (! empty($g['watch']))
                    <a class="primary" href="{{ $g['watch'] }}">@include('desktop.partials.icon', ['n' => 'play']) {{ ! empty($g['youtube']) ? 'Watch the video' : 'Timeline' }}</a>
                @endif
                @if (! empty($g['youtube']))
                    {{-- Uploaded from the app (2026-10-28): the Watch button plays it with the timeline under it,
                         and its public page (VideoController, names hidden) is the one to send to people. --}}
                    <a class="plain" href="https://mindcollector.com/wow/videos/{{ $g['youtube'] }}" target="_blank" rel="noopener">Page to share &#8599;</a>
                    <a class="plain" href="https://youtu.be/{{ $g['youtube'] }}" target="_blank" rel="noopener">YouTube &#8599;</a>
                @endif
            </div>
        @endif
    </div>
</div>
</div>

@if ($g['kind'] === 'game')

    @php $noteCount = count($g['notes']) + count($g['gameNotes']); @endphp
    <div class="tabs">
        <a id="b-summary" onclick="showTab('summary')">Summary</a>
        {{-- Basics, Moments and Notes are about the uploader's own play: not on a pooled card. --}}
        @if (empty($g['pool']))<a id="b-moments" onclick="showTab('moments')">Moments @if (! empty($g['moments']))<span class="cnt">{{ count($g['moments']) }}</span>@endif</a>@endif
        @if (empty($g['pool']))<a id="b-basics" onclick="showTab('basics')">Basics</a>@endif
        <a id="b-damage" onclick="showTab('damage')">Damage &amp; healing</a>
        <a id="b-numbers" onclick="showTab('numbers')">Numbers</a>
        @if (empty($g['pool']))<a id="b-notes" onclick="showTab('notes')">Notes @if ($noteCount)<span class="cnt">{{ $noteCount }}</span>@endif</a>@endif
    </div>

    <div class="tab" id="t-summary">
    {{-- The Summary as panels (2026-10-29, the dashboard layout): how it ended and the numbers first, the
         teams, the game on one line of time, then every death in full. Every figure is one the other
         tabs already show; this is where the eye lands. --}}
    @php $first = $g['deaths'][0] ?? null; @endphp
    <div class="grid2 lead">
        <div class="card headline">
            <div class="kicker">@include('desktop.partials.icon', ['n' => 'skull'])How it ended</div>
            @if ($first)
                <div class="t"><span style="color: {{ $first['color'] }}">{{ $first['name'] }}</span> died at {{ $first['clock'] }}</div>
                <div class="line small">
                    @if ($first['killingBlow'])Killing blow @if ($first['killingBlow']['icon'])<img class="spell-ic" src="{{ $first['killingBlow']['icon'] }}" alt="">@endif<b style="color: #FFFFFF">{{ $first['killingBlow']['spell'] }}</b>. @endif
                    @if ($first['healer']){{ $first['healer']['whose'] }} <span class="chip {{ $first['healer']['tone'] }}">{{ $first['healer']['label'] }}</span>, {{ lcfirst($first['healer']['medallion']) }}. @endif
                    @if ($first['goStartedAgo'] !== null){{ $first['goWhose'] }} go had been running {{ $first['goStartedAgo'] }}s.@endif
                </div>
                <div class="line"><a href="#deaths" onclick="document.getElementById('deaths').scrollIntoView(); return false">Read {{ count($g['deaths']) === 1 ? 'the death' : 'every death' }} &#8595;</a></div>
            @else
                <div class="t">No one died</div>
                <div class="line small">The game ended another way.</div>
            @endif
        </div>
        <div class="card">
            <div class="kicker">@include('desktop.partials.icon', ['n' => 'chart'])The game in numbers</div>
            <div class="tiles">
                @foreach ($g['tiles'] ?? [] as $t)
                    <div class="tile">
                        <div class="k">@include('desktop.partials.icon', ['n' => $t['icon']]){{ $t['label'] }}</div>
                        <div class="v">{{ $t['value'] }}</div>
                        @if ($t['sub'])<div class="s">{{ $t['sub'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ============================== teams: yours first, the way the header reads --}}
    <div class="grid2">
        @foreach (['us' => 'Your team', 'them' => 'Enemy team'] as $side => $title)
            <div class="card">
                <div class="kicker">@include('desktop.partials.icon', ['n' => 'users']){{ $title }} <span style="text-transform: none; letter-spacing: 0; font-weight: 400">&nbsp;&middot; {{ $g['glad'][$side] }} Gladiator season{{ $g['glad'][$side] === 1 ? '' : 's' }}@if ($g['mmr'][$side] ?? null) &middot; {{ $g['mmr'][$side] }} MMR @endif</span></div>
                @include('desktop.partials.roster', ['players' => $g['teams'][$side], 'showCc' => true])
            </div>
        @endforeach
    </div>

    {{-- ============================== the game on one line of time, and the checks beside it --}}
    <div class="grid2 top">
        <div class="card" @if (! empty($g['pool'])) style="grid-column: 1 / -1" @endif>
            <div class="kicker">@include('desktop.partials.icon', ['n' => 'clock'])Key events</div>
            <div class="events">
                @forelse ($g['events'] ?? [] as $e)
                    <div class="ev {{ $e['kind'] }} {{ $e['ours'] ? 'ours' : 'theirs' }}">
                        <span class="t">{{ $e['clock'] }}</span>
                        <span class="dot">@if ($e['icon'])<img src="{{ $e['icon'] }}" alt="">@else @include('desktop.partials.icon', ['n' => ['go' => 'swords', 'kill' => 'swords', 'death' => 'skull', 'end' => 'trophy', 'medallion' => 'shield'][$e['kind']] ?? 'bolt'])@endif</span>
                        <span class="x">{{ $e['text'] }}</span>
                    </div>
                @empty
                    <div class="small muted">Nothing measured for this game.</div>
                @endforelse
            </div>
        </div>
        @if (empty($g['pool']))
            <div class="card">
                <div class="kicker">@include('desktop.partials.icon', ['n' => 'target'])Checks <span style="text-transform: none; letter-spacing: 0; font-weight: 400">&nbsp;&middot; what the log shows by itself, for your team</span></div>
                @include('desktop.partials.checks', ['checks' => $g['checks']])
            </div>
        @endif
    </div>

    {{-- ============================== deaths --}}
    <div class="section" id="deaths">
        <div class="label">@include('desktop.partials.icon', ['n' => 'skull']){{ count($g['deaths']) === 1 ? 'The death' : 'Every death' }}</div>
        @forelse ($g['deaths'] as $d)
            <div class="box death">@include('desktop.partials.death', ['d' => $d])</div>
        @empty
            <div class="box muted">No one died; the game ended another way.</div>
        @endforelse
    </div>
    </div>

    @if (empty($g['pool']))
    <div class="tab" id="t-basics">
        @include('desktop.partials.basics', ['b' => $g['basics'] ?? null])
    </div>

    <div class="tab" id="t-moments">
        @include('desktop.partials.moments', ['m' => $g['moments'] ?? null])
    </div>
    @endif

    <div class="tab" id="t-damage">
        @include('desktop.partials.breakdown', ['b' => $g['breakdown']])
    </div>

    <div class="tab" id="t-numbers">
    {{-- ============================== stats --}}
    <div class="section">
        <div class="label">The game in numbers</div>
        <div class="box">
            <table class="stats">
                <tr><td class="small muted"></td><td class="n small muted">You</td><td class="n small muted">Enemy</td></tr>
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

    @if (empty($g['pool']))
    <div class="tab" id="t-notes">
        @include('desktop.partials.game-notes', ['notes' => $g['gameNotes']])
        @include('desktop.partials.notes', ['notes' => $g['notes']])
    </div>
    @endif

@else

    {{-- ============================== a shuffle lobby --}}
    @php $noteCount = count($g['gameNotes']) + collect($g['rounds'])->sum(fn ($r) => count($r['notes'])); @endphp
    <div class="tabs">
        <a id="b-summary" onclick="showTab('summary')">Rounds</a>
        <a id="b-moments" onclick="showTab('moments')">Moments @if (! empty($g['moments']))<span class="cnt">{{ count($g['moments']) }}</span>@endif</a>
        <a id="b-basics" onclick="showTab('basics')">Basics</a>
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
            @if (! empty($r['talents']))
                <div class="small" style="margin-top: 4px">
                    <span class="muted">Talents changed since their last round:</span>
                    @foreach ($r['talents'] as $c)
                        <span class="name" style="color: {{ $c['color'] }}">{{ $c['name'] }}</span>
                        @if ($c['took'])took {{ implode(', ', $c['took']) }}@endif{{ $c['took'] && $c['dropped'] ? ',' : '' }}
                        @if ($c['dropped'])dropped {{ implode(', ', $c['dropped']) }}@endif{{ $loop->last ? '' : ';' }}
                    @endforeach
                </div>
            @endif
            @if ($r['death'])
                <div class="line">@include('desktop.partials.death', ['d' => $r['death']])</div>
            @endif
            @if ($r['look'] || $r['checks'])
                <details class="more">
                <summary>Your buttons this round</summary>
                @if ($r['look'])
                    <div class="line"><div class="label" style="margin-top:8px">Your buttons <span class="aside">an estimate, not a verdict</span></div>
                    @include('desktop.partials.look', ['items' => $r['look']])</div>
                @endif
                @if ($r['checks'])
                    <div class="line"><div class="label" style="margin-top:8px">Checks <span class="aside">your buttons, from the log alone</span></div>
                    @include('desktop.partials.checks', ['checks' => $r['checks']])</div>
                @endif
                </details>
            @endif
            @if ($r['notes'])
                <div class="line small gold">{{ count($r['notes']) }} note{{ count($r['notes']) === 1 ? '' : 's' }} on this round, under Notes</div>
            @endif
        </div>
    @endforeach

    </div>

    <div class="tab" id="t-basics">
        @include('desktop.partials.basics', ['b' => $g['basics'] ?? null])
    </div>

    <div class="tab" id="t-moments">
        @include('desktop.partials.moments', ['m' => $g['moments'] ?? null])
    </div>

    <div class="tab" id="t-damage">
        @if (! empty($g['byRound']))
            <div class="section">
                <div class="label">By round <span class="aside">damage, then healing with absorbs; shaded where they were on your team</span></div>
                <div class="box">
                    <table class="stats">
                        <tr class="small muted">
                            <td></td>
                            @foreach ($g['byRound']['rounds'] as $c)
                                <td class="n">Round {{ $c['n'] }} <span style="color: {{ $c['won'] ? '#86efac' : '#F28B82' }}">{{ $c['won'] ? 'W' : 'L' }}</span></td>
                            @endforeach
                        </tr>
                        @foreach ($g['byRound']['players'] as $p)
                            <tr>
                                <td><span class="name" style="color: {{ $p['color'] }}">{{ $p['name'] }}</span>@if ($p['you'])<span class="you">YOU</span>@endif</td>
                                @foreach ($p['cells'] as $cell)
                                    <td class="n" style="{{ $cell && $cell['with'] ? 'background: #232323;' : '' }} padding: 3px 6px">
                                        @if ($cell)
                                            {{ $cell['damage'] }}<br><span class="small muted">{{ $cell['healing'] }}</span>
                                        @else
                                            <span class="muted">&ndash;</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endif
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
