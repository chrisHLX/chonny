@props([
    // Specialization models (gameClass loaded) to choose from, any order: grouped by class here.
    'specs',
    // The chosen value (compared as a string), or null for none.
    'selected' => null,
    // The Livewire method called with the chosen value, or with null for "Any".
    'call',
    // Which of the spec's attributes is the value: Blizzard's id by default.
    'valueKey' => 'external_spec_id',
    // The "none" choice's words, or null for no such choice.
    'any' => 'Any spec',
])

{{--
    One spec, chosen from a grid of class-coloured spec icons (2026-10-30, Chriso: the Leaderboards'
    dropdowns were plain phone pickers "where as on the comps page they actually have the class icons
    and are styled"). The 3v3 Comps page's picker as a component: a button showing the choice, and a
    panel of every class in its own colour with its specs as icons, named under each so a phone needs
    no hover, and a search. Teleported to the body so no card's transform can trap it.
--}}
@php
    $classColors = config('wow_classes.colors');
    $groups = collect($specs)->filter(fn ($s) => $s->gameClass)->groupBy(fn ($s) => $s->gameClass->name)->sortKeys();
    $current = collect($specs)->first(fn ($s) => $selected !== null && (string) $s->{$valueKey} === (string) $selected);
@endphp
<div x-data="{ open: false, search: '' }" @keydown.escape.window="open = false">
    <button type="button" @click="open = true"
            class="w-full flex items-center gap-2.5 text-left pl-2 pr-3 py-1.5 rounded-lg border border-line bg-surface-2 hover:border-gold/40 transition-colors">
        @if ($current)
            <x-spec-icon :spec="$current" size="w-7 h-7"/>
            <span class="min-w-0 flex-1">
                <span class="block text-[13px] font-semibold text-ink truncate leading-tight">{{ $current->name }}</span>
                <span class="block text-[11px] truncate leading-tight" style="color: {{ $classColors[$current->gameClass->slug] ?? '#B3B3B3' }}">{{ $current->gameClass->name }}</span>
            </span>
        @else
            <span class="w-7 h-7 rounded-md border border-line-strong bg-surface-1 flex items-center justify-center shrink-0">
                <x-mc-icon name="badge-wow" class="w-3.5 h-3.5 text-ink-subtle"/>
            </span>
            <span class="flex-1 text-[13px] text-ink-muted">{{ $any ?? 'Choose a spec' }}</span>
        @endif
        <svg class="w-3.5 h-3.5 text-ink-subtle shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>

    @teleport('body')
        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
             class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4"
             @click.self="open = false; search = ''">
            <div class="linear-card max-w-2xl w-full p-4 sm:p-5 relative max-h-[85vh] overflow-y-auto">
                <button type="button" @click="open = false; search = ''" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10" aria-label="Close">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div class="flex items-center gap-2 mb-4 pr-7">
                    <input type="text" x-model="search" placeholder="Search a class or spec…" class="form-input !text-[13px] !py-1.5 flex-1 min-w-0">
                    @if ($any !== null)
                        <button type="button" wire:click="{{ $call }}(null)" @click="open = false; search = ''"
                                class="shrink-0 px-3 py-1.5 rounded-full text-[12.5px] font-semibold transition-colors {{ $current ? 'bg-surface-2 text-ink-muted hover:text-ink' : 'bg-ink text-black' }}">{{ $any }}</button>
                    @endif
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                    @foreach ($groups as $className => $classSpecs)
                        @php $color = $classColors[$classSpecs->first()->gameClass->slug] ?? '#8A8A9A'; @endphp
                        <div data-search-group="{{ \Illuminate\Support\Str::lower($className.' '.$classSpecs->pluck('name')->implode(' ')) }}"
                             x-show="search === '' || $el.dataset.searchGroup.includes(search.toLowerCase())">
                            <p class="text-[11px] uppercase tracking-wide font-bold mb-2" style="color: {{ $color }}">{{ $className }}</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($classSpecs->sortBy('name') as $spec)
                                    @php $on = $current && $current->is($spec); @endphp
                                    <button type="button" wire:click="{{ $call }}('{{ $spec->{$valueKey} }}')" @click="open = false; search = ''"
                                            data-search="{{ \Illuminate\Support\Str::lower($className.' '.$spec->name) }}"
                                            x-show="search === '' || $el.dataset.search.includes(search.toLowerCase())"
                                            title="{{ $spec->name }} {{ $className }}"
                                            class="w-[68px] flex flex-col items-center gap-1 rounded-lg p-1.5 transition-colors {{ $on ? 'bg-gold-subtle ring-2 ring-gold/70' : 'hover:bg-surface-2' }}">
                                        <x-spec-icon :spec="$spec" :color="$color" size="w-11 h-11"/>
                                        <span class="text-[10.5px] leading-tight text-center {{ $on ? 'text-gold-light font-semibold' : 'text-ink-muted' }}">{{ $spec->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endteleport
</div>
