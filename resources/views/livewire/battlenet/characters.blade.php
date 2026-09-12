<div class="max-w-4xl mx-auto px-4 py-8" @if ($this->isAwaitingSync) wire:poll.4s @endif>
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.13em] text-gold mb-1">Battle.net</p>
        <h1 class="font-display text-3xl text-ink">Your characters</h1>
        <p class="text-[13.5px] text-ink-muted mt-1">
            Exp, ratings, gear and talents, read straight from Blizzard. Sign a guide with one of these
            and readers see who wrote it.
        </p>
    </div>

    @if (session('battlenet_status'))
        <div class="linear-card border-line-gold p-3 mb-4 text-[13px] text-ink">{{ session('battlenet_status') }}</div>
    @endif
    @if (session('battlenet_error'))
        <div class="linear-card border-red-500/40 p-3 mb-4 text-[13px] text-red-300">{{ session('battlenet_error') }}</div>
    @endif

    @if (! $this->account)
        {{-- Not linked ------------------------------------------------------------ --}}
        <div class="linear-card p-8 text-center">
            <h2 class="font-display text-xl text-ink mb-2">Link your Battle.net account</h2>
            <p class="text-[13.5px] text-ink-muted max-w-md mx-auto mb-5">
                We read your character list, your arena exp and ratings, your gear and your talent builds.
                We never see your password, and we don't keep your Blizzard sign-in once the link is made.
            </p>
            @if ($configured)
                <a href="{{ route('battlenet.redirect') }}" class="btn-primary inline-flex">Link Battle.net</a>
            @else
                <p class="text-[12px] text-ink-subtle">Battle.net linking isn't configured on this server yet.</p>
            @endif
        </div>
    @else
        {{-- Account header --------------------------------------------------------- --}}
        @php
            $bestExp = $this->account->bestExp();
            $bestRank = $this->account->bestRankTitle();
        @endphp
        <div class="linear-card p-4 mb-6 flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex-1 min-w-0">
                <p class="text-[17px] text-ink font-semibold">{{ $this->account->battletag }}</p>
                <p class="text-[12px] text-ink-subtle mt-0.5">
                    {{ $this->account->characters->count() }} {{ Str::plural('character', $this->account->characters->count()) }}
                    @if ($this->account->characters_synced_at)
                        &middot; list updated {{ $this->account->characters_synced_at->diffForHumans() }}
                    @endif
                </p>
                @if ($bestExp || $bestRank)
                    <p class="text-[13px] text-ink-muted mt-2">
                        @if ($bestExp)
                            Account exp <span class="text-gold font-semibold tabular-nums">{{ $bestExp['rating'] }}</span>
                            <span class="text-ink-subtle">({{ $bestExp['bracket'] }}, {{ $bestExp['character']->name }})</span>
                        @endif
                        @if ($bestRank)
                            @if ($bestExp) &middot; @endif
                            best title <span class="text-ink">{{ $bestRank->pvp_rank_title }}</span>
                        @endif
                    </p>
                @endif
            </div>
            <div class="flex items-center gap-2 shrink-0">
                {{-- The list needs the player's own token, which Blizzard does not let us renew —
                     so refreshing it IS signing in again. Blizzard skips the consent screen
                     the second time. --}}
                <a href="{{ route('battlenet.redirect') }}" class="btn-ghost" title="Picks up new characters and transfers">Refresh list</a>
                <form method="POST" action="{{ route('battlenet.unlink') }}"
                      x-data x-on:submit="if (! confirm('Unlink Battle.net? Your characters are removed, and guides signed with one lose the signature (the guides themselves stay).')) $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-ghost hover:!text-red-400">Unlink</button>
                </form>
            </div>
        </div>

        @if ($this->isAwaitingSync)
            <p class="text-[12px] text-ink-subtle mb-3">
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-gold animate-pulse mr-1 align-middle"></span>
                Fetching details from Blizzard&hellip; this page updates on its own.
            </p>
        @endif

        {{-- Characters ---------------------------------------------------------------- --}}
        @forelse ($this->characters as $character)
            <div class="linear-card p-4 mb-3" wire:key="char-{{ $character->id }}">
                <div class="flex items-start gap-4">
                    <a href="{{ route('characters.show', $character->id) }}" wire:navigate class="flex-1 min-w-0 group">
                        <x-battlenet.character-summary :character="$character"/>
                    </a>

                    <div class="flex flex-col items-end gap-1 shrink-0 text-right">
                        <button type="button" wire:click="refresh({{ $character->id }})"
                                wire:loading.attr="disabled" wire:target="refresh({{ $character->id }})"
                                class="text-[12px] text-ink-subtle hover:text-gold transition-colors">
                            <span wire:loading.remove wire:target="refresh({{ $character->id }})">Refresh</span>
                            <span wire:loading wire:target="refresh({{ $character->id }})">Refreshing&hellip;</span>
                        </button>
                        @if ($character->synced_at)
                            <span class="text-[11px] text-ink-subtle">updated {{ $character->synced_at->diffForHumans() }}</span>
                        @elseif ($character->level >= (int) config('services.battlenet.detail_min_level', 70))
                            <span class="text-[11px] text-ink-subtle">waiting for details</span>
                        @endif
                    </div>
                </div>

                @if ($character->sync_error)
                    <p class="text-[12px] text-red-300/90 mt-2">{{ $character->sync_error }}</p>
                @endif
            </div>
        @empty
            <div class="linear-card p-8 text-center">
                <p class="text-[13.5px] text-ink-muted">
                    No characters at level {{ config('services.battlenet.detail_min_level', 70) }}+ on this account.
                </p>
            </div>
        @endforelse

        @if ($this->hiddenCount > 0)
            <button type="button" wire:click="$toggle('showLowLevel')"
                    class="text-[12px] text-ink-subtle hover:text-gold transition-colors mt-2">
                {{ $showLowLevel ? 'Hide' : 'Show' }} {{ $this->hiddenCount }} lower-level {{ Str::plural('character', $this->hiddenCount) }}
            </button>
        @endif
    @endif
</div>
