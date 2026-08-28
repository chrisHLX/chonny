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
        @elseif ($heroTrees->isNotEmpty())
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

    {{-- readOnly: purely a CSS non-interactivity cue (pointer-events-none + slightly reduced
         opacity) — every mutating method already no-ops server-side regardless (see
         TalentSelector's $readOnly docblock), this just stops the grid/pvp buttons from looking
         clickable in the first place. Deliberately not touching talent-tree-grid.blade.php's own
         button markup to add this — that partial is shared with the live admin editor. --}}
    <div class="p-5 space-y-6" @if ($readOnly) style="pointer-events: none; opacity: 0.92;" @endif>
        @if ($layout === 'grid')
            {{-- Positional tree layout mirroring the real in-game/Wowhead-style talent UI — see
                 talent-tree-grid.blade.php. Used by Admin\TalentBuildEditor (as of 2026-08-27);
                 the flat card list below is kept as the default for any future caller that
                 doesn't need positioning/edges/lock enforcement, but nothing currently uses it. --}}
            @include('livewire.partials.talent-tree-grid', ['nodes' => $classTalentNodes, 'edges' => $classTalentEdges, 'label' => 'Class Talents', 'chosenEntries' => $chosenEntries, 'pointsSpent' => $classPointsSpent])
            @include('livewire.partials.talent-tree-grid', ['nodes' => $specTalentNodes, 'edges' => $specTalentEdges, 'label' => ($specialization?->name ?? 'Spec').' Talents', 'chosenEntries' => $chosenEntries, 'pointsSpent' => $specPointsSpent])
            @include('livewire.partials.talent-tree-grid', ['nodes' => $heroTalentNodes, 'edges' => $heroTalentEdges, 'label' => ($selectedHeroTree?->name ?? 'Hero').' Talents', 'chosenEntries' => $chosenEntries, 'pointsSpent' => $heroPointsSpent])
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
                                {{ $isFull ? 'opacity-40 cursor-not-allowed' : '' }}"
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
