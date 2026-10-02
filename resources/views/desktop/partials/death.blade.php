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
