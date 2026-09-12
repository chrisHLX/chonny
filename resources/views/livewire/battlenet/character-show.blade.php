<div class="max-w-[1800px] mx-auto px-4 py-8">
    <div class="max-w-4xl">
        <a href="{{ route('characters.index') }}" wire:navigate
           class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; Your characters</a>

        <div class="linear-card p-5 mt-3 mb-6">
            <div class="flex items-start gap-4">
                <x-battlenet.character-summary :character="$character" class="flex-1 min-w-0"/>

                <div class="flex flex-col items-end gap-1 shrink-0 text-right">
                    <button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh"
                            class="btn-ghost">
                        <span wire:loading.remove wire:target="refresh">Refresh</span>
                        <span wire:loading wire:target="refresh">Refreshing&hellip;</span>
                    </button>
                    @if ($character->synced_at)
                        <span class="text-[11px] text-ink-subtle">updated {{ $character->synced_at->diffForHumans() }}</span>
                    @endif
                </div>
            </div>

            @if ($character->arenas_played)
                <p class="text-[12px] text-ink-subtle mt-3 tabular-nums">
                    {{ number_format($character->arenas_played) }} rated arenas played lifetime,
                    {{ number_format($character->arenas_won ?? 0) }} won
                    ({{ round(($character->arenas_won ?? 0) / max(1, $character->arenas_played) * 100) }}%).
                </p>
            @endif

            @if ($character->sync_error)
                <p class="text-[12px] text-red-300/90 mt-2">{{ $character->sync_error }}</p>
            @endif
        </div>

        <div class="linear-card p-5 mb-6">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-4">Gear</h2>
            <x-battlenet.gear :equipment="$character->equipment ?? []"/>
        </div>
    </div>

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

        <x-battlenet.talent-build :view="$this->talentView"
                                  :key="'char-'.$character->id.'-'.($this->talentView['spec']->id ?? 'none')"/>
    </div>
</div>
