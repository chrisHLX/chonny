<div class="max-w-[1800px] mx-auto px-4 py-8" @if ($needsFetch) wire:init="load" @endif>
    <div class="max-w-4xl">
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

            @if ($this->history !== [])
                <div class="linear-card p-5 mb-6">
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-1">Rating this season</h2>
                    <p class="text-[12px] text-ink-subtle mb-3">Each rating we have seen, from the first time this character was looked up here.</p>
                    <div class="space-y-2">
                        @foreach ($this->history as $bracket)
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="text-[11px] uppercase tracking-wider text-ink-subtle w-full sm:w-44 shrink-0">{{ $bracket['label'] }}</span>
                                @foreach ($bracket['points'] as $i => $p)
                                    @if ($i > 0) <span class="text-ink-subtle text-[11px]">&rarr;</span> @endif
                                    <span class="text-[13px] font-semibold text-ink tabular-nums" title="{{ $p['at']->format('j M Y, H:i') }} UTC">{{ $p['rating'] }}</span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="linear-card p-5 mb-6">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-4">Gear</h2>
                <x-battlenet.gear :equipment="$character->equipment ?? []"/>
            </div>
        @else
            <div class="linear-card p-8 mt-3 mb-6 text-center">
                <h1 class="font-display text-2xl text-ink mb-2">{{ $name }}<span class="text-ink-subtle">-{{ $realmName }}</span></h1>
                @if ($error)
                    <p class="text-[13.5px] text-red-300/90 mb-4">{{ $error }}</p>
                    <x-battlenet.refresh-button target="refresh" action="refresh" class="btn-secondary"/>
                @elseif ($notFound)
                    <p class="text-[13.5px] text-ink-muted max-w-md mx-auto">
                        Blizzard has no public profile for a character by this name on {{ $realmName }} ({{ strtoupper($region) }}).
                        Check the spelling and the realm. A character that has not logged in for a long time, or has just
                        moved realm, also has none.
                    </p>
                    <a href="{{ route('players.search') }}" wire:navigate class="btn-secondary inline-flex mt-4">Search again</a>
                @else
                    <p class="text-[13.5px] text-gold">Fetching from Blizzard&hellip;</p>
                    <noscript><p class="text-[12px] text-ink-subtle mt-2">The lookup needs JavaScript.</p></noscript>
                @endif
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
