{{-- One death: who, the killing blow, who did the damage, the healer, defensives in the last 30s. --}}
<div class="who">
    @if ($d['icon'])<img class="spec-ic" src="{{ $d['icon'] }}" style="border:1px solid {{ $d['color'] }}">@endif
    <span class="muted">&nbsp;{{ $d['clock'] }}</span>&nbsp;
    <span class="name" style="color: {{ $d['color'] }}">{{ $d['name'] }}</span> died
    <span class="chip {{ $d['ours'] ? 'bad' : 'good' }}">{{ $d['ours'] ? 'yours' : 'theirs' }}</span>
</div>

@if ($d['killingBlow'] || $d['shares'])
    <div class="line">
        @if ($d['killingBlow'])
            <span class="muted">Killing blow</span>&nbsp;
            @if ($d['killingBlow']['icon'])<img class="spell-ic" src="{{ $d['killingBlow']['icon'] }}">@endif<b>{{ $d['killingBlow']['spell'] }}</b>
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
                <span class="def">@if ($x['icon'])<img class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span class="muted">{{ $x['who'] }}, {{ $x['ago'] }}s before</span></span>
            @endforeach
        </div>
    @else
        <span class="muted">No defensives in the last 30s.</span>
    @endif
</div>

{{-- The answer sheet (RoundAnalysisService version 7): their go beside every button your team had for it. --}}
@if (! empty($d['answers']))
    @php $s = $d['answers']; @endphp
    <div class="answers">
        <div class="label" style="margin-top: 12px">What your team had for their go <span class="aside">from {{ $s['from'] }} to the death: what was there, not what would have won</span></div>

        @if ($s['offensive'])
            <div class="line small"><span class="muted">Their offensive cooldowns:&nbsp;</span>
                @foreach ($s['offensive'] as $x)
                    <span class="def">@if ($x['icon'])<img class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span></span>
                @endforeach
            </div>
        @endif
        @if ($s['control'])
            <div class="line small"><span class="muted">Their crowd control on you:&nbsp;</span>
                @foreach ($s['control'] as $x)
                    <span class="def">@if ($x['icon'])<img class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span class="muted">on {{ $x['on'] }}, {{ $x['clock'] }}</span></span>
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

        @if ($s['ready'])
            <div class="line"><span class="chip warn">Ready, never pressed</span> <span class="small muted">&nbsp;the dying player's own first, then the team's defensives, crowd control to peel, interrupts, Medallions</span></div>
            <div class="line small">
                @foreach ($s['ready'] as $x)
                    <span class="def">@if ($x['icon'])<img class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span> @if ($x['tried'])<span class="gold">tried {{ $x['tried'] }}</span>@endif</span>
                @endforeach
            </div>
        @endif
        @if ($s['pressed'])
            <div class="line"><span class="chip neutral">Pressed in their go</span></div>
            <div class="line small">
                @foreach ($s['pressed'] as $x)
                    <span class="def">@if ($x['icon'])<img class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span> <span class="muted">{{ $x['clock'] }}</span></span>
                @endforeach
            </div>
        @endif
        @if ($s['down'])
            <div class="line"><span class="chip neutral">On cooldown when it began</span></div>
            <div class="line small">
                @foreach ($s['down'] as $x)
                    <span class="def">@if ($x['icon'])<img class="spell-ic" src="{{ $x['icon'] }}">@endif{{ $x['spell'] }} <span style="color: {{ $x['color'] }}">{{ $x['name'] }}</span> <span class="muted">back at {{ $x['back'] }}</span></span>
                @endforeach
            </div>
        @endif
    </div>
@endif
