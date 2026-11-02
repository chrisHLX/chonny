{{-- Crowd control something broke (GameCardService::ccBreaksModel, 2026-11-02): one dot a landing, then
     what broke each one and how long it had held. Icons first, like the Empowered box. --}}
<div class="kt">
    <div class="kt-head">
        <div class="kicker">@include('desktop.partials.icon', ['n' => 'target'])Crowd Control Broken</div>
        <div class="seg">
            <a class="on" onclick="ktShow(this, 'us')">Your team</a>
            <a onclick="ktShow(this, 'them')">Enemy team</a>
        </div>
    </div>
    @foreach (['us', 'them'] as $side)
        <div class="kt-side" data-side="{{ $side }}" @if ($side === 'them') style="display: none" @endif>
            @forelse ($cb[$side] as $r)
                <div class="cb">
                    <span class="sp">
                        @if ($r['icon'])<img loading="lazy" src="{{ $r['icon'] }}" alt="{{ $r['spell'] }}">@else<i class="ph">{{ mb_substr($r['spell'], 0, 2) }}</i>@endif
                        <b>{{ $r['spell'] }}</b>
                    </span>
                    <span class="dots">
                        @foreach ($r['dots'] as $broke)<i class="{{ $broke ? 'broke' : '' }}"></i>@endforeach
                        <span class="small muted">{{ $r['broken'] }} of {{ $r['landed'] }} broke</span>
                    </span>
                    <span class="ic">
                        @foreach ($r['by'] as $b)
                            <span class="cc" data-tip="{{ $b['tip'] }}">@if ($b['icon'])<img loading="lazy" src="{{ $b['icon'] }}" alt="{{ $b['spell'] }}">@else<i>{{ mb_substr($b['spell'], 0, 2) }}</i>@endif<b>{{ $b['held'] }}s</b></span>
                        @endforeach
                    </span>
                </div>
            @empty
                <div class="small muted">None broken.</div>
            @endforelse
            @if ($cb[$side])<div class="small subtle cb-note">Under each: what broke it, and how long it had held.</div>@endif
        </div>
    @endforeach
</div>
