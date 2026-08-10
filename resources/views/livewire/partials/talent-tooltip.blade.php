{{--
    Hover tooltip for one talent entry inside the grid picker (icon owner's name/rank/description)
    — shown via pure CSS (the parent wraps its trigger button in `class="group relative"`; this
    stays `hidden` until `group-hover`), no Alpine/JS needed. `$rank`/`$maxRanks` are only passed
    for a multi-rank node's current entry; a CHOICE option or a plain single-rank node passes
    neither, so the rank line is omitted for those.

    Always opens upward (`bottom-full`) — not perfect for a node in the very top row of a tree
    (can clip against the modal's own scroll container), an accepted tradeoff rather than adding
    flip-direction detection for this first pass.
--}}
@php $spell = $entry->spell; @endphp
<div class="hidden group-hover:block absolute z-30 bottom-full left-1/2 -translate-x-1/2 mb-2 w-56 pointer-events-none">
    <div class="linear-card !border-line-strong shadow-gold-lg p-2.5 text-left">
        <p class="text-[12px] font-semibold text-ink">{{ $spell->display_name }}</p>
        @if ($maxRanks && $maxRanks > 1)
            <p class="text-[10px] text-gold mt-0.5">Rank {{ $rank }} / {{ $maxRanks }}</p>
        @endif
        @if ($spell->description)
            <p class="text-[11px] text-ink-muted mt-1 leading-snug">{{ Str::limit($spell->description, 220) }}</p>
        @endif
    </div>
</div>
