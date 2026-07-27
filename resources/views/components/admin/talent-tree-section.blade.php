{{-- resources/views/components/admin/talent-tree-section.blade.php --}}
@props(['title', 'nodes', 'source'])

<div class="linear-card overflow-hidden">
    <div class="px-5 py-4 border-b border-line flex items-center justify-between">
        <p class="text-[13px] font-semibold text-ink">{{ $title }}</p>
        <span class="text-[11px] text-ink-subtle">{{ $nodes->count() }} node(s)</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <x-admin.spell-table-head/>
            <tbody>
                @forelse ($nodes as $node)
                    @php $byRank = $node->entries->groupBy('rank'); @endphp
                    @foreach ($byRank as $rank => $entries)
                        @if ($entries->count() > 1)
                            <tr class="bg-surface-2">
                                <td colspan="4" class="pl-5 pr-5 py-1.5 text-[10px] text-violet font-medium uppercase tracking-wide">
                                    Choice (rank {{ $rank }}) — pick one
                                    <span class="text-ink-subtle font-mono normal-case">&middot; {{ $node->type }}</span>
                                </td>
                            </tr>
                        @endif
                        @foreach ($entries as $entry)
                            @if ($entry->spell)
                                <x-admin.game-spell-card
                                    :spell="$entry->spell"
                                    :source="$source"
                                    :context="$node->max_ranks > 1 ? \"Rank {$rank} of {$node->max_ranks}\" : null"
                                />
                            @endif
                        @endforeach
                    @endforeach
                @empty
                    <tr><td colspan="4" class="px-5 py-4 text-[12px] text-ink-subtle">No nodes found for this tree.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
