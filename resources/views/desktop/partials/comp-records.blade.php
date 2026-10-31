{{-- A list of comps, each with its games, record and win rate. --}}
<table class="rec">
    <tr class="hd"><td>Comp</td><td>Bracket</td><td class="n">Games</td><td class="n">Record</td><td class="n">Win rate</td></tr>
    @foreach ($comps as $c)
        <tr>
            <td>{{ $c['label'] }}</td>
            <td class="muted">{{ $c['bracket'] }}</td>
            <td class="n">{{ $c['games'] }}</td>
            <td class="n">{{ $c['won'] }}-{{ $c['lost'] }}</td>
            <td class="n">@include('desktop.partials.win-rate', ['r' => $c])</td>
        </tr>
    @endforeach
</table>
