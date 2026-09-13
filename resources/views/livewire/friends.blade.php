@php
    $classColors = config('wow_classes.colors', []);

    // A friend's best-rated character, when they have linked Battle.net: the one fact that tells
    // you who they are in game. Null for a player who hasn't linked, who is shown by handle alone.
    $mainOf = fn ($user) => $user->battlenetCharacters
        ->sortByDesc(fn ($c) => $c->bestExp()['rating'] ?? 0)
        ->first();
@endphp

<div class="max-w-3xl mx-auto px-4 py-8">
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.13em] text-gold mb-1">Friends</p>
        <h1 class="font-display text-3xl text-ink">The players you queue with</h1>
        <p class="text-[13.5px] text-ink-muted mt-1">
            Friends can read what you share with them, and edit any guide where you've switched on
            <span class="text-ink">"My friends can edit"</span>.
        </p>
    </div>

    {{-- Your handle, and adding someone by theirs --}}
    <div class="linear-card p-4 mb-6">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-3" x-data="{ copied: false }">
            <p class="text-[13px] text-ink-muted">
                Your handle is
                <span class="font-mono text-gold">&#64;{{ auth()->user()->handle() }}</span>
                &mdash; send it to a friend so they can add you.
            </p>
            <button type="button" class="btn-ghost text-[12px]"
                    x-on:click="navigator.clipboard.writeText('{{ auth()->user()->handle() }}'); copied = true; setTimeout(() => copied = false, 1500)">
                <span x-show="!copied">Copy</span><span x-show="copied" x-cloak class="text-gold">Copied</span>
            </button>
        </div>

        <div class="flex flex-col sm:flex-row gap-2">
            <input type="text" wire:model="handle" maxlength="60" autocomplete="off"
                   placeholder="Their handle, e.g. crawlordx"
                   class="form-input flex-1 text-[13.5px]"
                   wire:keydown.enter="add">
            <button type="button" wire:click="add" class="btn-primary shrink-0">Add friend</button>
        </div>
        @if ($message)
            <p class="text-[12px] mt-2 {{ $messageIsError ? 'text-red-400' : 'text-green-400' }}">{{ $message }}</p>
        @endif
    </div>

    {{-- Requests waiting on you --}}
    @if ($this->incoming->isNotEmpty())
        <div class="mb-6">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">
                Requests <span class="badge-gold ml-1">{{ $this->incoming->count() }}</span>
            </h2>
            <div class="flex flex-col gap-2">
                @foreach ($this->incoming as $request)
                    <div class="linear-card p-3 flex items-center gap-3" wire:key="in-{{ $request->id }}">
                        <div class="w-8 h-8 rounded-full bg-violet/15 flex items-center justify-center text-[12px] font-semibold text-violet shrink-0">
                            {{ strtoupper(substr($request->requester?->handle() ?? '?', 0, 1)) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[14px] text-ink truncate">&#64;{{ $request->requester?->handle() }}</p>
                            <p class="text-[11.5px] text-ink-subtle">{{ $request->created_at->diffForHumans() }}</p>
                        </div>
                        <button type="button" wire:click="accept({{ $request->id }})" class="btn-primary shrink-0">Accept</button>
                        <button type="button" wire:click="decline({{ $request->id }})" class="btn-ghost shrink-0">Decline</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Friends --}}
    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">
        Friends @if ($this->friends->isNotEmpty())<span class="text-ink-subtle font-normal">&middot; {{ $this->friends->count() }}</span>@endif
    </h2>

    @forelse ($this->friends as $friend)
        @php $main = $mainOf($friend); $exp = $main?->bestExp(); @endphp
        <div class="linear-card p-3 mb-2 flex items-center gap-3" wire:key="friend-{{ $friend->id }}">
            @if ($main?->specialization)
                <x-spec-icon :spec="$main->specialization" size="w-8 h-8"/>
            @else
                <div class="w-8 h-8 rounded-full bg-gold/10 flex items-center justify-center text-[12px] font-semibold text-gold shrink-0">
                    {{ strtoupper(substr($friend->handle(), 0, 1)) }}
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[14px] text-ink truncate">&#64;{{ $friend->handle() }}</p>
                @if ($main)
                    <p class="text-[11.5px] truncate">
                        <span style="color: {{ $classColors[$main->gameClass?->slug] ?? '#8A8A9A' }}">{{ $main->fullName() }}</span>
                        @if ($exp) <span class="text-ink-subtle">&middot;</span> <span class="text-gold tabular-nums">{{ $exp['rating'] }}</span> <span class="text-ink-subtle">exp</span> @endif
                    </p>
                @endif
            </div>
            <button type="button" wire:click="remove({{ $friend->id }})"
                    wire:confirm="Remove &#64;{{ $friend->handle() }} as a friend? They'll lose edit access to any guide shared with friends."
                    class="text-[12px] text-ink-subtle hover:text-red-400 transition-colors shrink-0">Remove</button>
        </div>
    @empty
        <div class="linear-card p-6 text-center mb-2">
            <p class="text-[13.5px] text-ink-muted">No friends yet. Add someone by their handle above.</p>
        </div>
    @endforelse

    {{-- Requests you sent --}}
    @if ($this->outgoing->isNotEmpty())
        <div class="mt-6">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle font-semibold mb-2">Waiting on them</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->outgoing as $request)
                    <span class="inline-flex items-center gap-2 px-2.5 py-1 rounded border border-line bg-surface-2 text-[12px] text-ink-muted" wire:key="out-{{ $request->id }}">
                        &#64;{{ $request->addressee?->handle() }}
                        <button type="button" wire:click="decline({{ $request->id }})" title="Cancel request"
                                class="text-ink-subtle hover:text-red-400 transition-colors">&times;</button>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Guildmates --}}
    @if ($this->suggestions->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-1">From your guilds</h2>
            <p class="text-[12px] text-ink-subtle mb-3">People you already share guides with.</p>
            <div class="grid sm:grid-cols-2 gap-2">
                @foreach ($this->suggestions as $person)
                    <div class="linear-card p-3 flex items-center gap-3" wire:key="sug-{{ $person->id }}">
                        <div class="w-7 h-7 rounded-full bg-gold/10 flex items-center justify-center text-[11px] font-semibold text-gold shrink-0">
                            {{ strtoupper(substr($person->handle(), 0, 1)) }}
                        </div>
                        <p class="flex-1 min-w-0 text-[13.5px] text-ink truncate">&#64;{{ $person->handle() }}</p>
                        <button type="button" wire:click="addUser({{ $person->id }})" class="btn-secondary shrink-0 text-[12px]">Add</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
