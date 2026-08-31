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
    // The grid is sized, and every coordinate cluster computed, from ONLY the nodes this partial
    // actually draws. Fixed 2026-09-01 from a real report ("a lot of empty space where the
    // talents aren't taking them up and instead being pushed to the sides"): sizing used to come
    // from every positioned node, but the render loop separately skipped any node with no
    // spell-bearing entry — so those skipped nodes still claimed a column (and a row) each,
    // reserving width nothing was ever drawn into. Measured across all 40 specs: 130 dead
    // columns total. Druid's class tree was the visible worst case at 13 columns of which the
    // last 4 were completely empty (~240px of dead space that pushed the real tree hard left),
    // plus an empty top row; Hunter's had 3 of 10 empty. Deriving both the clusters and
    // $posById from this one set also means an edge pointing at a skipped node is correctly
    // dropped by the isset() guard below, instead of drawing a stray line to the grid origin
    // via the old `?? 0` coordinate fallback.
    $renderNodes = $nodes->filter(fn ($n) => $n->pos_x !== null
        && $n->pos_y !== null
        && $n->entries->contains(fn ($e) => $e->spell));

    $nodesWithPos = $renderNodes;

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
    // sparse or dense its real node count is. Shrunk twice on 2026-08-29 chasing a container
    // overflow (80/84/24 -> 68/72/16 -> 50/54/12 -> 44/46/10), each pass making cells smaller to
    // fit more columns. **Raised back up on 2026-09-01 after finding the overflow was never
    // really about cell size at all**: the widest trees were wide because ~28 HERO-tree nodes
    // were being duplicated into nearly every spec tree (39 of 40 — see
    // TalentSelector::getSpecTalentNodesProperty()'s filter, added the same day). Feral Druid's
    // spec tree, for example, was 17 columns; its real width is 7. With that fixed, there is
    // room to size cells for CORRECTNESS rather than squeezing them to hide a data bug.
    //
    // 60px is not arbitrary — it's set by the widest thing a cell has to hold without
    // overlapping its neighbour, which is a CHOICE node's two side-by-side icons:
    // 2x24px (w-6) + 2px (gap-0.5) + 4px (p-0.5) + 2px (border) = 56px, leaving a 4px gap at
    // 60px spacing. That overlap is exactly what the 44px cells produced (a 70px-wide CHOICE
    // pair in a 44px cell visibly collided with both neighbours — the original user report).
    // Re-measured across all 40 specs at this size: the widest (Restoration Druid, 27 columns
    // across all three trees) lands at ~1712px, inside the 1800px container the admin editor
    // and the read-only Burst Window page both use.
    // Padding has to clear HALF the widest/tallest node, because every node is centred on its
    // grid point (-translate-x/y-1/2). At the old 10px a CHOICE node (56px wide, so 28px either
    // side of centre) hung 18px outside the container and was visibly sliced off by the box
    // border on the first column — same on the top row, where a multi-rank node's icon + rank
    // pips + the selected-checkmark badge reach ~24px above centre. 34px clears the widest case
    // (28px) with a small margin and still leaves every spec inside the container: re-measured
    // across all 40 specs at this padding, the widest (Restoration Druid) is 1616px against the
    // ~1768px available, and 0 specs overflow.
    $padding = 34;
    $cellW = 60;
    $cellH = 50;
    // (numCols - 1), not numCols: cellW is the distance BETWEEN column centres, and the first
    // centre already sits at $padding — so N columns span (N-1) gaps, not N. Using numCols
    // reserved one whole extra cell of dead space on the right of every tree (and one row's
    // worth below), which is the second half of the reported "empty space... talents pushed to
    // the sides": 60px per tree horizontally, ~180px across the three, on top of the dead
    // columns fixed above. Confirmed by parsing real rendered HTML — the rightmost node sat
    // exactly 60px + padding short of the container edge on every tree before this.
    $containerWidth = $padding * 2 + max(0, $numCols - 1) * $cellW;
    $containerHeight = $padding * 2 + max(0, $numRows - 1) * $cellH;

    $toPixels = fn ($x, $y) => [
        'left' => $padding + ($colIndexByX[$x] ?? 0) * $cellW,
        'top' => $padding + ($rowIndexByY[$y] ?? 0) * $cellH,
    ];
    $posById = $renderNodes->mapWithKeys(fn ($n) => [$n->id => $toPixels($n->pos_x, $n->pos_y)]);

    // Unique per include (this partial renders 3x per picker — class/spec/hero) so the SVG
    // <marker> id below can't collide across sections on the same page.
    $markerId = 'tt-arrow-'.\Illuminate\Support\Str::slug($label);

    // Tooltip content as plain data-* attributes, read by the shared viewport-positioned tooltip
    // at the bottom of this partial (see talent-tooltip.blade.php). Passing the text through
    // attributes rather than interpolating it into an Alpine expression avoids any quote/newline
    // escaping hazard in a spell description — Blade's own attribute escaping handles it.
    // Read-only mode makes only the BUTTONS inert, never this partial's wrapper divs — those
    // carry the hover handlers that drive the tooltip, and a look-but-don't-touch view still
    // needs to explain what each talent does. See talent-selector.blade.php's own note for why
    // this is a plain class per button rather than one arbitrary-variant class on the wrapper.
    $btnInert = ($readOnly ?? false) ? ' pointer-events-none' : '';

    $tipAttrs = function ($entry, ?int $rank, ?int $maxRanks, bool $locked) {
        $rankLabel = ($maxRanks && $maxRanks > 1) ? "Rank {$rank} / {$maxRanks}" : '';

        return 'data-tip-name="'.e($entry->spell->display_name).'" '
            .'data-tip-rank="'.e($rankLabel).'" '
            .'data-tip-locked="'.($locked ? '1' : '').'" '
            .'data-tip-desc="'.e(\Illuminate\Support\Str::limit($this->resolvedDescription($entry), 220)).'"';
    };
@endphp

@if ($renderNodes->isNotEmpty())
    {{-- Tooltip is a SINGLE shared element per tree, positioned in viewport coordinates
         (position: fixed) rather than one absolutely-positioned tooltip per node. Rewritten
         2026-09-01 from a real report ("when you hover a talent it needs to show the text
         without being cut off like it currently does when you hover talents on the box edge").

         Two separate causes, both fixed by this approach:
         1. The old tooltip was an absolutely-positioned child of the tree's scroll container.
            Setting `overflow-x: auto` makes the browser compute `overflow-y` to `auto` as well
            (per spec, `visible` can't pair with a scrolling value), so that container clipped
            tooltips vertically — the top row's tooltips were cut off no matter how they were
            positioned. The scroll container is gone entirely now (the outer flex row in
            talent-selector.blade.php already provides one shared horizontal scrollbar; this
            inner one was both redundant and the clipping culprit).
         2. It was hardcoded `bottom-full` (always upward) and horizontally centred, so an
            edge node's tooltip ran off the side or the top with nothing to flip it back.
         `position: fixed` escapes ancestor overflow clipping entirely, and show() below clamps
         to the viewport on both axes and flips above/below as space allows. Safe to use fixed
         here specifically because nothing on this element's ancestor chain sets transform /
         filter / backdrop-filter (any of which would make it a containing block and re-trap the
         tooltip) — the per-node wrappers DO set a transform, which is exactly why the tooltip
         lives out here at tree level rather than inside them. --}}
    <div x-data="{
            tip: null,
            tipX: -9999,
            tipY: -9999,
            show(el) {
                this.tip = {
                    name: el.dataset.tipName,
                    rank: el.dataset.tipRank,
                    locked: el.dataset.tipLocked === '1',
                    desc: el.dataset.tipDesc,
                };
                this.$nextTick(() => {
                    const t = this.$refs.tip;
                    if (!t) return;
                    const r = el.getBoundingClientRect();
                    const w = t.offsetWidth;
                    const h = t.offsetHeight;
                    let x = r.left + r.width / 2 - w / 2;
                    let y = r.top - h - 8;
                    if (y < 8) y = r.bottom + 8;
                    this.tipX = Math.max(8, Math.min(x, window.innerWidth - w - 8));
                    this.tipY = Math.max(8, Math.min(y, window.innerHeight - h - 8));
                });
            },
            hide() { this.tip = null; },
         }">
        <p class="text-[11px] font-semibold text-ink-muted uppercase tracking-wide mb-2">
            {{ $label }}
            <span class="text-ink-subtle normal-case font-normal">— {{ $pointsSpent }} point{{ $pointsSpent === 1 ? '' : 's' }} spent</span>
        </p>
        <div class="bg-surface-2/40 border border-line rounded-lg">
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

                @foreach ($renderNodes as $node)
                    @php
                        $pos = $posById[$node->id];
                        $entries = $node->entries->filter(fn ($e) => $e->spell);
                        $chosenEntryId = $chosenEntries[$node->id] ?? null;
                        // Only meaningful for a NOT-YET-invested node — an already-chosen node is
                        // never re-locked (see TalentSelector::toggleEntry()/cycleNode()'s own
                        // "only gate a fresh investment" rule), so this always reads false once
                        // $chosenEntryId is set, without needing a separate check here.
                        $isLocked = $chosenEntryId === null && $this->isNodeLocked($node);
                        $lockedClasses = 'border-line-strong grayscale opacity-20 cursor-not-allowed pointer-events-none';
                    @endphp
                    {{-- No @continue for entry-less nodes here any more: $renderNodes already
                         excludes them, and having the skip live separately from the sizing pass
                         is exactly what caused the dead-column bug this partial was fixed for. --}}
                    <div class="absolute -translate-x-1/2 -translate-y-1/2 z-10" style="left: {{ $pos['left'] }}px; top: {{ $pos['top'] }}px;">
                        @if ($node->type === 'CHOICE' && $entries->count() > 1)
                            {{-- Mutually-exclusive options in one slot — clicking either calls the
                                 normal toggleEntry(), which already replaces whichever was
                                 previously chosen for this node (see its own docblock). --}}
                            {{-- gap-0.5/p-0.5 + w-6 icons keep the whole pair at 56px so it fits
                                 inside one 60px cell — see the $cellW note above; the previous
                                 gap-1/p-1 + w-7 combination came to 70px and visibly overlapped
                                 both neighbouring cells. --}}
                            <div class="flex gap-0.5 bg-surface-1 border border-line-strong rounded-lg p-0.5">
                                @foreach ($entries as $entry)
                                    @php $isChosen = $chosenEntryId === $entry->id; @endphp
                                    <div class="relative"
                                         {!! $tipAttrs($entry, null, null, $isLocked) !!}
                                         @mouseenter="show($event.currentTarget)" @mouseleave="hide()"
                                         @focusin="show($event.currentTarget)" @focusout="hide()">
                                        <button
                                            type="button"
                                            wire:click="toggleEntry({{ $node->id }}, {{ $entry->id }})"
                                            @disabled($isLocked)
                                            class="block rounded-full overflow-hidden border transition {{ $isChosen ? 'border-gold ring-2 ring-gold' : ($isLocked ? $lockedClasses : 'border-line hover:border-line-strong grayscale opacity-40 hover:opacity-70 hover:grayscale-0') }}{{ $btnInert }}"
                                        >
                                            <x-spell-icon :spell="$entry->spell" size="w-6 h-6"/>
                                        </button>
                                        @if ($isChosen)
                                            <span class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-gold flex items-center justify-center ring-2 ring-surface-1">
                                                <svg class="w-1.5 h-1.5 text-surface-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            </span>
                                        @elseif ($isLocked)
                                            @include('livewire.partials.talent-lock-badge')
                                        @endif
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
                            <div class="relative flex flex-col items-center gap-0.5"
                                 {!! $tipAttrs($currentEntry, $currentRank, $node->max_ranks, $isLocked) !!}
                                 @mouseenter="show($event.currentTarget)" @mouseleave="hide()"
                                 @focusin="show($event.currentTarget)" @focusout="hide()">
                                <button type="button" wire:click="cycleNode({{ $node->id }})" @disabled($isLocked) class="flex flex-col items-center gap-0.5{{ $btnInert }}">
                                    <span class="block rounded-full overflow-hidden border transition {{ $currentRank > 0 ? 'border-gold ring-2 ring-gold' : ($isLocked ? $lockedClasses : 'border-line hover:border-line-strong grayscale opacity-40 hover:opacity-70 hover:grayscale-0') }}">
                                        <x-spell-icon :spell="$currentEntry->spell" size="w-8 h-8"/>
                                    </span>
                                    <span class="flex gap-0.5">
                                        @for ($i = 1; $i <= $node->max_ranks; $i++)
                                            <span class="w-1.5 h-1.5 rounded-full {{ $i <= $currentRank ? 'bg-gold' : 'bg-line-strong' }}"></span>
                                        @endfor
                                    </span>
                                </button>
                                @if ($currentRank > 0)
                                    <span class="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-gold flex items-center justify-center ring-2 ring-surface-1">
                                        <svg class="w-2 h-2 text-surface-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                @elseif ($isLocked)
                                    @include('livewire.partials.talent-lock-badge')
                                @endif
                            </div>
                        @else
                            @php $entry = $entries->first(); $isChosen = $chosenEntryId === $entry->id; @endphp
                            <div class="relative"
                                 {!! $tipAttrs($entry, null, null, $isLocked) !!}
                                 @mouseenter="show($event.currentTarget)" @mouseleave="hide()"
                                 @focusin="show($event.currentTarget)" @focusout="hide()">
                                <button
                                    type="button"
                                    wire:click="toggleEntry({{ $node->id }}, {{ $entry->id }})"
                                    @disabled($isLocked)
                                    class="block rounded-full overflow-hidden border transition {{ $isChosen ? 'border-gold ring-2 ring-gold' : ($isLocked ? $lockedClasses : 'border-line hover:border-line-strong grayscale opacity-40 hover:opacity-70 hover:grayscale-0') }}{{ $btnInert }}"
                                >
                                    <x-spell-icon :spell="$entry->spell" size="w-8 h-8"/>
                                </button>
                                @if ($isChosen)
                                    <span class="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-gold flex items-center justify-center ring-2 ring-surface-1">
                                        <svg class="w-2 h-2 text-surface-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                @elseif ($isLocked)
                                    @include('livewire.partials.talent-lock-badge')
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        @include('livewire.partials.talent-tooltip')
    </div>
@endif
