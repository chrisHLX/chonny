@php
    $categoryOrder = ['Crowd Control', 'Defensive', 'Utility', 'Offensive', 'Other'];
    $categoryAccent = [
        'Crowd Control' => 'text-violet',
        'Defensive' => 'text-rose-400',
        'Utility' => 'text-amber-400',
        'Offensive' => 'text-orange-400',
        'Other' => 'text-ink-subtle',
    ];
    $categoryBadge = [
        'Crowd Control' => 'badge-blue',
        'Defensive' => 'badge-red',
        'Utility' => 'badge-amber',
        'Offensive' => 'badge-orange',
        'Other' => 'badge-gray',
    ];
    $groupOrder = ['active' => 'Active Abilities', 'passive' => 'Buffs & Passives'];
    $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
    $cooldownDisplay = fn (array $entry) => $entry['cooldown']['seconds'] !== null ? $fmtSeconds($entry['cooldown']['seconds']) : null;

    $drBadge = [
        'Stun' => 'badge-red',
        'Disorient' => 'badge-blue',
        'Incapacitate' => 'badge-amber',
        'Root' => 'badge-green',
        'Silence' => 'badge-gray',
        'Knockback' => 'badge-orange',
        'Disarm' => 'badge-gold',
        'Slow' => 'badge-gray',
    ];
    $fmtPvpDuration = fn ($spell) => $spell->pvp_duration_seconds !== null
        ? rtrim(rtrim(number_format((float) $spell->pvp_duration_seconds, 1), '0'), '.').'s'
        : null;
    $pvpCapSeconds = \App\Http\Services\CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS;

    $selectedMembers = collect($comp)->filter(fn ($m) => $m['spec']);
    $compTitle = $selectedMembers->isNotEmpty()
        ? $selectedMembers->pluck('class.name')->implode(' / ')
        : 'Build a 3v3 Team';
    $compSubtitle = $selectedMembers->map(fn ($m) => "{$m['label']}: {$m['class']->name} ({$m['spec']->name})")->implode(' • ');
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 space-y-5" x-data="{ openSpellId: null, tab: 'active' }">
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">WoW Comps</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">{{ $compTitle }}</h1>
        <p class="text-[12px] text-ink-muted mt-1">
            {{ $compSubtitle ?: "Pick a class + spec for each slot to compare spell kits side by side." }}
        </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        @foreach ($slots as $index => $slot)
            @php
                $isHealer = $slot['label'] === 'Healer';
                $selectedClass = $comp[$index]['class'];
                $selectedSpec = $comp[$index]['spec'];
                $selectedColor = $selectedClass ? (config('wow_classes.colors')[$selectedClass->slug] ?? '#8A8A9A') : null;
            @endphp
            {{-- Spec-first picker: one click opens a searchable flyout grouped by class, and
                 clicking a spec directly (e.g. typing "disc" -> click "Discipline") sets both
                 class + spec in a single selectSpec() call — replaces the old two-step
                 class-select-then-spec-select pair. --}}
            <div class="linear-card p-4 space-y-2 relative" x-data="{ open: false, search: '' }" @click.outside="open = false; search = ''">
                <span class="{{ $isHealer ? 'badge-green' : 'badge-blue' }}">{{ strtoupper($slot['label']) }}</span>

                <button type="button"
                        @click="open = !open; if (open) $nextTick(() => $refs.search.focus())"
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
                        <span class="text-[13px] text-ink-muted">Choose spec…</span>
                    @endif
                    <svg class="w-3.5 h-3.5 text-ink-subtle ml-auto flex-shrink-0 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                     class="absolute z-30 left-0 right-0 mt-1 linear-card !border-line-strong shadow-gold-lg p-3 max-h-96 overflow-y-auto">
                    <input type="text" x-ref="search" x-model="search" @click.stop
                           placeholder="Search a class or spec…"
                           class="form-input !text-[12px] !py-1.5 mb-3 w-full">

                    @foreach ($classSpecs as $class)
                        @php $classColor = config('wow_classes.colors')[$class->slug] ?? '#8A8A9A'; @endphp
                        <div class="mb-2.5 last:mb-0"
                             data-search-group="{{ Str::lower($class->name.' '.$class->specializations->pluck('name')->implode(' ')) }}"
                             x-show="search === '' || $el.dataset.searchGroup.includes(search.toLowerCase())">
                            <div class="flex items-center gap-1.5 mb-1 px-1">
                                <x-class-icon :class="$class" size="w-4 h-4"/>
                                <span class="text-[10px] uppercase tracking-wide font-semibold" style="color: {{ $classColor }}">{{ $class->name }}</span>
                            </div>
                            <div class="space-y-0.5">
                                @foreach ($class->specializations as $spec)
                                    <button type="button"
                                            data-search="{{ Str::lower($class->name.' '.$spec->name) }}"
                                            x-show="search === '' || $el.dataset.search.includes(search.toLowerCase())"
                                            wire:click="selectSpec({{ $index }}, {{ $class->id }}, {{ $spec->id }})"
                                            @click="open = false; search = ''"
                                            class="w-full flex items-center gap-2 text-left px-2 py-1.5 rounded hover:bg-surface-2 transition-colors {{ $selectedSpec?->id === $spec->id ? 'bg-gold/10' : '' }}">
                                        <x-spec-icon :spec="$spec" :color="$classColor" size="w-6 h-6"/>
                                        <span class="text-[12px] text-ink truncate">{{ $spec->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @if ($selectedMembers->isNotEmpty())
        <div class="space-y-6 min-w-0">
            <div class="grid grid-cols-3 gap-3 px-1">
                @foreach ($comp as $member)
                    <div class="flex items-center justify-between gap-1.5 min-w-0">
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide truncate">
                            {{ $member['spec'] ? "{$member['spec']->name} {$member['class']->name}" : '—' }}
                        </p>
                        @if ($member['spec'])
                            <button type="button" wire:click="openPicker({{ $member['spec']->id }})"
                                    title="Edit my talents for this spec"
                                    class="text-ink-subtle hover:text-gold flex-shrink-0 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Tab bar — same .tab-btn/.tab-active pattern as Spell Explorer. Pure client-side
                 (Alpine `tab` state on the outer x-data), so switching never round-trips. --}}
            <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1 w-fit">
                <button type="button" @click="tab = 'active'" class="tab-btn flex items-center gap-1.5" :class="tab === 'active' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Active Abilities
                </button>
                <button type="button" @click="tab = 'cooldowns'" class="tab-btn flex items-center gap-1.5" :class="tab === 'cooldowns' ? 'tab-active' : 'tab-inactive'">
                    <x-mc-icon name="icon-hourglass" class="w-3.5 h-3.5"/>
                    Main Cooldowns
                </button>
                <button type="button" @click="tab = 'passive'" class="tab-btn flex items-center gap-1.5" :class="tab === 'passive' ? 'tab-active' : 'tab-inactive'">
                    <x-mc-icon name="icon-leaf" class="w-3.5 h-3.5"/>
                    Buffs &amp; Passives
                </button>
                <button type="button" @click="tab = 'synergies'" class="tab-btn flex items-center gap-1.5" :class="tab === 'synergies' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                    Synergies
                </button>
            </div>

            @foreach ($groupOrder as $groupKey => $groupLabel)
                @php
                    $groupHasAny = $selectedMembers->contains(fn ($m) => collect($m['entries'])->contains(fn ($e) => ($e['spell']->is_passive ? 'passive' : 'active') === $groupKey));
                @endphp
                @continue(!$groupHasAny)

                <div x-show="tab === '{{ $groupKey }}'" x-cloak>
                    @foreach ($categoryOrder as $category)
                        @php
                            $categoryHasAny = $selectedMembers->contains(fn ($m) => collect($m['entries'])->contains(fn ($e) => ($e['spell']->is_passive ? 'passive' : 'active') === $groupKey && $e['category'] === $category));
                        @endphp
                        @continue(!$categoryHasAny)

                        <div class="mb-4">
                            <p class="text-[10px] uppercase tracking-wide {{ $categoryAccent[$category] }} font-semibold mb-1.5 pl-1">{{ $category }}</p>
                            <div class="grid grid-cols-3 gap-3">
                                @foreach ($comp as $mi => $member)
                                    <div class="linear-card p-1.5 space-y-0.5">
                                        @php
                                            $catEntries = collect($member['entries'])->filter(
                                                fn ($e) => ($e['spell']->is_passive ? 'passive' : 'active') === $groupKey && $e['category'] === $category
                                            );
                                        @endphp
                                        @forelse ($catEntries as $entry)
                                            @php $modalKey = "m{$mi}-s{$entry['spell']->id}"; @endphp
                                            <button type="button"
                                                    @click="openSpellId = '{{ $modalKey }}'"
                                                    class="w-full flex items-center gap-2 text-left px-1.5 py-1 rounded hover:bg-surface-2 transition-colors {{ ($entry['isSelected'] ?? true) ? '' : 'opacity-50' }}">
                                                <x-spell-icon :spell="$entry['spell']" size="w-6 h-6"/>
                                                <span class="flex-1 min-w-0 text-[12px] text-ink truncate">{{ $entry['spell']->display_name }}</span>
                                                <span class="text-[10px] text-ink-subtle whitespace-nowrap">{{ $cooldownDisplay($entry) ?? '—' }}</span>
                                            </button>
                                        @empty
                                            <p class="text-[11px] text-ink-subtle px-1.5 py-1">—</p>
                                        @endforelse
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach

            {{-- Main Cooldowns tab — same category-grouped card-grid layout as Active Abilities
                 above (deliberately the same markup shape, just a different entry filter), not
                 the old fixed top-3/20s+ sidebar summary. Shows every active (non-passive) spell
                 that has a real cooldown value; Crowd Control spells show regardless of whether
                 a cooldown is captured — same rule Spell Explorer's own Main Cooldowns tab
                 uses (some CC has no cooldown data but is still worth surfacing). --}}
            <div x-show="tab === 'cooldowns'" x-cloak>
                @foreach ($categoryOrder as $category)
                    @php
                        $cooldownEntryFilter = fn ($e) => !$e['spell']->is_passive
                            && $e['category'] === $category
                            && ($category === 'Crowd Control' || $cooldownDisplay($e) !== null);
                        $categoryHasAny = $selectedMembers->contains(fn ($m) => collect($m['entries'])->contains($cooldownEntryFilter));
                    @endphp
                    @continue(!$categoryHasAny)

                    <div class="mb-4">
                        <p class="text-[10px] uppercase tracking-wide {{ $categoryAccent[$category] }} font-semibold mb-1.5 pl-1">{{ $category }}</p>
                        <div class="grid grid-cols-3 gap-3">
                            @foreach ($comp as $mi => $member)
                                <div class="linear-card p-1.5 space-y-0.5">
                                    @php $catEntries = collect($member['entries'])->filter($cooldownEntryFilter); @endphp
                                    @forelse ($catEntries as $entry)
                                        @php $modalKey = "m{$mi}-s{$entry['spell']->id}"; @endphp
                                        <button type="button"
                                                @click="openSpellId = '{{ $modalKey }}'"
                                                class="w-full flex items-center gap-2 text-left px-1.5 py-1 rounded hover:bg-surface-2 transition-colors {{ ($entry['isSelected'] ?? true) ? '' : 'opacity-50' }}">
                                            <x-spell-icon :spell="$entry['spell']" size="w-6 h-6"/>
                                            <span class="flex-1 min-w-0 text-[12px] text-ink truncate">{{ $entry['spell']->display_name }}</span>
                                            <span class="text-[10px] text-ink-subtle whitespace-nowrap">{{ $cooldownDisplay($entry) ?? '—' }}</span>
                                        </button>
                                    @empty
                                        <p class="text-[11px] text-ink-subtle px-1.5 py-1">—</p>
                                    @endforelse
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Synergies tab — deterministic CC-chain sequencing via CcChainBuilder. Only
                 spells with a curated dr_category are even eligible (124 dataset-wide as of
                 2026-08-11 — most CC isn't classified yet); of those, only ones ALSO carrying a
                 chain_target can be placed into a chain (currently just the 8 hand-curated
                 worked-example spells). Everything else surfaces honestly under "Not Yet
                 Classified" rather than being silently dropped or guessed into a chain — see
                 WowComps::getSynergiesProperty()'s docblock. --}}
            <div x-show="tab === 'synergies'" x-cloak class="space-y-4">
                @php
                    // Neat, labeled CD/Duration pair reused across every Synergies section —
                    // CD comes from cooldown_by_id (the same talent-modified effective cooldown
                    // Active Abilities/Main Cooldowns already show); Duration only ever shows a
                    // real number when pvp_duration_seconds has been hand-curated (never falls
                    // back to the raw, confirmed-unreliable/PvE-scoped duration_seconds column —
                    // see CLAUDE.md's "PvP CC duration cap" section for why that field can't be
                    // trusted directly, e.g. Polymorph's own duration_seconds reads 60s).
                    $cdLabel = fn ($spell) => isset($synergies['cooldown_by_id'][$spell->id]) && $synergies['cooldown_by_id'][$spell->id] !== null
                        ? $fmtSeconds($synergies['cooldown_by_id'][$spell->id])
                        : '—';
                @endphp
                <div class="linear-card p-4">
                    <p class="text-[12px] text-ink-muted leading-relaxed">
                        Opens with an instant Stun when available, then alternates DR category to avoid Diminishing Returns where possible. In PvP, CC duration caps at <span class="text-ink font-semibold">{{ $pvpCapSeconds }}s</span> regardless of tooltip value, and DR resets after 20s of no reapplication. Only spells with a curated DR category are eligible — most of the game's CC isn't classified yet. "Duration" only shows once a spell's real PvP CC duration has been hand-verified — a blank duration means it hasn't been curated yet, not that the CC is instant.
                    </p>
                </div>

                @foreach ([['key' => 'kill_target_chain', 'label' => 'Kill-Target Chain'], ['key' => 'healer_chain', 'label' => 'Healer-Lock Chain']] as $chainDef)
                    <div class="linear-card p-4">
                        <p class="text-[11px] uppercase tracking-wide text-gold font-semibold mb-3">{{ $chainDef['label'] }}</p>
                        @if (empty($synergies[$chainDef['key']]))
                            <p class="text-[12px] text-ink-subtle">No classified CC available for this chain yet.</p>
                        @else
                            <div class="flex flex-wrap items-stretch gap-2">
                                @foreach ($synergies[$chainDef['key']] as $i => $step)
                                    @php
                                        $stepSpell = $step['spell'];
                                        $ownerIndex = $synergies['owner_map'][$stepSpell->id] ?? null;
                                        $ownerMember = $ownerIndex !== null ? ($comp[$ownerIndex] ?? null) : null;
                                        $durationLabel = $fmtPvpDuration($stepSpell);
                                    @endphp
                                    @if ($i > 0)
                                        <div class="flex items-center text-ink-subtle text-[14px] px-0.5">→</div>
                                    @endif
                                    <div class="linear-card !p-2.5 w-40 flex-shrink-0 {{ $step['dr_immune'] ? 'opacity-60' : '' }}">
                                        <div class="flex items-center gap-1.5 mb-1">
                                            <x-spell-icon :spell="$stepSpell" size="w-6 h-6"/>
                                            <span class="text-[11px] text-ink font-semibold truncate">{{ $stepSpell->display_name }}</span>
                                        </div>
                                        <span class="{{ $drBadge[$stepSpell->dr_category] ?? 'badge-gray' }} !text-[9px]">{{ $stepSpell->dr_category }}</span>
                                        <div class="flex items-center gap-2.5 text-[10px] font-mono mt-1.5">
                                            <span class="text-ink-subtle">CD <span class="text-ink">{{ $cdLabel($stepSpell) }}</span></span>
                                            <span class="text-ink-subtle">Dur <span class="{{ $durationLabel ? 'text-ink' : 'text-ink-subtle italic' }}">{{ $durationLabel ?? '—' }}</span></span>
                                        </div>
                                        @if ($ownerMember && $ownerMember['spec'])
                                            <p class="text-[10px] text-ink-subtle truncate mt-1">{{ $ownerMember['class']->name }} ({{ $ownerMember['spec']->name }})</p>
                                        @endif
                                        <p class="text-[10px] font-semibold mt-1 {{ $step['dr_immune'] ? 'text-rose-400' : ($step['dr_applied'] ? 'text-amber-400' : 'text-emerald-400') }}">
                                            {{ $step['dr_immune'] ? 'DR Immune' : $step['dr_percentage'].'%' }}
                                        </p>
                                        @if ($step['dr_reason'])
                                            <p class="text-[9px] text-ink-subtle mt-0.5 leading-snug">{{ ucfirst($step['dr_reason']) }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- Peels and Interrupts — independent of dr_category entirely (a spell can be
                     BOTH a DR-chain entry AND a peel/interrupt, e.g. Ursol's Vortex or Typhoon).
                     Plain grouped lists, not sequenced through CcChainBuilder — diminishing
                     returns doesn't apply to either concept. --}}
                @foreach ([['key' => 'peels', 'label' => 'Peels', 'accent' => 'text-emerald-400'], ['key' => 'interrupts', 'label' => 'Interrupts', 'accent' => 'text-sky-400']] as $flagDef)
                    @if ($synergies[$flagDef['key']]->isNotEmpty())
                        <div class="linear-card p-4">
                            <p class="text-[11px] uppercase tracking-wide {{ $flagDef['accent'] }} font-semibold mb-2">{{ $flagDef['label'] }}</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($synergies[$flagDef['key']] as $spell)
                                    @php
                                        $ownerIndex = $synergies['owner_map'][$spell->id] ?? null;
                                        $ownerMember = $ownerIndex !== null ? ($comp[$ownerIndex] ?? null) : null;
                                        $flagDurationLabel = $fmtPvpDuration($spell);
                                    @endphp
                                    <div class="flex items-center gap-1.5 px-2 py-1 rounded bg-surface-2 border border-line">
                                        <x-spell-icon :spell="$spell" size="w-5 h-5"/>
                                        <span class="text-[11px] text-ink-muted">{{ $spell->display_name }}</span>
                                        <span class="text-[10px] text-ink-subtle font-mono">CD {{ $cdLabel($spell) }}</span>
                                        @if ($flagDurationLabel)
                                            <span class="text-[10px] text-ink-subtle font-mono">Dur {{ $flagDurationLabel }}</span>
                                        @endif
                                        @if ($ownerMember && $ownerMember['spec'])
                                            <span class="text-[10px] text-ink-subtle">— {{ $ownerMember['spec']->name }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach

                @if ($synergies['unclassified']->isNotEmpty())
                    <div class="linear-card p-4">
                        <p class="text-[11px] uppercase tracking-wide text-ink-subtle font-semibold mb-2">Not Yet Classified for Chaining</p>
                        <p class="text-[11px] text-ink-muted mb-3">These have a curated DR category but haven't been assigned a Healer-Lock / Kill-Target chain_target yet — needs the same one-at-a-time expert confirmation as the DR category itself, not a guess.</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($synergies['unclassified'] as $spell)
                                @php $unclassifiedDurationLabel = $fmtPvpDuration($spell); @endphp
                                <div class="flex items-center gap-1.5 px-2 py-1 rounded bg-surface-2 border border-line">
                                    <x-spell-icon :spell="$spell" size="w-5 h-5"/>
                                    <span class="text-[11px] text-ink-muted">{{ $spell->display_name }}</span>
                                    <span class="{{ $drBadge[$spell->dr_category] ?? 'badge-gray' }} !text-[9px]">{{ $spell->dr_category }}</span>
                                    <span class="text-[10px] text-ink-subtle font-mono">CD {{ $cdLabel($spell) }}</span>
                                    @if ($unclassifiedDurationLabel)
                                        <span class="text-[10px] text-ink-subtle font-mono">Dur {{ $unclassifiedDurationLabel }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Spell detail modal — one hidden content block per entry, toggled by openSpellId.
             Simplest correct approach for a shape-check page; a production version would swap
             this for a single dynamically-populated modal instead of rendering one block per
             spell. --}}
        <div class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
             x-show="openSpellId !== null" x-cloak
             @click.self="openSpellId = null"
             @keydown.escape.window="openSpellId = null">
            @foreach ($comp as $mi => $member)
                @foreach ($member['entries'] as $entry)
                    @php
                        $modalKey = "m{$mi}-s{$entry['spell']->id}";
                        $spell = $entry['spell'];
                        $cooldown = $entry['cooldown'];
                        $charges = $entry['charges'];
                        $cooldownChanged = $cooldown['seconds'] !== null && $cooldown['base_seconds'] !== null && round($cooldown['seconds'], 2) !== round($cooldown['base_seconds'], 2);
                        $chargesChanged = $charges['charges'] !== null && $charges['base_charges'] !== null && $charges['charges'] !== $charges['base_charges'];
                    @endphp
                    <div x-show="openSpellId === '{{ $modalKey }}'" x-cloak x-data="{ expandedMod: null }"
                         class="linear-card max-w-md w-full p-5 relative max-h-[85vh] overflow-y-auto"
                         @click.stop>
                        <button type="button" @click="openSpellId = null" class="absolute top-3 right-3 text-ink-subtle hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        <div class="flex items-start gap-3 mb-3 pr-6">
                            <x-spell-icon :spell="$spell" size="w-11 h-11" class="rounded-lg shrink-0"/>
                            <div class="min-w-0">
                                <p class="text-[15px] font-semibold text-ink">{{ $spell->display_name }}</p>
                                <p class="text-[10px] text-ink-subtle font-mono">#{{ $spell->spell_id }}</p>
                                <span class="{{ $categoryBadge[$entry['category']] ?? 'badge-gray' }} mt-1">{{ $entry['category'] }}</span>
                            </div>
                        </div>

                        <p class="text-[13px] text-ink-muted leading-relaxed">{{ $entry['description']['text'] ?: 'No description available.' }}</p>

                        @if ($entry['description']['uncertain'])
                            <p class="text-[10px] text-ink-subtle italic mt-1.5">Some values above vary by condition or aren't fully known — check in-game.</p>
                        @endif
                        @if (!empty($entry['formulaModifiers']) && $entry['formulaModifiers']->isNotEmpty())
                            <p class="text-[10px] text-ink-subtle mt-1.5"><span class="font-semibold">Scales with:</span> {{ $entry['formulaModifiers']->pluck('display_name')->implode(', ') }}</p>
                        @endif

                        <div class="flex items-center gap-4 text-[12px] mt-3 pt-3 border-t border-line">
                            <div>
                                <span class="text-ink-subtle">Cooldown</span>
                                <span class="text-ink font-semibold ml-1">{{ $cooldownDisplay($entry) ?? '—' }}</span>
                                @if ($cooldownChanged)
                                    <span class="text-[10px] text-ink-subtle line-through ml-1">{{ $fmtSeconds($cooldown['base_seconds']) }}</span>
                                @endif
                            </div>
                            @if ($charges['charges'] !== null && $charges['charges'] > 1)
                                <div>
                                    <span class="text-ink-subtle">Charges</span>
                                    <span class="text-ink font-semibold ml-1">{{ $charges['charges'] }}</span>
                                    @if ($chargesChanged)
                                        <span class="text-[10px] text-ink-subtle line-through ml-1">{{ $charges['base_charges'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if ($entry['modifiers']['named']->isNotEmpty())
                            <div class="mt-3 pt-3 border-t border-line">
                                <p class="text-[10px] uppercase tracking-wide text-ink-subtle font-semibold mb-1.5">Modifies / Enhances</p>
                                @foreach ($entry['modifiers']['named'] as $mod)
                                    @php
                                        $modId = $mod['spell']->id;
                                        $modCooldown = $mod['cooldown'];
                                        $modCooldownDisplay = $modCooldown['seconds'] !== null ? $fmtSeconds($modCooldown['seconds']) : null;
                                    @endphp
                                    <div class="mb-1 last:mb-0">
                                        <button type="button"
                                                @click="expandedMod = expandedMod === {{ $modId }} ? null : {{ $modId }}"
                                                class="w-full flex items-center gap-1.5 text-left py-0.5 -mx-1 px-1 rounded hover:bg-surface-2 transition-colors">
                                            <svg class="w-3 h-3 text-ink-subtle flex-shrink-0 transition-transform" :class="expandedMod === {{ $modId }} && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>
                                            <x-spell-icon :spell="$mod['spell']" size="w-5 h-5" />
                                            <span class="text-[12px] text-ink-muted flex-1 truncate">{{ $mod['spell']->display_name }}</span>
                                        </button>
                                        <div x-show="expandedMod === {{ $modId }}" x-cloak x-collapse
                                             class="ml-[18px] pl-2.5 border-l border-line mt-1 mb-1.5">
                                            <span class="{{ $categoryBadge[$mod['category']] ?? 'badge-gray' }} mb-1">{{ $mod['category'] }}</span>
                                            <p class="text-[11px] text-ink-muted leading-relaxed mt-1">{{ $mod['description']['text'] ?: 'No description available.' }}</p>
                                            @if ($modCooldownDisplay)
                                                <p class="text-[10px] text-ink-subtle mt-1"><span class="font-semibold">Cooldown</span> {{ $modCooldownDisplay }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>

        {{-- Personal talent picker — edits the viewer's OWN saved TalentBuild for whichever
             member's spec was clicked (never the admin default), via
             TalentSelectionService::resolveActiveBuild()/getOrCreateUserBuild(). A plain
             Blade @if rather than an Alpine x-show: open/close already round-trip through
             openPicker()/closePicker() (Livewire actions), and closing is what makes the
             Spells table above pick up whatever was just saved — see closePicker()'s docblock. --}}
        @if ($activePickerSpecId)
            <div class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
                 @click.self="$wire.closePicker()">
                <div class="linear-card max-w-5xl w-full p-5 relative max-h-[90vh] overflow-y-auto">
                    <button type="button" wire:click="closePicker" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                    <livewire:talent-selector :spec-id="$activePickerSpecId" layout="grid" :key="'wow-comps-picker-'.$activePickerSpecId"/>
                </div>
            </div>
        @endif
    @endif
</div>
