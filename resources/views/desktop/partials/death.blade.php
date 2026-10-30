{{-- One death: who, the killing blow, who did the damage, the healer, defensives in the last 30s. --}}
<div class="who">
    @if ($d['icon'])<img loading="lazy" class="spec-ic" src="{{ $d['icon'] }}" style="border:1px solid {{ $d['color'] }}">@endif
    <span class="muted">&nbsp;{{ $d['clock'] }}</span>&nbsp;
    <span class="name" style="color: {{ $d['color'] }}">{{ $d['name'] }}</span> died
    <span class="chip {{ $d['ours'] ? 'bad' : 'good' }}">{{ $d['ours'] ? 'yours' : 'enemy' }}</span>
</div>

@if ($d['killingBlow'] || $d['shares'])
    <div class="line">
        @if ($d['killingBlow'])
            <span class="muted">Killing blow</span>&nbsp;
            @if ($d['killingBlow']['icon'])<img loading="lazy" class="spell-ic" src="{{ $d['killingBlow']['icon'] }}">@endif<b>{{ $d['killingBlow']['spell'] }}</b>
        @endif
        @if ($d['shares'])
            <div class="bar">@foreach ($d['shares'] as $s)<span style="width: {{ $s['pct'] }}%; background: {{ $s['color'] }}"></span>@endforeach</div>
            <div class="small legend"><span class="muted">Damage in the last 10s:</span>
                @foreach ($d['shares'] as $s)<span><b style="color: {{ $s['color'] }}">{{ $s['name'] }}</b> {{ $s['pct'] }}%</span>@endforeach
            </div>
        @endif
    </div>
@endif

@if ($d['healer'])
    <div class="line">
        <span class="muted">{{ $d['healer']['whose'] }}</span>&nbsp;
        <span class="chip {{ $d['healer']['tone'] }}">{{ $d['healer']['label'] }}</span>
        <span class="small muted">&nbsp;{{ $d['healer']['medallion'] }}</span>
    </div>
@endif

<div class="line small">
    @if ($d['goStartedAgo'] !== null)
        <span class="muted">{{ $d['goWhose'] }} go had been running {{ $d['goStartedAgo'] }}s.</span>
    @endif
    @if ($d['defensives'])
        <div style="margin-top:4px"><span class="muted">Defensives in the last 30s:&nbsp;</span>
            @foreach ($d['defensives'] as $x)
                <span class="def">@if ($x['icon'])<img loading="lazy" class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span class="muted">{{ $x['who'] }}, {{ $x['ago'] }}s before</span></span>
            @endforeach
        </div>
    @else
        <span class="muted">No defensives in the last 30s.</span>
    @endif
</div>


{{-- The rest folds (2026-10-29): read in full it was a wall of text that changed little about the
     next game, so the death says who, what killed and what was pressed, and the reader opens the rest. --}}
@if (! empty($d['abilities']) || ! empty($d['health']) || ! empty($d['answers']))
<details class="more">
<summary>The full read: what did the damage, their health, what your team had</summary>
{{-- What did the damage in those ten seconds, biggest first (RoundAnalysisService version 13). --}}
@if (! empty($d['abilities']))
    <table class="ab" style="margin-top: 8px">
        @foreach ($d['abilities'] as $a)
            <tr>
                <td class="sp">@if ($a['icon'])<img loading="lazy" class="spell-ic" src="{{ $a['icon'] }}">@else<span class="ph-s"></span>@endif<b>{{ $a['spell'] }}</b> <span class="small" style="color: {{ $a['color'] }}">{{ $a['name'] }}</span></td>
                <td class="sh"><div class="bar" style="margin:0"><span style="width: {{ $a['pct'] }}%; background: {{ $a['color'] }}"></span></div></td>
                <td class="n small muted">{{ $a['pct'] }}%</td>
            </tr>
        @endforeach
    </table>
@endif

{{-- Their health through the go (RoundAnalysisService version 14). --}}
@if (! empty($d['health']))
    @php $g = $d['health']; @endphp
    <div class="line">
        <div class="small"><span class="muted">Health, the last {{ $g['seconds'] }}s:</span> {{ $g['summary'] }}</div>
        <svg class="hpline" viewBox="0 0 {{ $g['w'] }} {{ $g['h'] }}" preserveAspectRatio="none" role="img" aria-label="Health over the last {{ $g['seconds'] }} seconds">
            @foreach ($g['locked'] as $l)<rect x="{{ $l['x'] }}" y="0" width="{{ $l['w'] }}" height="{{ $g['h'] }}" fill="#fca5a5" fill-opacity="0.12"><title>Healer locked out</title></rect>@endforeach
            <line x1="0" x2="{{ $g['w'] }}" y1="{{ $g['danger'] }}" y2="{{ $g['danger'] }}" stroke="#6A6A6A" stroke-dasharray="4 4" stroke-width="1" vector-effect="non-scaling-stroke"/>
            <polyline points="{{ $g['points'] }}" fill="none" stroke="{{ $g['color'] }}" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
            @foreach ($g['presses'] as $p)<line x1="{{ $p['x'] }}" x2="{{ $p['x'] }}" y1="0" y2="{{ $g['h'] }}" stroke="#C8952C" stroke-width="2" vector-effect="non-scaling-stroke"><title>{{ $p['label'] }}</title></line>@endforeach
        </svg>
        <div class="small subtle"><span style="float: right">the death</span>{{ $g['seconds'] }}s before</div>
        <div class="small subtle">Dashed: 35%. @if ($g['locked'])Shaded: the enemy healer locked out. @endif @if ($g['presses'])Gold: a defensive pressed on that side (hover for which). @endif</div>
    </div>
@endif

{{-- The answer sheet (RoundAnalysisService version 7): enemy go beside every button your team had for it. --}}
@if (! empty($d['answers']))
    @php $s = $d['answers']; @endphp
    <div class="answers">
        <div class="label" style="margin-top: 12px">What your team had for the enemy go <span class="aside">from {{ $s['from'] }} to the death: what was there, not what would have won</span></div>

        @if ($s['offensive'])
            <div class="line small"><span class="muted">Enemy offensive cooldowns:&nbsp;</span>
                @foreach ($s['offensive'] as $x)
                    <span class="def">@if ($x['icon'])<img loading="lazy" class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span></span>
                @endforeach
            </div>
        @endif
        @if ($s['control'])
            <div class="line small"><span class="muted">Enemy crowd control on you:&nbsp;</span>
                @foreach ($s['control'] as $x)
                    <span class="def">@if ($x['icon'])<img loading="lazy" class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span class="muted">on {{ $x['on'] }}, {{ $x['clock'] }}</span></span>
                @endforeach
            </div>
        @endif
        @foreach ($s['lockout'] as $l)
            <div class="line small">
                <b style="color: {{ $l['color'] }}">{{ $l['name'] }}</b> locked out {{ $l['seconds'] }}s of it, the longest stretch {{ $l['longest'] }}s from {{ $l['longestFrom'] }}.
                @if ($l['free'])
                    <span class="gold">Free {{ $l['free'] }} just before it: the last moment to press something.</span>
                @endif
            </div>
        @endforeach

        {{-- Ready and never pressed, by whether it could have saved them (App\Support\DefensiveFit). --}}
        @if ($s['schools'])
            <div class="line small"><span class="muted">The damage that killed, last 10s:</span> {{ $s['schools']['physical'] }}% physical, {{ $s['schools']['magic'] }}% magic</div>
        @endif
        @if ($s['fits'])
            <div class="line"><span class="chip warn">Ready, and could have saved them</span> <span class="small muted">&nbsp;reached them and covers that damage</span></div>
            <div class="line small">
                @foreach ($s['fits'] as $x)
                    <span class="def">@if ($x['icon'])<img loading="lazy" class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span> @if ($x['tried'])<span class="gold">tried {{ $x['tried'] }}</span>@endif</span>
                @endforeach
            </div>
        @endif
        @if ($s['others'])
            <div class="line"><span class="chip neutral">Ready, other answers</span> <span class="small muted">&nbsp;crowd control, peels, trinkets, hiding: whether one fitted is your read</span></div>
            <div class="line small">
                @foreach ($s['others'] as $x)
                    <span class="def">@if ($x['icon'])<img loading="lazy" class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span> @if ($x['tried'])<span class="gold">tried {{ $x['tried'] }}</span>@endif</span>
                @endforeach
            </div>
        @endif
        @if ($s['cannot'])
            <div class="line"><span class="chip neutral">Ready, but could not help here</span></div>
            <div class="line small">
                @foreach ($s['cannot'] as $x)
                    <span class="def subtle">{{ $x['spell'] }} {{ $x['name'] }} <span>({{ $x['why'] }})</span></span>
                @endforeach
            </div>
        @endif
        @if ($s['pressed'])
            <div class="line"><span class="chip neutral">Pressed in the enemy go</span></div>
            <div class="line small">
                @foreach ($s['pressed'] as $x)
                    <span class="def">@if ($x['icon'])<img loading="lazy" class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span> <span class="muted">{{ $x['clock'] }}</span></span>
                @endforeach
            </div>
        @endif
        @if ($s['down'])
            <div class="line"><span class="chip neutral">On cooldown when it began</span></div>
            <div class="line small">
                @foreach ($s['down'] as $x)
                    <span class="def">@if ($x['icon'])<img loading="lazy" class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span> <span class="muted">back at {{ $x['back'] }}</span></span>
                @endforeach
            </div>
        @endif
    </div>
@endif
</details>
@endif
