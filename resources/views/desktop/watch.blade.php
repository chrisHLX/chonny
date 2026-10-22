{{--
    Watch: one game's video with its measured timeline drawn underneath (2026-10-21).

    Per LOBBY, not per round, because one recording covers the whole thing: a Solo Shuffle is six
    rounds in a single capture. Each round gets its own rail, and its start inside the recording
    comes from the wall-clock gap between the rounds' `played_at` values -- which includes each
    prep period for free, since it is real elapsed time. That is second-precision, so every rail is
    nudgeable; only round one's position has to be set by hand.

    The video is a file the player already has (OBS, ShadowPlay, Game Bar), chosen with a picker
    and read through a blob URL. It is never uploaded and never leaves the machine. Nothing here
    records anything -- docs/vision-experiment.md says why recording comes last.

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
    .stage { background: #000000; border-radius: 8px; overflow: hidden; position: sticky; top: 0; z-index: 5; }
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
    .rail { position: relative; height: 52px; background: #181818; border-radius: 6px; overflow: hidden; cursor: pointer; }
    .go { position: absolute; height: 19px; border-radius: 3px; opacity: .92; }
    .go.us { background: #2f7d4f; top: 6px; }
    .go.them { background: #8c3b3b; top: 28px; }
    .go.kill { box-shadow: inset 0 0 0 2px #E8B84B; }
    .death { position: absolute; top: 0; width: 2px; height: 100%; background: #F28B82; }
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
    <span class="title">Watch</span>
    <span class="when muted">{{ $title ?? '' }}</span>
</div>

<div class="wrap">

    <div id="stage" class="stage" style="display:none"><video id="vid" controls preload="metadata"></video></div>

    <div id="drop" class="drop">
        <b>Open your recording of this game</b>
        <span class="muted small">Your own capture from OBS, ShadowPlay or Game Bar. It stays on this machine &mdash; the file is read in the page and never uploaded.</span>
        <label class="pick">Choose a video<input id="file" type="file" accept="video/*" style="display:none"></label>
        @if (! empty($suggest))
            <div class="hint">Probably <code>{{ $suggest }}</code> &mdash; the file whose time best fits this game.</div>
        @elseif (! empty($playedAt))
            <div class="hint">This game started around <code>{{ $playedAt }}</code>. Look for the recording covering that time.</div>
        @endif
        <div class="nosupport small subtle" style="margin-top:10px">This view needs a newer browser. The timelines below still work.</div>
    </div>

    <div class="offset">
        Line up: where round 1 starts in the video
        <button type="button" id="minus">&minus;1s</button>
        <input id="off" type="range" min="-60" max="900" step="0.5" value="0">
        <button type="button" id="plus">+1s</button>
        <span class="v" id="offv">0.0s</span>
        <button type="button" id="here" title="Use the video's current position as the start of round 1">set from playhead</button>
    </div>
    <div class="legend">
        <b style="background:#2f7d4f"></b> your goes <b style="background:#8c3b3b"></b> enemy
        <b style="background:#F28B82"></b> a death
        <span class="subtle">&nbsp;&middot;&nbsp; gold edge = the go killed someone. Click a rail or a row to jump. Later rounds are placed from the real time between them.</span>
    </div>

    @foreach ($rounds as $i => $r)
        @php
            $dur = max(1, (float) $r['duration']);
            $pct = fn ($s) => max(0, min(100, 100 * $s / $dur));
            $events = [];
            foreach ($r['goes'] as $g) {
                $events[] = ['t' => (float) $g['from'], 'side' => $g['side'],
                    'label' => 'Go on '.($g['target'] ?? '?').(! empty($g['kill']) ? ' — killed' : ''),
                    'detail' => $g['chain'] ?? ''];
            }
            foreach ($r['deaths'] as $d) {
                $events[] = ['t' => (float) $d['t'], 'side' => $d['side'] ?? null,
                    'label' => 'Death: '.\Illuminate\Support\Str::before($d['name'] ?? ($d['who'] ?? '?'), '-'),
                    'detail' => $d['killingBlow']['spell'] ?? ''];
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
                @foreach ($r['goes'] as $g)
                    @php $w = max(0.4, $pct((float) $g['to']) - $pct((float) $g['from'])); @endphp
                    <div class="go {{ $g['side'] }}{{ ! empty($g['kill']) ? ' kill' : '' }}"
                         style="left: {{ $pct((float) $g['from']) }}%; width: {{ $w }}%"
                         title="{{ $g['side'] === 'us' ? 'Your go' : 'Enemy go' }} — {{ $clock($g['from']) }} to {{ $clock($g['to']) }}{{ ! empty($g['chain']) ? ' — '.$g['chain'] : '' }}"></div>
                @endforeach
                @foreach ($r['deaths'] as $d)
                    <div class="death" style="left: {{ $pct((float) $d['t']) }}%"></div>
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
                    <tr data-t="{{ $e['t'] }}">
                        <td class="t">{{ $clock($e['t']) }}</td>
                        <td class="w">@if ($e['side'])<span class="pill {{ $e['side'] }}">{{ $e['side'] === 'us' ? 'you' : 'them' }}</span>@endif</td>
                        <td><b>{{ $e['label'] }}</b>@if ($e['detail'])<div class="small subtle">{{ $e['detail'] }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td class="muted small">Nothing measured in this round.</td></tr>
                @endforelse
            </table>
        </div>
    @endforeach

    <div class="foot">
        Round times are the game's own clock, from the combat log. The video is lined up by hand
        because nothing here started the recording; once the app does, it will know the wall-clock
        instant of the first frame and line itself up.
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
        offset = Math.round(v * 2) / 2;
        off.value = offset;
        offv.textContent = offset.toFixed(1) + 's';
    }

    file.addEventListener('change', function () {
        if (!file.files || !file.files[0]) { return; }
        vid.src = URL.createObjectURL(file.files[0]);
        stage.style.display = 'block';
        drop.style.display = 'none';
    });

    off.addEventListener('input', function () { setOffset(parseFloat(off.value) || 0); });
    document.getElementById('minus').addEventListener('click', function () { setOffset(offset - 1); });
    document.getElementById('plus').addEventListener('click', function () { setOffset(offset + 1); });
    document.getElementById('here').addEventListener('click', function () {
        if (vid.src) { setOffset(vid.currentTime); }
    });

    /** round index + seconds within that round -> video seconds */
    function seek(r, t) {
        if (!vid.src) { return; }
        vid.currentTime = Math.max(0, offset + r.start + t);
        vid.play().catch(function () {});
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
                    row.addEventListener('click', function () { seek(r, parseFloat(t)); });
                })(rows[k]);
            }
        })(rounds[j]);
    }

    setOffset(0);
})();
</script>
</body>
</html>
