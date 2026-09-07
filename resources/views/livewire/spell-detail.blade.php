<div class="max-w-3xl mx-auto px-4 py-8">
    <div class="mb-4">
        <a href="{{ route('spell-finder') }}" class="text-[11px] text-ink-subtle hover:text-ink-muted">&larr; Spell Finder</a>
    </div>

    <div class="linear-card p-6 relative">
        <x-ornament.corner position="tl" class="absolute top-2 left-2 w-8 h-8 text-gold/15"/>
        <x-ornament.corner position="br" class="absolute bottom-2 right-2 w-8 h-8 text-gold/15"/>

        <x-spells.detail :profile="$profile" :spec-id="$this->specId" expanded/>
    </div>

    {{-- Mounted so the counter chips inside <x-spells.detail> can open a sibling spell without
         a full page navigation — the same shared component every other page uses. --}}
    <livewire:spell-detail-modal/>
</div>
