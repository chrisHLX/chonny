@php
    $icons = function (array $slugs, string $size = 'w-6 h-6') use ($classes) {
        return collect($slugs)->map(fn ($s) => $classes[$s] ?? null)->filter();
    };
    $name = fn (array $c) => $c['nick'] ?? collect($c['classes'])->map(fn ($s) => \App\Http\Services\CompLeaderboardService::className($s))->implode(' + ');
    $start = $board['season']['start'];
@endphp
<div class="linear-card p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Comps this season, from real games</h2>
        <span class="text-[12px] text-ink-subtle">
            {{ number_format($board['games']) }} 3v3 games uploaded to MindCollector since {{ $start->format('j M') }}@if ($board['season']['id']) (season {{ $board['season']['id'] }})@endif
        </span>
    </div>
    <p class="text-[12.5px] text-ink-muted mb-4">
        Every 3v3 comp our players met or played, with how it did. Open a comp for its goes, the crowd control it chains, who it kills and every game.
    </p>

    @if ($comps === [])
        <p class="text-[13px] text-ink-subtle">No 3v3 games uploaded yet this season.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-[13px] tabular-nums">
                <thead class="text-[10.5px] uppercase tracking-wider text-ink-subtle">
                    <tr>
                        <th class="text-left font-normal py-1.5 pr-2 w-8">#</th>
                        <th class="text-left font-normal py-1.5 pr-3">Comp</th>
                        <th class="text-right font-normal py-1.5 pr-3">Games</th>
                        <th class="text-right font-normal py-1.5 pr-3">Won&ndash;lost</th>
                        <th class="text-right font-normal py-1.5 pr-3">Win rate</th>
                        <th class="text-right font-normal py-1.5 pr-3" title="The teams' average matchmaking rating in these games">MMR</th>
                        <th class="text-right font-normal py-1.5 pr-3" title="The players' highest 3v3 rating ever, averaged, where Blizzard has a profile">Exp</th>
                        <th class="py-1.5"></th>
                    </tr>
                </thead>
                @foreach ($comps as $i => $c)
                    <tbody x-data="{ open: false }" class="border-t border-line">
                        <tr class="cursor-pointer hover:bg-surface-2" @click="open = !open">
                            <td class="py-2 pr-2 text-ink-subtle">{{ $i + 1 }}</td>
                            <td class="py-2 pr-3">
                                <div class="flex items-center gap-2">
                                    <span class="flex -space-x-1 shrink-0">
                                        @foreach ($icons($c['classes']) as $cls)
                                            <x-class-icon :class="$cls" size="w-6 h-6"/>
                                        @endforeach
                                    </span>
                                    <span class="font-semibold text-ink">{{ $name($c) }}</span>
                                    @if ($c['nick'])
                                        <span class="text-[11.5px] text-ink-subtle hidden sm:inline">{{ collect($c['classes'])->map(fn ($s) => \App\Http\Services\CompLeaderboardService::className($s))->implode(', ') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="py-2 pr-3 text-right text-ink">{{ $c['games'] }}</td>
                            <td class="py-2 pr-3 text-right text-ink-muted">{{ $c['won'] }}&ndash;{{ $c['lost'] }}</td>
                            <td class="py-2 pr-3 text-right {{ ($c['rate'] ?? 0) >= 55 ? 'text-green-400' : (($c['rate'] ?? 100) <= 45 ? 'text-red-300' : 'text-ink') }}">{{ $c['rate'] !== null ? $c['rate'].'%' : '-' }}</td>
                            <td class="py-2 pr-3 text-right text-ink-muted">{{ $c['mmr'] ?? '-' }}</td>
                            <td class="py-2 pr-3 text-right text-ink-muted">{{ $c['exp'] ?? '-' }}</td>
                            <td class="py-2 text-right">
                                <svg class="w-3.5 h-3.5 text-ink-subtle inline transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            </td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td></td>
                            <td colspan="7" class="pb-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-[10.5px] uppercase tracking-wider text-ink-subtle mb-1.5">Played as</p>
                                        @foreach ($c['lineups'] as $l)
                                            <p class="text-[12.5px] text-ink-muted">
                                                @foreach ($l['specs'] as $s)
                                                    <span style="color: {{ $colors[$s['class']] ?? '#B3B3B3' }}">{{ $s['label'] }}</span>@if (! $loop->last), @endif
                                                @endforeach
                                                <span class="text-ink-subtle">({{ $l['games'] }})</span>
                                            </p>
                                        @endforeach
                                        <a href="{{ route('comps.played', ['key' => $c['key']]) }}" wire:navigate
                                           class="btn-primary inline-flex mt-3 text-[12.5px]">Open the {{ $name($c) }} card</a>
                                    </div>
                                    <div>
                                        <p class="text-[10.5px] uppercase tracking-wider text-ink-subtle mb-1.5">Against each comp</p>
                                        <div class="space-y-1">
                                            @foreach (array_slice($c['matchups'], 0, 10) as $m)
                                                <a href="{{ route('comps.played', ['key' => $c['key'], 'vs' => $m['key']]) }}" wire:navigate
                                                   class="flex items-center gap-2 text-[12.5px] rounded px-1.5 py-0.5 hover:bg-surface-3">
                                                    <span class="text-ink-subtle w-5">vs</span>
                                                    <span class="flex -space-x-1 shrink-0">
                                                        @foreach ($icons($m['classes']) as $cls)
                                                            <x-class-icon :class="$cls" size="w-5 h-5"/>
                                                        @endforeach
                                                    </span>
                                                    <span class="text-ink flex-1 truncate">{{ $name($m) }}</span>
                                                    <span class="tabular-nums {{ $m['won'] > $m['lost'] ? 'text-green-400' : ($m['won'] < $m['lost'] ? 'text-red-300' : 'text-ink-muted') }}">{{ $m['won'] }}&ndash;{{ $m['lost'] }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>

        @if (! $all && count($board['comps']) > count($comps))
            <button type="button" wire:click="showAll" class="btn-ghost mt-3 text-[12.5px]">Show every comp ({{ count($board['comps']) }})</button>
        @endif
    @endif
</div>
