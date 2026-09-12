@php
    $classColor = $class ? (config('wow_classes.colors')[$class->slug] ?? '#8A8A9A') : '#8A8A9A';
@endphp

{{-- classPickerOpen / pendingSpec mirror the identical Alpine state SpellExplorer and WowComps
     use for the same shared picker modal, so the interaction is the same one everywhere on the
     site. pendingTab is this page's own: a tab switch loads a lazy panel, which is a real
     round trip, so the tab being switched TO shows a spinner until its panel arrives. --}}
<div class="max-w-6xl mx-auto px-4 py-8 space-y-5"
     x-data="{ classPickerOpen: false, pendingSpec: false, pendingTab: null }">

    {{-- Header + spec picker, in one card: the page is entirely about one spec, so the thing
         that changes it belongs beside its name rather than in a separate block below. --}}
    <div class="linear-card px-6 py-5">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Class Guides</p>
                <h1 class="font-display text-[28px] font-bold leading-tight mt-1" style="color: {{ $classColor }}">
                    {{ $spec?->name }} {{ $class?->name }}
                </h1>
                <p class="text-[12.5px] text-ink-muted mt-1.5 max-w-2xl">
                    The talents top players run, how to line up your burst, your full spell kit, and what
                    answers your crowd control. Built from high-rated arena matches and current patch data.
                </p>
            </div>

            <button type="button"
                    @click="classPickerOpen = true"
                    :disabled="pendingSpec"
                    :class="pendingSpec && 'opacity-60 cursor-wait'"
                    class="flex items-center gap-3 text-left px-2.5 py-2 rounded-lg border border-line hover:border-gold/40 transition-colors shrink-0">
                @if ($spec)
                    <x-spec-icon :spec="$spec" :color="$classColor" size="w-9 h-9"/>
                    <span class="min-w-0">
                        <span class="block text-[13px] font-semibold text-ink truncate">{{ $spec->name }}</span>
                        <span class="block text-[11px] text-ink-muted truncate">{{ $class?->name }}</span>
                    </span>
                @else
                    <span class="text-[13px] text-ink-muted">Choose class &amp; spec...</span>
                @endif
                <template x-if="pendingSpec">
                    <svg class="animate-spin w-4 h-4 text-gold ml-1 shrink-0" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </template>
                <template x-if="!pendingSpec">
                    <svg class="w-3.5 h-3.5 text-ink-subtle ml-1 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </template>
            </button>
        </div>
    </div>

    {{-- Tab bar. Each tab is a real Livewire round trip (it swaps which lazy panel is mounted),
         so unlike WowComps' Alpine-only tab bar this needs no fetch() beacon to be tracked -
         PvpGuides::selectTab() logs it directly. A tab with no data on file for this spec is
         still clickable; it is marked so the viewer knows what they are opening. --}}
    <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1">
        @foreach ($tabs as $key => $meta)
            <button type="button"
                    wire:click="selectTab('{{ $key }}')"
                    @click="pendingTab = '{{ $key }}'"
                    title="{{ $meta['blurb'] }}"
                    class="tab-btn flex items-center gap-1.5 {{ $tab === $key ? 'tab-active' : 'tab-inactive' }}">
                {{ $meta['label'] }}
                @unless ($tabHasData[$key])
                    <span class="text-[9px] text-ink-subtle" title="No analysed data on file for this spec yet">&mdash;</span>
                @endunless
                <template x-if="pendingTab === '{{ $key }}' && '{{ $tab }}' !== '{{ $key }}'">
                    <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </template>
            </button>
        @endforeach
    </div>

    <p class="text-[11.5px] text-ink-muted px-1">{{ $tabs[$tab]['blurb'] }}</p>

    {{-- Exactly one panel is mounted at a time, and it is the real page component for that tab -
         no logic was duplicated into this page. :key includes the spec and the tab so switching
         either genuinely re-mounts the child rather than reusing a panel built for the old one.
         `lazy` defers each panel's real work to its own follow-up request, so the header and tab
         bar paint immediately; see PvpGuides' class docblock for why lazy lives on the tag. --}}
    <div>
        @if ($tab === 'kit')
            <livewire:class-guide :class-slug="$class->slug" :spec-slug="$spec->slug" :embedded="true"
                                  :key="'kit-'.$spec->id" lazy/>
        @elseif ($tab === 'burst')
            @if ($tabHasData['burst'])
                <livewire:burst-guide-class-block :class-slug="$class->slug" :only-spec-slug="$spec->slug"
                                                  :key="'burst-'.$spec->id" lazy/>
            @else
                <div class="linear-card px-6 py-5">
                    <p class="text-[12.5px] text-ink-muted">
                        We don't have enough recorded {{ $spec?->name }} {{ $class?->name }} matches to build
                        its offensive kit yet. Check back as more games come in.
                    </p>
                </div>
            @endif
        @elseif ($tab === 'spells')
            <livewire:spell-explorer :class-id="$class->id" :spec-id="$spec->id" :embedded="true"
                                     :key="'spells-'.$spec->id" lazy/>
        @elseif ($tab === 'counters')
            <livewire:claudes-counters :only-class-name="$class->name" :embedded="true"
                                       :key="'counters-'.$class->id" lazy/>
        @endif
    </div>

    {{-- Shared class/spec picker modal - the same markup and interaction SpellExplorer and
         WowComps use, so picking a spec feels identical wherever you are on the site. --}}
    <div x-show="classPickerOpen" x-cloak x-transition.opacity.duration.100ms x-data="{ search: '' }"
         class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="classPickerOpen = false; search = ''">
        <div class="linear-card max-w-2xl w-full p-5 relative max-h-[85vh] overflow-y-auto">
            <button type="button" @click="classPickerOpen = false; search = ''" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <input type="text" x-model="search" placeholder="Search a class or spec..."
                   class="form-input !text-[12px] !py-1.5 mb-4 w-full">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                @foreach ($classSpecs as $c)
                    @php $cc = config('wow_classes.colors')[$c->slug] ?? '#8A8A9A'; @endphp
                    <div data-search-group="{{ Str::lower($c->name.' '.$c->specializations->pluck('name')->implode(' ')) }}"
                         x-show="search === '' || $el.dataset.searchGroup.includes(search.toLowerCase())">
                        <p class="text-[11px] uppercase tracking-wide font-bold mb-2" style="color: {{ $cc }}">{{ $c->name }}</p>
                        <div class="flex flex-wrap gap-2.5">
                            @foreach ($c->specializations as $sp)
                                <button type="button"
                                        data-search="{{ Str::lower($c->name.' '.$sp->name) }}"
                                        x-show="search === '' || $el.dataset.search.includes(search.toLowerCase())"
                                        @click="
                                            pendingSpec = true;
                                            classPickerOpen = false;
                                            search = '';
                                            $wire.selectSpec({{ $c->id }}, {{ $sp->id }}).finally(() => pendingSpec = false);
                                        "
                                        title="{{ $sp->name }} {{ $c->name }}"
                                        class="rounded-md hover:ring-2 hover:ring-gold/60 transition-shadow {{ $spec && $sp->id === $spec->id ? 'ring-2 ring-gold' : '' }}">
                                    <x-spec-icon :spec="$sp" :color="$cc" size="w-12 h-12"/>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- The one shared spell-detail modal for this page. Every panel suppresses its own copy
         when embedded (see each panel's $embedded flag) - two instances would both answer the
         same show-spell-detail event and render two stacked modals. --}}
    <livewire:spell-detail-modal/>
</div>
