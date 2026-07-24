{{-- resources/views/components/admin/spell-section.blade.php --}}
@props(['title', 'spells', 'source', 'note' => null])

<div class="linear-card overflow-hidden">
    <div class="px-5 py-4 border-b border-line flex items-center justify-between">
        <div>
            <p class="text-[13px] font-semibold text-ink">{{ $title }}</p>
            @if ($note)
                <p class="text-[11px] text-ink-subtle mt-0.5">{{ $note }}</p>
            @endif
        </div>
        <span class="text-[11px] text-ink-subtle">{{ $spells->count() }} spell(s)</span>
    </div>

    <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
        @forelse ($spells as $spell)
            <x-admin.game-spell-card :spell="$spell" :source="$source"/>
        @empty
            <p class="text-[12px] text-ink-subtle">No spells found.</p>
        @endforelse
    </div>
</div>
