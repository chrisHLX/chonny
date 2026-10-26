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
    .stage video { display: block; width: 100%; max-height: 56vh; background: #000000; }
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

    <div id="stage" class="stage" style="display:none"><video id="vid" controls preload="metadata"></video></div>

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
                    'detail' => implode("\n", $lines)];
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
                        <td><b>{{ $e['label'] }}</b>@if ($e['detail'])<div class="small subtle">{!! nl2br(e($e['detail'])) !!}</div>@endif</td>
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

    file.addEventListener('change', function () {
        if (!file.files || !file.files[0]) { return; }
        vid.src = URL.createObjectURL(file.files[0]);
        stage.style.display = 'block';
        drop.style.display = 'none';
    });

    // Recorded by the app: open it from where it was saved, already lined up. If it cannot be
    // opened (moved, deleted, or a browser that will not read local files), ask for it instead.
    var recorded = @json($video ?? null);
    if (recorded) {
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
