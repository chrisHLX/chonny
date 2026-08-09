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
@endphp

<div class="max-w-6xl mx-auto px-4 py-8 space-y-5"
     x-data="{
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
                    || (this.filter === 'main-cooldowns' && (row.dataset.hasCooldown === '1' || row.dataset.category === 'Crowd Control'))
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
     x-init="$nextTick(() => applyFilters())">

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

    {{-- Class / Spec pickers — the real <select> is an invisible overlay covering the whole
         box, so the browser handles interaction/native option list while everything visible is
         our own markup. Avoids any cross-browser native-<select>-appearance quirks entirely. --}}
    <div class="flex flex-wrap gap-3">
        <div class="relative flex items-center gap-3 linear-card px-4 py-2.5 w-full sm:w-64">
            <div class="w-9 h-9 rounded-lg bg-gold/10 border border-gold/20 flex items-center justify-center shrink-0 pointer-events-none">
                <x-mc-icon name="badge-wow" class="w-4 h-4 text-gold"/>
            </div>
            <div class="flex-1 min-w-0 pointer-events-none">
                <label class="block text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Class</label>
                <p class="text-[14px] font-semibold text-ink truncate">{{ $selectedClass?->name ?? 'Choose class…' }}</p>
            </div>
            <svg class="w-3.5 h-3.5 text-ink-subtle shrink-0 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
            <select wire:model.live="classId"
                    class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" aria-label="Class">
                <option value="">Choose class…</option>
                @foreach ($classes as $class)
                    <option value="{{ $class->id }}">{{ $class->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="relative flex items-center gap-3 linear-card px-4 py-2.5 w-full sm:w-64">
            <div class="w-9 h-9 rounded-lg bg-violet/10 border border-violet/20 flex items-center justify-center shrink-0 pointer-events-none">
                <x-mc-icon name="icon-axis-hex" class="w-4 h-4 text-violet"/>
            </div>
            <div class="flex-1 min-w-0 pointer-events-none">
                <label class="block text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Specialization</label>
                <p class="text-[14px] font-semibold text-ink truncate">{{ $selectedSpec?->name ?? 'Choose spec…' }}</p>
            </div>
            <svg class="w-3.5 h-3.5 text-ink-subtle shrink-0 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
            @if ($classId)
                <select wire:model.live="specId"
                        class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" aria-label="Specialization">
                    <option value="">Choose spec…</option>
                    @foreach ($specializations as $spec)
                        <option value="{{ $spec->id }}">{{ $spec->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    @if ($specId)
        {{-- Filter tabs + search --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1">
                <button type="button" @click="setFilter('all')" class="tab-btn flex items-center gap-1.5" :class="filter === 'all' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z"/></svg>
                    All Spells
                </button>
                <button type="button" @click="setFilter('group:active')" class="tab-btn flex items-center gap-1.5" :class="filter === 'group:active' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Active Abilities
                </button>
                <button type="button" @click="setFilter('main-cooldowns')" class="tab-btn flex items-center gap-1.5" :class="filter === 'main-cooldowns' ? 'tab-active' : 'tab-inactive'">
                    <x-mc-icon name="icon-hourglass" class="w-3.5 h-3.5"/>
                    Main Cooldowns
                </button>
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
                description="Cooldowns and charges reflect this spec's admin-curated default talent build (see /admin/talent-builds) — not a personal build."
            />
        </div>

        <div x-show="!hasResults" x-cloak class="linear-card p-6 text-center text-[13px] text-ink-muted">
            No spells match your filters.
        </div>

        @if (empty($entries))
            <div class="linear-card p-5 text-[13px] text-ink-muted">
                No default talent build has been set for this spec yet — configure one at
                <a href="{{ route('admin.talent-builds') }}" class="text-gold hover:underline">/admin/talent-builds</a>
                to see spells here.
            </div>
        @endif
    @endif
</div>
