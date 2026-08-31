<div class="linear-card overflow-hidden" wire:key="talent-selector-{{ $specId }}">
    <div class="px-5 py-4 border-b border-line flex items-center justify-between gap-3">
        <div>
            <p class="text-[13px] font-semibold text-ink">
                {{ $specialization?->name }} Talents
                @if ($readOnly)
                    <span class="badge-gray ml-1">Read-only</span>
                @elseif ($isDefaultEditor)
                    <span class="badge-gold ml-1">Editing Default</span>
                @elseif (!auth()->check())
                    <span class="badge-gray ml-1">Preview — sign in to save</span>
                @endif
            </p>
            <p class="text-[11px] text-ink-subtle mt-0.5">
                @if ($readOnly)
                    A real talent build from an archived match — nothing here can be changed.
                @else
                    Pick your talents to see accurate cooldowns and modifiers below.
                @endif
            </p>
        </div>

        @if ($moduleHeroTreeId || $readOnly)
            {{-- This guide only covers one hero tree — no picker needed, see TalentSelector's
                 $moduleHeroTreeId docblock. Shown as a fact about the content, not a choice. --}}
            <span class="badge-gray">{{ $selectedHeroTree?->name ?? 'Hero Talent' }}</span>
        @elseif ($layout !== 'grid' && $heroTrees->isNotEmpty())
            {{-- 'grid' layout gets its own in-game-style "choose your hero talent" picker inline
                 in the tree row below instead of this plain dropdown — see that section. --}}
            <select wire:model.live="heroTreeId" class="form-select text-[12px] py-1.5">
                <option value="">Choose Hero Talent…</option>
                @foreach ($heroTrees as $tree)
                    <option value="{{ $tree->id }}">{{ $tree->name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if (!$readOnly)
    <div class="px-5 py-4 border-b border-line bg-surface-2/40">
        <p class="text-[11px] font-semibold text-ink-muted uppercase tracking-wide mb-2">Import from Blizzard</p>
        <p class="text-[10px] text-ink-subtle mb-2">
            Paste the "Export" string from the in-game talent UI (or Wowhead/Raidbots). This only sets your PvE tree picks — PvP talents aren't part of Blizzard's export format and still need to be chosen above.
        </p>

        @if ($importPreview === null)
            <div class="flex gap-2">
                <input
                    type="text"
                    wire:model="importString"
                    placeholder="Paste talent string…"
                    class="form-input flex-1 text-[12px] font-mono py-1.5"
                />
                <button type="button" wire:click="previewImport" class="btn-secondary text-[12px] py-1.5 px-3 whitespace-nowrap">
                    Preview
                </button>
            </div>
            @if ($importError)
                <p class="text-[11px] text-red-400 mt-2">{{ $importError }}</p>
            @endif
        @else
            <div class="rounded-lg border border-gold/40 bg-gold-subtle/30 p-3">
                <p class="text-[11px] font-semibold text-gold mb-2">
                    This will select {{ count($importPreview) }} talent(s) — review before applying:
                </p>
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach ($importPreview as $row)
                        <span class="badge-gold">{{ $row['spellName'] }}</span>
                    @endforeach
                </div>

                @if (!empty($importWarnings))
                    <div class="mb-3 text-[10px] text-amber-400 space-y-0.5">
                        @foreach ($importWarnings as $warning)
                            <p>⚠ {{ $warning }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="flex gap-2">
                    <button type="button" wire:click="applyImport" class="btn-primary text-[12px] py-1.5 px-3">Apply</button>
                    <button type="button" wire:click="discardImport" class="btn-ghost text-[12px] py-1.5 px-3">Discard</button>
                </div>
            </div>
        @endif
    </div>
    @endif

    {{-- readOnly: purely a CSS non-interactivity cue — every mutating method already no-ops
         server-side regardless (see TalentSelector's $readOnly docblock), this just stops the
         grid/pvp buttons from looking clickable in the first place. Deliberately not touching
         talent-tree-grid.blade.php's own button markup to add this — that partial is shared with
         the live admin editor.

         Moved onto the buttons themselves (2026-09-01) rather than the blanket
         `pointer-events: none` on this whole wrapper it used to be: that killed mouse events for
         the ENTIRE subtree, so hover tooltips never fired on the read-only page — first under the
         old CSS `group-hover` tooltips (`:hover` can't match with pointer-events disabled) and it
         would equally have killed the new JS-positioned ones. Reading what a talent actually does
         is the main point of a look-but-don't-touch view, so the buttons alone are made inert and
         the wrapper divs that carry the hover handlers stay live. Every write path is
         server-guarded anyway (see TalentSelector::$readOnly), so this is presentation only.

         Deliberately plain `pointer-events-none` on each button rather than a
         `[&_button]:pointer-events-none` arbitrary variant on this wrapper — that reads better
         but was verified NOT to compile here: a real `npm run build` produced no such rule in
         public/build/assets/*.css (nor `opacity-[0.92]`), while plain `.pointer-events-none` was
         present. Tailwind's extractor doesn't reliably pull an arbitrary variant out of a Blade
         `{{ }}` ternary in this project's setup, so relying on it would have silently shipped a
         read-only view whose buttons still looked clickable. --}}
    <div class="p-5 space-y-6 {{ $readOnly ? 'opacity-90' : '' }}">
        @if ($layout === 'grid')
            {{-- Positional tree layout mirroring the real in-game talent UI: class tree on the
                 left, hero tree (single choice) in the middle, spec tree on the right — same
                 left-to-right order and same-row placement the real client uses, replacing the
                 old always-stacked-vertically layout that forced a long page scroll just to see
                 all three trees. Each tree still sizes itself to its own real column/row count
                 (talent-tree-grid.blade.php) — a hero tree is naturally much narrower than
                 class/spec since it has far fewer nodes, so it reads as the compact middle column
                 without needing a separate "compact mode".

                 Hero-tree choice is inline here (not the header <select> above, which only
                 renders for the legacy 'list' layout) — mirrors the real in-game "Activate" popup
                 (pick one of the two, see both compared side by side, only the chosen one stays
                 visible afterward): before a tree is chosen, a placeholder card opens the picker
                 modal; nothing about the *other* option is shown anywhere until it's actually
                 picked, same as the real client. `x-data` lives on this wrapper (not the root
                 component div) so it doesn't collide with anything else on the page. --}}
            {{-- flex-nowrap + overflow-x-auto (rather than a viewport-width breakpoint like
                 `2xl:flex-nowrap`) so the three trees always stay grouped in one row and share
                 one scrollbar if they don't fit — a viewport breakpoint doesn't actually track
                 this element's own (container-capped) width, and centering + wrapping together
                 can clip the left tree's start position once scrolling kicks in. --}}
            <div x-data="{ heroPicker: false }" class="flex flex-nowrap items-start gap-4 overflow-x-auto pb-2">
                <div class="flex-shrink-0">
                    @include('livewire.partials.talent-tree-grid', ['nodes' => $classTalentNodes, 'edges' => $classTalentEdges, 'label' => 'Class Talents', 'chosenEntries' => $chosenEntries, 'pointsSpent' => $classPointsSpent])
                </div>

                <div class="flex-shrink-0">
                    @if ($moduleHeroTreeId || $readOnly || $heroTreeId)
                        @php $currentHeroOption = collect($heroTreeOptions)->first(fn ($o) => $o['tree']->id === $heroTreeId); @endphp
                        <div>
                            {{-- A small circular "identity" portrait above the hero tree, same
                                 spirit as the real in-game/Wowhead hero-tree circle — built from
                                 real data we already have (the tree's own keystone spell, see
                                 TalentSelector::getHeroTreeOptionsProperty()), not invented art.
                                 We don't have Blizzard's actual hero-tree background artwork on
                                 file (a separate asset this app has never fetched), so this is a
                                 deliberately simpler stand-in for that, not a pixel copy of it. --}}
                            @if ($currentHeroOption)
                                <div class="flex justify-center mb-2">
                                    <div class="w-12 h-12 rounded-full overflow-hidden ring-2 ring-gold/70 shadow-gold-sm">
                                        <x-spell-icon :spell="$currentHeroOption['icon']" size="w-12 h-12"/>
                                    </div>
                                </div>
                            @endif
                            @include('livewire.partials.talent-tree-grid', ['nodes' => $heroTalentNodes, 'edges' => $heroTalentEdges, 'label' => ($selectedHeroTree?->name ?? 'Hero').' Talents', 'chosenEntries' => $chosenEntries, 'pointsSpent' => $heroPointsSpent])
                            @if (!$moduleHeroTreeId && !$readOnly && count($heroTreeOptions) > 1)
                                <button type="button" x-on:click="heroPicker = true" class="mt-2 text-[11px] text-ink-subtle hover:text-gold transition">
                                    Change hero talent tree →
                                </button>
                            @endif
                        </div>
                    @elseif (!$readOnly && count($heroTreeOptions) >= 1)
                        {{-- Not chosen yet — top-aligned with the same "LABEL — n points spent"
                             heading row talent-tree-grid.blade.php renders for the other two
                             trees (not @include'd directly here since there's no tree to render
                             yet), so all three columns start at the same height instead of this
                             one floating at whatever height a plain centered box would land at
                             next to two much taller trees. --}}
                        <div>
                            <p class="text-[11px] font-semibold text-ink-muted uppercase tracking-wide mb-2">Hero Talents</p>
                            @if (count($heroTreeOptions) === 1)
                                {{-- A spec should always have exactly two hero trees by game
                                     design (see TalentTree::specializations()'s docblock) — this
                                     is a data-gap fallback, not the normal path: with only one
                                     real option there's nothing to compare, so skip the picker
                                     modal and just activate it directly on click. --}}
                                <button
                                    type="button"
                                    wire:click="$set('heroTreeId', {{ $heroTreeOptions[0]['tree']->id }})"
                                    class="w-40 h-40 flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-line-strong bg-surface-2/40 hover:border-gold/60 text-ink-subtle hover:text-gold transition"
                                >
                                    <x-spell-icon :spell="$heroTreeOptions[0]['icon']" size="w-10 h-10"/>
                                    <span class="text-[12px] font-semibold text-center px-2">{{ $heroTreeOptions[0]['tree']->name }}</span>
                                </button>
                            @else
                                <button
                                    type="button"
                                    x-on:click="heroPicker = true"
                                    class="w-40 h-40 flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-line-strong bg-surface-2/40 hover:border-gold/60 text-ink-subtle hover:text-gold transition"
                                >
                                    <x-mc-icon name="icon-compass" class="w-8 h-8"/>
                                    <span class="text-[12px] font-semibold text-center px-2">Choose Hero<br/>Talent Tree</span>
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex-shrink-0">
                    @include('livewire.partials.talent-tree-grid', ['nodes' => $specTalentNodes, 'edges' => $specTalentEdges, 'label' => ($specialization?->name ?? 'Spec').' Talents', 'chosenEntries' => $chosenEntries, 'pointsSpent' => $specPointsSpent])
                </div>

                @if (!$moduleHeroTreeId && !$readOnly && count($heroTreeOptions) > 1)
                    {{-- Both hero trees compared side by side, same shape as the real in-game
                         popup (see the reference screenshot this was built from) — but built only
                         from real, on-hand data (icon/name/talent count), never invented lore
                         text, since TalentTree carries no description column. Picking one calls
                         straight into the existing $heroTreeId property (updatedHeroTreeId()
                         already prunes any stale picks in the tree being left) — no new PHP
                         method needed, same as any other wire:model-backed picker in this app. --}}
                    <div x-show="heroPicker" x-cloak x-transition.opacity.duration.100ms
                         class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
                         @click.self="heroPicker = false">
                        <div class="linear-card max-w-2xl w-full p-6 relative">
                            <button type="button" x-on:click="heroPicker = false" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                            <h3 class="font-display text-[16px] font-bold text-ink mb-1">Choose Your Hero Talent Tree</h3>
                            <p class="text-[11px] text-ink-subtle mb-4">Only one is active at a time — picking a tree clears any points spent in the other.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                @foreach ($heroTreeOptions as $option)
                                    @php $isActive = $option['tree']->id === $heroTreeId; @endphp
                                    <div class="rounded-lg border {{ $isActive ? 'border-gold bg-gold-subtle/20' : 'border-line bg-surface-1' }} p-4 flex flex-col items-center text-center gap-3">
                                        <x-spell-icon :spell="$option['icon']" size="w-16 h-16"/>
                                        <div>
                                            <p class="font-display text-[15px] font-bold text-ink">{{ $option['tree']->name }}</p>
                                            <p class="text-[11px] text-ink-subtle mt-0.5">{{ $option['nodeCount'] }} talents</p>
                                        </div>
                                        @if ($isActive)
                                            <span class="badge-gold">Active</span>
                                        @else
                                            <button
                                                type="button"
                                                x-on:click="
                                                    @if ($heroPointsSpent > 0)
                                                        if (!confirm('Switch to {{ $option['tree']->name }}? This clears your points in {{ $selectedHeroTree?->name }}.')) return;
                                                    @endif
                                                    $wire.set('heroTreeId', {{ $option['tree']->id }});
                                                    heroPicker = false;
                                                "
                                                class="btn-primary text-[12px] py-1.5 px-4"
                                            >
                                                Activate
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @else
            @foreach ([
                ['label' => 'Class Talents', 'nodes' => $classTalentNodes],
                ['label' => ($specialization?->name ?? 'Spec').' Talents', 'nodes' => $specTalentNodes],
                ['label' => ($selectedHeroTree?->name ?? 'Hero').' Talents', 'nodes' => $heroTalentNodes],
            ] as $section)
                @if ($section['nodes']->isNotEmpty())
                    <div>
                        <p class="text-[11px] font-semibold text-ink-muted uppercase tracking-wide mb-2">{{ $section['label'] }}</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            @foreach ($section['nodes'] as $node)
                                @php $byRank = $node->entries->groupBy('rank'); @endphp
                                <div class="rounded-lg border border-line bg-surface-1 p-2.5">
                                    @foreach ($byRank as $rank => $entries)
                                        <div class="flex flex-wrap gap-1.5 {{ !$loop->last ? 'mb-1.5' : '' }}">
                                            @foreach ($entries as $entry)
                                                @continue(!$entry->spell)
                                                @php $isChosen = ($chosenEntries[$node->id] ?? null) === $entry->id; @endphp
                                                <button
                                                    type="button"
                                                    wire:click="toggleEntry({{ $node->id }}, {{ $entry->id }})"
                                                    title="{{ $entry->spell->description }}"
                                                    class="flex-1 min-w-[8rem] px-2.5 py-2 rounded-md border text-left text-[12px] transition
                                                        {{ $isChosen ? 'border-gold bg-gold-subtle' : 'border-line bg-surface-2 hover:border-line-strong' }}"
                                                >
                                                    <span class="font-semibold {{ $isChosen ? 'text-gold' : 'text-ink' }}">{{ $entry->spell->display_name }}</span>
                                                    @if ($node->max_ranks > 1)
                                                        <span class="text-ink-subtle text-[10px]"> · Rank {{ $rank }}</span>
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @endif

        @if ($pvpTalents->isNotEmpty())
            <div>
                <div class="flex items-center justify-between mb-2">
                    <p class="text-[11px] font-semibold text-ink-muted uppercase tracking-wide">PvP Talents</p>
                    <span class="text-[11px] text-ink-subtle">{{ count($chosenPvpTalentIds) }}/3 selected</span>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($pvpTalents as $talent)
                        @continue(!$talent->spell)
                        @php
                            $isChosen = in_array($talent->id, $chosenPvpTalentIds, true);
                            $isFull = !$isChosen && count($chosenPvpTalentIds) >= 3;
                        @endphp
                        <button
                            type="button"
                            wire:click="togglePvpTalent({{ $talent->id }})"
                            @disabled($isFull)
                            title="{{ $talent->spell->description }}"
                            class="flex items-center gap-2 px-2.5 py-2 rounded-md border text-left text-[12px] transition
                                {{ $isChosen ? 'border-violet bg-violet-subtle' : 'border-line bg-surface-2 hover:border-line-strong' }}
                                {{ $isFull ? 'opacity-40 cursor-not-allowed' : '' }}
                                {{ $readOnly ? 'pointer-events-none' : '' }}"
                        >
                            <x-spell-icon :spell="$talent->spell" size="w-7 h-7"/>
                            <span class="font-semibold {{ $isChosen ? 'text-violet-hover' : 'text-ink' }}">{{ $talent->spell->display_name }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
