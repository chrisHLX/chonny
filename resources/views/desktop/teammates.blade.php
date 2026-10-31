{{--
    Everyone a player has queued with, and their own comps, built by TeammatesService for the
    Teammates page in the app and on /wow/coach. The Comps page holds the enemy comps they have met.
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@include('desktop.partials.styles')
@include('desktop.partials.teammate-styles')
</head>
<body>

<div class="head">
    <span class="title">Teammates</span>
    <span class="when muted">{{ $totals['games'] }} 2v2 and 3v3 games &middot; {{ $totals['won'] }}-{{ $totals['lost'] }}</span>
</div>

<div class="section card">
    <p class="kicker">Your comps</p>
    @php [$few, $many] = collect($comps)->partition('lead'); @endphp
    @if ($many->isNotEmpty())
        @include('desktop.partials.comp-records', ['comps' => $many])
    @endif
    @if ($few->isNotEmpty())
        <details class="more" @if ($many->isEmpty()) open @endif>
            <summary>{{ $few->count() }} comp{{ $few->count() === 1 ? '' : 's' }} with fewer than 10 games</summary>
            @include('desktop.partials.comp-records', ['comps' => $few])
        </details>
    @endif
</div>

<div class="section card">
    <p class="kicker">Who you play with</p>
    @if ($mates)
        <table class="rec">
            <tr class="hd"><td>Teammate</td><td>Rating</td><td class="n">Games</td><td class="n">Record</td><td class="n">Win rate</td></tr>
            @foreach ($mates as $m)
                <tr class="link">
                    <td>
                        <a class="name" href="{{ $m['file'] }}" style="color: {{ $m['color'] }}">{{ $m['name'] }}</a>
                        <span class="muted small">{{ array_key_first($m['specs']) }}</span>
                        @foreach ($m['titles'] as $t)
                            <span class="tag" title="{{ $t['tip'] }}">{{ $t['text'] }}</span>
                        @endforeach
                    </td>
                    <td class="muted">{{ $m['rating'] ? $m['rating']['rating'] : '' }}</td>
                    <td class="n">{{ $m['games'] }}</td>
                    <td class="n">{{ $m['won'] }}-{{ $m['lost'] }}</td>
                    <td class="n">@include('desktop.partials.win-rate', ['r' => $m])</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nobody yet. A teammate shows up here once you've played two games together.</p>
    @endif
    @if ($once)
        <p class="muted small" style="margin-top: 8px">{{ $once }} more you've played one game with.</p>
    @endif
    <p class="muted small" style="margin-top: 8px">Best win rate first, from 10 games together. Smaller records follow, by games played. Shuffle isn't counted, because those teammates are drawn for you.</p>
</div>

</body>
</html>
