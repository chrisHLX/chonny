<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.13em] text-gold mb-1">World of Warcraft</p>
        <h1 class="font-display text-3xl text-ink">Player lookup</h1>
        <p class="text-[13.5px] text-ink-muted mt-1">
            Any character's arena and Solo Shuffle ratings, highest rating ever, titles, gear and talents, from Blizzard.
        </p>
    </div>

    <form wire:submit="search" class="linear-card p-5 mb-8">
        <div class="grid grid-cols-1 sm:grid-cols-[110px_1fr_1fr_auto] gap-3 items-start">
            <div>
                <label for="lookup-region" class="block text-[11px] uppercase tracking-wider text-ink-subtle mb-1">Region</label>
                <select id="lookup-region" wire:model.live="region" class="form-select w-full">
                    @foreach ($regions as $r)
                        <option value="{{ $r }}">{{ strtoupper($r) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lookup-name" class="block text-[11px] uppercase tracking-wider text-ink-subtle mb-1">Character</label>
                <input id="lookup-name" type="text" wire:model="name" class="form-input w-full" autocomplete="off"
                       placeholder="Name, or Name-Realm" autofocus>
                @error('name') <p class="text-[12px] text-red-300 mt-1">{{ $message }}</p> @enderror
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
