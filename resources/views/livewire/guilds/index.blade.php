<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.13em] text-gold mb-1">Guilds</p>
        <h1 class="font-display text-3xl text-ink">Your guilds</h1>
        <p class="text-[13.5px] text-ink-muted mt-1">
            A group you share guides with. Everyone in a guild can read every guide shared with it.
        </p>
    </div>

    <div class="linear-card p-4 mb-6">
        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">Start a guild</h2>
        <div class="flex flex-col sm:flex-row gap-2">
            <input type="text" wire:model="name" maxlength="60"
                   placeholder="Name it — your team, your community, whatever you call yourselves"
                   class="form-input flex-1 text-[13.5px]"
                   wire:keydown.enter="create">
            <button type="button" wire:click="create" class="btn-primary shrink-0">Create</button>
        </div>
        @if ($error)
            <p class="text-[12px] text-red-400 mt-2">{{ $error }}</p>
        @endif
    </div>

    @forelse ($this->guilds as $guild)
        <a href="{{ route('guilds.show', $guild) }}" wire:navigate
           class="linear-card p-4 mb-3 block hover:border-line-gold transition-colors">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="font-display text-[17px] text-ink truncate">{{ $guild->name }}</h3>
                    @if ($guild->description)
                        <p class="text-[12.5px] text-ink-muted mt-0.5 truncate">{{ $guild->description }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-3 shrink-0 text-[11.5px] text-ink-subtle tabular-nums">
                    <span>{{ $guild->members_count }} {{ Str::plural('member', $guild->members_count) }}</span>
                    <span>{{ $guild->guides_count }} {{ Str::plural('guide', $guild->guides_count) }}</span>
                    @if ($guild->owner_id === auth()->id())
                        <span class="badge-gold">Owner</span>
                    @endif
                </div>
            </div>
        </a>
    @empty
        <div class="linear-card p-8 text-center">
            <p class="text-[13.5px] text-ink-muted">
                You are not in a guild yet. Create one above, or open an invite link someone sent you.
            </p>
        </div>
    @endforelse
</div>
