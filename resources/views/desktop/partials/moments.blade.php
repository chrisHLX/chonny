{{--
    The Moments tab (GameMomentsService): situations in the game a player would recognise, each with
    what was up, what was pressed and what it cost. Grouped by kind, in time order within each.
    IE11: tables, no flex gaps.
--}}
@php
    $kinds = [
        'control' => ['Crowd control through a big defensive', 'control landing while a big defensive was up: the seconds of it lost'],
        'dispel' => ['Crowd control on a teammate in their go', 'what you could dispel while enemy go was on, and what happened'],
        'opening' => ['The start of enemy goes', 'your first casts, your first big defensive, and the target\'s health'],
    ];
    $chip = ['warn' => ['warn', 'LOOK'], 'good' => ['good', 'GOOD'], 'neutral' => ['neutral', 'NOTE']];
@endphp
@if ($m === null)
    <div class="box muted">Moments need this game measured at the latest version: it is measured again when the app next sends it.</div>
@elseif ($m === [])
    <div class="box muted">Nothing in this game matched a moment: no crowd control through a big defensive, no control on a teammate in enemy goes you could have dispelled, and no enemy go to read.</div>
@else
    @foreach ($kinds as $kind => [$title, $about])
        @php $rows = array_values(array_filter($m, fn ($x) => $x['kind'] === $kind)); @endphp
        @if ($rows)
            <div class="section">
                <div class="label">{{ $title }} <span class="aside">{{ $about }}</span></div>
                <div class="box">
                    <table class="look">
                        @foreach ($rows as $x)
                            <tr>
                                <td class="ic" style="width: 70px; vertical-align: top">
                                    <span class="chip {{ $chip[$x['tone']][0] }}">{{ $chip[$x['tone']][1] }}</span>
                                </td>
                                <td>
                                    <span class="muted">@if ($x['round']){{ $x['round'] }}, @endif{{ $x['clock'] }}</span>&nbsp;
                                    <b>{{ $x['title'] }}</b>
                                    @if (! empty($x['icons']))
                                        &nbsp;@foreach ($x['icons'] as $ic)<img class="spell-ic" src="{{ $ic }}" style="margin-right: 3px">@endforeach
                                    @endif
                                    <div class="small" style="margin-top: 3px">{{ $x['text'] }}</div>
                                    @if (($x['hp']['start'] ?? null) !== null)
                                        <div class="small muted" style="margin-top: 2px">{{ $x['hpOf'] ?? 'Their' }} health: {{ $x['hp']['start'] }}% at the start, {{ $x['hp']['low'] }}% at the lowest.</div>
                                    @endif
                                    @if (! empty($x['steps']))
                                        <table class="stats" style="margin-top: 6px; width: auto">
                                            @foreach ($x['steps'] as $st)
                                                <tr>
                                                    <td class="small muted" style="padding: 1px 10px 1px 0">{{ $st['clock'] }}</td>
                                                    <td class="small" style="padding: 1px 8px 1px 0">{{ $st['who'] }}</td>
                                                    <td class="small" style="padding: 1px 8px 1px 0">@if ($st['icon'])<img class="spell-ic" src="{{ $st['icon'] }}" style="margin-right: 4px">@endif<span @if (($st['mark'] ?? null) === 'control') class="warn-text" @elseif (($st['mark'] ?? null) === 'dispel') class="gold" @endif>{{ $st['spell'] }}</span></td>
                                                    <td class="small muted" style="padding: 1px 0">@if ($st['on'])on {{ $st['on'] }}@endif</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @endif
                                    @if (! empty($x['caveat']))
                                        <div class="small subtle" style="margin-top: 4px">{{ $x['caveat'] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endif
    @endforeach
    <div class="small subtle" style="margin-top: 6px">A moment says what happened and when. What should have been pressed instead depends on what the log cannot see: positioning, calls, what your team was setting up.</div>
@endif
