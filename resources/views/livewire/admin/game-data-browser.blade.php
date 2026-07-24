<div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
    <div class="max-w-6xl mx-auto space-y-6">

        {{-- Header --}}
        <div>
            <h1 class="text-[17px] font-semibold text-ink">Game Data Browser</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">
                Internal inspection tool for the imported WoW reference data — not intended to be polished.
            </p>
        </div>

        {{-- Cascading selectors --}}
        <div class="linear-card p-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1 block">Class</label>
                    <select wire:model.live="classId" class="form-select w-full">
                        <option value="">— Select a class —</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1 block">Specialization</label>
                    <select wire:model.live="specId" class="form-select w-full" @if (!$classId) disabled @endif>
                        <option value="">— Select a specialization —</option>
                        @foreach ($specializations as $spec)
                            <option value="{{ $spec->id }}">{{ $spec->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1 block">Hero Talent Tree (optional)</label>
                    <select wire:model.live="heroTreeId" class="form-select w-full" @if (!$classId) disabled @endif>
                        <option value="">— None —</option>
                        @foreach ($heroTrees as $tree)
                            <option value="{{ $tree->id }}">{{ $tree->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        @if (!$selectedClass || !$selectedSpec)
            <div class="linear-card p-8 text-center">
                <p class="text-[13px] text-ink-muted">Select a class and a specialization to see the breakdown.</p>
            </div>
        @else
            {{-- Completeness-at-a-glance strip --}}
            <div class="linear-card p-4">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-[12px]">
                    <span class="text-ink-subtle">
                        <span class="text-ink font-semibold">{{ $selectedClass->name }}</span> / {{ $selectedSpec->name }}
                        @if ($selectedHeroTree) / {{ $selectedHeroTree->name }} @endif
                    </span>
                    <span class="text-ink-muted">Baseline: <span class="text-ink font-medium">{{ $baselineSpells->count() }}</span></span>
                    <span class="text-ink-muted">Class Talents: <span class="text-ink font-medium">{{ $classTalentNodes->flatMap->entries->count() }}</span> ({{ $classTalentNodes->count() }} nodes)</span>
                    <span class="text-ink-muted">Spec Talents: <span class="text-ink font-medium">{{ $specTalentNodes->flatMap->entries->count() }}</span> ({{ $specTalentNodes->count() }} nodes)</span>
                    @if ($selectedHeroTree)
                        <span class="text-ink-muted">Hero Talents: <span class="text-ink font-medium">{{ $heroTalentNodes->flatMap->entries->count() }}</span> ({{ $heroTalentNodes->count() }} nodes)</span>
                    @endif
                    <span class="text-ink-muted">PvP Talents: <span class="text-ink font-medium">{{ $pvpTalents->count() }}</span></span>
                </div>
                @if (!$classTalentTree)
                    <p class="text-[11px] text-red-400 mt-2">No class talent tree found for {{ $selectedClass->name }} — check the import.</p>
                @endif
                @if (!$specTalentTree)
                    <p class="text-[11px] text-red-400 mt-2">No spec talent tree found for {{ $selectedSpec->name }} — check the import.</p>
                @endif
            </div>

            {{-- Baseline Abilities --}}
            <x-admin.spell-section
                title="Baseline Abilities"
                :spells="$baselineSpells"
                source="Baseline"
                note="Available regardless of spec — from {{ $selectedClass->name }}'s baseline.txt"
            />

            {{-- Class Talents --}}
            <x-admin.talent-tree-section title="Class Talents" :nodes="$classTalentNodes" source="Class Talent"/>

            {{-- Specialization Talents --}}
            <x-admin.talent-tree-section title="{{ $selectedSpec->name }} Talents" :nodes="$specTalentNodes" source="Spec Talent"/>

            {{-- Hero Talent abilities (only when a hero tree is selected) --}}
            @if ($selectedHeroTree)
                <x-admin.talent-tree-section title="{{ $selectedHeroTree->name }} (Hero Talents)" :nodes="$heroTalentNodes" source="Hero Talent"/>
            @endif

            {{-- PvP Talents --}}
            <div class="linear-card overflow-hidden">
                <div class="px-5 py-4 border-b border-line flex items-center justify-between">
                    <p class="text-[13px] font-semibold text-ink">PvP Talents</p>
                    <span class="text-[11px] text-ink-subtle">{{ $pvpTalents->count() }} talent(s)</span>
                </div>
                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
                    @forelse ($pvpTalents as $pvpTalent)
                        @if ($pvpTalent->spell)
                            <x-admin.game-spell-card
                                :spell="$pvpTalent->spell"
                                source="PvP Talent"
                                :context="'Unlocks at level '.$pvpTalent->unlock_level"
                            />
                        @endif
                    @empty
                        <p class="text-[12px] text-ink-subtle">No PvP talents found for {{ $selectedSpec->name }}.</p>
                    @endforelse
                </div>
            </div>

            {{-- Passive abilities (if available) --}}
            @if ($passiveLikeSpells->isNotEmpty())
                <div class="linear-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-line">
                        <p class="text-[13px] font-semibold text-ink">Passive Abilities (if available)</p>
                        <p class="text-[11px] text-ink-subtle mt-0.5">
                            Heuristic only — the schema has no explicit passive/cast-time flag. This just flags spells
                            whose description mentions the word "passive"; expect false negatives, not a source of truth.
                        </p>
                    </div>
                    <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach ($passiveLikeSpells as $tagged)
                            <x-admin.game-spell-card :spell="$tagged['spell']" :source="$tagged['source']"/>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
