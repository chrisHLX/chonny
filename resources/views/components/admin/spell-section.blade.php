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

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <x-admin.spell-table-head/>
            <tbody>
                @forelse ($spells as $spell)
                    <x-admin.game-spell-card :spell="$spell" :source="$source"/>
                @empty
                    <tr><td colspan="4" class="px-5 py-4 text-[12px] text-ink-subtle">No spells found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
