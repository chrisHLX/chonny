@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-4xl mx-auto px-4 py-8">
    <a href="{{ route('guilds.index') }}" wire:navigate
       class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; Guilds</a>

    <div class="flex items-start justify-between gap-4 mt-2 mb-6">
        <div class="min-w-0">
            <h1 class="font-display text-3xl text-ink">{{ $guild->name }}</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ $guild->description ?: 'Guides shared inside this guild.' }}
            </p>
            <p class="text-[11.5px] text-ink-subtle mt-1">
                Owned by {{ $guild->owner?->username ?? $guild->owner?->name }}
                &middot; {{ $this->members->count() }} {{ Str::plural('member', $this->members->count()) }}
            </p>
        </div>

        <div class="flex flex-col items-end gap-2 shrink-0">
            @if ($this->isMember)
                @unless ($this->isOwner)
                    <button type="button" wire:click="leave" class="btn-ghost">Leave</button>
                @else
                    <button type="button" wire:click="destroy"
                            wire:confirm="Delete this guild? Guides shared with it are kept — they just stop being visible to the guild."
                            class="btn-danger text-[12px]">Delete guild</button>
                @endunless
            @else
                <button type="button" wire:click="join" class="btn-primary">Join this guild</button>
            @endif
        </div>
    </div>

    @if ($notice)
        <div class="linear-card p-3 mb-4 border-line-gold">
            <p class="text-[13px] text-gold">{{ $notice }}</p>
        </div>
    @endif

    @if ($this->isMember)
        {{-- The invite. A guild's URL is how people join, so it is offered plainly rather than
             hidden behind an "invite" flow that does the same thing with more steps. --}}
        <div class="linear-card p-4 mb-6" x-data="{ copied: false }">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">Invite link</h2>
            <div class="flex gap-2">
                <input type="text" readonly value="{{ route('guilds.show', $guild) }}"
                       class="form-input flex-1 text-[12.5px] text-ink-muted"
                       x-ref="link" x-on:focus="$el.select()">
                <button type="button" class="btn-ghost shrink-0"
                        x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 1500)">
                    <span x-show="!copied">Copy</span>
                    <span x-show="copied" x-cloak class="text-gold">Copied</span>
                </button>
            </div>
            <p class="text-[11.5px] text-ink-subtle mt-2">Anyone with this link can join.</p>
        </div>

        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">
            Guides shared here
        </h2>

        @forelse ($this->guides as $guide)
            <x-guides.card :guide="$guide" :class-colors="$classColors" :show-author="true"/>
        @empty
            <div class="linear-card p-8 text-center mb-6">
                <p class="text-[13.5px] text-ink-muted">
                    Nothing shared yet. Set a guide's visibility to <span class="text-ink">Guild</span> in the
                    builder and pick this guild.
                </p>
            </div>
        @endforelse

        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mt-8 mb-3">Members</h2>
        <div class="linear-card divide-y divide-line">
            @foreach ($this->members as $member)
                <div class="flex items-center justify-between gap-3 p-3">
                    <div class="min-w-0">
                        <p class="text-[13.5px] text-ink truncate">{{ $member->username ?? $member->name }}</p>
                        @if ($member->id === $guild->owner_id)
                            <span class="badge-gold">Owner</span>
                        @endif
                    </div>
                    @if ($this->isOwner && $member->id !== $guild->owner_id)
                        <button type="button" wire:click="remove({{ $member->id }})"
                                class="text-[11.5px] text-ink-subtle hover:text-red-400 transition-colors">Remove</button>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="linear-card p-8 text-center">
            <p class="text-[13.5px] text-ink-muted">
                @auth
                    Join this guild to read the guides shared inside it.
                @else
                    Sign in and join this guild to read the guides shared inside it.
                @endauth
            </p>
        </div>
    @endif
</div>
