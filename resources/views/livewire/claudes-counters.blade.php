{{-- Embedded (a PvP Guides panel) drops the page-level width cap/padding and its own header —
     the parent page supplies both. --}}
<div class="{{ ($embedded ?? false) ? 'space-y-5' : 'max-w-6xl mx-auto px-4 py-8 space-y-5' }}">

    @unless ($embedded ?? false)
    {{-- Header --}}
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Spell Counters</p>
        <h1 class="font-display text-[26px] font-bold leading-tight mt-1 text-ink">Crowd Control and Its Counters</h1>
        <p class="text-[12.5px] text-ink-muted mt-1.5 max-w-2xl">
            Every crowd-control ability in the game, grouped by class, with the abilities that answer it.
            A counter can be something you can press while controlled, a cooldown that makes you immune to
            that type of CC (like Icebound Fortitude), a cooldown that blocks its magic school (like Cloak of
            Shadows or Divine Shield), or a dodge or parry effect. Click any spell for details.
        </p>
    </div>
    @endunless

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
                                <span class="{{ config('spell_display.dr_badges')[$rowSpell->dr_category] ?? 'badge-gray' }} !text-[9px]">{{ $rowSpell->dr_category }}</span>
                            </button>
                            @unless ($row['hasAnyCounter'])
                                <span class="text-[10px] text-ink-subtle italic">no known counter</span>
                            @endunless
                        </div>

                        @if ($row['hasAnyCounter'])
                            <div class="flex flex-wrap gap-x-4 gap-y-1.5 mt-2 pt-2 border-t border-line">
                                {{-- Buckets come pre-ordered and pre-sorted from the component;
                                     labels and confidence both come from SpellCounter so no
                                     template invents its own vocabulary for them. --}}
                                @foreach ($row['buckets'] as $mechanism => $entries)
                                    @php
                                        $shown = $entries->take(8);
                                        $remaining = $entries->count() - $shown->count();
                                        $weak = !$entries->first()->isHighConfidence();
                                    @endphp
                                    <div class="flex items-center flex-wrap gap-1.5 {{ $weak ? 'opacity-60' : '' }}">
                                        <span class="text-[9px] text-ink-subtle uppercase tracking-wide"
                                              @if ($weak) title="Blizzard's 'Allow While Stunned' attribute also fires on auras that merely persist through the effect — treat with care." @endif>
                                            {{ $entries->first()->label() }} ({{ $entries->count() }}){{ $weak ? ' ?' : '' }}:
                                        </span>
                                        @foreach ($shown as $counter)
                                            <button type="button"
                                                    wire:click="$dispatch('show-spell-detail', { spellId: {{ $counter->counter_spell_id }} })"
                                                    class="flex items-center gap-1 bg-surface-2 rounded px-1.5 py-0.5 hover:bg-surface-3 transition-colors">
                                                <x-spell-icon :spell="$counter->counterSpell" size="w-4 h-4"/>
                                                <span class="text-[10.5px] text-ink-muted">{{ $counter->counterSpell->display_name }}</span>
                                            </button>
                                        @endforeach
                                        @if ($remaining > 0)
                                            <span class="text-[10px] text-ink-subtle italic">+{{ $remaining }} more</span>
                                        @endif
                                    </div>
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
            Counters are worked out automatically from game data, so some answers may be missing. Roots,
            disorients, knockbacks, disarms and slows don't have verified counters yet and show as none rather
            than a guess. Use this as a starting point for your own matchup planning.
        </p>
    </div>

    {{-- Only one shared modal may exist per page; when embedded, PvP Guides mounts it. --}}
    @unless ($embedded ?? false)
        <livewire:spell-detail-modal/>
    @endunless
</div>
