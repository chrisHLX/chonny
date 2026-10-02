{{--
    Losses the log shows by itself (RoundAnalysisService::checks): an offensive cooldown left ready
    for a whole cooldown or more, and a defensive put on a teammate who was already immune.
--}}
@if ($checks === null)
    <span class="muted">Not checked for this game yet. Run a sync with <b>--fresh</b> to check older games.</span>
@elseif ($checks === [])
    <span class="muted">Nothing found: no offensive cooldown sat ready for a whole cooldown, and nothing went on an immune teammate.</span>
@else
    <table class="look">
        @foreach ($checks as $c)
            <tr>
                <td class="ic">@if ($c['icon'])<img class="spell-ic" src="{{ $c['icon'] }}">@else<span class="ph-s"></span>@endif</td>
                <td><span class="name" style="color: {{ $c['color'] }}">{{ $c['name'] }}</span>@if ($c['you'])<span class="you">YOU</span>@endif
                    &nbsp;<b>{{ $c['spell'] }}</b> {{ $c['text'] }}</td>
            </tr>
        @endforeach
    </table>
@endif
