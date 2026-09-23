@props(['title', 'note' => null, 'open' => false])

{{--
    A collapsed section on the Matchup Lab.

    Native <details> rather than an Alpine panel: it needs no JavaScript, it survives a Livewire
    re-render without the component having to track which panel is open, and it is keyboard- and
    screen-reader-correct for free. The Lab re-renders on every spec pick, so a JS-held open state
    would be one more thing to rehydrate.

    The chevron rotates with `group-open:`, a stock Tailwind variant — if it ever stops turning,
    check the built CSS for the rule before assuming the markup is wrong (see CLAUDE.md's note on
    arbitrary variants silently not compiling).
--}}
<details class="linear-card group" @if ($open) open @endif>
    <summary class="list-none cursor-pointer select-none flex items-center gap-2 p-3">
        <svg class="w-3.5 h-3.5 text-ink-subtle shrink-0 transition-transform group-open:rotate-90"
             fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <span class="text-[12.5px] font-semibold text-ink">{{ $title }}</span>
        @if ($note)
            <span class="text-[11px] text-ink-subtle truncate">{{ $note }}</span>
        @endif
    </summary>

    <div class="px-3 pb-3 pt-0">
        {{ $slot }}
    </div>
</details>
