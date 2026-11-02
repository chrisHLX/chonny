{{-- One death as a picture (GameCardService::deathChart, 2026-11-02): who, what killed, then one clock for
     the cooldowns, the crowd control, the defensives and every hit. What can be drawn is not written too. --}}
<div class="who dt-who">
    @if ($d['icon'])<img loading="lazy" class="spec-ic" src="{{ $d['icon'] }}" style="border:1px solid {{ $d['color'] }}">@endif
    <span class="muted">{{ $d['clock'] }}</span>
    <span><span class="name" style="color: {{ $d['color'] }}">{{ $d['name'] }}</span> died</span>
    <span class="chip {{ $d['ours'] ? 'bad' : 'good' }}">{{ $d['ours'] ? 'yours' : 'enemy' }}</span>
    @if ($d['killingBlow'])
        <span class="dt-blow"><span class="muted">Killing blow</span> @if ($d['killingBlow']['icon'])<img loading="lazy" class="spell-ic" src="{{ $d['killingBlow']['icon'] }}">@endif<b>{{ $d['killingBlow']['spell'] }}</b></span>
    @endif
    @if ($d['healer'])
        <span class="dt-blow"><span class="muted">{{ $d['healer']['whose'] }}</span> <span class="chip {{ $d['healer']['tone'] }}">{{ $d['healer']['label'] }}</span> <span class="small muted">{{ $d['healer']['medallion'] }}</span></span>
    @endif
</div>

@if ($d['chart'])
    @php $c = $d['chart']; @endphp
    <div class="dt" style="--z: 1">
        @if ($c['zoom'])
            <div class="dt-top">
                <div class="seg">
                    <a class="on" onclick="dtZoom(this, 1)">Last {{ $c['seconds'] }}s</a>
                    <a onclick="dtZoom(this, {{ $c['zoom'] }})">Last 10s</a>
                </div>
            </div>
        @endif

        @include('desktop.partials.death-lane', ['lane' => $c['cooldowns']])
        @foreach ($c['people'] as $p)
            <div class="dt-row">
                <div class="dt-lab" style="color: {{ $p['color'] }}">{{ $p['name'] }}</div>
                <div class="dt-track"><div class="dt-in">
                    @foreach ($p['bars'] as $b)@include('desktop.partials.death-bar', ['b' => $b])@endforeach
                    @if (! $p['bars'])<span class="dt-free">Not in crowd control</span>@endif
                </div></div>
            </div>
        @endforeach
        @include('desktop.partials.death-lane', ['lane' => $c['defensives']])

        <div class="dt-row dt-health">
            <div class="dt-lab">Health</div>
            <div class="dt-track"><div class="dt-in">
                @foreach ($c['locked'] as $l)<div class="dt-locked" style="left: {{ $l['left'] }}%; width: {{ $l['width'] }}%" data-tip="{{ $c['lockedLabel'] }}"></div>@endforeach
                <div class="dt-danger"></div>
                <svg class="dt-line" viewBox="0 0 1000 100" preserveAspectRatio="none" role="img" aria-label="Health over the last {{ $c['seconds'] }} seconds"><polyline points="{{ $c['line'] }}" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>
            </div></div>
        </div>

        @if ($c['hits'])
            <div class="dt-row dt-hits">
                <div class="dt-lab">Hits<span class="dt-peak">Biggest {{ $c['peak'] }}</span></div>
                <div class="dt-track"><div class="dt-in">
                    @foreach ($c['locked'] as $l)<div class="dt-locked" style="left: {{ $l['left'] }}%; width: {{ $l['width'] }}%"></div>@endforeach
                    <div class="dt-area">
                        @foreach ($c['hits'] as $h)
                            <i class="dt-hit{{ $h['crit'] ? ' crit' : '' }}" style="left: {{ $h['left'] }}%; height: {{ $h['height'] }}%; background: linear-gradient(to top, {{ $h['color'] }} {{ $h['solid'] }}%, {{ $h['color'] }}4D {{ $h['solid'] }}%)" data-tip="{{ $h['tip'] }}"></i>
                            @if ($h['pin'])
                                <span class="dt-pin {{ $h['pin'] }}" style="left: {{ $h['left'] }}%; bottom: {{ $h['height'] }}%" data-tip="{{ $h['tip'] }}">@if ($h['icon'])<img loading="lazy" src="{{ $h['icon'] }}" alt="{{ $h['spell'] }}">@else<b>{{ mb_substr($h['spell'], 0, 2) }}</b>@endif</span>
                            @endif
                        @endforeach
                    </div>
                </div></div>
            </div>
        @endif

        <div class="dt-row dt-axis">
            <div class="dt-lab"></div>
            <div class="dt-track"><div class="dt-in">
                <span class="dt-from">{{ $c['from'] }}</span>
                @foreach ($c['ticks'] as $t)<span class="dt-tick{{ $t['edge'] ? ' edge' : '' }}" style="left: {{ $t['left'] }}%">{{ $t['label'] }}</span>@endforeach
                <span class="dt-end">Death</span>
            </div></div>
        </div>

        <div class="dt-key small">
            @if ($d['hitRows'])
                <span class="muted">Damage, last 10s</span>
                @foreach ($d['hitRows']['shares'] as $s)<span><i class="sw" style="background: {{ $s['color'] }}"></i>{{ $s['name'] }} {{ $s['pct'] }}%</span>@endforeach
            @elseif ($d['shares'])
                <span class="muted">Damage in the last 10s:</span>
                @foreach ($d['shares'] as $s)<span><i class="sw" style="background: {{ $s['color'] }}"></i>{{ $s['name'] }} {{ $s['pct'] }}%</span>@endforeach
            @endif
            @if ($c['schools'])<span class="muted">{{ $c['schools']['physical'] }}% physical, {{ $c['schools']['magic'] }}% magic</span>@endif
        </div>
        <div class="dt-key small subtle">
            @if ($c['hits'])<span><i class="star"></i>Crit</span><span><i class="sw faint"></i>A shield took it</span>@endif
            @if ($c['locked'])<span><i class="sw shade"></i>{{ $c['lockedLabel'] }}</span>@endif
            @if (collect($c['people'])->flatMap(fn ($p) => $p['bars'])->contains('broke', true))<span><i class="sw crack"></i>Crowd control broken</span>@endif
            <span>Dashed: 35% health</span>
        </div>
    </div>
@elseif ($d['shares'])
    <div class="line small legend"><span class="muted">Damage in the last 10s:</span>
        @foreach ($d['shares'] as $s)<span><b style="color: {{ $s['color'] }}">{{ $s['name'] }}</b> {{ $s['pct'] }}%</span>@endforeach
    </div>
@endif

{{-- How much each ability did, gold for the part from crits. A round measured before version 19 has only totals. --}}
@if ($d['hitRows'])
    <div class="hr-rows">
        <div class="hr heads"><span>Last 10s</span><span>Damage, gold from crits</span><span>{{ collect($d['hitRows']['rows'])->contains(fn ($r) => $r['up']) ? 'Cooldowns up' : '' }}</span><span></span></div>
        @foreach ($d['hitRows']['rows'] as $r)
            <div class="hr">
                <span class="sp">
                    @if ($r['icon'])<img loading="lazy" src="{{ $r['icon'] }}" alt="{{ $r['spell'] }}">@else<i class="ph">{{ mb_substr($r['spell'], 0, 2) }}</i>@endif
                    <span class="nm"><b>{{ $r['spell'] }}</b> <span class="by"><i class="sw" style="background: {{ $r['color'] }}"></i>{{ $r['name'] }}</span></span>
                </span>
                <span class="dmg">
                    <span class="track"><i style="width: {{ $r['bar'] }}%; background: linear-gradient(to right, {{ $r['color'] }} {{ 100 - $r['critShare'] }}%, #E8B84B {{ 100 - $r['critShare'] }}%)"></i></span>
                    <span class="ct">{{ $r['count'] }} {{ $r['count'] === 1 ? 'hit' : 'hits' }}@if ($r['crits']), {{ $r['crits'] }} {{ $r['crits'] === 1 ? 'crit' : 'crits' }}@endif</span>
                </span>
                <span class="up">
                    @foreach ($r['up'] as $u)
                        @if ($u['icon'])<img loading="lazy" src="{{ $u['icon'] }}" alt="{{ $u['spell'] }}" title="{{ $u['spell'] }} up for most of these hits">@else<i class="ph" title="{{ $u['spell'] }} up for most of these hits">{{ mb_substr($u['spell'], 0, 2) }}</i>@endif
                    @endforeach
                </span>
                <b class="pct">{{ $r['pct'] }}%</b>
            </div>
        @endforeach
    </div>
@elseif (! empty($d['abilities']))
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

{{-- The answer sheet (RoundAnalysisService version 7), one row of icons a player. Ready and never pressed
     is sorted by whether it could have saved them (App\Support\DefensiveFit); it lists what was there,
     and which one fitted the go is the player's read. --}}
@if (! empty($d['answers']['kit']))
    <details class="more">
        <summary>What your team had for the enemy go</summary>
        <div class="kit">
            @foreach ($d['answers']['kit'] as $p)
                <div class="kit-row">
                    <div class="dt-lab" style="color: {{ $p['color'] }}">{{ $p['name'] }}</div>
                    <div class="kit-icons">
                        @foreach ($p['items'] as $x)
                            <span class="cc {{ $x['state'] }}" data-tip="{{ $x['tip'] }}">@if ($x['icon'])<img loading="lazy" src="{{ $x['icon'] }}" alt="{{ $x['spell'] }}">@else<i>{{ mb_substr($x['spell'], 0, 2) }}</i>@endif<b>{{ $x['cap'] }}</b></span>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <div class="dt-key small subtle">
                <span><i class="sw ring"></i>Ready, could have saved them</span>
                <span>Lit: ready, or pressed at the time shown</span>
                <span>Grey: on cooldown until the time shown, or no use here</span>
            </div>
        </div>
    </details>
@endif
