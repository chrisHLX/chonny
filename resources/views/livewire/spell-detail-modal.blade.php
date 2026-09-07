{{--
    Thin shell only. Everything inside used to live here as ~190 lines of markup that was the
    only place several spell facts were rendered at all; it now lives in <x-spells.detail>, shared
    verbatim with the /spell/{id} page, so the two can never drift.
--}}
<div>
@if ($entry)
    <div class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="$wire.close()"
         @keydown.escape.window="$wire.close()">
        <div class="linear-card max-w-lg w-full p-5 relative max-h-[85vh] overflow-y-auto" @click.stop>
            <button type="button" wire:click="close" class="absolute top-3 right-3 text-ink-subtle hover:text-ink">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="pr-6">
                <x-spells.detail :profile="$entry" :spec-id="$specId"/>
            </div>

            <div class="mt-4 pt-3 border-t border-line">
                <a href="{{ route('spell.show', $entry->spell->id) }}"
                   class="text-[11px] text-gold hover:text-gold-light underline decoration-dotted">
                    Open full page for {{ $entry->displayName() }} &rarr;
                </a>
            </div>
        </div>
    </div>
@endif
</div>
