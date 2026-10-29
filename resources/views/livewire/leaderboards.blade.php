@php
    $name = fn (array $c) => $c['nick'] ?? collect($c['classes'])->map(fn ($s) => \App\Http\Services\CompLeaderboardService::className($s))->implode(' + ');
    $start = $board['season']['start'];
    $rateClass = fn (?int $r) => ($r ?? 0) >= 55 ? 'text-green-400' : (($r ?? 100) <= 45 ? 'text-red-300' : 'text-ink');
    // A team's experience as a badge (2026-10-29): the board's own bands, so it reads at a glance.
    $band = fn (?int $e) => match (true) {
        $e === null => null,
        $e >= 2400 => ['2400+', 'badge-gold'],
        $e >= 2100 => ['Duelist', 'badge-amber'],
        $e >= 1800 => ['Rival', 'badge-blue'],
        default => ['Under 1800', 'badge-gray'],
    };
@endphp
<div class="max-w-[1280px] mx-auto px-3 sm:px-4 py-6 sm:py-8">
    <a href="{{ route('wow-comps') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; 3v3 Comps</a>

    {{-- The header (2026-10-29, the dashboard layout): an icon, the title in the heading face, and what it is drawn from. --}}
    <div class="page-hero mt-3 mb-5 flex items-center gap-4 !px-4 sm:!px-6 !py-5 sm:!py-6">
        <span class="hidden sm:flex w-14 h-14 rounded-xl bg-gold-subtle border border-line-gold items-center justify-center text-gold shrink-0" aria-hidden="true">
            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4h8v5a4 4 0 0 1-8 0zM8 6H5a3 3 0 0 0 3 3M16 6h3a3 3 0 0 1-3 3M12 13v4M9 20h6"/></svg>
        </span>
        <div class="min-w-0">
            <h1 class="font-display text-3xl sm:text-4xl text-ink leading-tight">3v3 Leaderboards</h1>
            <p class="text-[13px] text-ink-muted mt-1">This season's comps and games from real 3v3 games uploaded to MindCollector, and Blizzard's 3v3 ladder.</p>
            <p class="text-[12px] text-ink-subtle mt-0.5 tabular-nums">Since {{ $start->format('j M') }} &middot; {{ number_format($board['games']) }} games</p>
        </div>
    </div>

    <div class="flex gap-2 mb-5">
        <button type="button" wire:click="setTab('comps')" class="tab-btn {{ $tabNow === 'comps' ? 'tab-active' : 'tab-inactive' }}">Comps</button>
        <button type="button" wire:click="setTab('games')" class="tab-btn {{ $tabNow === 'games' ? 'tab-active' : 'tab-inactive' }}">Games</button>
        <button type="button" wire:click="setTab('players')" class="tab-btn {{ $tabNow === 'players' ? 'tab-active' : 'tab-inactive' }}">Players</button>
    </div>

    <div wire:loading.class="opacity-60" wire:target="setTab,setExp,setSpec,setRegion,toggleSeen,showMorePlayers,showMoreGames">
    @if ($tabNow === 'comps')
    <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_300px] lg:gap-5">
    <div class="min-w-0">
        {{-- The filters: the team's experience (its players' best 3v3 rating ever, averaged) and a spec in it. --}}
        <div class="linear-card px-4 py-3 mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[11px] uppercase tracking-wider text-ink-subtle mr-1">Experience</span>
                <button type="button" wire:click="setExp(null)" class="chip {{ $board['minExp'] === null ? 'chip-on' : '' }}">Everyone</button>
                @foreach ($bands as $min => $label)
                    <button type="button" wire:click="setExp({{ $min }})" class="chip {{ $board['minExp'] === $min ? 'chip-on' : '' }}" title="{{ $label }}">{{ $min }}+</button>
                @endforeach
            </div>
            <label class="flex items-center gap-2">
                <span class="text-[11px] uppercase tracking-wider text-ink-subtle">Spec</span>
                <select class="form-select !py-1 !text-[12.5px] max-w-[220px]" wire:change="setSpec($event.target.value)">
                    <option value="">Every spec</option>
                    @foreach (collect($specsSeen)->groupBy('class') as $class => $specs)
                        <optgroup label="{{ \App\Http\Services\CompLeaderboardService::className((string) $class) }}">
                            @foreach ($specs as $s)
                                <option value="{{ $s['id'] }}" @selected(($board['spec'] ?? null) === $s['id'])>{{ $s['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
        </div>
        <p class="text-[12px] text-ink-subtle mb-4">
            @if ($board['minExp'])
                Teams whose players' best 3v3 rating ever averages {{ $board['minExp'] }} or more.
            @else
                Experience is a team's players' best 3v3 rating ever, averaged.
            @endif
            Known for {{ number_format($board['known']) }} of {{ number_format($board['teams']) }} teams, where Blizzard has a profile; the others are left out of a filter.
            Both teams of every game count. Open a comp for its games, or see every game in the Games tab.
        </p>

        <div class="linear-card p-0 overflow-hidden">
            @if ($comps === [])
                <p class="p-5 text-[13px] text-ink-subtle">
                    {{ $board['minExp'] || ($board['spec'] ?? null) ? 'No team with these filters in the games uploaded this season.' : 'No 3v3 games uploaded yet this season.' }}
                </p>
            @else
                <table class="w-full text-[13px] tabular-nums">
                    <thead class="text-[10.5px] uppercase tracking-wider text-ink-subtle">
                        <tr class="border-b border-line">
                            <th class="text-left font-normal py-2.5 pl-4 pr-2 w-10 hidden sm:table-cell">#</th>
                            <th class="text-left font-normal py-2.5 pl-3 sm:pl-0 pr-2">Comp</th>
                            <th class="text-right font-normal py-2.5 pr-2 sm:pr-3">Games</th>
                            <th class="text-right font-normal py-2.5 pr-3 hidden md:table-cell">Won&ndash;lost</th>
                            <th class="text-right font-normal py-2.5 pr-2 sm:pr-3">Win rate</th>
                            <th class="text-right font-normal py-2.5 pr-3 hidden sm:table-cell" title="The teams' average matchmaking rating in these games">MMR</th>
                            <th class="text-right font-normal py-2.5 pr-3 sm:pr-4" title="The players' best 3v3 rating ever, averaged, where Blizzard has a profile">Experience</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($comps as $i => $c)
                            @php $b = $band($c['exp']); @endphp
                            <tr class="cursor-pointer hover:bg-surface-2" onclick="if (!event.target.closest('a')) this.querySelector('a').click()">
                                <td class="py-3 pl-4 pr-2 align-middle hidden sm:table-cell">
                                    <span class="inline-flex w-7 h-7 items-center justify-center rounded-full text-[12px] font-semibold {{ $i < 3 ? 'border border-line-gold text-gold bg-gold-subtle' : 'text-ink-subtle' }}">{{ $i + 1 }}</span>
                                </td>
                                <td class="py-3 pl-3 sm:pl-0 pr-2">
                                    <a href="{{ route('comps.played', ['key' => $c['key']]) }}" wire:navigate class="flex items-center gap-3">
                                        <span class="flex gap-0.5 shrink-0">
                                            @forelse ($c['lineups'][0]['ids'] ?? [] as $id)
                                                @if ($specModels[$id] ?? null) <x-spec-icon :spec="$specModels[$id]" size="w-6 h-6 sm:w-7 sm:h-7"/> @endif
                                            @empty
                                                @foreach ($c['classes'] as $slug)
                                                    @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-7 h-7"/> @endif
                                                @endforeach
                                            @endforelse
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block font-semibold text-ink leading-tight">{{ $name($c) }}</span>
                                            @if ($c['lineups'][0] ?? null)
                                                {{-- On a phone the icons say it: the names made the row too wide for its numbers. --}}
                                                <span class="hidden sm:block text-[11.5px] leading-tight mt-0.5">
                                                    @foreach ($c['lineups'][0]['specs'] as $s)
                                                        <span style="color: {{ $colors[$s['class']] ?? '#B3B3B3' }}">{{ $s['label'] }}</span>@if (! $loop->last)<span class="text-ink-subtle"> &middot; </span>@endif
                                                    @endforeach
                                                </span>
                                            @endif
                                        </span>
                                    </a>
                                    {{-- The best-rated ladder players seen on it: outside the comp's link, so each opens its own page. --}}
                                    @if ($c['top'] ?? [])
                                        <p class="hidden sm:block text-[11.5px] text-ink-subtle leading-tight mt-1 sm:ml-[100px]">
                                            Played by
                                            @foreach ($c['top'] as $p)
                                                <a href="{{ $p['url'] }}" wire:navigate class="hover:underline" style="color: {{ $colors[$p['class']] ?? '#B3B3B3' }}">{{ $p['name'] }}</a>
                                                <span class="tabular-nums">{{ $p['rating'] }}</span>@if (! $loop->last), @endif
                                            @endforeach
                                        </p>
                                    @endif
                                </td>
                                <td class="py-3 pr-2 sm:pr-3 text-right text-ink">{{ $c['games'] }}</td>
                                <td class="py-3 pr-3 text-right text-ink-muted hidden md:table-cell">{{ $c['won'] }}&ndash;{{ $c['lost'] }}</td>
                                <td class="py-3 pr-2 sm:pr-3 text-right font-semibold {{ $rateClass($c['rate']) }}">{{ $c['rate'] !== null ? $c['rate'].'%' : '-' }}</td>
                                <td class="py-3 pr-3 text-right text-ink-muted hidden sm:table-cell">{{ $c['mmr'] ?? '-' }}</td>
                                <td class="py-3 pr-3 sm:pr-4 text-right">
                                    @if ($b)
                                        <span class="{{ $b[1] }} !text-[10.5px] font-semibold">{{ $b[0] }}</span>
                                        <span class="block text-[11px] text-ink-subtle mt-0.5">{{ $c['exp'] }}</span>
                                    @else
                                        <span class="text-ink-subtle">-</span>
                                    @endif
                                </td>
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

    {{-- The side column (2026-10-29): what is new, who plays, and the way to add your own games. Below the
         board on a phone. --}}
    <aside class="mt-6 lg:mt-0 space-y-4">
        <div class="linear-card p-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-[11.5px] font-bold uppercase tracking-[.12em] text-ink-muted">Newest games</h2>
                <button type="button" wire:click="setTab('games')" class="text-[12px] text-gold hover:text-gold-light">Every game &rarr;</button>
            </div>
            @forelse ($newest as $gm)
                <a href="{{ route('comps.game', ['round' => $gm['round']]) }}" wire:navigate class="flex items-center gap-2 -mx-2 px-2 py-1.5 rounded-md hover:bg-surface-2">
                    <span class="flex-1 min-w-0">
                        @foreach (['us', 'them'] as $side)
                            @php $t = $gm[$side]; @endphp
                            <span class="flex items-center gap-1.5 min-w-0">
                                <span class="flex -space-x-1 shrink-0">
                                    @foreach ($t['classes'] as $slug)
                                        @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-4 h-4"/> @endif
                                    @endforeach
                                </span>
                                <span class="truncate text-[12px] {{ $t['won'] ? 'text-ink font-semibold' : 'text-ink-muted' }}">{{ $name($t) }}</span>
                            </span>
                        @endforeach
                    </span>
                    <span class="shrink-0 text-[11px] text-ink-subtle tabular-nums">{{ $gm['at']?->format('j M') }}</span>
                </a>
            @empty
                <p class="text-[12px] text-ink-subtle">No games yet this season.</p>
            @endforelse
        </div>

        @if ($topSeen)
            <div class="linear-card p-4">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-[11.5px] font-bold uppercase tracking-[.12em] text-ink-muted">Top players seen</h2>
                    <button type="button" wire:click="setTab('players')" class="text-[12px] text-gold hover:text-gold-light">The ladder &rarr;</button>
                </div>
                <p class="text-[11.5px] text-ink-subtle mb-2">The highest on Blizzard's US 3v3 ladder who are in an uploaded game.</p>
                @foreach ($topSeen as $p)
                    <a href="{{ $p['url'] }}" wire:navigate class="flex items-center gap-2 -mx-2 px-2 py-1.5 rounded-md hover:bg-surface-2">
                        <span class="w-6 text-[11px] text-ink-subtle tabular-nums">{{ $p['rank'] }}</span>
                        <span class="flex-1 min-w-0 truncate text-[12.5px] font-semibold" style="color: {{ $colors[$p['class']] ?? '#FFFFFF' }}">{{ $p['name'] }}</span>
                        <span class="shrink-0 text-[12px] text-ink tabular-nums">{{ $p['rating'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="linear-card p-4 border border-line-gold bg-gold-subtle">
            <h2 class="font-display text-lg text-ink leading-tight">Add your own games</h2>
            <p class="text-[12.5px] text-ink-muted mt-1 mb-3">The app reads your combat log after each game: how every death happened, your buttons and where you stand against your spec.</p>
            <a href="{{ route('app') }}" wire:navigate class="btn-primary text-[12.5px]">Get the app</a>
        </div>
    </aside>
    </div>
    @elseif ($tabNow === 'games')
        <p class="text-[12px] text-ink-subtle mb-4">
            Every 3v3 game uploaded to MindCollector this season, newest first. Open one for both teams, how each death happened
            and everyone's damage and healing. No player is named; the first team is the one that uploaded it.
        </p>

        <div class="linear-card p-0 overflow-hidden">
            @if ($games['games'] === [])
                <p class="p-5 text-[13px] text-ink-subtle">No 3v3 games uploaded yet this season.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($games['games'] as $gm)
                        <li>
                            <a href="{{ route('comps.game', ['round' => $gm['round']]) }}" wire:navigate
                               class="flex items-center gap-3 px-3 sm:px-4 py-2.5 hover:bg-surface-2 transition-colors">
                                <span class="w-14 sm:w-20 shrink-0 text-[11.5px] leading-tight text-ink-subtle tabular-nums">
                                    {{ $gm['at']?->format('D j M') }}<span class="block">{{ $gm['at']?->format('H:i') }}</span>
                                </span>
                                <span class="flex-1 min-w-0 space-y-1">
                                    @foreach (['us', 'them'] as $side)
                                        @php $t = $gm[$side]; @endphp
                                        <span class="flex items-center gap-2 min-w-0">
                                            <span class="flex -space-x-1 shrink-0">
                                                @foreach ($t['classes'] as $slug)
                                                    @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-5 h-5"/> @endif
                                                @endforeach
                                            </span>
                                            <span class="truncate text-[13px] {{ $t['won'] ? 'text-ink font-semibold' : 'text-ink-muted' }}">{{ $name($t) }}</span>
                                            @if ($t['won'])
                                                <span class="shrink-0 rounded-full bg-green-500/15 text-green-300 text-[10.5px] font-bold px-2 py-px">WON</span>
                                            @endif
                                            @if ($t['mmr'])
                                                <span class="ml-auto shrink-0 text-[12px] text-ink-subtle tabular-nums">{{ $t['mmr'] }}</span>
                                            @endif
                                        </span>
                                    @endforeach
                                </span>
                                <span class="shrink-0 text-ink-subtle" aria-hidden="true">&rsaquo;</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if (count($games['games']) < $games['total'] && count($games['games']) < \App\Livewire\Leaderboards::GAMES_MAX)
            <button type="button" wire:click="showMoreGames" class="btn-ghost mt-3 text-[12.5px]">Show more games ({{ number_format($games['total'] - count($games['games'])) }} more)</button>
        @endif
    @else
        <div class="flex flex-wrap items-center gap-2 mb-2">
            @foreach ($regions as $r)
                <button type="button" wire:click="setRegion('{{ $r }}')" class="chip {{ $regionNow === $r ? 'chip-on' : '' }}">{{ strtoupper($r) }}</button>
            @endforeach
            <span class="w-px h-5 bg-line mx-1"></span>
            <button type="button" wire:click="toggleSeen" class="chip {{ $seenOnly ? 'chip-on' : '' }}">Seen in uploaded games</button>
        </div>
        <p class="text-[12px] text-ink-subtle mb-4">
            @if ($ladder['season'])
                Blizzard's {{ strtoupper($regionNow) }} 3v3 ladder this season, as of {{ $ladder['asOf']?->diffForHumans() }}.
                "Seen on" is the comps a player played in the games uploaded to MindCollector this season: Blizzard's ladder names no team.
                {{ number_format($ladder['seen']) }} of its {{ number_format($ladder['total']) }} players are in an uploaded game.
            @endif
        </p>

        <div class="linear-card p-0 overflow-hidden">
            @if (! $ladder['season'])
                <p class="p-5 text-[13px] text-ink-subtle">Blizzard's {{ strtoupper($regionNow) }} 3v3 ladder has not been pulled yet.</p>
            @elseif ($ladder['players'] === [])
                <p class="p-5 text-[13px] text-ink-subtle">No player on the {{ strtoupper($regionNow) }} ladder is in an uploaded game yet.</p>
            @else
                <table class="w-full text-[13px] tabular-nums">
                    <thead class="text-[10.5px] uppercase tracking-wider text-ink-subtle">
                        <tr class="border-b border-line">
                            <th class="text-left font-normal py-2 pl-3 sm:pl-4 pr-2 w-10">#</th>
                            <th class="text-left font-normal py-2 pr-2">Player</th>
                            <th class="text-left font-normal py-2 pr-3 hidden sm:table-cell">Seen on</th>
                            <th class="text-right font-normal py-2 pr-3 hidden sm:table-cell">Won&ndash;lost</th>
                            <th class="text-right font-normal py-2 pr-3 sm:pr-4">Rating</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($ladder['players'] as $p)
                            @php
                                // The comps a player was seen on, each a link to that comp's card.
                                $seenOn = collect($p['comps'])->map(fn ($c) => '<a href="'.e(route('comps.played', ['key' => $c['key']])).'" wire:navigate class="inline-flex items-center gap-1 mr-2 hover:underline">'
                                    .'<span class="text-ink">'.e($name($c)).'</span><span class="text-ink-subtle">('.$c['games'].')</span></a>')->implode('');
                            @endphp
                            <tr class="hover:bg-surface-2">
                                <td class="py-2 pl-3 sm:pl-4 pr-2 text-ink-subtle align-top">{{ number_format($p['rank']) }}</td>
                                <td class="py-2 pr-2">
                                    <a href="{{ $p['url'] }}" wire:navigate class="font-semibold hover:underline" style="color: {{ $colors[$p['class']] ?? '#FFFFFF' }}">{{ $p['name'] }}</a>
                                    <span class="text-[11.5px] text-ink-subtle">{{ $p['realm'] }}</span>
                                    @if ($seenOn !== '')
                                        <span class="block sm:hidden text-[11.5px] mt-0.5">{!! $seenOn !!}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 text-[12px] hidden sm:table-cell">{!! $seenOn !== '' ? $seenOn : '<span class="text-ink-subtle">-</span>' !!}</td>
                                <td class="py-2 pr-3 text-right text-ink-muted hidden sm:table-cell">{{ $p['won'] }}&ndash;{{ $p['lost'] }}</td>
                                <td class="py-2 pr-3 sm:pr-4 text-right font-semibold text-ink">{{ $p['rating'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($ladder['season'] && count($ladder['players']) >= $ladderShown && $ladderShown < \App\Livewire\Leaderboards::LADDER_MAX)
            <button type="button" wire:click="showMorePlayers" class="btn-ghost mt-3 text-[12.5px]">Show more players</button>
        @endif
    @endif
    </div>
</div>
