@props([
    'label',
    'classes',
    'selected' => '',
    'property',
])

{{--
    A row of class icons acting as a single-choice filter, in place of a native <select>.

    Matches the comp picker in wow-comps.blade.php: the class's own in-game colour, its real icon,
    and selection shown as a ring rather than as text in a collapsed dropdown. A dropdown listing
    thirteen class NAMES was the only class picker on the site that did not look like the others,
    and on a phone it hid every option until tapped — so a visitor could not see that filtering by
    class was even worth doing.

    Clicking the selected class again clears it, so "any class" needs no separate reset control
    beyond the All button.
--}}
<div>
    <div class="flex items-center gap-2 mb-2">
        <span class="text-[11px] uppercase tracking-[0.12em] text-ink-subtle font-semibold">{{ $label }}</span>
        @if ($selected !== '')
            <button type="button" wire:click="$set('{{ $property }}', '')"
                    class="text-[11px] text-ink-subtle hover:text-gold transition-colors">
                clear
            </button>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-1.5">
        <button type="button" wire:click="$set('{{ $property }}', '')"
                class="px-2.5 h-9 rounded-md border text-[12px] transition-colors
                       {{ $selected === ''
                            ? 'border-gold text-gold-light bg-gold-subtle font-semibold'
                            : 'border-line-strong text-ink-muted hover:text-ink' }}">
            Any
        </button>

        @foreach ($classes as $gameClass)
            @php $color = config('wow_classes.colors')[$gameClass->slug] ?? '#8A8A9A'; @endphp
            <button type="button"
                    wire:click="$set('{{ $property }}', '{{ $selected === $gameClass->slug ? '' : $gameClass->slug }}')"
                    title="{{ $label }} {{ $gameClass->name }}"
                    aria-pressed="{{ $selected === $gameClass->slug ? 'true' : 'false' }}"
                    class="rounded-md transition-all {{ $selected === $gameClass->slug
                        ? 'ring-2 ring-offset-1 ring-offset-surface-1'
                        : 'opacity-70 hover:opacity-100' }}"
                    @if ($selected === $gameClass->slug) style="--tw-ring-color: {{ $color }}" @endif>
                <x-class-icon :class="$gameClass" size="w-9 h-9"/>
            </button>
        @endforeach
    </div>
</div>
