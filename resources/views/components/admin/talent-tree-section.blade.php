{{-- resources/views/components/admin/talent-tree-section.blade.php --}}
@props(['title', 'nodes', 'source'])

<div class="linear-card overflow-hidden">
    <div class="px-5 py-4 border-b border-line flex items-center justify-between">
        <p class="text-[13px] font-semibold text-ink">{{ $title }}</p>
        <span class="text-[11px] text-ink-subtle">{{ $nodes->count() }} node(s)</span>
    </div>

    <div class="p-5 space-y-4">
        @forelse ($nodes as $node)
            <div class="border border-line rounded-lg p-3">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span class="text-[10px] uppercase tracking-wide text-ink-subtle font-mono">{{ $node->type }}</span>
                    <span class="text-[10px] text-ink-subtle">pos ({{ $node->pos_x }}, {{ $node->pos_y }})</span>
                    @if ($node->max_ranks > 1)
                        <span class="text-[10px] text-ink-subtle">max rank {{ $node->max_ranks }}</span>
                    @endif
                </div>

                @php $byRank = $node->entries->groupBy('rank'); @endphp

                <div class="space-y-3">
                    @foreach ($byRank as $rank => $entries)
                        @if ($entries->count() > 1)
                            <p class="text-[10px] text-violet font-medium uppercase tracking-wide">
                                Choice (rank {{ $rank }}) — pick one
                            </p>
                        @endif
                        <div class="grid gap-2 {{ $entries->count() > 1 ? 'grid-cols-1 md:grid-cols-2' : 'grid-cols-1' }}">
                            @foreach ($entries as $entry)
                                @if ($entry->spell)
                                    <x-admin.game-spell-card
                                        :spell="$entry->spell"
                                        :source="$source"
                                        :context="$node->max_ranks > 1 ? \"Rank {$rank} of {$node->max_ranks}\" : null"
                                    />
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-[12px] text-ink-subtle">No nodes found for this tree.</p>
        @endforelse
    </div>
</div>
