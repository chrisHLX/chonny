{{--
    ONE shared hover tooltip per talent tree, positioned in viewport coordinates by the Alpine
    `show()` in talent-tree-grid.blade.php (which owns the `tip`/`tipX`/`tipY` state this reads).
    Rendered once at tree level, deliberately OUTSIDE the positioned/transformed node wrappers.

    Rewritten 2026-09-01 (was: one absolutely-positioned tooltip per node, hidden/shown purely by
    Tailwind `group-hover`). Two real problems with that approach, both reported by a user:
      - It lived inside the tree's `overflow-x-auto` scroll container. `overflow-x: auto` forces
        the browser to compute `overflow-y` to `auto` too, so that container clipped tooltips
        vertically — top-row talents had their text cut off with no way to style around it.
      - It was hardcoded to open upward and horizontally centred, so nodes near any box edge ran
        their tooltip off the side or the top.
    `position: fixed` here escapes ancestor overflow clipping outright, and show() clamps to the
    viewport on both axes (flipping below the node when there isn't room above).

    Also a real render-cost win, not just a correctness fix: a dense tree renders ~70 nodes, and
    this collapses ~70 always-present hidden tooltip DOM blocks (each with its own resolved
    description markup) down to a single element whose text is swapped on hover — the per-node
    description text itself is still resolved server-side, now into a data-* attribute rather
    than a full markup block (see $tipAttrs in talent-tree-grid.blade.php).

    `pointer-events-none` matters: without it the tooltip can land under the cursor and
    immediately trigger the trigger's own mouseleave, flickering the tooltip on and off.
--}}
<div x-show="tip" x-cloak x-ref="tip"
     class="fixed z-[60] w-60 pointer-events-none"
     :style="`left: ${tipX}px; top: ${tipY}px;`">
    <div class="linear-card !border-line-strong shadow-gold-lg p-2.5 text-left">
        <p class="text-[12px] font-semibold text-ink" x-text="tip?.name"></p>
        <p class="text-[10px] text-gold mt-0.5" x-show="tip?.rank" x-text="tip?.rank"></p>
        <p class="text-[10px] text-ink-subtle mt-0.5" x-show="tip?.locked">🔒 Requires an earlier talent, or more points spent in this tree</p>
        <p class="text-[11px] text-ink-muted mt-1 leading-snug" x-show="tip?.desc" x-text="tip?.desc"></p>
    </div>
</div>
