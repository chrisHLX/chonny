<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.13em] text-gold mb-1">World of Warcraft</p>
        <h1 class="font-display text-3xl text-ink">Player lookup</h1>
        <p class="text-[13.5px] text-ink-muted mt-1">
            Any character's arena and Solo Shuffle ratings, highest rating ever, titles, gear and talents, from Blizzard.
        </p>
    </div>

    <form wire:submit="search" class="linear-card p-5 mb-8">
        <div class="grid grid-cols-1 sm:grid-cols-[auto_1fr_1fr_auto] gap-3 items-start">
            <div>
                <span class="block text-[11px] uppercase tracking-wider text-ink-subtle mb-1">Region</span>
                {{-- Pills, as on the Leaderboards (2026-10-30): two choices need no dropdown. --}}
                <div class="inline-flex p-1 rounded-full bg-black/40 border border-line" role="radiogroup" aria-label="Region">
                    @foreach ($regions as $r)
                        <button type="button" wire:click="$set('region', '{{ $r }}')" role="radio" aria-checked="{{ $region === $r ? 'true' : 'false' }}"
                                class="px-3 py-1 rounded-full text-[12.5px] font-semibold transition-colors {{ $region === $r ? 'bg-ink text-black' : 'text-ink-muted hover:text-ink' }}">{{ strtoupper($r) }}</button>
                    @endforeach
                </div>
            </div>
            {{-- Suggestions as you type (Search::suggestions()). Arrow keys move, Enter opens the
                 highlighted one, or looks up what is typed when none is. --}}
            <div class="relative" x-data="{ open: false, i: -1 }" @click.outside="open = false">
                <label for="lookup-name" class="block text-[11px] uppercase tracking-wider text-ink-subtle mb-1">Character</label>
                <input id="lookup-name" type="text" wire:model.live.debounce.250ms="name" class="form-input w-full" autocomplete="off"
                       placeholder="Name, or Name-Realm" autofocus
                       @focus="open = true" @input="open = true; i = -1"
                       @keydown.arrow-down.prevent="open = true; i = Math.min(i + 1, $refs.list ? $refs.list.children.length - 1 : -1)"
                       @keydown.arrow-up.prevent="i = Math.max(i - 1, -1)"
                       @keydown.escape="open = false"
                       @keydown.enter="if (open && i >= 0 && $refs.list) { $event.preventDefault(); $refs.list.children[i].querySelector('a').click() }">
                @error('name') <p class="text-[12px] text-red-300 mt-1">{{ $message }}</p> @enderror

                @if ($suggestions !== [])
                    <ul x-ref="list" x-show="open" x-cloak
                        class="absolute z-30 left-0 right-0 sm:right-auto sm:w-[32rem] mt-1 bg-surface-3 rounded-lg shadow-lg py-1 max-h-80 overflow-y-auto">
                        @foreach ($suggestions as $k => $s)
                            <li>
                                <a href="{{ $s['url'] }}" wire:navigate
                                   class="flex items-baseline gap-2 px-3 py-1.5 text-[13px] hover:bg-surface-2"
                                   :class="i === {{ $k }} && 'bg-surface-2'" @mouseenter="i = {{ $k }}">
                                    <span class="font-semibold" style="color: {{ $classColors[$s['class']] ?? '#E5E5E5' }}">{{ $s['name'] }}</span>
                                    <span class="text-ink-muted truncate">{{ $s['realm'] }}</span>
                                    <span class="text-[10.5px] uppercase text-ink-subtle">{{ $s['region'] }}</span>
                                    <span class="ml-auto text-[11.5px] text-ink-subtle whitespace-nowrap">{{ $s['detail'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <label for="lookup-realm" class="block text-[11px] uppercase tracking-wider text-ink-subtle mb-1">Realm</label>
                <input id="lookup-realm" type="text" wire:model="realm" list="lookup-realms" class="form-input w-full" autocomplete="off"
                       placeholder="Realm">
                <datalist id="lookup-realms">
                    @foreach ($realms as $realmName)
                        <option value="{{ $realmName }}"></option>
                    @endforeach
                </datalist>
                @error('realm') <p class="text-[12px] text-red-300 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:pt-[22px]">
                <button type="submit" class="btn-primary w-full sm:w-auto" wire:loading.attr="disabled" wire:target="search">Look up</button>
            </div>
        </div>
        <p class="text-[12px] text-ink-subtle mt-3">You can also paste a Check-PvP or Armory link into the character box.</p>
    </form>

    @if ($recent->isNotEmpty())
        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">Recently looked up</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            @foreach ($recent as $c)
                @php
                    $color = config('wow_classes.colors')[$c->gameClass?->slug] ?? '#8A8A9A';
                    $best = $c->bestExp();
                    $top = $c->currentRatings()[0] ?? null;
                @endphp
                <a href="{{ route('players.show', ['region' => $c->region, 'realm' => $c->realm_slug, 'name' => mb_strtolower($c->name)]) }}" wire:navigate
                   class="linear-card px-3 py-2.5 flex items-center gap-3 hover:bg-surface-2 transition-colors">
                    @if ($c->specialization)
                        <x-spec-icon :spec="$c->specialization" size="w-8 h-8"/>
                    @elseif ($c->gameClass)
                        <x-class-icon :class="$c->gameClass" size="w-8 h-8"/>
                    @endif
                    <span class="min-w-0 flex-1">
                        <span class="block text-[13.5px] font-semibold truncate" style="color: {{ $color }}">{{ $c->name }}<span class="text-ink-subtle font-normal">-{{ $c->realm_name }}</span></span>
                        <span class="block text-[11.5px] text-ink-subtle truncate">
                            {{ strtoupper($c->region) }}
                            @if ($best) &middot; {{ $best['rating'] }} {{ $best['bracket'] }} exp @endif
                            @if ($top) &middot; {{ $top['label'] }} {{ $top['rating'] }} @endif
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
