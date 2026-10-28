{{--
    Watch: one game's video with its measured timeline drawn underneath (2026-10-21).

    Per LOBBY, not per round, because one recording covers the whole thing: a Solo Shuffle is six
    rounds in a single capture. Each round gets its own rail, and its start inside the recording
    comes from the wall-clock gap between the rounds' `played_at` values -- which includes each
    prep period for free, since it is real elapsed time. That is second-precision, so every rail is
    nudgeable; only round one's position has to be set by hand.

    The video is a file the player already has (OBS, ShadowPlay, Game Bar), chosen with a picker
    and read through a blob URL. It is never uploaded and never leaves the machine.

    A video the desktop app recorded through OBS (2026-10-25) comes with its own timing, so the
    command passes `$video` (its file:/// address and round 1's exact position) and the page opens
    it and lines it up by itself. The picker stays, for when the file has moved.

    What is drawn is what RoundAnalysisService already measured: goes as WINDOWS (from/to seconds),
    not as points. A go is a structured stretch of coordinated cooldowns, so it is a block on a
    rail with its own duration.

    UNLIKE the other desktop pages this one is NOT IE11-safe: it needs <video>, modern JS and
    flexbox, so it is for WebView2 (the C# app) and the website. In the PowerShell app's IE11
    control the feature check below hides the video half and the rails still read.
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Watch &mdash; {{ $title ?? 'Game' }}</title>
@include('desktop.partials.styles')
<style>
    .wrap { max-width: 1180px; }
    .stage { background: #000000; border-radius: 8px; overflow: hidden; position: sticky; top: 0; z-index: 5;
        /* covers the page around it, so the timeline's edge labels do not show beside it when scrolled */
        box-shadow: 0 0 0 16px #121212; }
    .stage video { display: block; width: 100%; max-height: 46vh; background: #000000; }
    .pl-wrap { padding: 1px 0 3px; }
    .rib { position: relative; height: 20px; margin-left: 2px; background: #181818; border-radius: 4px; overflow: hidden; }
    .rib .now { position: absolute; right: 0; top: 0; width: 2px; height: 100%; background: #FFFFFF; opacity: .5; }
    .rib .c { position: absolute; top: 1px; width: 18px; height: 18px; border-radius: 3px; overflow: hidden; background: #2A2A2A; margin-left: -9px; }
    .rib .c img { width: 100%; height: 100%; display: block; }
    .rib .c.big { box-shadow: 0 0 0 1.5px #E8B84B; z-index: 2; }
    .rib .c .lt { display: block; font-size: 8px; color: #B3B3B3; text-align: center; line-height: 18px; }
    .rib .sec { position: absolute; top: 0; height: 100%; width: 1px; background: #232323; }
    .blank { display: none; height: 22vh; min-height: 120px; background: #000000; color: #6A6A6A; align-items: center; justify-content: center; flex-direction: column; gap: 6px; }
    .blank .big { font-size: 34px; font-weight: 800; color: #FFFFFF; font-variant-numeric: tabular-nums; }
    .blank .small { font-size: 12px; }
    .clockbar { display: none; align-items: center; gap: 10px; padding: 8px 10px; background: #121212; }
    .clockbar button { background: #FFFFFF; color: #000000; border: 0; border-radius: 9999px; width: 34px; height: 34px; font-size: 14px; font-weight: 800; cursor: pointer; }
    .clockbar input[type=range] { flex: 1; }
    .clockbar .tm { font-size: 12.5px; color: #B3B3B3; font-variant-numeric: tabular-nums; min-width: 84px; text-align: right; }
    .clockbar select { background: #2A2A2A; color: #FFFFFF; border: 0; border-radius: 6px; padding: 4px 6px; font-size: 12px; }
    .frames { display: none; gap: 14px; padding: 8px 10px 6px; background: #121212; }
    .frames .side { flex: 1; min-width: 0; }
    .frames .who { font-size: 10.5px; color: #6A6A6A; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
    .pl { display: flex; align-items: center; gap: 4px; padding: 2px 0; }
    .pl .nm { width: 96px; flex: none; font-weight: 700; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pl .hp { position: relative; width: 58px; height: 14px; flex: none; border-radius: 3px; overflow: hidden; background: #2A2A2A; }
    .pl .hp .f { position: absolute; left: 0; top: 0; bottom: 0; background: #22C55E; }
    .pl .hp .f.mid { background: #F59E0B; }
    .pl .hp .f.low { background: #EF4444; }
    .pl .hp .v { position: absolute; left: 0; right: 0; top: 0; text-align: center; font-size: 9.5px; line-height: 14px; font-weight: 800; color: #FFFFFF; text-shadow: 0 0 2px #000, 0 0 2px #000; }
    .pl .st { width: 74px; flex: none; font-size: 10.5px; color: #fca5a5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pl .abs { display: flex; gap: 3px; flex-wrap: wrap; }
    .pl .gap { width: 6px; }
    .pl.locked .abs { opacity: .4; }
    .pl.dead { opacity: .25; }
    .ab { position: relative; width: 24px; height: 24px; border-radius: 4px; overflow: hidden; background: #2A2A2A; flex: none; }
    .ab img { width: 100%; height: 100%; display: block; }
    .ab .lt { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #B3B3B3; }
    .ab.cd img { filter: grayscale(1) brightness(.4); }
    .ab .cdt { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 800; color: #FFFFFF; text-shadow: 0 0 3px #000, 0 0 2px #000; }
    .ab .ch { position: absolute; right: 1px; bottom: -1px; font-size: 9.5px; color: #E8B84B; font-weight: 800; text-shadow: 0 0 2px #000; }
    .frames-note { display: none; padding: 0 10px 6px; font-size: 11px; color: #6A6A6A; background: #121212; }
    .drop { padding: 40px 20px; text-align: center; background: #181818; border: 1px dashed #3E3E3E; border-radius: 8px; }
    .drop b { display: block; font-size: 15px; margin-bottom: 4px; }
    .pick { display: inline-block; margin-top: 10px; padding: 8px 16px; border-radius: 9999px;
        background: #C8952C; color: #000000; font-weight: 700; font-size: 13px; cursor: pointer; }
    .pick:hover { background: #E8B84B; }
    .nosupport { display: none; }
    .hint { margin-top: 10px; font-size: 12px; color: #B3B3B3; }
    .hint code { color: #E8B84B; }

    .rnd { margin-top: 18px; }
    .rnd .rh { margin-bottom: 4px; }
    .rnd .rh b { font-size: 14px; margin-right: 8px; }
    .rail { position: relative; height: 64px; background: #181818; border-radius: 6px; overflow: hidden; cursor: pointer; }
    .go { position: absolute; height: 19px; border-radius: 3px; opacity: .92; }
    .go.us { background: #2f7d4f; top: 6px; }
    .go.them { background: #8c3b3b; top: 28px; }
    .go.kill { box-shadow: inset 0 0 0 2px #E8B84B; }
    .death { position: absolute; top: 0; width: 2px; height: 100%; background: #F28B82; }
    .def { position: absolute; top: 51px; width: 3px; height: 10px; border-radius: 1px; }
    .fall { position: absolute; height: 19px; border-radius: 3px; }
    .fall.us { background: #2f7d4f; top: 6px; }
    .fall.them { background: #c0392b; top: 28px; }
    .fall.control { background-image: repeating-linear-gradient(135deg, rgba(0,0,0,.35) 0 4px, transparent 4px 8px); }
    .fall.died { box-shadow: inset 0 0 0 2px #E8B84B; }
    .unmoved { position: absolute; top: 0; width: 3px; height: 5px; background: #B3B3B3; border-radius: 1px; }
    .go.old { opacity: .5; height: 6px; }
    .go.old.us { top: 1px; }
    .go.old.them { top: 49px; }
    .def.us { background: #86efac; }
    .def.them { background: #fca5a5; }
    .ev tr.now td { background: #232323; }
    .bd-toggle { margin-top: 6px; background: #2A2A2A; color: #FFFFFF; border: 0; border-radius: 9999px; padding: 4px 12px; font-size: 12px; font-weight: 700; cursor: pointer; }
    .bd-toggle:hover { background: #3E3E3E; }
    .fb { display: none; margin: 8px 0 4px; padding: 10px 12px; background: #181818; border-radius: 8px; cursor: default; }
    .fb.open { display: block; }
    .fb .fb-h { font-size: 11px; font-weight: 700; color: #B3B3B3; text-transform: uppercase; letter-spacing: .06em; margin: 10px 0 4px; }
    .fb .fb-h:first-child { margin-top: 0; }
    .fb table { width: 100%; border-collapse: collapse; }
    .fb td { padding: 3px 0; border-top: 0 !important; vertical-align: top; font-size: 12.5px; }
    .fb tr[data-st] { cursor: pointer; }
    .fb tr[data-st]:hover td { background: #232323; }
    .fb td.at { width: 58px; color: #B3B3B3; font-variant-numeric: tabular-nums; }
    .fb .drl { display: inline-block; margin-left: 6px; font-size: 11px; color: #B3B3B3; }
    .fb .drl.half { color: #E8B84B; }
    .fb .drl.immune { color: #fca5a5; }
    .fb .kick { margin-top: 2px; font-size: 12px; color: #86efac; }
    .fb .kick.none { color: #6A6A6A; }
    .fb .who { margin: 3px 0; font-size: 12.5px; }
    .fb .who b { margin-right: 6px; }
    .fb .note { margin-top: 10px; font-size: 11px; color: #6A6A6A; }
    .ev tr.hide { display: none; }
    .jump { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: 12px 0 2px; font-size: 12.5px; color: #B3B3B3; }
    .jump button { background: #2A2A2A; color: #FFFFFF; border: 0; border-radius: 9999px; padding: 6px 12px; cursor: pointer; font-size: 12.5px; font-weight: 700; }
    .jump button:hover { background: #3E3E3E; }
    .jump button.off { background: transparent; color: #6A6A6A; box-shadow: inset 0 0 0 1px #3E3E3E; }
    .jump .sep { width: 10px; }
    .applinks { float: right; margin-top: 2px; }
    .applinks a { display: inline-block; margin-left: 6px; padding: 6px 14px; border-radius: 9999px; background: #2A2A2A; color: #FFFFFF; font-weight: 700; font-size: 12.5px; text-decoration: none; }
    .applinks a.gold { background: #C8952C; color: #000000; }
    .head2 { position: absolute; top: 0; width: 2px; height: 100%; background: #FFFFFF; display: none; pointer-events: none; }
    .ticks { position: relative; height: 14px; color: #6A6A6A; font-size: 10.5px; }
    .ticks span { position: absolute; transform: translateX(-50%); }

    .legend { font-size: 11.5px; color: #B3B3B3; margin: 10px 0 2px; }
    .legend b { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin: 0 4px 0 12px; }
    .legend b:first-child { margin-left: 0; }

    .offset { margin: 12px 0 4px; font-size: 12.5px; color: #B3B3B3; }
    .offset input[type=range] { vertical-align: middle; width: 240px; margin: 0 8px; }
    .offset .v { color: #FFFFFF; font-weight: 700; font-variant-numeric: tabular-nums; }
    .offset button { background: #2A2A2A; color: #FFFFFF; border: 0; border-radius: 4px; padding: 3px 9px; cursor: pointer; font-size: 12px; margin-left: 4px; }
    .offset button:hover { background: #3E3E3E; }

    .ev { width: 100%; }
    .ev td { padding: 4px 0; vertical-align: top; border-top: 1px solid #282828; cursor: pointer; }
    .ev tr:hover td { background: #1f1f1f; }
    .ev .t { width: 54px; color: #B3B3B3; font-variant-numeric: tabular-nums; }
    .ev .w { width: 70px; }
    .pill { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 10.5px; font-weight: 700; }
    .pill.us { background: rgba(47,125,79,.25); color: #86efac; }
    .pill.them { background: rgba(140,59,59,.25); color: #fca5a5; }
    @media (max-width: 560px) {
        .stage { position: static; }
        .ev .w { width: 54px; }
        .offset input[type=range] { width: 140px; }
    }
    /* Phones and narrow frames (2026-10-26): side by side, each team had half a phone's width and a
       player's buttons stacked one per line. The teams stack instead, and each player's buttons wrap
       across the whole width under their name. */
    @media (max-width: 760px) {
        .frames { flex-direction: column; gap: 10px; padding: 8px; }
        .pl { flex-wrap: wrap; row-gap: 3px; }
        .pl .nm { width: auto; max-width: 60%; }
        .pl .st { width: auto; margin-left: 6px; }
        .pl .abs { flex: 1 1 100%; }
        .ab { width: 22px; height: 22px; }
        .rib { margin-left: 0; }
        .blank { height: 14vh; min-height: 90px; }
        .blank .big { font-size: 26px; }
        .clockbar { flex-wrap: wrap; gap: 8px; }
        .clockbar input[type=range] { order: 5; flex: 1 1 100%; }
        .jump { gap: 5px; }
        .legend { font-size: 11px; }
    }
</style>
</head>
<body>

@php
    $clock = fn ($s) => sprintf('%d:%02d', intdiv((int) $s, 60), (int) $s % 60);
@endphp

<div class="head">
    @if (! empty($app))
        <span class="applinks">
            @if (! empty($app['game']))<a class="gold" href="{{ $app['game'] }}">Open this game</a>@endif
            @if (! empty($app['folder']))<a href="{{ $app['folder'] }}">Show in folder</a>@endif
        </span>
    @endif
    <span class="title">Watch</span>
    <span class="when muted">{{ $title ?? '' }}</span>
</div>

<div class="wrap">

    <div id="stage" class="stage" style="display:none"><video id="vid" controls preload="metadata"></video>
        {{-- No recording of this game (2026-10-26): a clock drives everything the video's time does. --}}
        <div id="blank" class="blank"><span class="big" id="blank-t">0:00</span><span class="small">No recording of this game: press play to run the game's own clock</span></div>
        <div id="bar" class="clockbar">
            <button type="button" id="bar-play" title="Play or pause (space)">&#9654;</button>
            <input type="range" id="bar-seek" min="0" max="100" step="0.1" value="0">
            <span class="tm" id="bar-tm">0:00 / 0:00</span>
            <select id="bar-speed" title="Speed"><option value="0.5">0.5x</option><option value="1" selected>1x</option><option value="2">2x</option><option value="4">4x</option></select>
        </div>
        <div id="frames" class="frames"></div>
        <div id="frames-note" class="frames-note">This game was stored before each player's own talents were kept: it shows each spec's usual buttons, leaving out talents nobody pressed in it. Games from 26 Oct on show each player's own talents.</div>
    </div>

    <div id="drop" class="drop">
        <b>Open your recording of this game</b>
        <span class="muted small">Your own capture from OBS, ShadowPlay or Game Bar. It stays on this machine &mdash; the file is read in the page and never uploaded.</span>
        <label class="pick">Choose a video<input id="file" type="file" accept="video/*" style="display:none"></label>
        @if (! empty($video))
            <div class="hint" id="moved">MindCollector recorded this game as <code>{{ $video['name'] }}</code>, but it could not be opened from where it was saved. Choose it if it has moved.</div>
        @elseif (! empty($suggest))
            <div class="hint">Probably <code>{{ $suggest }}</code> &mdash; the file whose time best fits this game.</div>
        @elseif (! empty($playedAt))
            <div class="hint">This game started around <code>{{ $playedAt }}</code>. Look for the recording covering that time.</div>
        @endif
        <div class="nosupport small subtle" style="margin-top:10px">This view needs a newer browser. The timelines below still work.</div>
    </div>

    <div class="offset">
        @if (! empty($video))
            Round 1 starts this far into the video, from MindCollector's recording
        @else
            Line up: where round 1 starts in the video
        @endif
        <button type="button" id="minus">&minus;1s</button>
        <input id="off" type="range" min="-60" max="900" step="0.1" value="0">
        <button type="button" id="plus">+1s</button>
        <span class="v" id="offv">0.0s</span>
        <button type="button" id="here" title="Use the video's current position as the start of round 1">set from playhead</button>
    </div>
    <div class="jump">
        <button type="button" id="prev" title="The event before this point (,)">&larr; Previous</button>
        <button type="button" id="next" title="The next event after this point (.)">Next &rarr;</button>
        <span class="sep"></span>
        Show
        <button type="button" data-kind="fall">Falls</button>
        <button type="button" data-kind="death">Deaths</button>
        <button type="button" data-kind="def">Defensives</button>
        <button type="button" data-kind="unmoved">Moved nobody</button>
        <button type="button" data-kind="go" class="off" title="The go rule before falls, to compare">Old goes</button>
    </div>
    <div class="legend">
        <b style="background:#2f7d4f"></b> they fell to your pressure <b style="background:#c0392b"></b> you fell to theirs
        <b style="background:#F28B82"></b> a death
        <b style="background:#86efac"></b> your defensive <b style="background:#fca5a5"></b> theirs
        <b style="background:#B3B3B3"></b> a cooldown that moved nobody
        <span class="subtle">&nbsp;&middot;&nbsp; A fall is someone's health dropping 20+ and the cooldowns and control that did it. Darker = more stacked; striped = control with no cooldown; gold edge = they died. Click the timeline to go to that point, or an event to see it from 3s before.</span>
    </div>

    @foreach ($rounds as $i => $r)
        @php
            $dur = max(1, (float) $r['duration']);
            $pct = fn ($s) => max(0, min(100, 100 * $s / $dur));
            $events = [];
            foreach ($r['goes'] as $g) {
                $events[] = ['kind' => 'go', 't' => (float) $g['from'], 'side' => $g['side'],
                    'label' => 'Old go on '.($g['target'] ?? '?').(! empty($g['kill']) ? ' — killed' : ''),
                    'detail' => $g['chain'] ?? ''];
            }
            $kinds = ['cooldown' => 'cooldowns', 'control' => 'control, no cooldown', 'damage' => 'damage only'];
            foreach ($r['falls'] ?? [] as $f) {
                $lines = array_filter([
                    $f['sync'],
                    $f['cooldowns'] ? 'Cooldowns: '.implode(', ', $f['cooldowns']) : null,
                    $f['control'] ? 'Control: '.implode(', ', $f['control']) : null,
                    $f['answers'] ? 'Answered with: '.implode(', ', $f['answers']) : null,
                ]);
                $events[] = ['kind' => 'fall', 't' => (float) $f['from'], 'side' => $f['side'],
                    'label' => $f['on'].' '.$f['high'].'% → '.($f['died'] ? 'died' : $f['low'].'%').' · '.$kinds[$f['kind']],
                    'detail' => implode("\n", $lines), 'bd' => $f['breakdown'] ?? null];
            }
            foreach ($r['unmoved'] ?? [] as $u) {
                $events[] = ['kind' => 'unmoved', 't' => (float) $u['t'], 'side' => $u['side'],
                    'label' => $u['spell'].' moved nobody',
                    'detail' => $u['by'].($u['alone'] ? ', pressed with no control within 5s' : '')];
            }
            foreach ($r['deaths'] as $d) {
                $events[] = ['kind' => 'death', 't' => (float) $d['t'], 'side' => $d['side'] ?? null,
                    'label' => 'Death: '.\Illuminate\Support\Str::before($d['name'] ?? ($d['who'] ?? '?'), '-'),
                    'detail' => $d['killingBlow']['spell'] ?? ''];
            }
            foreach ($r['defensives'] ?? [] as $d) {
                $events[] = ['kind' => 'def', 't' => (float) $d['t'], 'side' => $d['side'],
                    'label' => $d['spell'],
                    'detail' => $d['who'].(! empty($d['on']) ? ' on '.$d['on'] : '')];
            }
            usort($events, fn ($a, $b) => $a['t'] <=> $b['t']);
        @endphp

        <div class="rnd" data-start="{{ $r['startOffset'] }}" data-dur="{{ $dur }}">
            <script type="application/json" class="frames-data">@json(['players' => $r['frames'] ?? [], 'icons' => (object) ($r['castIcons'] ?? [])])</script>
            <div class="rh">
                <b>{{ $r['label'] }}</b>
                @if (! empty($r['result']))
                    <span class="{{ $r['result'] === 'won' ? 'won' : 'lost' }} small">{{ $r['result'] }}</span>
                @endif
                <span class="subtle small">&nbsp;{{ $clock($dur) }}@if ($r['startOffset'] > 0) &nbsp;&middot;&nbsp; starts {{ $clock($r['startOffset']) }} into the recording @endif</span>
            </div>

            <div class="rail">
                @foreach ($r['falls'] ?? [] as $f)
                    @php $w = max(0.4, $pct((float) $f['to']) - $pct((float) $f['from'])); @endphp
                    <div class="fall {{ $f['side'] }} {{ $f['kind'] }}{{ $f['died'] ? ' died' : '' }}"
                         style="left: {{ $pct((float) $f['from']) }}%; width: {{ $w }}%; opacity: {{ min(1, 0.35 + 0.15 * $f['intensity']) }}"
                         title="{{ $f['on'] }} {{ $f['high'] }}% to {{ $f['died'] ? 'dead' : $f['low'].'%' }}, {{ $clock($f['from']) }} to {{ $clock($f['to']) }}{{ $f['sync'] ? ' — '.$f['sync'] : '' }}"></div>
                @endforeach
                @foreach ($r['unmoved'] ?? [] as $u)
                    <div class="unmoved" style="left: {{ $pct((float) $u['t']) }}%" title="{{ $u['spell'] }} ({{ $u['by'] }}) at {{ $clock($u['t']) }} moved nobody"></div>
                @endforeach
                @foreach ($r['goes'] as $g)
                    @php $w = max(0.4, $pct((float) $g['to']) - $pct((float) $g['from'])); @endphp
                    <div class="go old {{ $g['side'] }}{{ ! empty($g['kill']) ? ' kill' : '' }}"
                         style="left: {{ $pct((float) $g['from']) }}%; width: {{ $w }}%"
                         title="{{ $g['side'] === 'us' ? 'Your go' : 'Enemy go' }} — {{ $clock($g['from']) }} to {{ $clock($g['to']) }}{{ ! empty($g['chain']) ? ' — '.$g['chain'] : '' }}"></div>
                @endforeach
                @foreach ($r['deaths'] as $d)
                    <div class="death" style="left: {{ $pct((float) $d['t']) }}%"></div>
                @endforeach
                @foreach ($r['defensives'] ?? [] as $d)
                    <div class="def {{ $d['side'] }}" style="left: {{ $pct((float) $d['t']) }}%" title="{{ $d['spell'] }} ({{ $d['who'] }}{{ ! empty($d['on']) ? ' on '.$d['on'] : '' }}) at {{ $clock($d['t']) }}"></div>
                @endforeach
                <div class="head2"></div>
            </div>
            <div class="ticks">
                @for ($s = 0; $s <= $dur; $s += max(15, 15 * (int) ceil($dur / 180)))
                    <span style="left: {{ $pct($s) }}%">{{ $clock($s) }}</span>
                @endfor
            </div>

            <table class="ev">
                @forelse ($events as $e)
                    <tr data-t="{{ $e['t'] }}" data-kind="{{ $e['kind'] }}">
                        <td class="t">{{ $clock($e['t']) }}</td>
                        <td class="w">@if ($e['side'])<span class="pill {{ $e['side'] }}">{{ $e['side'] === 'us' ? 'you' : 'them' }}</span>@endif</td>
                        <td><b>{{ $e['label'] }}</b>@if ($e['detail'])<div class="small subtle">{!! nl2br(e($e['detail'])) !!}</div>@endif
                            @if (! empty($e['bd']))
                                @php $bd = $e['bd']; $theirs = $e['side'] === 'us'; @endphp
                                <button type="button" class="bd-toggle">Breakdown</button>
                                <div class="fb">
                                    <div class="fb-h">How it was built</div>
                                    <table>
                                        @foreach ($bd['steps'] as $st)
                                            @php $drLevel = str_ends_with($st['dr'] ?? '', 'half') ? 'half' : (str_ends_with($st['dr'] ?? '', 'immune') ? 'immune' : 'full'); @endphp
                                            <tr data-st="{{ $st['t'] }}">
                                                <td class="at">{{ sprintf('%+.1fs', $st['at']) }}</td>
                                                <td>{{ $st['text'] }} <span class="subtle">by {{ $st['by'] }}</span>
                                                    @if (! empty($st['dr']))<span class="drl {{ $drLevel }}">{{ $st['dr'] }}</span>@endif
                                                    @if (array_key_exists('kick', $st))
                                                        @if ($st['kick'])
                                                            <div class="kick">A cast worth a kick: {{ implode(', ', $st['kick']) }} free as it came in</div>
                                                        @else
                                                            <div class="kick none">A cast worth a kick: nobody on {{ $theirs ? 'their' : 'your' }} side was free with an interrupt ready</div>
                                                        @endif
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </table>
                                    <div class="fb-h">{{ $theirs ? 'Their side' : 'Your side' }} as it started</div>
                                    @foreach ($bd['defenders'] as $d)
                                        <div class="who"><b style="color: {{ $d['color'] }}">{{ $d['name'] }}</b>
                                            <span class="{{ $d['state'] === 'free' ? '' : 'subtle' }}">{{ $d['state'] === 'free' ? 'free' : ($d['state'] === 'dead' ? 'dead' : 'locked: '.$d['state']) }}</span>
                                            @if ($d['ready']['answers'])<div class="small subtle">Defensives ready: {{ implode(', ', $d['ready']['answers']) }}</div>@endif
                                            @if ($d['ready']['interrupts'])<div class="small subtle">Interrupt ready: {{ implode(', ', $d['ready']['interrupts']) }}</div>@endif
                                            @if ($d['ready']['control'])<div class="small subtle">Control ready: {{ implode(', ', $d['ready']['control']) }}</div>@endif
                                        </div>
                                    @endforeach
                                    <div class="fb-h">Spent</div>
                                    <div class="small">Cooldowns: {{ $bd['spent'] ? implode(', ', $bd['spent']) : 'none, all control' }}</div>
                                    <div class="small">Answered with: {{ $bd['answered'] ? implode(', ', $bd['answered']) : 'nothing' }}</div>
                                    <div class="note">Click a step to see it from just before. Diminishing returns: a second control of a kind lasts half as long, a third does nothing, until 20 seconds pass without one. "Ready" means not pressed within its cooldown, so it could have been back sooner. Whether a kick was in range or in sight, the log cannot say.</div>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="muted small">Nothing measured in this round.</td></tr>
                @endforelse
            </table>
        </div>
    @endforeach

    <div class="foot">
        @if (! empty($video))
            Round times are the game's own clock, from the combat log. The app started this recording,
            so it knows when the first frame was taken on the same clock, and each round is placed from that.
        @else
            Round times are the game's own clock, from the combat log. The video is lined up by hand
            because nothing here started the recording. Games the MindCollector app records through OBS
            line themselves up.
        @endif
    </div>
</div>

<script>
(function () {
    var vid = document.getElementById('vid'), stage = document.getElementById('stage'),
        drop = document.getElementById('drop'), file = document.getElementById('file'),
        off = document.getElementById('off'), offv = document.getElementById('offv');
    var offset = 0;   // video seconds at which round 1 begins
    var rounds = [];

    var ok = !!(window.URL && window.URL.createObjectURL && vid && vid.canPlayType && window.addEventListener);
    if (!ok) {
        var ns = document.querySelector('.nosupport'); if (ns) { ns.style.display = 'block'; }
        var pk = document.querySelector('.pick'); if (pk) { pk.style.display = 'none'; }
        return;
    }

    var blankMode = @json(! empty($noVideo));
    var blocks = document.getElementsByClassName('rnd');
    for (var i = 0; i < blocks.length; i++) {
        rounds.push({
            el: blocks[i],
            start: parseFloat(blocks[i].getAttribute('data-start')) || 0,
            dur: parseFloat(blocks[i].getAttribute('data-dur')) || 1,
            rail: blocks[i].getElementsByClassName('rail')[0],
            head: blocks[i].getElementsByClassName('head2')[0]
        });
    }

    function setOffset(v) {
        offset = Math.round(v * 10) / 10;
        off.value = offset;
        offv.textContent = offset.toFixed(1) + 's';
    }

    // No recording: a clock with the video's API (currentTime, paused, play, pause, events), so the
    // rails, the frames, the casts line and Previous/Next run exactly as they do over a video.
    // Uploaded to YouTube (2026-10-26): its player wrapped in the video's API (currentTime, paused,
    // play, pause, events), so the rails, frames, casts line and Previous/Next follow it unchanged.
    var recordedEarly = @json($video ?? null);
    if (recordedEarly && recordedEarly.youtube) {
        var holder = document.createElement('div');
        holder.id = 'yt';
        holder.style.cssText = 'width:100%;aspect-ratio:16/9;max-height:46vh;background:#000';
        vid.parentNode.insertBefore(holder, vid);
        vid.style.display = 'none';
        var ytPlayer = null, ytTimer = null;
        var ytv = { paused: true, src: 'youtube', _h: {},
            addEventListener: function (n, f) { (this._h[n] = this._h[n] || []).push(f); },
            emit: function (n) { (this._h[n] || []).forEach(function (f) { f(); }); },
            play: function () { if (ytPlayer) { ytPlayer.playVideo(); } return Promise.resolve(); },
            pause: function () { if (ytPlayer) { ytPlayer.pauseVideo(); } }
        };
        Object.defineProperty(ytv, 'currentTime', {
            get: function () { return ytPlayer && ytPlayer.getCurrentTime ? ytPlayer.getCurrentTime() : 0; },
            set: function (t) { if (ytPlayer && ytPlayer.seekTo) { ytPlayer.seekTo(Math.max(0, t), true); ytv.emit('seeked'); ytv.emit('timeupdate'); } }
        });
        window.onYouTubeIframeAPIReady = function () {
            ytPlayer = new YT.Player('yt', {
                videoId: recordedEarly.youtube,
                playerVars: { rel: 0, playsinline: 1, modestbranding: 1 },
                events: {
                    onReady: function () { ytv.emit('loadedmetadata'); ytv.emit('timeupdate'); },
                    onStateChange: function (e) {
                        ytv.paused = e.data !== 1;
                        clearInterval(ytTimer);
                        if (!ytv.paused) { ytTimer = setInterval(function () { ytv.emit('timeupdate'); }, 250); }
                        ytv.emit(ytv.paused ? 'pause' : 'play');
                    }
                }
            });
        };
        var api = document.createElement('script');
        api.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(api);
        vid = ytv;
        stage.style.display = 'block';
        drop.style.display = 'none';
    }

    if (blankMode && rounds.length) {
        var last = rounds[rounds.length - 1];
        var total = last.start + last.dur + 5;
        var clock = { currentTime: 0, paused: true, src: 'clock', duration: total, rate: 1, _h: {}, _at: 0,
            addEventListener: function (n, f) { (this._h[n] = this._h[n] || []).push(f); },
            emit: function (n) { (this._h[n] || []).forEach(function (f) { f(); }); },
            play: function () {
                var self = this;
                if (!self.paused) { return Promise.resolve(); }
                if (self.currentTime >= self.duration) { self.currentTime = 0; }
                self.paused = false;
                self._at = performance.now();
                (function tick(now) {
                    if (self.paused) { return; }
                    self.currentTime = Math.min(self.duration, self.currentTime + self.rate * (now - self._at) / 1000);
                    self._at = now;
                    self.emit('timeupdate');
                    if (self.currentTime >= self.duration) { self.pause(); return; }
                    window.requestAnimationFrame(tick);
                })(self._at);
                self.emit('play');
                return Promise.resolve();
            },
            pause: function () { this.paused = true; this.emit('pause'); }
        };
        vid = clock;
        var fmt = function (s) { s = Math.max(0, s); return Math.floor(s / 60) + ':' + ('0' + Math.floor(s % 60)).slice(-2); };
        var seekBar = document.getElementById('bar-seek'), playBtn = document.getElementById('bar-play');
        seekBar.max = total;
        clock.addEventListener('timeupdate', function () {
            seekBar.value = clock.currentTime;
            document.getElementById('blank-t').textContent = fmt(clock.currentTime);
            document.getElementById('bar-tm').textContent = fmt(clock.currentTime) + ' / ' + fmt(total);
        });
        clock.addEventListener('play', function () { playBtn.innerHTML = '&#10074;&#10074;'; });
        clock.addEventListener('pause', function () { playBtn.innerHTML = '&#9654;'; });
        playBtn.addEventListener('click', function () { clock.paused ? clock.play() : clock.pause(); });
        seekBar.addEventListener('input', function () { clock.currentTime = parseFloat(seekBar.value) || 0; clock.emit('timeupdate'); clock.emit('seeked'); });
        document.getElementById('bar-speed').addEventListener('change', function () { clock.rate = parseFloat(this.value) || 1; });
        document.addEventListener('keydown', function (e) {
            if (e.key === ' ' && !(e.target && /input|textarea|select|button/i.test(e.target.tagName))) { e.preventDefault(); clock.paused ? clock.play() : clock.pause(); }
        });
        document.getElementById('vid').style.display = 'none';
        document.getElementById('blank').style.display = 'flex';
        document.getElementById('bar').style.display = 'flex';
        stage.style.display = 'block';
        drop.style.display = 'none';
        var offRow = document.querySelector('.offset');
        if (offRow) { offRow.style.display = 'none'; }
        window.setTimeout(function () { clock.emit('timeupdate'); }, 0);
    }

    file.addEventListener('change', function () {
        if (!file.files || !file.files[0]) { return; }
        vid.src = URL.createObjectURL(file.files[0]);
        stage.style.display = 'block';
        drop.style.display = 'none';
    });

    // Recorded by the app: open it from where it was saved, already lined up. If it cannot be
    // opened (moved, deleted, or a browser that will not read local files), ask for it instead.
    var recorded = @json($video ?? null);
    if (recorded && !recorded.youtube) {
        vid.addEventListener('error', function () {
            if (vid.src.indexOf('blob:') === 0) { return; }
            vid.removeAttribute('src');
            stage.style.display = 'none';
            drop.style.display = 'block';
        });
        vid.src = recorded.url;
        stage.style.display = 'block';
        drop.style.display = 'none';
    }

    off.addEventListener('input', function () { setOffset(parseFloat(off.value) || 0); });
    document.getElementById('minus').addEventListener('click', function () { setOffset(offset - 1); });
    document.getElementById('plus').addEventListener('click', function () { setOffset(offset + 1); });
    document.getElementById('here').addEventListener('click', function () {
        if (vid.src) { setOffset(vid.currentTime); }
    });

    var LEAD = 3;   // an event is shown from a few seconds before, so what led to it is seen

    /** round index + seconds within that round -> video seconds */
    function seek(r, t) {
        if (!vid.src) { return; }
        vid.currentTime = Math.max(0, offset + r.start + t);
        vid.play().catch(function () {});
    }

    // Every shown event row, in video order: what Previous and Next step through.
    var hidden = {};
    function eventList() {
        var list = [];
        for (var i = 0; i < rounds.length; i++) {
            var rows = rounds[i].el.querySelectorAll('tr[data-t]');
            for (var k = 0; k < rows.length; k++) {
                if (hidden[rows[k].getAttribute('data-kind')]) { continue; }
                list.push({ r: rounds[i], t: parseFloat(rows[k].getAttribute('data-t')) });
            }
        }
        list.sort(function (a, b) { return (a.r.start + a.t) - (b.r.start + b.t); });
        return list;
    }
    // The old goes start hidden: falls replaced them (2026-10-26); the button shows them to compare.
    hidden.go = true;
    var oldRows = document.querySelectorAll('tr[data-kind="go"]');
    for (var oi = 0; oi < oldRows.length; oi++) { oldRows[oi].className = 'hide'; }
    var oldMarks = document.querySelectorAll('.go');
    for (var om = 0; om < oldMarks.length; om++) { oldMarks[om].style.visibility = 'hidden'; }
    var events = eventList();

    function step(dir) {
        if (!vid.src || events.length === 0) { return; }
        // The playhead as the event it was jumped to: a jump lands LEAD seconds early.
        var here = vid.currentTime - offset + LEAD;
        var pick = null;
        for (var i = 0; i < events.length; i++) {
            var at = events[i].r.start + events[i].t;
            if (dir > 0 && at > here + 0.5) { pick = events[i]; break; }
            if (dir < 0 && at < here - 0.5) { pick = events[i]; }
        }
        if (pick) { seek(pick.r, pick.t - LEAD); }
    }
    document.getElementById('next').addEventListener('click', function () { step(1); });
    document.getElementById('prev').addEventListener('click', function () { step(-1); });
    document.addEventListener('keydown', function (e) {
        if (e.target && /input|textarea/i.test(e.target.tagName)) { return; }
        if (e.key === '.') { step(1); }
        if (e.key === ',') { step(-1); }
    });

    var kinds = document.querySelectorAll('.jump button[data-kind]');
    for (var q = 0; q < kinds.length; q++) {
        kinds[q].addEventListener('click', function () {
            var kind = this.getAttribute('data-kind');
            hidden[kind] = !hidden[kind];
            this.className = hidden[kind] ? 'off' : '';
            var rows = document.querySelectorAll('tr[data-kind="' + kind + '"]');
            for (var k = 0; k < rows.length; k++) { rows[k].className = hidden[kind] ? 'hide' : ''; }
            var marks = document.querySelectorAll({ def: '.def', death: '.death', go: '.go', fall: '.fall', unmoved: '.unmoved' }[kind]);
            for (var m = 0; m < marks.length; m++) { marks[m].style.visibility = hidden[kind] ? 'hidden' : ''; }
            events = eventList();
        });
    }

    // Arena frames at the playhead (2026-10-26): each player's buttons, ready or coming back.
    for (var fi = 0; fi < rounds.length; fi++) {
        var fd = rounds[fi].el.querySelector('.frames-data');
        var fdata = fd ? JSON.parse(fd.textContent || '{}') : {};
        rounds[fi].frames = fdata.players || [];
        rounds[fi].castIcons = fdata.icons || {};
    }
    var framesEl = document.getElementById('frames'), framesNote = document.getElementById('frames-note');
    var shownRound = null, cells = [];

    /** A button's state at round time t: charges ready, or seconds until one is back. */
    function stateAt(a, t) {
        var max = a.charges || 1, charges = max, back = null;
        function settle(until) {
            while (charges < max && back !== null && back <= until) {
                charges++;
                back = charges < max ? back + a.cd : null;
            }
        }
        for (var i = 0; i < a.presses.length && a.presses[i] <= t; i++) {
            settle(a.presses[i]);
            // Pressed with nothing ready: something cut the cooldown (the data holds no such cuts).
            if (charges < 1) { charges = 1; back = charges < max ? back : null; }
            charges--;
            if (back === null) { back = a.presses[i] + a.cd; }
        }
        settle(t);
        return { ready: charges, max: max, back: charges < max && back !== null ? back - t : null };
    }

    var RIBBON = 12, ribbons = [];

    /** The casts line: what each player pressed in the RIBBON seconds before t, placed by time. */
    function drawRibbons(t) {
        ribbons.forEach(function (rb) {
            var html = '<span class="now"></span>';
            for (var s = Math.ceil(t - RIBBON); s < t; s++) {
                html += '<span class="sec" style="left:' + (100 * (s - (t - RIBBON)) / RIBBON) + '%"></span>';
            }
            var list = rb.p.casts;
            for (var i = 0; i < list.length; i++) {
                var c = list[i];
                if (c[0] > t) { break; }
                if (c[0] < t - RIBBON) { continue; }
                var left = 100 * (c[0] - (t - RIBBON)) / RIBBON, icon = shownRound.castIcons[c[1]];
                var tip = c[1] + ' at ' + Math.floor(c[0] / 60) + ':' + ('0' + Math.floor(c[0] % 60)).slice(-2) + (c[2] ? ' on ' + c[2] : '');
                html += '<span class="c' + (rb.tracked[c[1]] ? ' big' : '') + '" style="left:' + left + '%" title="' + tip.replace(/"/g, '&quot;') + '">'
                    + (icon ? '<img src="' + icon + '" alt="">' : '<span class="lt">' + c[1].substr(0, 2) + '</span>') + '</span>';
            }
            rb.el.innerHTML = html;
        });
    }

    function buildFrames(r) {
        framesEl.innerHTML = '';
        cells = [];
        ribbons = [];
        var usual = false;
        ['us', 'them'].forEach(function (side) {
            var col = document.createElement('div');
            col.className = 'side';
            col.innerHTML = '<div class="who">' + (side === 'us' ? @js(! empty($public) ? 'Recording team' : 'Your team') : 'Their team') + '</div>';
            r.frames.filter(function (p) { return p.side === side; }).forEach(function (p) {
                usual = usual || p.usual;
                var wrap = document.createElement('div');
                wrap.className = 'pl-wrap';
                var row = document.createElement('div');
                row.className = 'pl';
                row.innerHTML = '<span class="nm" style="color:' + p.color + '" title="' + p.spec + '">' + p.name + '</span>'
                    + (p.hp && p.hp.length ? '<span class="hp" title="Health"><span class="f"></span><span class="v"></span></span>' : '')
                    + '<span class="st"></span><span class="abs"></span>';
                var rib = document.createElement('div');
                rib.className = 'rib';
                rib.title = 'What ' + p.name + ' pressed in the last ' + RIBBON + 's, newest at the right';
                wrap.appendChild(row);
                wrap.appendChild(rib);
                var tracked = {};
                p.abilities.forEach(function (a) { tracked[a.name] = true; });
                ribbons.push({ p: p, el: rib, tracked: tracked, row: row });
                var abs = row.querySelector('.abs'), last = null;
                p.abilities.forEach(function (a) {
                    if (last !== null && a.kind !== last) { var g = document.createElement('span'); g.className = 'gap'; abs.appendChild(g); }
                    last = a.kind;
                    var b = document.createElement('span');
                    b.className = 'ab';
                    b.innerHTML = (a.icon ? '<img src="' + a.icon + '" alt="">' : '<span class="lt">' + a.name.substr(0, 2) + '</span>') + '<span class="cdt"></span><span class="ch"></span>';
                    abs.appendChild(b);
                    cells.push({ a: a, el: b, p: p, row: row });
                });
                col.appendChild(wrap);
            });
            framesEl.appendChild(col);
        });
        framesEl.style.display = r.frames.length ? 'flex' : 'none';
        framesNote.style.display = usual ? 'block' : 'none';
    }

    function drawFrames(v) {
        var r = null;
        for (var i = 0; i < rounds.length; i++) { if (v - offset >= rounds[i].start - 60) { r = rounds[i]; } }
        if (!r || !r.frames.length) { framesEl.style.display = 'none'; return; }
        if (r !== shownRound) { buildFrames(r); shownRound = r; }
        var t = v - offset - r.start, rows = [];
        cells.forEach(function (c) {
            var s = stateAt(c.a, t);
            var cooling = s.ready === 0;
            c.el.className = cooling ? 'ab cd' : 'ab';
            c.el.querySelector('.cdt').textContent = cooling && s.back !== null ? Math.ceil(s.back) : '';
            c.el.querySelector('.ch').textContent = c.a.charges > 1 ? s.ready : '';
            c.el.title = c.a.name + ' (' + Math.round(c.a.cd) + 's): ' + (cooling ? 'back in ' + Math.ceil(s.back) + 's at the latest' : 'ready') + (c.a.charges > 1 ? ', ' + s.ready + ' of ' + c.a.charges + ' charges' : '');
            if (rows.indexOf(c.row) < 0) { rows.push(c.row); c.row.p = c.p; }
        });
        drawRibbons(t);
        // Every player's row, also one with no button to track: its health and control still show.
        ribbons.forEach(function (rb) { if (rows.indexOf(rb.row) < 0) { rows.push(rb.row); rb.row.p = rb.p; } });
        rows.forEach(function (row) {
            var p = row.p, st = row.querySelector('.st'), cc = null;
            p.locked.forEach(function (l) { if (t >= l[0] && t < l[1]) { cc = l; } });
            var dead = p.died !== null && t >= p.died;
            row.className = 'pl' + (dead ? ' dead' : (cc ? ' locked' : ''));
            st.textContent = dead ? 'dead' : (cc ? cc[2] + ' ' + Math.ceil(cc[1] - t) + 's' : '');
            // Health, a reading a second (timeline.hp): green, amber under 50%, red under 25%.
            var bar = row.querySelector('.hp');
            if (bar) {
                var hp = dead ? 0 : p.hp[Math.max(0, Math.min(p.hp.length - 1, Math.floor(t)))];
                var fill = bar.querySelector('.f');
                fill.style.width = hp + '%';
                fill.className = 'f' + (hp < 25 ? ' low' : (hp < 50 ? ' mid' : ''));
                bar.querySelector('.v').textContent = hp + '%';
            }
        });
    }
    vid.addEventListener('timeupdate', function () { drawFrames(vid.currentTime); });
    // The casts line slides smoothly while the video plays (timeupdate fires only about 4 times a second).
    (function slide() {
        if (!vid.paused && shownRound) { drawRibbons(vid.currentTime - offset - shownRound.start); }
        window.requestAnimationFrame(slide);
    })();
    vid.addEventListener('seeked', function () { drawFrames(vid.currentTime); });
    vid.addEventListener('loadedmetadata', function () { drawFrames(vid.currentTime); });

    vid.addEventListener('timeupdate', function () {
        var v = vid.currentTime - offset;
        for (var i = 0; i < rounds.length; i++) {
            var r = rounds[i], into = v - r.start;
            if (into >= 0 && into <= r.dur) {
                r.head.style.display = 'block';
                r.head.style.left = (100 * into / r.dur) + '%';
            } else {
                r.head.style.display = 'none';
            }
            var rows = r.el.querySelectorAll('tr[data-t]'), now = null;
            for (var k = 0; k < rows.length; k++) {
                if (rows[k].className === 'hide') { continue; }
                if (rows[k].className === 'now') { rows[k].className = ''; }
                if (into <= r.dur && parseFloat(rows[k].getAttribute('data-t')) <= into) { now = rows[k]; }
            }
            if (now) { now.className = 'now'; }
        }
    });

    // A fall's breakdown: the button opens it; a step jumps to just before it; nothing in it seeks
    // to the fall itself as a click on the row would.
    var toggles = document.querySelectorAll('.bd-toggle');
    for (var bt = 0; bt < toggles.length; bt++) {
        toggles[bt].addEventListener('click', function (e) {
            e.stopPropagation();
            var box = this.nextElementSibling;
            box.className = box.className.indexOf('open') >= 0 ? 'fb' : 'fb open';
            this.textContent = box.className === 'fb open' ? 'Hide breakdown' : 'Breakdown';
        });
    }
    var boxes = document.querySelectorAll('.fb');
    for (var bx = 0; bx < boxes.length; bx++) {
        boxes[bx].addEventListener('click', function (e) {
            e.stopPropagation();
            var row = e.target.closest ? e.target.closest('tr[data-st]') : null;
            if (!row) { return; }
            var el = row.closest('.rnd');
            for (var k = 0; k < rounds.length; k++) {
                if (rounds[k].el === el) { seek(rounds[k], parseFloat(row.getAttribute('data-st')) - 1); }
            }
        });
    }

    for (var j = 0; j < rounds.length; j++) {
        (function (r) {
            r.rail.addEventListener('click', function (e) {
                var box = r.rail.getBoundingClientRect();
                seek(r, r.dur * (e.clientX - box.left) / box.width);
            });
            var rows = r.el.getElementsByTagName('tr');
            for (var k = 0; k < rows.length; k++) {
                (function (row) {
                    var t = row.getAttribute('data-t');
                    if (t === null) { return; }
                    row.addEventListener('click', function () { seek(r, parseFloat(t) - LEAD); });
                })(rows[k]);
            }
        })(rounds[j]);
    }

    setOffset(recorded ? recorded.offset : 0);
})();
</script>
</body>
</html>
