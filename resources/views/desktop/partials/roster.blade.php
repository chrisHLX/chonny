{{-- Players with their experience; $showCc adds the seconds each spent crowd-controlled. --}}
<table class="roster">
    @foreach ($players as $p)
        <tr>
            <td class="ic">
                @if ($p['icon'])
                    <img class="spec-ic" src="{{ $p['icon'] }}" style="border:1px solid {{ $p['color'] }}">
                @else
                    <span class="ph" style="border:1px solid {{ $p['color'] }}"></span>
                @endif
            </td>
            <td>
                <span class="name" style="color: {{ $p['color'] }}">{{ $p['name'] }}</span>@if ($p['you'])<span class="you">YOU</span>@endif
                <div class="small muted">{{ $p['spec'] }}@if ($p['healer']) &middot; healer @endif
                    @if ($showCc && $p['lockout'] !== null) &middot; CC'd {{ $p['lockout'] }}s @endif</div>
            </td>
            <td class="xp">
                @if ($p['xp']['state'] === 'ok')
                    @if ($p['xp']['glad'] > 0)
                        <span class="chip glad">{{ $p['xp']['glad'] }}&times; Glad</span>
                    @endif
                    <div class="small">@if ($p['xp']['exp'])<b>{{ $p['xp']['exp'] }}</b> @endif<span class="muted">{{ $p['xp']['title'] ?? 'no rank' }}</span></div>
                @elseif ($p['xp']['state'] === 'none')
                    <span class="small subtle">no public profile</span>
                @else
                    <span class="small subtle">not looked up yet</span>
                @endif
            </td>
        </tr>
    @endforeach
</table>
