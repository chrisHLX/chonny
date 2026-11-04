{{-- Whom each team went after, and its goes (KillTargetService, 2026-11-01). Icons first, as few words as
     will carry it, like PvP Guides' Empowered box. $kt is keyed by the attacking team. $teams, on the
     website's pooled card, names each team by its comp: there is no "you" there. --}}
<div class="kt">
    <div class="kt-head">
        <div class="kicker">@include('desktop.partials.icon', ['n' => 'target'])Kill targets</div>
        <div class="seg">
            <a class="on" onclick="ktShow(this, 'us')">{{ $teams['us'] ?? 'Your team' }}</a>
            <a onclick="ktShow(this, 'them')">{{ $teams['them'] ?? 'Enemy team' }}</a>
        </div>
    </div>
    @foreach (isset($teams) ? ['us' => 'Their healer', 'them' => 'Their healer'] : ['us' => 'Their healer', 'them' => 'Your healer'] as $side => $healerLabel)
        <div class="kt-side" data-side="{{ $side }}" @if ($side === 'them') style="display: none" @endif>
            @foreach ($kt[$side]['targets'] as $i => $t)
                <div class="kt-target">
                    <div class="who"><span style="color: {{ $t['color'] }}">{{ $t['name'] }}</span>@unless (str_contains($t['name'], $t['spec'])) <span class="muted">{{ $t['spec'] }}</span>@endunless @if ($i === 0) <span class="chip neutral">Primary</span>@endif</div>
                    <div class="bar"><span style="width: {{ $t['share'] }}%; background: {{ $t['color'] }}"></span></div>
                    <div class="amt">{{ $t['amount'] }}</div>
                    <div class="fate">@if ($t['died'])@include('desktop.partials.icon', ['n' => 'skull'])Died {{ $t['died'] }}@endif</div>
                </div>
            @endforeach

            @if ($kt[$side]['goes'])
                <div class="kt-go heads">
                    <span>Goes</span><span>{{ $healerLabel }}</span><span>Target</span><span>Cooldowns</span>
                </div>
                @foreach ($kt[$side]['goes'] as $go)
                    <div class="kt-go">
                        <span class="at">{{ $go['clock'] }}</span>
                        <span class="ic" data-label="{{ $healerLabel }}">
                            @forelse ($go['healerCc'] as $c)
                                <span class="cc" title="{{ $c['spell'] }}">@if ($c['icon'])<img loading="lazy" src="{{ $c['icon'] }}" alt="{{ $c['spell'] }}">@else<i>{{ mb_substr($c['spell'], 0, 2) }}</i>@endif<b>{{ $c['seconds'] }}s</b></span>
                            @empty
                                <span class="muted small">None</span>
                            @endforelse
                        </span>
                        <span class="drop" data-label="Target">
                            <span class="line1"><span style="color: {{ $go['who']['color'] }}">{{ $go['who']['name'] }}</span> <span class="muted">{{ $go['high'] }}% to {{ $go['low'] }}%</span>@if ($go['died']) @include('desktop.partials.icon', ['n' => 'skull'])@endif</span>
                            <span class="hp"><i style="left: {{ $go['low'] }}%; width: {{ max(1, $go['high'] - $go['low']) }}%; background: {{ $go['who']['color'] }}"></i></span>
                        </span>
                        <span class="ic" data-label="Cooldowns">
                            @forelse ($go['cooldowns'] as $c)
                                <span class="cc" title="{{ $c['spell'] }}">@if ($c['icon'])<img loading="lazy" src="{{ $c['icon'] }}" alt="{{ $c['spell'] }}">@else<i>{{ mb_substr($c['spell'], 0, 2) }}</i>@endif</span>
                            @empty
                                <span class="muted small">None</span>
                            @endforelse
                        </span>
                    </div>
                @endforeach
                @if ($kt[$side]['allGoes'] > count($kt[$side]['goes']))
                    <div class="small muted kt-more">The {{ count($kt[$side]['goes']) }} biggest of {{ $kt[$side]['allGoes'] }} goes.</div>
                @endif
            @else
                <div class="small muted kt-more">No goes in this game.</div>
            @endif
        </div>
    @endforeach
</div>
