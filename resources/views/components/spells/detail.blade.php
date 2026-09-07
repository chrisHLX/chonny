@props([
    // App\Support\SpellProfile
    'profile',
    // Whether a real spec context was resolved. Gates the build-dependent sections: with no
    // build there is nothing for a talent to be "not selected" in.
    'specId' => null,
    // The /spell/{id} page shows counters and availability; the modal keeps them collapsed to
    // stay compact. Both read the same profile.
    'expanded' => false,
])

@php
    $spell = $profile->spell;
    $categoryBadges = config('spell_display.category_badges');
    $drBadges = config('spell_display.dr_badges');
    $ccTokenLabel = config('spell_display.cc_token_labels');

    $fmtSeconds = fn ($s) => rtrim(rtrim(number_format((float) $s, 2), '0'), '.') . 's';
    // Defensive `?? null` reads throughout — see the identical note in wow-comps.blade.php for
    // why (a real production incident, 2026-08-31).
    $cooldownSeconds = $profile['cooldown']['seconds'] ?? null;
    $baseCooldown = $profile['cooldown']['base_seconds'] ?? null;
    $chargeCount = $profile['charges']['charges'] ?? null;
    $baseCharges = $profile['charges']['base_charges'] ?? null;
    $duration = $profile->effectiveDurationSeconds();

    $toneClass = fn (array $t) => match ($t['tone']) {
        'category' => $categoryBadges[$t['label']] ?? 'badge-gray',
        'dr' => $drBadges[$t['label']] ?? 'badge-gray',
        'muted' => 'badge-gray',
        default => 'badge-gold',
    };

    // Only the high-confidence mechanisms are shown by default; `usable_while` is real data but
    // demonstrably noisy (see SpellCounterIndexer's docblock) so it is separated, not deleted.
    $counterGroups = $profile->countersByMechanism();
    $strongCounters = $counterGroups->filter(fn ($rows, $m) => ($rows->first()?->isHighConfidence() ?? false));
    $weakCounters = $counterGroups->reject(fn ($rows, $m) => ($rows->first()?->isHighConfidence() ?? false));
@endphp

<div>
    {{-- Identity ------------------------------------------------------------------------- --}}
    <div class="flex items-start gap-3 mb-3">
        <x-spell-icon :spell="$spell" size="{{ $expanded ? 'w-14 h-14' : 'w-11 h-11' }}" class="rounded-lg shrink-0"/>
        <div class="min-w-0">
            <p class="{{ $expanded ? 'text-[20px]' : 'text-[15px]' }} font-semibold text-ink">{{ $profile->displayName() }}</p>
            <p class="text-[10px] text-ink-subtle font-mono">#{{ $spell->spell_id }}</p>
            <div class="flex flex-wrap gap-1 mt-1.5">
                @foreach ($profile->traits() as $trait)
                    <span class="{{ $toneClass($trait) }}">{{ $trait['label'] }}</span>
                @endforeach
            </div>
        </div>
    </div>

    {{-- The at-a-glance shape row. Every value here is build-independent and read straight off
         the materialized row — no service call, no talent context needed. --}}
    @php
        $facts = array_filter([
            'School' => $spell->school,
            'Type' => $spell->spell_type,
            'Cast' => $spell->cast_type ? ucfirst($spell->cast_type) : null,
            'Range' => $spell->range_yards ? $spell->range_yards . ' yd' : null,
            'Mechanic' => $spell->mechanic,
        ]);
    @endphp
    @if ($facts)
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] mb-3">
            @foreach ($facts as $label => $value)
                <span><span class="text-ink-subtle">{{ $label }}</span> <span class="text-ink-muted font-medium">{{ $value }}</span></span>
            @endforeach
        </div>
    @endif

    {{-- Description ---------------------------------------------------------------------- --}}
    <p class="text-[13px] text-ink-muted leading-relaxed">{{ $profile['description']['text'] ?: 'No description available.' }}</p>

    @if ($profile['description']['uncertain'] ?? false)
        <p class="text-[10px] text-ink-subtle italic mt-1.5">Some values above vary by condition or aren't fully known — check in-game.</p>
    @endif
    @if ($profile['formulaModifiers']->isNotEmpty())
        <p class="text-[10px] text-ink-subtle mt-1.5"><span class="font-semibold">Scales with:</span> {{ $profile['formulaModifiers']->pluck('display_name')->implode(', ') }}</p>
    @endif
    @if (!$specId)
        <p class="text-[10px] text-gold/70 italic mt-1.5">No spec context — showing base values, not talent-modified.</p>
    @endif

    {{-- Numbers -------------------------------------------------------------------------- --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-[12px] mt-3 pt-3 border-t border-line">
        <div>
            <span class="text-ink-subtle">Cooldown</span>
            <span class="text-ink font-semibold ml-1">{{ $cooldownSeconds !== null ? $fmtSeconds($cooldownSeconds) : '—' }}</span>
            @if ($cooldownSeconds !== null && $baseCooldown !== null && round($cooldownSeconds, 2) !== round($baseCooldown, 2))
                <span class="text-[10px] text-ink-subtle line-through ml-1">{{ $fmtSeconds($baseCooldown) }}</span>
            @endif
        </div>

        {{-- Duration was previously absent from this view entirely, despite being one of the
             first things anyone wants when they click a CC ability. pvp_duration_seconds wins
             when known and is labelled as such — it is hand-verified per spell and is NOT
             derivable from duration_seconds (Polymorph reads 60s in PvE against a real 6s in
             PvP). See SpellProfile::effectiveDurationSeconds(). --}}
        @if ($duration !== null)
            <div>
                <span class="text-ink-subtle">{{ $profile->durationIsPvpVerified() ? 'Duration (PvP)' : 'Duration' }}</span>
                <span class="text-ink font-semibold ml-1">{{ $fmtSeconds($duration) }}</span>
                @if ($profile->durationIsPvpVerified() && $spell->duration_seconds !== null && round((float) $spell->duration_seconds, 2) !== round($duration, 2))
                    <span class="text-[10px] text-ink-subtle ml-1">({{ $fmtSeconds($spell->duration_seconds) }} PvE)</span>
                @endif
            </div>
        @endif

        @if ($chargeCount !== null && $chargeCount > 1)
            <div>
                <span class="text-ink-subtle">Charges</span>
                <span class="text-ink font-semibold ml-1">{{ $chargeCount }}</span>
                @if ($baseCharges !== null && $chargeCount !== $baseCharges)
                    <span class="text-[10px] text-ink-subtle line-through ml-1">{{ $baseCharges }}</span>
                @endif
            </div>
        @endif
    </div>
    @if ($spell->cooldown_scaling_note)
        <p class="text-[10px] text-ink-subtle italic mt-1.5">{{ $spell->cooldown_scaling_note }}</p>
    @endif

    {{-- CC interaction --------------------------------------------------------------------
         Silence is a school-lockout, not a universal action-lock like Stun/Fear/Confuse/Charm —
         those need an explicit "Allow While X" Attribute flag (usable_while_cc) because they
         block ALL actions regardless of school. A Physical ability was never subject to the
         Silence lockout, so no per-spell flag exists for it anywhere in the dataset; the
         materialized silence_immune_by_school column carries that fact. --}}
    @php $isPhysicalActive = (bool) $spell->silence_immune_by_school; @endphp
    @if ($spell->usable_while_cc || $spell->bypasses_active_defense || $spell->cc_immunity_note || $profile->grantsCcImmunity->isNotEmpty() || $profile->grantsSchoolImmunity || $isPhysicalActive)
        <div class="mt-2 pt-2 border-t border-line space-y-1">
            @if ($isPhysicalActive)
                <p class="text-[10px] text-violet"><span class="font-semibold">Physical ability — not affected by Silence</span></p>
            @endif
            @if ($spell->usable_while_cc)
                <p class="text-[10px] text-violet">
                    <span class="font-semibold">Usable while:</span>
                    {{ collect(explode(',', $spell->usable_while_cc))->map(fn ($t) => $ccTokenLabel[$t] ?? $t)->implode(', ') }}
                </p>
            @endif
            @if ($spell->bypasses_active_defense)
                <p class="text-[10px] text-violet"><span class="font-semibold">Cannot be dodged, parried, or blocked.</span></p>
            @endif
            {{-- A talent-gated immunity must never be shown as though every owner of the spell has
                 it: base Fade grants nothing at all, it is Phase Shift that does. The qualifier is
                 driven off cc_immunity_gating_spell_id (already on $spell, no extra query), and the
                 curated cc_immunity_note directly below names the specific talent. --}}
            @if ($profile->grantsCcImmunity->isNotEmpty())
                <p class="text-[10px] text-violet">
                    <span class="font-semibold">Grants immunity to:</span> {{ $profile->grantsCcImmunity->implode(', ') }}
                    {{ $spell->cc_immunity_gating_spell_id ? '(only with the PvP talent below)' : '(while active)' }}
                </p>
            @endif
            @if ($profile->grantsSchoolImmunity)
                <p class="text-[10px] text-violet"><span class="font-semibold">Grants school immunity:</span> {{ $profile->grantsSchoolImmunity }}</p>
            @endif
            @if ($spell->cc_immunity_note)
                <p class="text-[10px] text-ink-subtle italic">{{ $spell->cc_immunity_note }}</p>
            @endif
        </div>
    @endif

    {{-- Counters ---------------------------------------------------------------------------
         Read from the materialized spell_counters index, so this section costs one relation load
         rather than the four in-memory candidate pools ClaudesCounters used to rebuild per
         request — and, more importantly, is available here at all, which it never was before. --}}
    @if ($strongCounters->isNotEmpty() || $weakCounters->isNotEmpty())
        <div class="mt-3 pt-3 border-t border-line" x-data="{ showWeak: false }">
            <p class="text-[10px] uppercase tracking-wide text-ink-subtle font-semibold mb-1.5">Countered By</p>

            @foreach ($strongCounters as $mechanism => $rows)
                <div class="mb-2 last:mb-0">
                    <p class="text-[10px] text-ink-subtle mb-1">{{ $rows->first()->label() }}</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($rows->sortBy(fn ($r) => $r->counterSpell->display_name) as $row)
                            <button type="button"
                                    wire:click="$dispatch('show-spell-detail', { spellId: {{ $row->counter_spell_id }} })"
                                    class="flex items-center gap-1 px-1.5 py-1 rounded bg-surface-2 hover:bg-surface-3 transition-colors">
                                <x-spell-icon :spell="$row->counterSpell" size="w-4 h-4"/>
                                <span class="text-[11px] text-ink-muted">{{ $row->counterSpell->display_name }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if ($weakCounters->isNotEmpty())
                <button type="button" @click="showWeak = !showWeak"
                        class="text-[10px] text-ink-subtle hover:text-ink-muted mt-1 underline decoration-dotted">
                    <span x-show="!showWeak">Show lower-confidence counters</span>
                    <span x-show="showWeak" x-cloak>Hide lower-confidence counters</span>
                </button>
                <div x-show="showWeak" x-cloak x-collapse class="mt-1.5">
                    {{-- Deliberately labelled rather than hidden or silently included. Blizzard's
                         "Allow While Stunned" attribute is real, but it is set on auras that
                         merely persist through a stun (Living Bomb, Freezing Trap) as well as on
                         genuine act-through-CC abilities, and nothing in the data separates the
                         two. Saying so is more useful than pretending either way. --}}
                    <p class="text-[10px] text-ink-subtle italic mb-1">
                        Flagged usable while affected — Blizzard's own attribute, but it also fires on auras that merely persist through the effect, so treat with care.
                    </p>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($weakCounters->flatten()->sortBy(fn ($r) => $r->counterSpell->display_name) as $row)
                            <button type="button"
                                    wire:click="$dispatch('show-spell-detail', { spellId: {{ $row->counter_spell_id }} })"
                                    class="flex items-center gap-1 px-1.5 py-1 rounded bg-surface-2/60 hover:bg-surface-3 transition-colors opacity-75">
                                <x-spell-icon :spell="$row->counterSpell" size="w-4 h-4"/>
                                <span class="text-[11px] text-ink-subtle">{{ $row->counterSpell->display_name }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Who has it ------------------------------------------------------------------------ --}}
    @if ($profile->availability?->isNotEmpty())
        @php
            $bySpec = $profile->availability
                ->filter(fn ($a) => $a->gameClass !== null)
                ->groupBy(fn ($a) => $a->gameClass->name);
        @endphp
        @if ($bySpec->isNotEmpty())
            <div class="mt-3 pt-3 border-t border-line">
                <p class="text-[10px] uppercase tracking-wide text-ink-subtle font-semibold mb-1.5">Available To</p>
                <div class="space-y-1">
                    @foreach ($bySpec as $className => $rows)
                        <p class="text-[11px]">
                            <span class="text-ink-muted font-medium">{{ $className }}</span>
                            <span class="text-ink-subtle">
                                {{ $rows->map(fn ($a) => $a->specialization?->name)->filter()->unique()->implode(', ') ?: 'all specs' }}
                            </span>
                        </p>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    {{-- Talents affecting this spell ------------------------------------------------------
         One list with a switch per row, replacing the old split between "Modifies / Enhances"
         (selected) and "Could Be Improved By" (not selected). Those were two views of the same
         question, and once rows became toggleable a spell would have jumped between two sections
         on every click. SpellProfile::talentToggles() does the merging and ordering.

         Flicking a switch re-resolves the numbers server-side against an overridden selection
         set — it does NOT write to any talent build. See TogglesSpellTalents for why the state is
         deliberately ephemeral. --}}
    @php $toggles = $profile->talentToggles(); @endphp
    @if ($toggles)
        <div class="mt-3 pt-3 border-t border-line" x-data="{ expandedMod: null }">
            <div class="flex items-baseline justify-between gap-2 mb-1.5">
                <p class="text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Talents Affecting This Spell</p>
                @if ($profile->hasTalentOverrides())
                    <button type="button" wire:click="resetTalentOverrides"
                            class="text-[10px] text-gold/80 hover:text-gold underline decoration-dotted">
                        Reset to build
                    </button>
                @endif
            </div>

            @if ($specId)
                <p class="text-[10px] text-ink-subtle mb-1.5">
                    Switched on = active in this build. Flick any of them to see the effect on the numbers above — nothing is saved.
                </p>
            @else
                {{-- With no spec there is no build for a talent to be on or off in, so the
                     switches would be describing nothing. Rows still render (they are real
                     modifiers) but read-only, rather than offering a control that cannot mean
                     anything. --}}
                <p class="text-[10px] text-ink-subtle mb-1.5">Open this spell from a class/spec view to switch these on and off.</p>
            @endif

            @foreach ($toggles as $row)
                @php
                    $rowSpell = $row['spell'];
                    $modId = $rowSpell->id;
                    $rowCooldown = $row['cooldown']['seconds'] ?? null;
                    $magnitude = ($row['modifierValue'] !== null && $row['modifierUnit'])
                        ? $row['modifierValue'] . ' ' . $row['modifierUnit']
                        : null;
                @endphp
                <div class="mb-1 last:mb-0">
                    <div class="flex items-center gap-1.5 py-0.5 -mx-1 px-1 rounded {{ $row['isActive'] ? '' : 'opacity-70' }}">
                        @if ($specId)
                            {{-- The switch. $row['isActive'] is passed back so the component knows
                                 what the click meant without rebuilding the profile to find out. --}}
                            <button type="button"
                                    wire:click="toggleTalent({{ $row['selectionSpellId'] }}, {{ $row['isActive'] ? 'true' : 'false' }})"
                                    aria-pressed="{{ $row['isActive'] ? 'true' : 'false' }}"
                                    title="{{ $row['isActive'] ? 'Active — click to see this spell without it' : 'Not taken — click to see what it would do' }}"
                                    class="relative w-7 h-4 rounded-full flex-shrink-0 transition-colors {{ $row['isActive'] ? 'bg-gold' : 'bg-surface-3 border border-line-strong' }}">
                                <span class="absolute top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full bg-surface-0 transition-all {{ $row['isActive'] ? 'left-[14px]' : 'left-[3px]' }}"></span>
                            </button>
                        @endif
                        <button type="button"
                                @click="expandedMod = expandedMod === {{ $modId }} ? null : {{ $modId }}"
                                class="flex items-center gap-1.5 flex-1 min-w-0 text-left hover:bg-surface-2 rounded px-1 -mx-1 py-0.5 transition-colors">
                            <svg class="w-3 h-3 text-ink-subtle flex-shrink-0 transition-transform" :class="expandedMod === {{ $modId }} && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <x-spell-icon :spell="$rowSpell" size="w-5 h-5" class="{{ $row['isActive'] ? '' : 'grayscale' }}"/>
                            <span class="text-[12px] {{ $row['isActive'] ? 'text-ink-muted' : 'text-ink-subtle' }} flex-1 truncate">{{ $rowSpell->display_name }}</span>
                            @if ($row['isOverridden'])
                                <span class="badge-gold !text-[8px] flex-shrink-0">changed</span>
                            @endif
                            @if ($magnitude)
                                <span class="text-[10px] text-gold/70 font-mono flex-shrink-0">{{ $magnitude }}</span>
                            @endif
                        </button>
                    </div>
                    <div x-show="expandedMod === {{ $modId }}" x-cloak x-collapse
                         class="ml-[18px] pl-2.5 border-l border-line mt-1 mb-1.5">
                        <span class="{{ $categoryBadges[$row['category']] ?? 'badge-gray' }} mb-1">{{ $row['category'] }}</span>
                        <p class="text-[11px] text-ink-muted leading-relaxed mt-1">{{ $row['description']['text'] ?: 'No description available.' }}</p>
                        @if ($rowCooldown !== null)
                            <p class="text-[10px] text-ink-subtle mt-1"><span class="font-semibold">Cooldown</span> {{ $fmtSeconds($rowCooldown) }}</p>
                        @endif
                        @if (!$row['isActive'])
                            {{-- Honest about the rank assumption: a talent that is not taken has no
                                 rank on file, and resolveRankAwareMagnitude() assumes the highest
                                 one rather than showing nothing. Say so instead of quietly
                                 presenting a max-rank number as if it were the only number. --}}
                            <p class="text-[10px] text-ink-subtle italic mt-1">Not taken in this build — switching it on shows its effect at maximum rank.</p>
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($specId)
                {{-- Deliberately stated rather than left for someone to notice. The description
                     text above resolves from whether a talent is present in the spec's KIT, not
                     from whether it is selected — and that is correct, not a shortcut: measured
                     across all 40 default builds, 702 of 1450 conditional tokens would render a
                     different branch under selection-based gating, and 600 of those are gated on
                     something that is not a talent at all (the spec's own identity passive, the
                     spell itself). Switching that gate would put Shadow Priest wording on a Holy
                     Priest. See CLAUDE.md's "Talent toggles" section. --}}
                <p class="text-[10px] text-ink-subtle italic mt-2">Switches change the numbers, not the description text — Blizzard writes that wording per spec, not per talent choice.</p>
            @endif
        </div>
    @endif
</div>
