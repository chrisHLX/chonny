<div class="max-w-6xl mx-auto px-4 py-8 space-y-5">

    {{-- Header --}}
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Spell Counters</p>
        <h1 class="font-display text-[26px] font-bold leading-tight mt-1 text-ink">Counterable Spells &amp; Their Counters</h1>
        <p class="text-[12.5px] text-ink-muted mt-1.5 max-w-2xl">
            Every real crowd-control ability in the game, grouped by class, alongside what actually counters it —
            found across every class, not just one chosen matchup. Four kinds of counter, never conflated:
            an ability usable even while affected, a cooldown that grants immunity to a specific CC mechanic
            (Icebound Fortitude-style), a cooldown that grants immunity to the CC's own school (Cloak of Shadows/
            Divine Shield/Blessing of Protection-style), and (only when the CC itself can actually be dodged/parried)
            a real dodge/parry buff. Click any spell for full detail.
        </p>
    </div>

    @foreach ($counterableByClass as $className => $rows)
        <div class="linear-card px-6 py-5">
            <h2 class="font-display text-xl text-gold mb-3">{{ $className }} <span class="text-ink-subtle text-sm font-sans">({{ $rows->count() }})</span></h2>
            <div class="space-y-2">
                @foreach ($rows as $row)
                    @php $rowSpell = $row['spell']; @endphp
                    <div class="linear-card !p-3 {{ $row['hasAnyCounter'] ? '' : 'opacity-60' }}">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <button type="button" wire:click="$dispatch('show-spell-detail', { spellId: {{ $rowSpell->id }} })"
                                    class="flex items-center gap-2 hover:opacity-80 transition-opacity">
                                <x-spell-icon :spell="$rowSpell" size="w-7 h-7"/>
                                <span class="text-[12px] text-ink font-semibold">{{ $rowSpell->display_name }}</span>
                                <span class="badge-blue !text-[9px]">{{ $rowSpell->dr_category }}</span>
                            </button>
                            @unless ($row['hasAnyCounter'])
                                <span class="text-[10px] text-ink-subtle italic">no known counter</span>
                            @endunless
                        </div>

                        @if ($row['hasAnyCounter'])
                            <div class="flex flex-wrap gap-x-4 gap-y-1.5 mt-2 pt-2 border-t border-line">
                                @foreach ([
                                    ['label' => 'Usable while', 'entries' => $row['usableWhileThis']],
                                    ['label' => 'Immune via (mechanic)', 'entries' => $row['grantsImmunity']],
                                    ['label' => 'Immune via (school)', 'entries' => $row['schoolImmunity']],
                                    ['label' => 'Dodge/Parry', 'entries' => $row['dodgeParryBoost']],
                                ] as $bucket)
                                    @if ($bucket['entries']->isNotEmpty())
                                        @php $shown = $bucket['entries']->take(8); $remaining = $bucket['entries']->count() - $shown->count(); @endphp
                                        <div class="flex items-center flex-wrap gap-1.5">
                                            <span class="text-[9px] text-ink-subtle uppercase tracking-wide">{{ $bucket['label'] }} ({{ $bucket['entries']->count() }}):</span>
                                            @foreach ($shown as $counterSpell)
                                                <button type="button"
                                                        wire:click="$dispatch('show-spell-detail', { spellId: {{ $counterSpell->id }} })"
                                                        class="flex items-center gap-1 bg-surface-2 rounded px-1.5 py-0.5 hover:bg-surface-3 transition-colors">
                                                    <x-spell-icon :spell="$counterSpell" size="w-4 h-4"/>
                                                    <span class="text-[10.5px] text-ink-muted">{{ $counterSpell->display_name }}</span>
                                                </button>
                                            @endforeach
                                            @if ($remaining > 0)
                                                <span class="text-[10px] text-ink-subtle italic">+{{ $remaining }} more</span>
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    @if ($counterableByClass->isEmpty())
        <div class="linear-card px-6 py-5">
            <p class="text-ink-muted text-sm">No crowd-control abilities found for the current patch.</p>
        </div>
    @endif

    {{-- Disclaimer, kept from the prior version's tone but rewritten for what this page now does. --}}
    <div class="linear-card px-6 py-4">
        <p class="text-[10.5px] text-ink-subtle leading-relaxed">
            Every fact above (dr_category, usable-while-CC'd, immunity-granting cooldowns, dodge/parry buffs) is
            read directly from the same database every other page on this site uses — nothing here is hand-typed
            per matchup. The dr_category-to-counter-signal mapping is deliberately narrow: only Stun has a verified
            "usable while X" correspondence, and only Stun/Silence/Incapacitate have a verified immunity-mechanic
            correspondence — a CC type outside those (Root, Disorient, Knockback, Disarm, Slow) will correctly show
            "no known counter" rather than a guessed one. Written by Claude (an AI); treat it as a starting
            hypothesis, not a verdict.
        </p>
    </div>

    <livewire:spell-detail-modal/>
</div>
