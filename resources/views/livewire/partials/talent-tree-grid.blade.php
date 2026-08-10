{{--
    Renders one talent tree (class/spec/hero) as a positional grid mirroring the real in-game
    layout, using talent_nodes.pos_x/pos_y (Blizzard's own raw coordinates, imported as-is —
    see ImportSpellData.php) plus talent_node_edges for the connector lines between prerequisite
    nodes. Included once per tree from talent-selector.blade.php when $layout === 'grid'.

    Expects (all shared from the parent Blade scope via @include, not passed explicitly):
    $nodes (Collection<TalentNode> with entries.spell eager-loaded), $edges
    (Collection<TalentNodeEdge> scoped to this tree's own node ids), $label (string),
    $chosenEntries (array<int,int> talent_node_id => chosen talent_node_entry id — the
    component's own public property).

    Node click targets call straight back into TalentSelector's existing methods:
    toggleEntry() for a single-rank node or either side of a CHOICE node (already correct,
    unmodified), cycleNode() for a multi-rank non-CHOICE node (new — see TalentSelector.php).

    Positioning: fixed-pixel grid cells (column/row CLUSTER INDEX × a constant cell size), not
    percentage-of-bounding-box — switched 2026-08-10 after a real report that percentage-of-span
    sizing gave wildly inconsistent visual density between trees: a sparse tree (e.g. a 14-node
    hero tree, 5 real columns/rows) still got stretched to fill the container's full width, so
    each row ended up ~250px tall; a dense tree (73 nodes, 18 columns) got squeezed the opposite
    way. A fixed cell size gives every tree the same visual density regardless of how sparse or
    dense its real node count is; a wide tree scrolls horizontally (`overflow-x-auto`) instead of
    being squeezed to fit. Column/row index still comes from the same near-duplicate-coordinate
    clustering as before (see the threshold note below) — only the final pixel mapping changed.

    Edges render as arrowed lines in the same pixel space as the nodes (an SVG <marker> per
    include, id namespaced by $label so the three sections on one page — class/spec/hero — don't
    collide). Each node gets a hover tooltip (talent-tooltip.blade.php, pure CSS via Tailwind
    `group`/`group-hover`, no JS) showing the spell's name/rank/description.

    Selected-state contrast: a real WoW spell icon's own art already has a baked-in gold-colored
    decorative frame (confirmed against real screenshots — this is Blizzard's own icon asset, not
    anything this app draws), which made an earlier gold-ring-only "selected" indicator hard to
    tell apart from every icon's own border at a glance. Unselected nodes are now desaturated
    (`grayscale` + reduced opacity) and selected nodes stay full-color with a gold ring AND a
    small checkmark badge — three redundant signals (color, ring, badge) instead of one subtle one.
--}}
@php
    $nodesWithPos = $nodes->filter(fn ($n) => $n->pos_x !== null && $n->pos_y !== null);

    // Cluster near-duplicate raw coordinates into one shared column/row index. Blizzard's raw
    // pos_x/pos_y occasionally carries several values within a few units of each other for what
    // is visually the same column/row (confirmed 2026-08-10 against real data: Restoration
    // Druid's spec tree has real column gaps of ~295-900 units, but several "columns" only 1-4
    // units apart). 60 units is comfortably below every observed noise gap (<=4) and well below
    // every observed real gap (>=295), so this only ever merges genuine duplicates, never two
    // real distinct columns/rows.
    $clusterIndex = function (Illuminate\Support\Collection $values, int $threshold = 60): array {
        $sorted = $values->unique()->sort()->values();
        $clusters = [];
        $current = [];

        foreach ($sorted as $value) {
            if ($current !== [] && $value - end($current) > $threshold) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $value;
        }
        if ($current !== []) {
            $clusters[] = $current;
        }

        $map = [];
        foreach ($clusters as $index => $cluster) {
            foreach ($cluster as $raw) {
                $map[$raw] = $index;
            }
        }

        return $map; // raw coordinate value => 0-based column/row index
    };

    $colIndexByX = $clusterIndex($nodesWithPos->pluck('pos_x'));
    $rowIndexByY = $clusterIndex($nodesWithPos->pluck('pos_y'));
    $numCols = $colIndexByX === [] ? 1 : max($colIndexByX) + 1;
    $numRows = $rowIndexByY === [] ? 1 : max($rowIndexByY) + 1;

    // Fixed per-cell size (px) — every tree renders at the same visual density regardless of how
    // sparse or dense its real node count is. Sized to comfortably fit the widest element (an
    // 80px-wide CHOICE-node pair) and tallest (a 44px icon + rank-pip row) without touching
    // neighboring cells.
    $padding = 24;
    $cellW = 80;
    $cellH = 84;
    $containerWidth = $padding * 2 + $numCols * $cellW;
    $containerHeight = $padding * 2 + $numRows * $cellH;

    $toPixels = fn ($x, $y) => [
        'left' => $padding + ($colIndexByX[$x] ?? 0) * $cellW,
        'top' => $padding + ($rowIndexByY[$y] ?? 0) * $cellH,
    ];
    $posById = $nodes->mapWithKeys(fn ($n) => [$n->id => $toPixels($n->pos_x ?? 0, $n->pos_y ?? 0)]);

    // Unique per include (this partial renders 3x per picker — class/spec/hero) so the SVG
    // <marker> id below can't collide across sections on the same page.
    $markerId = 'tt-arrow-'.\Illuminate\Support\Str::slug($label);
@endphp

@if ($nodes->isNotEmpty())
    <div>
        <p class="text-[11px] font-semibold text-ink-muted uppercase tracking-wide mb-2">{{ $label }}</p>
        <div class="bg-surface-2/40 border border-line rounded-lg overflow-x-auto">
            <div class="relative" style="width: {{ $containerWidth }}px; height: {{ $containerHeight }}px;">
                <svg class="absolute inset-0" width="{{ $containerWidth }}" height="{{ $containerHeight }}">
                    <defs>
                        <marker id="{{ $markerId }}" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M0,0 L10,5 L0,10 z" class="fill-ink-subtle"/>
                        </marker>
                    </defs>
                    @foreach ($edges as $edge)
                        @continue(!isset($posById[$edge->from_node_id]) || !isset($posById[$edge->to_node_id]))
                        @php
                            $from = $posById[$edge->from_node_id];
                            $to = $posById[$edge->to_node_id];
                        @endphp
                        <line
                            x1="{{ $from['left'] }}" y1="{{ $from['top'] }}"
                            x2="{{ $to['left'] }}" y2="{{ $to['top'] }}"
                            stroke="currentColor" stroke-width="2"
                            class="text-ink-subtle"
                            marker-end="url(#{{ $markerId }})"
                        />
                    @endforeach
                </svg>

                @foreach ($nodes as $node)
                    @php
                        $pos = $posById[$node->id];
                        $entries = $node->entries->filter(fn ($e) => $e->spell);
                        $chosenEntryId = $chosenEntries[$node->id] ?? null;
                    @endphp
                    @continue($entries->isEmpty())
                    <div class="absolute -translate-x-1/2 -translate-y-1/2 z-10" style="left: {{ $pos['left'] }}px; top: {{ $pos['top'] }}px;">
                        @if ($node->type === 'CHOICE' && $entries->count() > 1)
                            {{-- Mutually-exclusive options in one slot — clicking either calls the
                                 normal toggleEntry(), which already replaces whichever was
                                 previously chosen for this node (see its own docblock). --}}
                            <div class="flex gap-1 bg-surface-1 border border-line-strong rounded-lg p-1">
                                @foreach ($entries as $entry)
                                    @php $isChosen = $chosenEntryId === $entry->id; @endphp
                                    <div class="group relative">
                                        <button
                                            type="button"
                                            wire:click="toggleEntry({{ $node->id }}, {{ $entry->id }})"
                                            class="rounded-md border transition {{ $isChosen ? 'border-gold ring-2 ring-gold' : 'border-line hover:border-line-strong grayscale opacity-40 hover:opacity-70 hover:grayscale-0' }}"
                                        >
                                            <x-spell-icon :spell="$entry->spell" size="w-9 h-9"/>
                                        </button>
                                        @if ($isChosen)
                                            <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-gold flex items-center justify-center ring-2 ring-surface-1">
                                                <svg class="w-2.5 h-2.5 text-surface-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            </span>
                                        @endif
                                        @include('livewire.partials.talent-tooltip', ['entry' => $entry, 'rank' => null, 'maxRanks' => null])
                                    </div>
                                @endforeach
                            </div>
                        @elseif ($node->max_ranks > 1)
                            {{-- One talent, several rank tiers sharing this node (e.g. Improved
                                 Fade rank 1/rank 2) — click advances rank via cycleNode(), pips
                                 below show progress toward max_ranks. Icon/tooltip reflect whichever
                                 rank is currently chosen (falling back to rank 1 as a preview when
                                 none is chosen yet), not always rank 1's entry. --}}
                            @php
                                $currentEntry = $entries->first(fn ($e) => $e->id === $chosenEntryId) ?? $entries->sortBy('rank')->first();
                                $currentRank = $currentEntry?->id === $chosenEntryId ? $currentEntry->rank : 0;
                            @endphp
                            <div class="group relative flex flex-col items-center gap-0.5">
                                <button type="button" wire:click="cycleNode({{ $node->id }})" class="flex flex-col items-center gap-0.5">
                                    <span class="block rounded-md border transition {{ $currentRank > 0 ? 'border-gold ring-2 ring-gold' : 'border-line hover:border-line-strong grayscale opacity-40 hover:opacity-70 hover:grayscale-0' }}">
                                        <x-spell-icon :spell="$currentEntry->spell" size="w-11 h-11"/>
                                    </span>
                                    <span class="flex gap-0.5">
                                        @for ($i = 1; $i <= $node->max_ranks; $i++)
                                            <span class="w-1.5 h-1.5 rounded-full {{ $i <= $currentRank ? 'bg-gold' : 'bg-line-strong' }}"></span>
                                        @endfor
                                    </span>
                                </button>
                                @if ($currentRank > 0)
                                    <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-gold flex items-center justify-center ring-2 ring-surface-1">
                                        <svg class="w-2.5 h-2.5 text-surface-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                @endif
                                @include('livewire.partials.talent-tooltip', ['entry' => $currentEntry, 'rank' => $currentRank, 'maxRanks' => $node->max_ranks])
                            </div>
                        @else
                            @php $entry = $entries->first(); $isChosen = $chosenEntryId === $entry->id; @endphp
                            <div class="group relative">
                                <button
                                    type="button"
                                    wire:click="toggleEntry({{ $node->id }}, {{ $entry->id }})"
                                    class="block rounded-md border transition {{ $isChosen ? 'border-gold ring-2 ring-gold' : 'border-line hover:border-line-strong grayscale opacity-40 hover:opacity-70 hover:grayscale-0' }}"
                                >
                                    <x-spell-icon :spell="$entry->spell" size="w-11 h-11"/>
                                </button>
                                @if ($isChosen)
                                    <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-gold flex items-center justify-center ring-2 ring-surface-1">
                                        <svg class="w-2.5 h-2.5 text-surface-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                @endif
                                @include('livewire.partials.talent-tooltip', ['entry' => $entry, 'rank' => null, 'maxRanks' => null])
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
