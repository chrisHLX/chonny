@php
    $name = fn (array $c) => $c['nick'] ?? collect($c['classes'])->map(fn ($s) => \App\Http\Services\CompLeaderboardService::className($s))->implode(' + ');
    $start = $board['season']['start'];
    $rateClass = fn (?int $r) => ($r ?? 0) >= 55 ? 'text-green-400' : (($r ?? 100) <= 45 ? 'text-red-300' : 'text-ink');
@endphp
<div class="max-w-[1100px] mx-auto px-4 py-8">
    <a href="{{ route('wow-comps') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; 3v3 Comps</a>

    <div class="mt-3 mb-5">
        <h1 class="font-display text-2xl text-ink leading-tight">Comp leaderboard</h1>
        <p class="text-[12.5px] text-ink-muted mt-1">
            Every 3v3 comp in the {{ number_format($board['games']) }} games uploaded to MindCollector since {{ $start->format('j M') }}@if ($board['season']['id']) (season {{ $board['season']['id'] }})@endif.
            Both teams of every game count. No player is named. Open a comp to see its games.
        </p>
    </div>

    {{-- The experience filter: a team's best 3v3 rating ever, averaged over its players. --}}
    <div class="flex flex-wrap items-center gap-2 mb-2">
        <span class="text-[11px] uppercase tracking-wider text-ink-subtle mr-1">Experience</span>
        <button type="button" wire:click="setExp(null)" class="chip {{ $board['minExp'] === null ? 'chip-on' : '' }}">Everyone</button>
        @foreach ($bands as $min => $label)
            <button type="button" wire:click="setExp({{ $min }})" class="chip {{ $board['minExp'] === $min ? 'chip-on' : '' }}" title="{{ $label }}">{{ $min }}+</button>
        @endforeach
    </div>
    <p class="text-[12px] text-ink-subtle mb-4">
        @if ($board['minExp'])
            Teams whose players' best 3v3 rating ever averages {{ $board['minExp'] }} or more.
        @else
            Experience is a team's players' best 3v3 rating ever, averaged.
        @endif
        Known for {{ number_format($board['known']) }} of {{ number_format($board['teams']) }} teams, where Blizzard has a profile; the others are left out of a filter.
    </p>

    <div class="linear-card p-0 overflow-hidden" wire:loading.class="opacity-60" wire:target="setExp">
        @if ($comps === [])
            <p class="p-5 text-[13px] text-ink-subtle">
                {{ $board['minExp'] ? 'No team at '.$board['minExp'].'+ experience in the games uploaded this season.' : 'No 3v3 games uploaded yet this season.' }}
            </p>
        @else
            <table class="w-full text-[13px] tabular-nums">
                <thead class="text-[10.5px] uppercase tracking-wider text-ink-subtle">
                    <tr class="border-b border-line">
                        <th class="text-left font-normal py-2 pl-4 pr-2 w-8 hidden sm:table-cell">#</th>
                        <th class="text-left font-normal py-2 pl-3 sm:pl-0 pr-2">Comp</th>
                        <th class="text-right font-normal py-2 pr-2 sm:pr-3">Games</th>
                        <th class="text-right font-normal py-2 pr-3 hidden sm:table-cell">Won&ndash;lost</th>
                        <th class="text-right font-normal py-2 pr-2 sm:pr-3">Won</th>
                        <th class="text-right font-normal py-2 pr-2 sm:pr-3" title="The teams' average matchmaking rating in these games">MMR</th>
                        <th class="text-right font-normal py-2 pr-3 sm:pr-4" title="The players' best 3v3 rating ever, averaged, where Blizzard has a profile">Exp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($comps as $i => $c)
                        @php $url = route('comps.played', ['key' => $c['key']]); @endphp
                        <tr class="cursor-pointer hover:bg-surface-2" onclick="if (!event.target.closest('a')) this.querySelector('a').click()">
                            <td class="py-2.5 pl-4 pr-2 text-ink-subtle hidden sm:table-cell">{{ $i + 1 }}</td>
                            <td class="py-2.5 pl-3 sm:pl-0 pr-2">
                                <a href="{{ $url }}" wire:navigate class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-2">
                                    <span class="flex -space-x-1 shrink-0">
                                        @foreach ($c['classes'] as $slug)
                                            @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-6 h-6"/> @endif
                                        @endforeach
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-ink leading-tight">{{ $name($c) }}</span>
                                        @if ($c['lineups'][0] ?? null)
                                            <span class="block text-[11.5px] leading-tight mt-0.5">
                                                @foreach ($c['lineups'][0]['specs'] as $s)
                                                    <span style="color: {{ $colors[$s['class']] ?? '#B3B3B3' }}">{{ $s['label'] }}</span>@if (! $loop->last)<span class="text-ink-subtle">, </span>@endif
                                                @endforeach
                                            </span>
                                        @endif
                                    </span>
                                </a>
                            </td>
                            <td class="py-2.5 pr-2 sm:pr-3 text-right text-ink">{{ $c['games'] }}</td>
                            <td class="py-2.5 pr-3 text-right text-ink-muted hidden sm:table-cell">{{ $c['won'] }}&ndash;{{ $c['lost'] }}</td>
                            <td class="py-2.5 pr-2 sm:pr-3 text-right {{ $rateClass($c['rate']) }}">{{ $c['rate'] !== null ? $c['rate'].'%' : '-' }}</td>
                            <td class="py-2.5 pr-2 sm:pr-3 text-right text-ink-muted">{{ $c['mmr'] ?? '-' }}</td>
                            <td class="py-2.5 pr-3 sm:pr-4 text-right text-ink-muted">{{ $c['exp'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if (! $all && count($board['comps']) > count($comps))
        <button type="button" wire:click="showAll" class="btn-ghost mt-3 text-[12.5px]">Show every comp ({{ count($board['comps']) }})</button>
    @endif
</div>
