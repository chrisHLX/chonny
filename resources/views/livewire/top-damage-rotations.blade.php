@php
    $selectedSpec = $classSpecs->flatMap->specializations->firstWhere('id', $specId);
    $selectedClass = $classes->firstWhere('id', $classId);
    $selectedColor = $selectedClass ? (config('wow_classes.colors')[$selectedClass->slug] ?? '#8A8A9A') : null;
    $pageTitle = $selectedSpec && $selectedClass
        ? "{$selectedSpec->name} {$selectedClass->name} — Burst Windows"
        : 'Burst Windows';
    $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
    // See wow-comps.blade.php's identical helper for the full rationale — same generated_at
    // field, same "Updated ..." label, kept consistent across both consumers of this data.
    // Explicit isToday() check (2026-08-27, direct request) rather than diffForHumans() alone —
    // a re-run that finds the exact same winning window (no new matches changed the result)
    // still gets a fresh generated_at stamp every time (see offensive-rotations.php's
    // unconditional write), but diffForHumans() would render that as "2 hours ago" instead of
    // plainly confirming "yes, the script ran today" — the actual thing a viewer wants to know.
    // Falls back to diffForHumans() for anything older than today, unchanged.
    $fmtRotationDate = function (?string $iso) {
        if (!$iso) return null;
        try {
            $date = \Carbon\Carbon::parse($iso);
            return $date->isToday() ? 'Today' : $date->diffForHumans();
        } catch (\Throwable) {
            return null;
        }
    };
    // Label + badge color per TopDamageRotations::getMechanicsProperty()'s `kind` classification.
    $kindBadge = [
        'talent' => ['label' => 'Talent', 'class' => 'badge-gold'],
        'pvp_talent' => ['label' => 'PvP Talent', 'class' => 'badge-amber'],
        'passive' => ['label' => 'Passive', 'class' => 'badge-gray'],
        'ability' => ['label' => 'Ability', 'class' => 'badge-green'],
    ];
@endphp

<div class="max-w-5xl mx-auto px-4 py-8 space-y-5" x-data="{ classPickerOpen: false, pendingSpec: false, talentModalOpen: false }">
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Burst Windows</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">{{ $pageTitle }}</h1>
        <p class="text-[12px] text-ink-muted mt-1">
            The single highest-damage real burst window found for a spec at a chosen length — a real example, not a "most common" claim. Same data and method as WoW Comps' Burst Window tab, just pick your own class/spec and time window here.
        </p>
    </div>

    {{-- Class/spec picker — same shared grid modal as WowComps/SpellExplorer (see
         SpellExplorer.blade.php's own docblock: "matches WowComps' picker exactly"). Single
         selection — this page only ever looks at one spec at a time. --}}
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
        <p x-show="pendingSpec" x-cloak class="text-[10px] text-gold-light mt-1.5">Loading…</p>
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
        {{-- Length selector — 6/12/20/30s, matches offensive-rotations.php's
             BURST_LENGTHS_SECONDS minus 10s (only ever the interim bracket WowComps' own tab
             used before 12s replaced it — not offered as a separate choice here). --}}
        <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1 w-fit">
            @foreach (\App\Livewire\TopDamageRotations::LENGTHS as $len)
                <button type="button" wire:click="selectLength({{ $len }})"
                        class="tab-btn" :class="'{{ $length === $len ? 'tab-active' : 'tab-inactive' }}'">
                    {{ $len }}s
                </button>
            @endforeach
        </div>

        {{-- Rotation display — identical shape to WowComps' "Peak Burst Example" card (see that
             class's getOffensiveRotationsProperty() docblock), just single-spec instead of
             looped over 3 comp members. Click-to-detail uses the shared SpellDetailModal
             component instead of WowComps' own inline per-entry modal block, since this page
             has no pre-computed "full entries" list to key a matching modal content block
             against — SpellDetailModal computes everything on demand from just a spell_id. --}}
        <div class="linear-card p-5">
            @if (!$rotation)
                <p class="text-[12px] text-ink-subtle italic">No arena-log data for this spec yet.</p>
            @else
                <div class="flex items-baseline justify-between gap-3 mb-4">
                    <p class="text-[11px] uppercase tracking-wide text-ink-subtle font-semibold">
                        {{ number_format($rotation['windows']) }} cooldown windows across {{ $rotation['matches'] }} matches
                    </p>
                </div>

                @if (!$rotation['window'])
                    <p class="text-[12px] text-ink-subtle italic">Not enough match evidence for a {{ $length }}s rotation on this spec yet.</p>
                @else
                    @php $window = $rotation['window']; $rotUpdated = $fmtRotationDate($rotation['generatedAt'] ?? null); @endphp
                    <div class="flex items-baseline gap-2 mb-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-wider text-gold font-semibold">Peak Burst Example</p>
                        <span class="text-[10px] text-ink-subtle">
                            {{ number_format($window['damage']) }} damage in {{ $fmtSeconds($window['durationSeconds']) }}{{ $window['killed'] ? ' — killed the target' : '' }}
                        </span>
                        @if ($rotUpdated)
                            <span class="text-[10px] text-ink-subtle/70 ml-auto" title="When this spec's match analysis was last run">Updated {{ $rotUpdated }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($window['steps'] as $si => $step)
                            @if ($si > 0)
                                <svg class="w-3.5 h-3.5 text-ink-subtle flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                </svg>
                            @endif
                            @php
                                $stepSpell = $step['spell'] ?? null;
                                $isAnchor = in_array($step['name'], $rotation['anchors'] ?? [], true);
                                // Same DR-greying rule as WowComps' rotation tab — a second use
                                // of the same CC inside one go is diminished (100/50/immune, see
                                // CLAUDE.md's DR rules), so it genuinely isn't worth the first.
                                $isDrDimmed = ($step['isRepeat'] ?? false) && ($step['isCc'] ?? false);
                            @endphp
                            @if ($stepSpell)
                                <button type="button"
                                        wire:click="$dispatch('show-spell-detail', { spellId: {{ $stepSpell->id }}, classId: {{ $classId }}, specId: {{ $specId }} })"
                                        class="linear-card !p-2.5 w-36 flex-shrink-0 text-left hover:border-gold/40 transition-colors {{ $isAnchor ? '!border-gold/50' : '' }} {{ $isDrDimmed ? 'opacity-45' : '' }}"
                                        @if ($isDrDimmed) title="Second use of this CC in the same go — diminished (50%)" @endif>
                                    <div class="flex items-center gap-2">
                                        <x-spell-icon :spell="$stepSpell" size="w-8 h-8"/>
                                        <span class="min-w-0">
                                            <span class="block text-[11px] {{ $isDrDimmed ? 'text-ink-muted' : 'text-ink' }} font-semibold truncate">{{ $stepSpell->display_name }}</span>
                                            @if ($isAnchor)
                                                <span class="badge-gold !text-[8px] !px-1 !py-0 mt-0.5">CD</span>
                                            @elseif ($isDrDimmed)
                                                <span class="badge-gray !text-[8px] !px-1 !py-0 mt-0.5">DR</span>
                                            @endif
                                        </span>
                                    </div>
                                </button>
                            @else
                                <div class="linear-card !p-2.5 w-36 flex-shrink-0">
                                    <span class="block text-[11px] text-ink font-semibold truncate">{{ $step['name'] }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- Talent Build — the real talents the player who produced this exact window actually
             had selected, embedded once at generation time by wow:enrich-rotation-talents (see
             that command's docblock: read straight from the archived match's own COMBATANT_INFO
             line, not a curated/admin-default build). Only renders when that field exists — an
             older, not-yet-re-enriched window simply omits the whole card rather than showing a
             misleading empty one. 2026-08-27 direct request. --}}
        @if ($rotation['window']['talentBuild'] ?? null)
            @php
                $tb = $rotation['window']['talentBuild'];
                $treeLabels = ['class' => 'Class', 'spec' => 'Spec', 'hero' => 'Hero'];
                $talentsByTree = collect($tb['talents'])->groupBy('treeType');
                $exportText = "Talent Build — {$pageTitle} (from a real archived match, ".now()->format('Y-m-d').")\n\n";
                foreach ($treeLabels as $key => $label) {
                    $group = $talentsByTree->get($key, collect());
                    if ($group->isEmpty()) continue;
                    $exportText .= "{$label} Talents:\n";
                    foreach ($group as $t) {
                        $exportText .= "  - {$t['name']}" . ($t['rank'] > 1 ? " (rank {$t['rank']})" : '') . "\n";
                    }
                    $exportText .= "\n";
                }
                if (!empty($tb['pvpTalents'])) {
                    $exportText .= "PvP Talents:\n";
                    foreach ($tb['pvpTalents'] as $t) {
                        $exportText .= "  - {$t['name']}\n";
                    }
                }
            @endphp
            {{-- `talentModalOpen` lives on the page-level x-data (top of file), NOT here — the
                 Copy-as-Text modal below is a sibling of this card, not a child, so a nested
                 scope here would leave that modal bound to an undefined variable (a blurred,
                 click-eating overlay nothing could dismiss). --}}
            <div class="linear-card p-5">
                <div class="flex items-baseline justify-between gap-3 mb-3 flex-wrap">
                    <p class="text-[10px] uppercase tracking-wider text-gold font-semibold">Talent Build Used</p>
                    <div class="flex items-center gap-2">
                        {{-- View-only mount of the same picker Admin\TalentBuildEditor uses —
                             see BurstWindowTalents/TalentSelector's own $readOnly docblock for
                             why nothing there can be changed. --}}
                        <a href="{{ route('burst-window-talents', [$selectedClass->slug, $selectedSpec->slug, $length]) }}"
                           class="btn-secondary !text-[10px] !py-1 !px-2.5">
                            View in Talent Calculator
                        </a>
                        <button type="button" @click="talentModalOpen = true"
                                class="btn-ghost !text-[10px] !py-1 !px-2.5">
                            Copy as Text
                        </button>
                    </div>
                </div>
                <p class="text-[11px] text-ink-muted mb-3 leading-relaxed">
                    The real talents selected by the player in this exact match — not a curated default build. Only 3 PvP talent slots are shown: real rated arena only ever fills 3 of the 4 possible slots.
                </p>

                <div class="space-y-3">
                    @foreach ($treeLabels as $key => $label)
                        @php $group = $talentsByTree->get($key, collect()); @endphp
                        @if ($group->isNotEmpty())
                            <div>
                                <p class="text-[9px] uppercase tracking-wide text-ink-subtle font-semibold mb-1.5">{{ $label }}</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($group as $t)
                                        @php $tSpell = $t['spell'] ?? null; @endphp
                                        @if ($tSpell)
                                            <button type="button"
                                                    wire:click="$dispatch('show-spell-detail', { spellId: {{ $tSpell->id }}, classId: {{ $classId }}, specId: {{ $specId }} })"
                                                    title="{{ $t['name'] }}{{ $t['rank'] > 1 ? ' (rank '.$t['rank'].')' : '' }}"
                                                    class="flex items-center gap-1.5 pl-1 pr-2 py-1 rounded-md border border-line hover:border-gold/40 transition-colors">
                                                <x-spell-icon :spell="$tSpell" size="w-5 h-5"/>
                                                <span class="text-[10px] text-ink font-medium truncate max-w-[9rem]">{{ $t['name'] }}</span>
                                                @if ($t['rank'] > 1)
                                                    <span class="text-[9px] text-ink-subtle">×{{ $t['rank'] }}</span>
                                                @endif
                                            </button>
                                        @else
                                            <span class="text-[10px] text-ink-muted px-2 py-1 rounded-md border border-line">{{ $t['name'] }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach

                    @if (!empty($tb['pvpTalents']))
                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-ink-subtle font-semibold mb-1.5">PvP Talents</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($tb['pvpTalents'] as $t)
                                    @php $pSpell = $t['spell'] ?? null; @endphp
                                    @if ($pSpell)
                                        <button type="button"
                                                wire:click="$dispatch('show-spell-detail', { spellId: {{ $pSpell->id }}, classId: {{ $classId }}, specId: {{ $specId }} })"
                                                title="{{ $t['name'] }}"
                                                class="flex items-center gap-1.5 pl-1 pr-2 py-1 rounded-md border border-line hover:border-violet/40 transition-colors">
                                            <x-spell-icon :spell="$pSpell" size="w-5 h-5"/>
                                            <span class="text-[10px] text-ink font-medium truncate max-w-[9rem]">{{ $t['name'] }}</span>
                                        </button>
                                    @else
                                        <span class="text-[10px] text-ink-muted px-2 py-1 rounded-md border border-line">{{ $t['name'] }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Copy-as-text modal — deliberately a readable talent list, NOT a real pasteable
                 Blizzard "Export" string. BlizzardTalentStringCodec::encodeBuild() (the only
                 mechanism that could produce one) is confirmed broken — see its own docblock,
                 "Export-string generation attempted, confirmed broken — pulled from UI,
                 2026-08-03" — a wrong string that LOOKS legitimate is worse than none. "View in
                 Talent Calculator" (above) is the visual, browsable way to see this build; this
                 modal is only for grabbing a plain-text copy of the same information. --}}
            <div x-show="talentModalOpen" x-cloak x-transition.opacity.duration.100ms
                 class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
                 @click.self="talentModalOpen = false">
                <div class="linear-card max-w-lg w-full p-5 relative">
                    <button type="button" @click="talentModalOpen = false" class="absolute top-3 right-3 text-ink-subtle hover:text-ink">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                    <p class="text-[13px] font-semibold text-ink mb-1">Talent List</p>
                    <p class="text-[11px] text-ink-muted mb-3 leading-relaxed">
                        A readable copy of the talents above — not yet a real in-game importable code. Use "View in Talent Calculator" for a visual, browsable view of the same build.
                    </p>
                    <textarea readonly rows="10"
                              x-ref="talentListText"
                              class="form-textarea !text-[11px] !font-mono w-full mb-3"
                              onclick="this.select()">{{ trim($exportText) }}</textarea>
                    <button type="button"
                            x-data="{ copied: false }"
                            @click="navigator.clipboard.writeText($refs.talentListText.value); copied = true; setTimeout(() => copied = false, 1500)"
                            class="btn-primary !text-[11px] !py-1.5 w-full">
                        <span x-show="!copied">Copy to Clipboard</span>
                        <span x-show="copied" x-cloak>Copied!</span>
                    </button>
                </div>
            </div>
        @endif
    @endif

    {{-- Important Mechanics (Review) — deliberately reads STAGING data (wow-arena-archive's
         mechanics/{class}/{spec}.txt, built by wow:record-important-mechanics), not a promoted
         copy — see ArenaLogService::mechanicsForSpec()'s docblock. This block exists to let a
         human review what the empirical scan found before any of it is trusted as real
         curated content, same posture as Admin\CcReview for bulk-applied CC data. --}}
    @if ($mechanics)
        <div class="linear-card p-5">
            <div class="flex items-baseline justify-between gap-3 mb-1 flex-wrap">
                <p class="text-[10px] uppercase tracking-wider text-gold font-semibold flex items-center gap-1.5">
                    Important Mechanics
                    <span class="badge-amber !text-[8px] !px-1 !py-0">REVIEW — NOT PROMOTED</span>
                </p>
                @if ($mechanics['generatedAt'])
                    <span class="text-[10px] text-ink-subtle/70">generated {{ $mechanics['generatedAt'] }}</span>
                @endif
            </div>
            <p class="text-[11px] text-ink-muted mb-3 leading-relaxed">
                Self-buffs and target-debuffs found across {{ number_format($mechanics['windowsFound']) }} real pre-kill window(s) ({{ number_format($mechanics['matchesScanned']) }} matches scanned, --window={{ $mechanics['windowSeconds'] }}s) — the non-obvious mechanics a cast sequence alone wouldn't show (e.g. Colossus Smash amplifying damage, Ancient Arts refunding combo points). Sourced from wow-arena-archive's staging archive, not yet spot-checked — treat percentages as a starting point for review, not a verified fact.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                @foreach (array_slice($mechanics['rows'], 0, 30) as $row)
                    @php
                        $mSpell = $row['spell'] ?? null;
                        $kb = $kindBadge[$row['kind'] ?? null] ?? null;
                    @endphp
                    @if ($mSpell)
                        <button type="button"
                                wire:click="$dispatch('show-spell-detail', { spellId: {{ $mSpell->id }}, classId: {{ $classId }}, specId: {{ $specId }} })"
                                class="linear-card !p-2.5 w-40 flex-shrink-0 text-left hover:border-gold/40 transition-colors">
                            <div class="flex items-center gap-2">
                                <x-spell-icon :spell="$mSpell" size="w-8 h-8"/>
                                <span class="min-w-0">
                                    <span class="block text-[11px] text-ink font-semibold truncate">{{ $mSpell->display_name }}</span>
                                    <span class="flex items-center gap-1 mt-0.5 flex-wrap">
                                        <span class="badge-{{ $row['type'] === 'target-debuff' ? 'gold' : 'blue' }} !text-[7px] !px-1 !py-0">{{ $row['type'] }}</span>
                                        @if ($kb)
                                            <span class="{{ $kb['class'] }} !text-[7px] !px-1 !py-0">{{ $kb['label'] }}</span>
                                        @endif
                                        <span class="text-[9px] text-ink-subtle font-mono">{{ $row['pct'] }}%</span>
                                    </span>
                                </span>
                            </div>
                        </button>
                    @else
                        <div class="linear-card !p-2.5 w-40 flex-shrink-0">
                            <span class="block text-[11px] text-ink font-semibold truncate">{{ $row['name'] }}</span>
                            <span class="flex items-center gap-1 mt-0.5 flex-wrap">
                                <span class="badge-{{ $row['type'] === 'target-debuff' ? 'gold' : 'blue' }} !text-[7px] !px-1 !py-0">{{ $row['type'] }}</span>
                                <span class="text-[9px] text-ink-subtle font-mono">{{ $row['pct'] }}%</span>
                            </span>
                        </div>
                    @endif
                @endforeach
            </div>
            @if (count($mechanics['rows']) > 30)
                <p class="text-[10px] text-ink-subtle/70 mt-2">+{{ count($mechanics['rows']) - 30 }} more in the raw file (not shown here).</p>
            @endif
        </div>
    @endif

    <livewire:spell-detail-modal/>
</div>
