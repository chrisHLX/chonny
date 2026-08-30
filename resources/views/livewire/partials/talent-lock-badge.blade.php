{{--
    Small lock icon badge for a node that can't be picked yet (an unmet prerequisite edge, or a
    Class/Spec point-threshold gate — see TalentSelectionService::isNodeLocked()). Same corner
    position as the selected-state checkmark badge in talent-tree-grid.blade.php; the two never
    render together, since a node is either chosen (never locked, per the "only gate a fresh
    investment" rule) or not-yet-chosen (only then possibly locked).
--}}
<span class="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-surface-0 flex items-center justify-center ring-2 ring-surface-1">
    <svg class="w-2 h-2 text-ink-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
    </svg>
</span>
