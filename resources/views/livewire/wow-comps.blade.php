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

    // Splits a formatted "30s"/"4.5s" label into [number, unit] so a CD/Duration stat block can
    // render the trailing "s" smaller and in a plain color than the number itself, instead of
    // one uniformly-styled string. Moved up here (was originally only defined inside the
    // Synergies section further down) 2026-08-20 so the Offensive/Defensive cooldown tabs' own
    // single-box card layout (see below) can reuse it too.
    $splitUnit = fn (string $label) => str_ends_with($label, 's')
        ? [substr($label, 0, -1), 's']
        : [$label, ''];

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

<div class="max-w-7xl mx-auto px-4 py-8 space-y-5" x-data="{ openSpellId: null, tab: 'offensive', classPickerSlot: null, pendingSlot: null }">
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
            {{-- Spec-first picker: one click opens the shared class/spec grid modal below
                 (classPickerSlot records which slot is choosing), and clicking a spec icon
                 directly sets both class + spec in a single selectSpec() call. Replaced the old
                 inline searchable flyout dropdown 2026-08-16, direct request (a full modal grid
                 reads better than a cramped per-slot dropdown, especially with 13 classes).
                 pendingSlot (set by the modal's spec button below, cleared once the selectSpec()
                 round trip resolves) drives the spinner/disabled state here — the spec-kit
                 computation getCompProperty() triggers can genuinely take a couple seconds for a
                 talent-heavy spec, and with no feedback that read as "the click didn't register"
                 (direct user report, 2026-08-17). --}}
            <div class="linear-card p-4 space-y-2">
                <span class="{{ $isHealer ? 'badge-green' : 'badge-blue' }}">{{ strtoupper($slot['label']) }}</span>

                <button type="button"
                        @click="classPickerSlot = {{ $index }}"
                        :disabled="pendingSlot === {{ $index }}"
                        :class="pendingSlot === {{ $index }} && 'opacity-60 cursor-wait'"
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
                    <template x-if="pendingSlot === {{ $index }}">
                        <svg class="animate-spin w-4 h-4 text-gold ml-auto flex-shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <template x-if="pendingSlot !== {{ $index }}">
                        <svg class="w-3.5 h-3.5 text-ink-subtle ml-auto flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </template>
                </button>
                <p x-show="pendingSlot === {{ $index }}" x-cloak class="text-[10px] text-gold-light">Loading spells…</p>
            </div>
        @endforeach
    </div>

    {{-- Class/spec picker modal — a full grid of every class (2-column, class-colored headers)
         and its specs, replacing the old per-slot inline flyout (2026-08-16, direct request with
         a reference screenshot of the desired layout). `classPickerSlot` (root x-data) holds
         which slot index is currently choosing, or null when closed; opened by each slot's button
         above, read here to route the click into the right selectSpec() call. Purely
         Alpine-driven open/close — no Livewire round trip needed just to open it — same pattern
         as the talent-picker modal further down, keyed off a plain client-side index instead of a
         server-side prop since no spec is chosen yet at the point this opens. --}}
    <div x-show="classPickerSlot !== null" x-cloak x-transition.opacity.duration.100ms x-data="{ search: '' }"
         class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="classPickerSlot = null; search = ''">
        <div class="linear-card max-w-2xl w-full p-5 relative max-h-[85vh] overflow-y-auto">
            <button type="button" @click="classPickerSlot = null; search = ''" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10">
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
                                            pendingSlot = classPickerSlot;
                                            classPickerSlot = null;
                                            search = '';
                                            $wire.selectSpec(pendingSlot, {{ $class->id }}, {{ $spec->id }}).finally(() => pendingSlot = null);
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

    @if ($selectedMembers->isNotEmpty())
        <div class="space-y-6 min-w-0">
            <div class="grid grid-cols-3 gap-3 px-1">
                @foreach ($comp as $member)
                    <div class="flex items-center justify-between gap-1.5 min-w-0">
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide truncate">
                            {{ $member['spec'] ? "{$member['spec']->name} {$member['class']->name}" : '—' }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- Tab bar — same .tab-btn/.tab-active pattern as Spell Explorer. Pure client-side
                 (Alpine `tab` state on the outer x-data), so switching never round-trips. --}}
            <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1 w-fit">
                <button type="button" @click="tab = 'offensive'" class="tab-btn flex items-center gap-1.5" :class="tab === 'offensive' ? 'tab-active' : 'tab-inactive'" title="Real, arena-log-verified offensive cooldowns for this exact spec">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8l-6.2 4.5 2.4-7.4L2 9.4h7.6z"/></svg>
                    Offensive Cooldowns
                </button>
                <button type="button" @click="tab = 'defensive'" class="tab-btn flex items-center gap-1.5" :class="tab === 'defensive' ? 'tab-active' : 'tab-inactive'" title="Real, arena-log-verified defensive cooldowns for this exact spec">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2l8 3.5v6c0 5-3.5 8.5-8 10.5-4.5-2-8-5.5-8-10.5v-6L12 2z"/></svg>
                    Defensive Cooldowns
                </button>
                <button type="button" @click="tab = 'synergies'" class="tab-btn flex items-center gap-1.5" :class="tab === 'synergies' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                    Crowd Control
                </button>
                <button type="button" @click="tab = 'pvptalents'" class="tab-btn flex items-center gap-1.5" :class="tab === 'pvptalents' ? 'tab-active' : 'tab-inactive'">
                    <x-mc-icon name="icon-scroll" class="w-3.5 h-3.5"/>
                    PvP Talents
                </button>
                <button type="button" @click="tab = 'active'" class="tab-btn flex items-center gap-1.5" :class="tab === 'active' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Active Abilities
                </button>
                <button type="button" @click="tab = 'passive'" class="tab-btn flex items-center gap-1.5" :class="tab === 'passive' ? 'tab-active' : 'tab-inactive'">
                    <x-mc-icon name="icon-leaf" class="w-3.5 h-3.5"/>
                    Buffs &amp; Passives
                </button>
                <button type="button" @click="tab = 'rotation'" class="tab-btn flex items-center gap-1.5" :class="tab === 'rotation' ? 'tab-active' : 'tab-inactive'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Offensive Rotation
                    <span class="badge-amber !text-[8px] !px-1 !py-0">DEV</span>
                </button>
                <button type="button" @click="tab = 'ratingtiers'" class="tab-btn flex items-center gap-1.5" :class="tab === 'ratingtiers' ? 'tab-active' : 'tab-inactive'">
                    <x-mc-icon name="icon-compass" class="w-3.5 h-3.5"/>
                    Rating Tiers
                    <span class="badge-amber !text-[8px] !px-1 !py-0">DEV</span>
                </button>
            </div>

            {{-- Offensive Cooldowns / Defensive Cooldowns tabs — replaced the single "Cooldowns"
                 tab 2026-08-20, direct request. That tab's own filter (isPriority + an arbitrary
                 "over 15s" toggle) is gone; both tabs below instead intersect isPriority (this
                 exact spec really cast it, per real arena-log evidence) with
                 offensiveDefensive (a real, arena-log-VERIFIED classification of what kind of
                 cooldown it is — School Damage/Direct Heal/Damage Taken%/etc effect signals,
                 not a guess), promoted from wow-arena-archive's classify-cooldowns.php. Both
                 signals come from real match evidence; combined, they answer "did this spec
                 really press this, and is it really offensive or defensive" far more precisely
                 than categorize()'s spec-blind, filler-inclusive heuristic (still used elsewhere
                 on this page for Active Abilities/Buffs & Passives, which answer a different,
                 broader question — "everything in the kit," not "the real cooldowns"). A spell
                 classified Mixed (real signals for both) appears in BOTH tabs, matching that
                 bucket's own honest "don't force one bucket" definition — same shared
                 $offDefFilter builder below, only the direction ('offensive'/'defensive') and
                 tab key differ.

                 Collapsed from 5 per-category boxes (one grid-of-3-member-columns per category)
                 down to ONE merged box per tab, 2026-08-20 direct follow-up — same card style the
                 Synergies tab's CC cards already use (icon/name/badge row/stat block/owner label,
                 see that section below), not the plain icon+name+cooldown list row the other
                 tabs on this page still use. Deliberately CD only, no Duration block — this data
                 has no curated PvP-duration equivalent (that's Synergies/dr_category-specific),
                 so showing a Dur stat here would just always read blank. Entries from all 3
                 members are merged into one flex-wrap grid, sorted by name, each card carrying
                 its own owner (class/spec) label — same "don't group by column, let each card
                 say who it belongs to" pattern as the Synergies boxes. --}}
            @php
                $offDefFilter = fn (string $direction) => fn ($e) => ($e['isPriority'] ?? false) && ($e['offensiveDefensive'][$direction] ?? false);
            @endphp
            @foreach (['offensive' => 'Offensive', 'defensive' => 'Defensive'] as $direction => $directionLabel)
                @php
                    $odFilter = $offDefFilter($direction);
                    // Grouped by comp member (2026-08-20, reverted the same-day flat-merge above
                    // this comment after direct follow-up feedback — a 20+ card grid mixing all
                    // 3 members with only a small owner label at the bottom of each card read as
                    // one undifferentiated wall of cards. Each member now gets its own labeled
                    // section, same "scan one class at a time" structure the Cooldowns/Active
                    // Abilities tabs already used before this tab existed — while keeping the
                    // richer single-box card style (icon/name/badges/CD stat) from the flat-merge
                    // version, not reverting all the way back to the old plain icon+name+cooldown
                    // row style those other tabs still use.
                    $odByMember = collect($comp)->map(fn ($member, $mi) => [
                        'mi' => $mi,
                        'member' => $member,
                        'entries' => collect($member['entries'])->filter($odFilter)->sortBy(fn ($e) => $e['spell']->display_name)->values(),
                    ])->filter(fn ($row) => $row['entries']->isNotEmpty())->values();
                @endphp
                <div x-show="tab === '{{ $direction }}'" x-cloak>
                    @if ($odByMember->isEmpty())
                        <p class="text-[12px] text-ink-subtle px-1 py-4 text-center">
                            No arena-log-verified {{ strtolower($directionLabel) }} cooldowns for any selected spec yet — try Active Abilities for the full kit.
                        </p>
                    @else
                        <div class="space-y-4">
                            @foreach ($odByMember as $row)
                                @php
                                    $member = $row['member'];
                                    $ownerColor = $member['class'] ? (config('wow_classes.colors')[$member['class']->slug] ?? null) : null;
                                @endphp
                                <div class="linear-card p-4">
                                    @if ($member['spec'])
                                        <p class="text-[11px] font-semibold uppercase tracking-wide mb-3" style="{{ $ownerColor ? 'color: '.$ownerColor : '' }}">{{ $member['class']->name }} ({{ $member['spec']->name }})</p>
                                    @endif
                                    <div class="flex flex-wrap gap-2.5">
                                        @foreach ($row['entries'] as $entry)
                                            @php
                                                $spell = $entry['spell'];
                                                $modalKey = "m{$row['mi']}-s{$spell->id}";
                                                [$cdValue, $cdUnit] = $splitUnit($cooldownDisplay($entry) ?? '—');
                                            @endphp
                                            <button type="button"
                                                    @click="openSpellId = '{{ $modalKey }}'"
                                                    class="linear-card !p-3 w-44 flex-shrink-0 text-left hover:border-gold/40 transition-colors {{ ($entry['isSelected'] ?? true) ? '' : 'opacity-50' }}">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <x-spell-icon :spell="$spell" size="w-8 h-8"/>
                                                    <span class="text-[12px] text-ink font-semibold truncate">{{ $spell->display_name }}</span>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-1">
                                                    <span class="{{ $categoryBadge[$entry['category']] ?? 'badge-gray' }} !text-[9px]">{{ $entry['category'] }}</span>
                                                    @if (($entry['offensiveDefensive']['label'] ?? null) === 'Mixed')
                                                        <span class="badge-amber !text-[9px]" title="Real signals for both offense and defense">Mixed</span>
                                                    @endif
                                                    @if (($entry['source'] ?? null) === 'talent')
                                                        <span class="badge-blue !text-[9px]" title="Talent">T</span>
                                                    @elseif (($entry['source'] ?? null) === 'pvp_talent')
                                                        <span class="badge-gold !text-[9px]" title="PvP Talent">PvP</span>
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-3 mt-2.5 pt-2.5 border-t border-line">
                                                    <div class="flex flex-col leading-none">
                                                        <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">CD</span>
                                                        <span class="text-[15px] font-bold text-ink tabular-nums">{{ $cdValue }}<span class="text-[10px] font-bold text-ink">{{ $cdUnit }}</span></span>
                                                    </div>
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

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
                                                @if (($entry['source'] ?? null) === 'talent')
                                                    <span class="badge-blue shrink-0" title="Talent">T</span>
                                                @elseif (($entry['source'] ?? null) === 'pvp_talent')
                                                    <span class="badge-gold shrink-0" title="PvP Talent">PvP</span>
                                                @endif
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

            {{-- Synergies tab — deterministic CC-chain sequencing via CcChainBuilder. Two cards,
                 per WowComps::SYNERGY_GROUPS: "Utility" (Knockback/Disarm/Slow/Root, merged into
                 one chain) and "DRs" (Stun/Silence/Incapacitate/Disorient, merged into one
                 chain) — each merge is safe because CcChainBuilder's DR bookkeeping still keys on
                 each spell's own real dr_category, so cross-category entries in one merged chain
                 never falsely show as DR'd against each other. Any dr_category not covered by
                 either group still renders under its own card (see
                 WowComps::getSynergiesProperty()'s leftover-chain fallback) — nothing with a
                 curated dr_category is ever silently dropped. --}}
            <div x-show="tab === 'synergies'" x-cloak class="space-y-4">
                @php
                    // Neat, labeled CD/Duration pair reused across every Synergies section —
                    // CD comes from cooldown_by_id (the same talent-modified effective cooldown
                    // Active Abilities/Cooldowns already show); Duration only ever shows a
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
                        CC is grouped into Diminishing Returns Groups (Stun, Silence, Incapacitate, Disorient — the categories that actually diminish each other) and Utility (Knockback, Disarm, Slow, Root — none of which diminish anything). Each spell's own category shows as a badge on its card — chain them yourself in-game. In PvP, CC duration caps at <span class="text-ink font-semibold">{{ $pvpCapSeconds }}s</span> regardless of tooltip value, and DR resets after 20s of no reapplication. Only spells with a curated DR category are eligible — most of the game's CC isn't classified yet. "Duration" only shows once a spell's real PvP CC duration has been hand-verified — a blank duration means it hasn't been curated yet, not that the CC is instant.
                    </p>
                </div>

                {{-- Both boxes are plain groupings by real dr_category, NOT sequenced through
                     CcChainBuilder (2026-08-16, direct instruction) — no DR%/immune tracking,
                     the category badge lives on each spell's own card ("like we had originally")
                     rather than a group sub-heading. The player builds their own in-game chain
                     from what's shown. --}}
                @foreach ($synergies['groups'] as $groupLabel => $spells)
                    <div class="linear-card p-4">
                        <p class="text-[11px] uppercase tracking-wide text-gold font-semibold mb-3">{{ $groupLabel }}</p>
                        @if ($spells->isEmpty())
                            <p class="text-[12px] text-ink-subtle">No classified CC available yet.</p>
                        @else
                            <div class="flex flex-wrap gap-2.5">
                                @foreach ($spells as $spell)
                                    @php
                                        $ownerIndex = $synergies['owner_map'][$spell->id] ?? null;
                                        $ownerMember = $ownerIndex !== null ? ($comp[$ownerIndex] ?? null) : null;
                                        $ownerColor = ($ownerMember && $ownerMember['class']) ? (config('wow_classes.colors')[$ownerMember['class']->slug] ?? null) : null;
                                        $durationLabel = $fmtPvpDuration($spell);
                                        [$cdValue, $cdUnit] = $splitUnit($cdLabel($spell));
                                        [$durValue, $durUnit] = $splitUnit($durationLabel ?? '—');
                                    @endphp
                                    {{-- Clickable — opens the same spell-detail modal Active Abilities/Main
                                         Cooldowns use, keyed by "m{memberIndex}-s{spellId}" (see the
                                         modal block near the bottom of this file, which already
                                         iterates every $comp member's full entry list, Synergies-tab
                                         spells included, so no new modal content needed here — just
                                         wiring the trigger). Note for the caveat this surfaces: the
                                         modal's description/cooldown come from the spell's raw PvE
                                         game data via ModuleSpellReferenceService, which can read
                                         differently from this card's own curated PvP duration/DR
                                         category (CC is frequently shortened/reworked in PvP) — both
                                         are shown deliberately, not reconciled. --}}
                                    <button type="button"
                                            @click="openSpellId = 'm{{ $ownerIndex }}-s{{ $spell->id }}'"
                                            class="linear-card !p-3 w-44 flex-shrink-0 text-left hover:border-gold/40 transition-colors">
                                        <div class="flex items-center gap-2 mb-2">
                                            <x-spell-icon :spell="$spell" size="w-8 h-8"/>
                                            <span class="text-[12px] text-ink font-semibold truncate">{{ $spell->display_name }}</span>
                                        </div>
                                        <span class="{{ $drBadge[$spell->dr_category] ?? 'badge-gray' }} !text-[9px]">{{ $spell->dr_category }}</span>
                                        <div class="flex items-center gap-3 mt-2.5 pt-2.5 border-t border-line">
                                            <div class="flex flex-col leading-none">
                                                <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">CD</span>
                                                <span class="text-[15px] font-bold text-ink tabular-nums">{{ $cdValue }}<span class="text-[10px] font-bold text-ink">{{ $cdUnit }}</span></span>
                                            </div>
                                            <div class="w-px h-7 bg-line"></div>
                                            <div class="flex flex-col leading-none">
                                                <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">Dur</span>
                                                <span class="text-[15px] font-bold tabular-nums {{ $durationLabel ? 'text-ink' : 'text-ink-subtle italic text-[13px]' }}">{{ $durValue }}<span class="text-[10px] font-bold text-ink">{{ $durUnit }}</span></span>
                                            </div>
                                        </div>
                                        @if ($ownerMember && $ownerMember['spec'])
                                            <p class="text-[10px] font-semibold truncate mt-2.5" style="{{ $ownerColor ? 'color: '.$ownerColor : '' }}">{{ $ownerMember['class']->name }} ({{ $ownerMember['spec']->name }})</p>
                                        @endif
                                    </button>
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
                                    <button type="button"
                                            @click="openSpellId = 'm{{ $ownerIndex }}-s{{ $spell->id }}'"
                                            class="flex items-center gap-1.5 px-2 py-1 rounded bg-surface-2 border border-line hover:border-gold/40 transition-colors">
                                        <x-spell-icon :spell="$spell" size="w-5 h-5"/>
                                        <span class="text-[11px] text-ink-muted">{{ $spell->display_name }}</span>
                                        <span class="text-[10px] text-ink-subtle font-mono">CD {{ $cdLabel($spell) }}</span>
                                        @if ($flagDurationLabel)
                                            <span class="text-[10px] text-ink-subtle font-mono">Dur {{ $flagDurationLabel }}</span>
                                        @endif
                                        @if ($ownerMember && $ownerMember['spec'])
                                            <span class="text-[10px] text-ink-subtle">— {{ $ownerMember['spec']->name }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach

            </div>

            {{-- PvP Talents tab — added 2026-08-18, direct request. No new computation needed:
                 every entry already carries 'source' => 'pvp_talent' (set in
                 WowComps::computeSpellReferencesFor(), from TalentSelectionService::
                 allPvpTalentSpellIds() — a direct, required spec_id FK on pvp_talents, no
                 NULL-bucket ambiguity to worry about) — this tab is a pure filter over data
                 already on the entry array, same click-to-open spell-detail modal as every other
                 tab. Deliberately NOT category-grouped (Offensive/Defensive/etc, unlike every
                 other tab on this page) — direct follow-up request the same day: categorize()'s
                 heuristic mostly buckets real PvP talents into 'Other' anyway (verified against
                 Discipline Priest's own 11 real entries — 8 of 11 landed in Other), so the
                 grouping added visual noise without actually organizing anything. Just one flat
                 list per member instead. Every PvP talent for the spec shows regardless of
                 whether it's part of the resolved build's own picks (opacity/"Not selected"
                 still reflects that, same as Active Abilities) — this is meant as a reference
                 list of what's available, not just what's chosen. --}}
            <div x-show="tab === 'pvptalents'" x-cloak>
                @php $anyPvpTalentAtAll = $selectedMembers->contains(fn ($m) => collect($m['entries'])->contains(fn ($e) => ($e['source'] ?? null) === 'pvp_talent')); @endphp
                @if (!$anyPvpTalentAtAll)
                    <p class="text-[12px] text-ink-subtle px-1 py-4 text-center">No PvP talent data for any selected spec.</p>
                @else
                    <div class="grid grid-cols-3 gap-3">
                        @foreach ($comp as $mi => $member)
                            <div class="linear-card p-1.5 space-y-0.5">
                                @php
                                    $pvpTalentEntries = collect($member['entries'])->filter(fn ($e) => ($e['source'] ?? null) === 'pvp_talent');
                                @endphp
                                @forelse ($pvpTalentEntries as $entry)
                                    @php $modalKey = "m{$mi}-s{$entry['spell']->id}"; @endphp
                                    <button type="button"
                                            @click="openSpellId = '{{ $modalKey }}'"
                                            class="w-full flex items-center gap-2 text-left px-1.5 py-1 rounded hover:bg-surface-2 transition-colors {{ ($entry['isSelected'] ?? true) ? '' : 'opacity-50' }}">
                                        <x-spell-icon :spell="$entry['spell']" size="w-6 h-6"/>
                                        <span class="flex-1 min-w-0 text-[12px] text-ink truncate">{{ $entry['spell']->display_name }}</span>
                                        <span class="badge-gold shrink-0" title="PvP Talent">PvP</span>
                                        <span class="text-[10px] text-ink-subtle whitespace-nowrap">{{ $cooldownDisplay($entry) ?? '—' }}</span>
                                    </button>
                                @empty
                                    <p class="text-[11px] text-ink-subtle px-1.5 py-1">—</p>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Offensive Rotation tab — replaced the Kill Sequence tab 2026-08-20 (direct
                 request). That tab showed a per-rating-band ranked FREQUENCY LIST of individual
                 abilities cast before a kill; this shows each spec's single most common real cast
                 COMBO as an ordered sequence, which is what actually reads as a rotation and
                 mirrors how the Crowd Control tab presents its groupings.

                 Data comes from ArenaLogService::rotationForSpec() — promoted, pre-computed
                 per-spec summaries built by wow-arena-archive's offensive-rotations.php (anchored
                 on each spec's own real offensive cooldowns, target identified from where damage
                 actually went in the window). Each combo is the top cast run that both contains
                 one of the spec's own offensive cooldowns and uses >= 3 distinct abilities —
                 without those rules the raw winner was routinely filler spam ("Mortal Strike x4")
                 or a dual-wield logging artifact rather than a rotation.

                 One grouping box per spec (per the request), each step rendered with the same
                 card styling the Crowd Control / Offensive Cooldowns tabs use, chained with
                 arrows. Sample sizes are shown deliberately — some specs' combos rest on very few
                 observations and shouldn't read with the same authority as one backed by 100. --}}
            <div x-show="tab === 'rotation'" x-cloak class="space-y-4">
                <div class="linear-card p-4">
                    <p class="text-[12px] text-ink-muted leading-relaxed">
                        <span class="text-amber-400 font-semibold">Preview / in development.</span>
                        The most common cast sequence each spec actually performs around its own offensive cooldowns, taken from real matches. The target is identified by where that player's damage actually went inside the window, so a go that didn't kill still counts. <span class="text-ink font-semibold">Kill combo</span> is the same thing restricted to windows where the target actually died. Sample sizes vary a lot by spec — a low count means "not much data yet," not a confident answer.
                    </p>
                </div>

                @foreach ($comp as $mi => $member)
                    @php
                        $rot = $offensiveRotations[$mi] ?? null;
                        $memberColor = ($member['class']) ? (config('wow_classes.colors')[$member['class']->slug] ?? null) : null;
                    @endphp
                    <div class="linear-card p-4">
                        @if (!$member['spec'])
                            <p class="text-[11px] uppercase tracking-wide text-ink-subtle font-semibold">{{ $member['label'] }} — no spec selected</p>
                        @else
                            <div class="flex items-baseline justify-between gap-3 mb-3">
                                <p class="text-[11px] uppercase tracking-wide font-semibold" style="{{ $memberColor ? 'color: '.$memberColor : '' }}">
                                    {{ $member['spec']->name }} {{ $member['class']->name }}
                                </p>
                                @if ($rot)
                                    <p class="text-[10px] text-ink-subtle">
                                        {{ number_format($rot['windows']) }} cooldown windows across {{ $rot['matches'] }} matches
                                    </p>
                                @endif
                            </div>

                            @if (!$rot || empty($rot['topCombo']))
                                <p class="text-[12px] text-ink-subtle italic">Not enough match evidence for a rotation on this spec yet.</p>
                            @else
                                @foreach ([['key' => 'topCombo', 'label' => 'Most common combo', 'sample' => $rot['windows']], ['key' => 'topKillCombo', 'label' => 'Most common kill combo', 'sample' => $rot['killWindows']]] as $block)
                                    @php $combo = $rot[$block['key']] ?? null; @endphp
                                    @continue(!$combo)

                                    <div class="{{ !$loop->first ? 'mt-4 pt-4 border-t border-line' : '' }}">
                                        <div class="flex items-baseline gap-2 mb-2.5">
                                            <p class="text-[10px] uppercase tracking-wider text-gold font-semibold">{{ $block['label'] }}</p>
                                            <span class="text-[10px] text-ink-subtle">
                                                seen in {{ $combo['count'] }} of {{ number_format($block['sample']) }} ({{ $combo['pct'] }}%)
                                            </span>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            @foreach ($combo['steps'] as $si => $step)
                                                @if ($si > 0)
                                                    <svg class="w-3.5 h-3.5 text-ink-subtle flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                                    </svg>
                                                @endif
                                                @php
                                                    $stepSpell = $step['spell'] ?? null;
                                                    $isAnchor = in_array($step['name'], $rot['anchors'] ?? [], true);
                                                @endphp
                                                @if ($stepSpell)
                                                    {{-- Clickable into the same spell-detail modal every other tab uses, but
                                                         ONLY when this spell is actually one of this member's own rendered
                                                         entries — a rotation step can be a filler/proc ability that isn't in
                                                         the tab's entry list, and keying the modal to a spell with no matching
                                                         content block would open an empty overlay. --}}
                                                    @php
                                                        $hasModal = collect($member['entries'])->contains(fn ($e) => $e['spell']->id === $stepSpell->id);
                                                    @endphp
                                                    <button type="button"
                                                            @if ($hasModal) @click="openSpellId = 'm{{ $mi }}-s{{ $stepSpell->id }}'" @else disabled @endif
                                                            class="linear-card !p-2.5 w-36 flex-shrink-0 text-left {{ $hasModal ? 'hover:border-gold/40 transition-colors' : 'cursor-default' }} {{ $isAnchor ? '!border-gold/50' : '' }}">
                                                        <div class="flex items-center gap-2">
                                                            <x-spell-icon :spell="$stepSpell" size="w-8 h-8"/>
                                                            <span class="min-w-0">
                                                                <span class="block text-[11px] text-ink font-semibold truncate">{{ $stepSpell->display_name }}</span>
                                                                @if ($isAnchor)
                                                                    <span class="badge-gold !text-[8px] !px-1 !py-0 mt-0.5">CD</span>
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
                                    </div>
                                @endforeach
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Rating Tiers tab — DEV/preview, reads data/arena-logs/rating-tiers/{class}/{spec}.json
                 directly (RatingTierAnalysisService / wow:analyze-rating-tiers), same "read straight
                 off disk, no DB, no cache" posture as the Kill Sequence tab above. Damage/spell-cast
                 rate/CC/interrupts/deaths/win-loss are shown per rating band, further split by hero
                 talent tree (Aldrachi Reaver vs Fel-Scarred etc.) — see the command's own docblock
                 for why the hero-tree split matters (a flat per-spec average silently blends
                 different playstyles together for hero-tree-exclusive abilities). --}}
            <div x-show="tab === 'ratingtiers'" x-cloak class="space-y-4">
                <div class="linear-card p-4">
                    <p class="text-[12px] text-ink-muted leading-relaxed">
                        <span class="text-amber-400 font-semibold">Preview / in development.</span>
                        Damage, spell-cast rate, and win/loss-controlled survivability compared across rating bands, further split by hero talent tree. Sample sizes vary a lot by spec and hero tree right now — a low count means "not much data yet," not a confident answer.
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    @foreach ($comp as $mi => $member)
                        <div class="linear-card p-3 space-y-3">
                            @if (!$member['spec'])
                                <p class="text-[11px] text-ink-subtle">—</p>
                            @else
                                @php $rt = $ratingTiers[$mi]; @endphp
                                <p class="text-[11px] font-semibold text-ink truncate">{{ $member['spec']->name }} {{ $member['class']->name }}</p>

                                @if (empty($rt['bands']))
                                    <p class="text-[11px] text-ink-subtle italic">No rating-tier data for this spec yet.</p>
                                @else
                                    @foreach ($rt['bands'] as $band)
                                        <div class="pt-2 first:pt-0 border-t border-line first:border-t-0">
                                            <div class="flex items-center justify-between">
                                                <span class="text-[11px] font-semibold text-ink">{{ $band['label'] }}</span>
                                                <span class="badge-gray !text-[9px] whitespace-nowrap">n={{ $band['n'] }}</span>
                                            </div>

                                            @if ($band['n'] === 0)
                                                <p class="text-[10px] text-ink-subtle italic mt-0.5">No matches on disk in this range.</p>
                                            @else
                                                <div class="grid grid-cols-2 gap-x-2 gap-y-0.5 mt-1 text-[10px]">
                                                    <span class="text-ink-subtle">DPS</span><span class="text-ink font-mono text-right">{{ number_format($band['avgDps']) }}</span>
                                                    <span class="text-ink-subtle">Casts/min</span><span class="text-ink font-mono text-right">{{ $band['avgCastsPerMin'] }}</span>
                                                    <span class="text-ink-subtle">Win rate</span><span class="text-ink font-mono text-right">{{ $band['winRate'] !== null ? $band['winRate'].'%' : '—' }}</span>
                                                    <span class="text-ink-subtle">Deaths/game</span><span class="text-ink font-mono text-right">{{ $band['avgDeaths'] }}</span>
                                                </div>

                                                @if (!empty($band['heroTreeBreakdown']) && count($band['heroTreeBreakdown']) > 1)
                                                    <div class="mt-1.5 pl-2 border-l border-line space-y-1">
                                                        @foreach ($band['heroTreeBreakdown'] as $treeName => $treeStats)
                                                            @if (($treeStats['n'] ?? 0) > 0)
                                                                <div class="flex items-center justify-between text-[9.5px]">
                                                                    <span class="text-ink-subtle truncate">{{ $treeName }} <span class="text-ink-subtle">(n={{ $treeStats['n'] }})</span></span>
                                                                    <span class="text-ink font-mono">{{ number_format($treeStats['avgDps']) }} dps</span>
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
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
    @endif
</div>
