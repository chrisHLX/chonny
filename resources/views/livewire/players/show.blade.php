@php
    $short = fn ($n) => $n >= 1000000 ? number_format($n / 1000000, 1).'M' : ($n >= 1000 ? round($n / 1000).'k' : (string) $n);
    $mmss = fn ($s) => intdiv((int) $s, 60).':'.str_pad((string) ((int) $s % 60), 2, '0', STR_PAD_LEFT);
    $color = fn ($slug) => $classColors[$slug] ?? '#8A8A9A';
    $headClass = $character?->gameClass?->slug ?? $board->first()?->class_slug ?? ($games['games'][0]['me']['class'] ?? null);
@endphp
<div class="max-w-[1800px] mx-auto px-4 py-8" @if ($needsFetch) wire:init="load" @endif>
    <div class="max-w-5xl">
        <a href="{{ route('players.search') }}" wire:navigate
           class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; Player lookup</a>

        @if ($character?->synced_at)
            <h1 class="sr-only">{{ $character->name }}-{{ $realmName }} PvP profile</h1>
            <div class="linear-card p-5 mt-3 mb-6 transition-opacity" wire:loading.class="opacity-60" wire:target="refresh,load">
                <div class="flex items-start gap-4">
                    <x-battlenet.character-summary :character="$character" class="flex-1 min-w-0"/>

                    <div class="flex flex-col items-end gap-1 shrink-0 text-right">
                        <x-battlenet.refresh-button target="refresh" action="refresh" class="btn-ghost"/>
                        <span class="text-[11px] text-ink-subtle" wire:loading.remove wire:target="refresh,load">
                            updated {{ $character->synced_at->diffForHumans() }}
                        </span>
                        <span class="text-[11px] text-gold" wire:loading wire:target="refresh,load">Fetching from Blizzard&hellip;</span>
                    </div>
                </div>

                @if ($character->arenas_played)
                    <p class="text-[12px] text-ink-subtle mt-3 tabular-nums">
                        {{ number_format($character->arenas_played) }} rated arenas played lifetime,
                        {{ number_format($character->arenas_won ?? 0) }} won
                        ({{ round(($character->arenas_won ?? 0) / max(1, $character->arenas_played) * 100) }}%).
                    </p>
                @endif

                @if ($error)
                    <p class="text-[12px] text-red-300/90 mt-2">{{ $error }}</p>
                @elseif ($character->sync_error)
                    <p class="text-[12px] text-red-300/90 mt-2">Blizzard has no public profile for this character right now. It shows again once they log in.</p>
                @endif
            </div>
        @else
            <div class="linear-card p-6 mt-3 mb-6 {{ $board->isEmpty() && $games['total'] === 0 ? 'text-center' : '' }}">
                <h1 class="font-display text-2xl mb-2" style="color: {{ $color($headClass) }}">{{ $name }}<span class="text-ink-subtle">-{{ $realmName }}</span></h1>
                @if ($error)
                    <p class="text-[13.5px] text-red-300/90 mb-4">{{ $error }}</p>
                    <x-battlenet.refresh-button target="refresh" action="refresh" class="btn-secondary"/>
                @elseif ($notFound)
                    <p class="text-[13.5px] text-ink-muted max-w-xl {{ $board->isEmpty() && $games['total'] === 0 ? 'mx-auto' : '' }}">
                        Blizzard has no public profile for a character by this name on {{ $realmName }} ({{ strtoupper($region) }}) right now.
                        @if ($board->isEmpty() && $games['total'] === 0)
                            Check the spelling and the realm. A character that has not logged in for a long time, or has just
                            moved realm, also has none.
                        @else
                            What we have seen of them is below.
                        @endif
                    </p>
                    @if ($board->isEmpty() && $games['total'] === 0)
                        <a href="{{ route('players.search') }}" wire:navigate class="btn-secondary inline-flex mt-4">Search again</a>
                    @endif
                @else
                    <p class="text-[13px] text-gold">Fetching their profile from Blizzard&hellip;</p>
                    <noscript><p class="text-[12px] text-ink-subtle mt-2">The full profile needs JavaScript.</p></noscript>
                @endif
            </div>
        @endif

        @if ($board->isNotEmpty())
            <div class="linear-card p-5 mb-6">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-1">On the leaderboards</h2>
                <p class="text-[12px] text-ink-subtle mb-3">Blizzard's {{ strtoupper($region) }} leaderboards this season, as of {{ $board->max('seen_at')?->diffForHumans() }}.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                    @foreach ($board as $e)
                        <div class="flex items-baseline gap-2 text-[13px]">
                            <span class="text-ink-muted flex-1 min-w-0 truncate">{{ $e->label }}</span>
                            <span class="text-ink-subtle tabular-nums">#{{ number_format($e->rank) }}</span>
                            <span class="font-semibold text-ink tabular-nums w-12 text-right">{{ $e->rating }}</span>
                            <span class="text-[11.5px] text-ink-subtle tabular-nums w-16 text-right" title="Won&ndash;lost this season">{{ $e->won }}&ndash;{{ $e->lost }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($this->history !== [])
            <div class="linear-card p-5 mb-6">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-1">Rating this season</h2>
                <p class="text-[12px] text-ink-subtle mb-3">Each rating we have seen, from the leaderboards each day and from lookups here.</p>
                <div class="space-y-2">
                    @foreach ($this->history as $bracket)
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span class="text-[11px] uppercase tracking-wider text-ink-subtle w-full sm:w-56 shrink-0">{{ $bracket['label'] }}</span>
                            @foreach (array_slice($bracket['points'], -30) as $i => $p)
                                @if ($i > 0) <span class="text-ink-subtle text-[11px]">&rarr;</span> @endif
                                <span class="text-[13px] font-semibold text-ink tabular-nums" title="{{ $p['at']->format('j M Y') }}">{{ $p['rating'] }}</span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($games['total'] > 0)
            <div class="linear-card p-5 mb-6">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-1">Games we have seen</h2>
                <p class="text-[12.5px] text-ink-muted mb-3">
                    In {{ number_format($games['total']) }} {{ \Illuminate\Support\Str::plural('game', $games['total']) }} uploaded to MindCollector
                    ({{ $games['won'] }}&ndash;{{ $games['lost'] }}),
                    from {{ $games['first']?->format('j M') }} to {{ $games['last']?->format('j M Y') }}.
                    @foreach ($games['specs'] as $spec => $n)
                        <span class="text-ink">{{ $spec }}</span> {{ $n }}@if (! $loop->last), @endif
                    @endforeach
                </p>

                @if ($games['partners'] !== [])
                    <p class="text-[11px] uppercase tracking-wider text-ink-subtle mb-1.5">Plays 2v2 and 3v3 with</p>
                    <div class="flex flex-wrap gap-1.5 mb-4">
                        @foreach ($games['partners'] as $p)
                            <a href="{{ $p['url'] }}" wire:navigate class="chip text-[12px]">
                                <span style="color: {{ $color($p['class']) }}">{{ $p['name'] }}</span>
                                <span class="text-ink-subtle tabular-nums">{{ $p['games'] }} &middot; {{ $p['won'] }}&ndash;{{ $p['games'] - $p['won'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="divide-y divide-line">
                    @foreach ($games['games'] as $g)
                        <div x-data="{ open: false }" class="py-2.5">
                            <div class="flex items-center gap-2">
                            <button type="button" @click="open = !open" class="flex-1 min-w-0 text-left flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="w-8 text-[12px] font-semibold {{ $g['won'] === true ? 'text-green-400' : ($g['won'] === false ? 'text-red-300' : 'text-ink-subtle') }}">
                                    {{ $g['won'] === true ? 'Won' : ($g['won'] === false ? 'Lost' : '?') }}
                                </span>
                                <span class="text-[12px] text-ink-subtle w-28 tabular-nums" title="The uploader's clock">{{ $g['playedAt']?->format('j M, H:i') }}</span>
                                <span class="text-[12px] text-ink-muted w-20">{{ $g['bracket'] }} &middot; {{ $mmss($g['seconds']) }}</span>
                                <span class="flex-1 min-w-0 text-[12.5px] truncate">
                                    @foreach ($g['team'] as $p)
                                        <span style="color: {{ $color($p['class']) }}" class="{{ ($p['isMe'] ?? false) ? 'font-semibold' : '' }}">{{ $p['name'] }}</span>@if (! $loop->last)<span class="text-ink-subtle">, </span>@endif
                                    @endforeach
                                    <span class="text-ink-subtle"> vs </span>
                                    @foreach ($g['enemy'] as $p)
                                        <span style="color: {{ $color($p['class']) }}">{{ $p['name'] }}</span>@if (! $loop->last)<span class="text-ink-subtle">, </span>@endif
                                    @endforeach
                                </span>
                                @if ($g['mmr'])
                                    <span class="text-[11.5px] text-ink-subtle tabular-nums" title="Team MMR against the other team's">{{ $g['mmr'] }}@if ($g['mmrThem']) v {{ $g['mmrThem'] }}@endif</span>
                                @endif
                                <svg class="w-3.5 h-3.5 text-ink-subtle transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            {{-- A 3v3 game of this season's board: its card and timeline, read from this player's team. --}}
                            @if ($g['card'] ?? null)
                                <a href="{{ $g['card'] }}" wire:navigate class="shrink-0 rounded-full border border-line-gold bg-gold-subtle text-gold-light hover:border-gold px-3 py-1 text-[12px] font-semibold">Open game &rarr;</a>
                            @endif
                            </div>

                            <div x-show="open" x-cloak class="mt-2 overflow-x-auto">
                                <table class="w-full text-[12px] tabular-nums min-w-[640px]">
                                    <thead class="text-ink-subtle text-[10.5px] uppercase tracking-wider">
                                        <tr>
                                            <th class="text-left font-normal py-1 pr-3">Player</th>
                                            <th class="text-left font-normal py-1 pr-3">Spec</th>
                                            <th class="text-right font-normal py-1 pr-3">Damage</th>
                                            <th class="text-right font-normal py-1 pr-3">Healing</th>
                                            <th class="text-right font-normal py-1 pr-3">Taken</th>
                                            <th class="text-right font-normal py-1 pr-3">Deaths</th>
                                            <th class="text-right font-normal py-1 pr-3">Ilvl</th>
                                            <th class="text-left font-normal py-1">PvP talents</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ([['Their team', $g['team']], ['Against', $g['enemy']]] as [$label, $side])
                                            @foreach ($side as $p)
                                                <tr class="{{ $loop->first ? 'border-t border-line' : '' }}">
                                                    <td class="py-1 pr-3 whitespace-nowrap">
                                                        <a href="{{ $p['url'] }}" wire:navigate class="hover:underline {{ ($p['isMe'] ?? false) ? 'font-semibold' : '' }}" style="color: {{ $color($p['class']) }}">{{ $p['name'] }}</a><span class="text-ink-subtle">-{{ $p['realm'] }}</span>
                                                    </td>
                                                    <td class="py-1 pr-3 text-ink-muted whitespace-nowrap">{{ $p['spec'] }}</td>
                                                    <td class="py-1 pr-3 text-right text-ink">{{ $short($p['damage']) }}</td>
                                                    <td class="py-1 pr-3 text-right text-ink">{{ $short($p['healing']) }}</td>
                                                    <td class="py-1 pr-3 text-right text-ink-muted">{{ $short($p['taken']) }}</td>
                                                    <td class="py-1 pr-3 text-right text-ink-muted">{{ $p['deaths'] }}</td>
                                                    <td class="py-1 pr-3 text-right text-ink-muted">{{ $p['ilvl'] ?? '' }}</td>
                                                    <td class="py-1 text-ink-subtle">{{ implode(', ', $p['pvpTalents']) }}</td>
                                                </tr>
                                            @endforeach
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($games['total'] > count($games['games']))
                    <button type="button" wire:click="moreGames" class="btn-ghost mt-3 text-[12.5px]" wire:loading.attr="disabled" wire:target="moreGames">
                        Show more ({{ number_format($games['total'] - count($games['games'])) }} more)
                    </button>
                @endif
            </div>
        @endif

        @if ($character?->synced_at)
            <div class="linear-card p-5 mb-6">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-4">Gear</h2>
                <x-battlenet.gear :equipment="$character->equipment ?? []"/>
            </div>
        @endif
    </div>

    @if ($character?->synced_at && $character->talents)
        <div class="linear-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Talents</h2>

                @php $snapshots = collect($character->talents ?? []); @endphp
                @if ($snapshots->count() > 1)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($snapshots as $snap)
                            @php
                                $selected = $specExternalId === null ? ($snap['active'] ?? false) : $specExternalId === $snap['spec_external_id'];
                            @endphp
                            <button type="button" wire:click="selectSpec({{ $snap['spec_external_id'] }})"
                                    class="tab-btn {{ $selected ? 'tab-active' : 'tab-inactive' }}">
                                {{ $snap['spec_name'] }}@if ($snap['active'] ?? false) <span class="text-ink-subtle">&middot; active</span>@endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($talentsShown)
                <x-battlenet.talent-build :view="$this->talentView"
                                          :key="'player-'.$character->id.'-'.($this->talentView['spec']->id ?? 'none')"/>
            @else
                <div wire:init="showTalents">
                    @php $active = $character->activeTalents(); @endphp
                    <p class="text-[13px] text-ink-subtle">
                        @if ($active['hero_tree'] ?? null) Hero talents: <span class="text-ink">{{ $active['hero_tree'] }}</span>. @endif
                        Drawing the talent tree&hellip;
                    </p>
                </div>
            @endif
        </div>
    @endif
</div>
