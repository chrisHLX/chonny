@props(['action', 'target'])

{{-- Refresh a character from Blizzard. A sync takes a few seconds, so while it runs the button
     spins, disables and says so — before this it only swapped a word, which was easy to miss. --}}
<button type="button" wire:click="{{ $action }}"
        wire:loading.attr="disabled" wire:target="{{ $target }}"
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 disabled:cursor-wait']) }}>
    <svg class="w-3.5 h-3.5" wire:loading.class="animate-spin text-gold" wire:target="{{ $target }}"
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>
    </svg>
    <span wire:loading.remove wire:target="{{ $target }}">Refresh</span>
    <span wire:loading wire:target="{{ $target }}">Refreshing&hellip;</span>
</button>
