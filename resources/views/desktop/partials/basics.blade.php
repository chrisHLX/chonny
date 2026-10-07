{{--
    The Basics tab (GameBasicsService): your team's goes, your crowd control and casts, your output
    against every player of your spec, and positioning and macros from your failed casts. Each line
    rests on a relationship measured across the whole archive; the numbers in its text are those.
    IE11: tables, no flex gaps.
--}}
@if ($b === null)
    <div class="box muted">The basics need this game measured at the latest version: run a sync with <b>--fresh</b>.</div>
@else
    @foreach ($b['sections'] as $section)
        <div class="section">
            <div class="label">{{ $section['title'] }}@if (! empty($section['source'])) <span class="aside">{{ $section['source'] }}</span>@endif</div>
            <div class="box">
                <table class="look">
                    @foreach ($section['rows'] as $row)
                        <tr>
                            <td class="ic" style="width: 70px">
                                <span class="chip {{ $row['tone'] === 'good' ? 'good' : ($row['tone'] === 'warn' ? 'warn' : 'neutral') }}">{{ $row['tone'] === 'good' ? 'GOOD' : ($row['tone'] === 'warn' ? 'WORK ON' : 'NOTE') }}</span>
                            </td>
                            <td>
                                <b>{{ $row['label'] }}</b>&nbsp; <span class="gold">{{ $row['value'] }}</span>
                                @if (! empty($row['icons']))
                                    &nbsp;@foreach ($row['icons'] as $ic)<img class="spell-ic" src="{{ $ic }}" style="margin-right: 3px">@endforeach
                                @endif
                                <div class="small muted" style="margin-top: 2px">{{ $row['text'] }}</div>
                                @if (! empty($row['macro']))
                                    <div class="small" style="margin-top: 4px; font-family: Consolas, monospace; color: #E8B84B">#showtooltip<br>/cast [@focus] {{ $row['macro'] }}</div>
                                    <div class="small muted" style="margin-top: 2px">or one each for <span style="font-family: Consolas, monospace">[@arena1]</span>, <span style="font-family: Consolas, monospace">[@arena2]</span>, <span style="font-family: Consolas, monospace">[@arena3]</span>. Set your focus on their healer as the gates open.</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endforeach
    <div class="small subtle" style="margin-top: 6px">Every figure against the archive is a correlation across {{ number_format(\App\Http\Services\GameBasicsService::archiveGames()) }} games and every player in them: it says what tends to go with winning, not what caused it.</div>
@endif
