{{--
    Damage and healing by player and ability (GameCardService::breakdownModel). One summary row per
    player; clicking it opens their abilities. IE11: the toggle is plain onclick and style.display.
--}}
<div class="section">
    <div class="label">Damage and healing <span class="aside">onto players only, not pets or totems; click a player for their abilities</span></div>
    <div class="box">
        @if ($b === null)
            <span class="muted">Not measured for this game yet. Run a sync with <b>--fresh</b> to measure older games again.</span>
        @else
            <table class="stats bd">
                <tr class="small muted"><td></td><td class="n">Damage</td><td class="n">Healing</td><td class="n">Absorbs</td><td class="n" title="Gaps over 2.5s between presses, not counting time crowd-controlled">Idle</td></tr>
                @foreach ($b['players'] as $p)
                    <tr class="pl" onclick="var e=document.getElementById('{{ $p['id'] }}');e.style.display=e.style.display==='none'?'':'none';">
                        <td>
                            @if ($p['icon'])<img class="spell-ic" src="{{ $p['icon'] }}" style="border:1px solid {{ $p['color'] }}">@else<span class="ph-s" style="border:1px solid {{ $p['color'] }}"></span>@endif
                            <span class="name" style="color: {{ $p['color'] }}">{{ $p['name'] }}</span>@if ($p['you'])<span class="you">YOU</span>@endif
                            @if ($p['them'])<span class="small subtle">&nbsp;them</span>@endif
                        </td>
                        <td class="n">{{ $p['damage'] }}</td>
                        <td class="n">{{ $p['healing'] }}</td>
                        <td class="n">{{ $p['absorbs'] }}</td>
                        <td class="n {{ $p['idle'] >= 25 ? 'worse' : '' }}">{{ $p['idle'] }}%</td>
                    </tr>
                    <tr id="{{ $p['id'] }}" style="{{ $p['you'] ? '' : 'display:none' }}">
                        <td colspan="5" class="abil">
                            @foreach ($p['kinds'] as $k)
                                <div class="small muted" style="margin:6px 0 2px">{{ $k['label'] }} <b style="color:#F0F0F2">{{ $k['total'] }}</b> &middot; {{ $k['perSecond'] }} a second alive</div>
                                <table class="ab">
                                    @foreach ($k['rows'] as $r)
                                        <tr>
                                            <td class="sp">@if ($r['icon'])<img class="spell-ic" src="{{ $r['icon'] }}">@else<span class="ph-s"></span>@endif{{ $r['spell'] }}</td>
                                            <td class="sh"><div class="bar"><span style="width: {{ max(1, $r['share']) }}%; background: {{ $p['color'] }}"></span></div></td>
                                            <td class="n">{{ $r['amount'] }}</td>
                                            <td class="n muted">{{ $r['share'] }}%</td>
                                            <td class="n muted small">{{ $r['hits'] }} hit{{ $r['hits'] === 1 ? '' : 's' }}@if ($r['overheal'] !== null) &middot; {{ $r['overheal'] }}% over @endif</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </table>
            @if ($b['partial'])
                <div class="small subtle" style="margin-top:6px">Some rounds were measured before this existed and are not in these totals.</div>
            @endif
        @endif
    </div>
</div>
