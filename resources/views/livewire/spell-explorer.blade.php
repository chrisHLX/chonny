@php
    $entries = $specId ? $this->spellReferences : [];
    $selectedSpec = $specializations->firstWhere('id', $specId);
    $selectedClass = $classes->firstWhere('id', $classId);
    $pageTitle = $selectedSpec && $selectedClass
        ? "{$selectedSpec->name} {$selectedClass->name} Spells"
        : ($selectedClass ? "{$selectedClass->name} Spells" : 'Spell Explorer');
    $pageSubtitle = $selectedSpec
        ? "Explore {$selectedSpec->name} {$selectedClass->name} spells, their cooldowns, and what modifies them."
        : ($selectedClass
            ? 'Choose a specialization to see its full spell kit.'
            : "Pick a class and spec to see its spells — cooldowns and charges reflect that spec's default talent build.");
    $heroSpell = $entries[0]['spell'] ?? null;
    // Only show the "Priority Spells" filter when this spec actually has real arena-log
    // spell-usage evidence (data/arena-logs/spell-usage/{class}/{spec}.txt) tagging at least one
    // entry — a spec with no matches processed for it yet has nothing to filter down to.
    $hasPriorityData = collect($entries)->contains(fn ($e) => $e['isPriority'] ?? false);
    // FIXED 2026-08-12: this used to be inlined directly as the <x-spells.table :description="...">
    // attribute's value — a multi-line ternary with an escaped double-quote nested inside the
    // attribute's own double-quote delimiter. Confirmed via direct render output that Blade's
    // component-tag compiler failed to recognize <x-spells.table> as a component at all when
    // written that way — it passed straight through as literal, uncompiled text (the tag syntax
    // itself, unrendered, sat in the page's HTML), which is why the page showed "No spells match
    // your filters": the real table markup, and therefore every data-role="spell-row" element
    // applyFilters() looks for, never existed in the DOM at all. Extracting to a plain variable
    // here removes the fragile nested-quote pattern from the component tag entirely.
    $spellsTableDescription = $usingPersonalBuild
        ? "Every talent and PvP talent for this spec, tagged by source. Greyed-out \"Not selected\" rows aren't part of your own saved build; cooldowns/charges on selected rows reflect your actual picks."
        : "Every talent and PvP talent for this spec, tagged by source. Greyed-out \"Not selected\" rows aren't part of this spec's admin-curated default build; cooldowns/charges on selected rows reflect that build's actual picks.";
@endphp

<div class="max-w-6xl mx-auto px-4 py-8 space-y-5"
     x-data="{
        classPickerOpen: false,
        pendingSpec: false,
        filter: 'all',
        search: '',
        hasResults: true,
        setFilter(f) { this.filter = f; this.applyFilters(); },
        applyFilters() {
            const term = this.search.trim().toLowerCase();
            const body = this.$refs.spellTableBody;
            if (!body) return;
            const visibleSections = new Set();
            body.querySelectorAll('[data-role=spell-row]').forEach(row => {
                const matchesFilter = this.filter === 'all'
                    || (this.filter === 'priority' && row.dataset.priority === '1')
                    || this.filter === 'group:' + row.dataset.group
                    || this.filter === 'category:' + row.dataset.category;
                const matchesSearch = !term || row.dataset.search.includes(term);
                const show = matchesFilter && matchesSearch;
                row.classList.toggle('hidden', !show);
                if (show) visibleSections.add(row.dataset.group + '|' + row.dataset.category);
            });
            body.querySelectorAll('[data-role=category-header]').forEach(h => {
                h.classList.toggle('hidden', !visibleSections.has(h.dataset.group + '|' + h.dataset.category));
            });
            body.querySelectorAll('[data-role=group-header]').forEach(h => {
                const anyVisible = Array.from(visibleSections).some(key => key.startsWith(h.dataset.group + '|'));
                h.classList.toggle('hidden', !anyVisible);
            });
            this.hasResults = visibleSections.size > 0;
        }
     }"
     x-init="
        $nextTick(() => applyFilters());
        if ($refs.spellTableBody) {
            new MutationObserver(() => applyFilters()).observe($refs.spellTableBody, { childList: true, subtree: true });
        }
     "
     x-on:spell-list-refreshed.window="$nextTick(() => applyFilters())">
    {{-- FIXED 2026-08-18: the 2026-08-12 fix below (dispatching 'spell-list-refreshed' after
         selectSpec(), re-running applyFilters() on that event) assumed Livewire's dispatched
         event always fires strictly after its DOM morph completes. Confirmed via direct testing
         2026-08-18 that a real duplicate-tab report ("Priority Spells page shows 'No spells
         match your filters' over real rows, but only when the same spec was already computed —
         i.e. cached — by WoW Comps first") was NOT a server/cache bug — reproduced the exact
         shared-cache-key read (WowComps computes a spec, SpellExplorer reads the identical Redis
         key) directly and got correct, non-empty data back every time. The remaining explanation
         is that a warm cache makes the round trip fast enough to expose a real ordering race
         between the dispatched event and the morph that a cold, slower compute was masking.
         Added a MutationObserver on $refs.spellTableBody as the primary trigger instead — it
         reacts to the actual DOM content changing, not a guessed event-ordering window, so it
         can't race regardless of how fast the server responds. The event listener below is kept
         as a harmless redundant trigger, not the load-bearing fix anymore.
    FIXED 2026-08-12: applyFilters() is a client-side DOM scan that used to only ever run
         once (x-init, on first paint). Switching class/spec (wire:model.live) or closing the
         talent picker re-renders the spell rows server-side but never re-ran this JS, so
         hasResults could get stuck showing "No spells match your filters" over real,
         correctly-rendered rows underneath. SpellExplorer::updatedClassId()/updatedSpecId()/
         closeTalentPicker() now dispatch 'spell-list-refreshed' after every such change; this
         listener re-runs applyFilters() against the freshly-morphed DOM in response. --}}

    {{-- Hero --}}
    <div class="linear-card relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.15] pointer-events-none select-none text-gold" aria-hidden="true">
            <svg class="absolute -right-10 -top-16 w-72 h-72" viewBox="-144 -144 288 288" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="0" cy="0" r="140" stroke="currentColor" stroke-width="1"/>
                <circle cx="0" cy="0" r="105" stroke="currentColor" stroke-width="0.7"/>
                <circle cx="0" cy="0" r="70" stroke="currentColor" stroke-width="0.5"/>
                <line x1="-140" y1="0" x2="140" y2="0" stroke="currentColor" stroke-width="0.4"/>
                <line x1="0" y1="-140" x2="0" y2="140" stroke="currentColor" stroke-width="0.4"/>
                <circle cx="0" cy="0" r="4" fill="currentColor"/>
            </svg>
        </div>

        <div class="relative flex items-center gap-4 px-6 py-7 flex-wrap">
            @if ($heroSpell)
                <x-spell-icon :spell="$heroSpell" size="w-16 h-16" class="rounded-xl shadow-gold-sm !border-line-gold"/>
            @else
                <div class="w-16 h-16 rounded-xl bg-gold-gradient shadow-gold flex items-center justify-center shrink-0">
                    <x-mc-icon name="badge-wow" class="w-9 h-9 text-surface-0"/>
                </div>
            @endif

            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Spell Explorer</p>
                <h1 class="font-display text-[26px] sm:text-[28px] font-bold text-ink leading-tight mt-0.5">{{ $pageTitle }}</h1>
                <p class="text-[13px] text-ink-muted mt-1 max-w-xl">{{ $pageSubtitle }}</p>
            </div>
        </div>
    </div>

    {{-- Class/spec picker — matches WowComps' picker exactly (2026-08-17, direct request): one
         button opens a shared full-grid modal (every class, 2-column, class-colored header, specs
         as a row of colored icon buttons) instead of the old pair of native <select> overlays.
         Replaces WowComps::selectSpec($index, ...)'s per-slot signature with a single
         selectSpec($classId, $specId) — this page only ever has one "slot." --}}
    @php
        $selectedColor = $selectedClass ? (config('wow_classes.colors')[$selectedClass->slug] ?? '#8A8A9A') : null;
    @endphp
    <div class="linear-card p-4 max-w-md">
        <button type="button"
                @click="classPickerOpen = true"
                :disabled="pendingSpec"
                :class="pendingSpec && 'opacity-60 cursor-wait'"
                class="w-full flex items-center gap-3 text-left px-2.5 py-2 rounded-lg border border-line hover:border-gold/40 transition-colors">
            @if ($selectedSpec)
                <x-spec-icon :spec="$selectedSpec" :color="$selectedColor" size="w-9 h-9"/>
                <span class="min-w-0">
                    <span class="block text-[13px] font-semibold text-ink truncate">{{ $selectedSpec->name }}</span>
                    <span class="block text-[11px] text-ink-muted truncate">{{ $selectedClass->name }}</span>
                </span>
            @else
                <div class="w-9 h-9 rounded-md border border-line-strong bg-surface-2 flex items-center justify-center flex-shrink-0">
                    <x-mc-icon name="badge-wow" class="w-4 h-4 text-ink-subtle"/>
                </div>
                <span class="text-[13px] text-ink-muted">Choose class &amp; spec…</span>
            @endif
            <template x-if="pendingSpec">
                <svg class="animate-spin w-4 h-4 text-gold ml-auto flex-shrink-0" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </template>
            <template x-if="!pendingSpec">
                <svg class="w-3.5 h-3.5 text-ink-subtle ml-auto flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </template>
        </button>
        <p x-show="pendingSpec" x-cloak class="text-[10px] text-gold-light mt-1.5">Loading spells…</p>
    </div>

    <div x-show="classPickerOpen" x-cloak x-transition.opacity.duration.100ms x-data="{ search: '' }"
         class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="classPickerOpen = false; search = ''">
        <div class="linear-card max-w-2xl w-full p-5 relative max-h-[85vh] overflow-y-auto">
            <button type="button" @click="classPickerOpen = false; search = ''" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <input type="text" x-model="search" placeholder="Search a class or spec…"
                   class="form-input !text-[12px] !py-1.5 mb-4 w-full">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                @foreach ($classSpecs as $class)
                    @php $classColor = config('wow_classes.colors')[$class->slug] ?? '#8A8A9A'; @endphp
                    <div data-search-group="{{ Str::lower($class->name.' '.$class->specializations->pluck('name')->implode(' ')) }}"
                         x-show="search === '' || $el.dataset.searchGroup.includes(search.toLowerCase())">
                        <p class="text-[11px] uppercase tracking-wide font-bold mb-2" style="color: {{ $classColor }}">{{ $class->name }}</p>
                        <div class="flex flex-wrap gap-2.5">
                            @foreach ($class->specializations as $spec)
                                <button type="button"
                                        data-search="{{ Str::lower($class->name.' '.$spec->name) }}"
                                        x-show="search === '' || $el.dataset.search.includes(search.toLowerCase())"
                                        @click="
                                            pendingSpec = true;
                                            classPickerOpen = false;
                                            search = '';
                                            $wire.selectSpec({{ $class->id }}, {{ $spec->id }}).finally(() => pendingSpec = false);
                                        "
                                        title="{{ $spec->name }} {{ $class->name }}"
                                        class="rounded-md hover:ring-2 hover:ring-gold/60 transition-shadow">
                                    <x-spec-icon :spec="$spec" :color="$classColor" size="w-12 h-12"/>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if ($specId)
        {{-- Filter tabs + search --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1">
                <button type="button" @click="setFilter('all')" class="tab-btn flex items-center gap-1.5" :class="filter === 'all' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z"/></svg>
                    All Spells
                </button>
                @if ($hasPriorityData)
                <button type="button" @click="setFilter('priority')" class="tab-btn flex items-center gap-1.5" :class="filter === 'priority' ? 'tab-active' : 'tab-inactive'" title="Spells actually seen cast in real ranked arena matches">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8l-6.2 4.5 2.4-7.4L2 9.4h7.6z"/></svg>
                    Priority Spells
                </button>
                @endif
                <button type="button" @click="setFilter('category:Utility')" class="tab-btn flex items-center gap-1.5" :class="filter === 'category:Utility' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Utility
                </button>
                <button type="button" @click="setFilter('category:Defensive')" class="tab-btn flex items-center gap-1.5" :class="filter === 'category:Defensive' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Defensive
                </button>
                <button type="button" @click="setFilter('category:Offensive')" class="tab-btn flex items-center gap-1.5" :class="filter === 'category:Offensive' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/></svg>
                    Offensive
                </button>
                <button type="button" @click="setFilter('group:passive')" class="tab-btn flex items-center gap-1.5" :class="filter === 'group:passive' ? 'tab-active' : 'tab-inactive'">
                    <x-mc-icon name="icon-leaf" class="w-3.5 h-3.5"/>
                    Buffs
                </button>
                <button type="button" @click="setFilter('category:Crowd Control')" class="tab-btn flex items-center gap-1.5" :class="filter === 'category:Crowd Control' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21a9 9 0 100-18 9 9 0 000 18z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636L5.636 18.364"/></svg>
                    Crowd Control
                </button>
                </div>
            </div>

            <div class="relative w-full sm:w-56">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink-subtle pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" x-model="search" @input="applyFilters()" placeholder="Search spells…"
                       class="form-input !pl-9">
            </div>
        </div>

        <div x-ref="spellTableBody" class="contents">
            <x-spells.table
                :entries="$entries"
                title=""
                :description="$spellsTableDescription"
            />
        </div>

        <div x-show="!hasResults" x-cloak class="linear-card p-6 text-center text-[13px] text-ink-muted">
            No spells match your filters.
        </div>

        @if (empty($entries))
            <div class="linear-card p-5 text-[13px] text-ink-muted">
                No talent tree, PvP talent, or baseline-ability data is imported for this spec yet.
            </div>
        @endif
    @endif
</div>
