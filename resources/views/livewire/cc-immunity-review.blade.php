<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="font-display text-3xl text-ink">CC Immunity &amp; Usable-While-CC'd Lookup</h1>
        <p class="text-ink-muted text-sm mt-1">Every spell tagged <code class="text-gold">usable_while_cc</code>, <code class="text-gold">bypasses_active_defense</code>, or <code class="text-gold">cc_immunity_note</code>, grouped by class then spec. The first two are auto-derived at import time from real Blizzard Attribute flags (no curation) — the note is hand-curated, PvP-talent-only facts with no structured data source at all. See CLAUDE.md's 2026-09-02 investigation write-up for the full mechanism trace.</p>
    </div>

    @php
        $ccTokenLabel = ['stun' => 'Stunned', 'fear' => 'Feared', 'flee' => 'Fleeing', 'confuse' => 'Confused', 'charm' => 'Charmed', 'horror' => 'Horror-stunned'];
    @endphp

    @foreach ($grouped as $className => $classGroup)
        <div class="linear-card p-5 mb-6">
            <h2 class="font-display text-xl text-gold mb-4">{{ $className }}</h2>

            @foreach ($classGroup['specs'] as $specName => $specGroup)
                <div class="mb-5 last:mb-0">
                    <h3 class="text-sm font-semibold text-ink-muted uppercase tracking-wide mb-2">{{ $specName }} <span class="text-ink-subtle normal-case">({{ $specGroup['spells']->count() }})</span></h3>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-ink-subtle border-b border-line">
                                    <th class="py-1.5 pr-4">Spell</th>
                                    <th class="py-1.5 pr-4">Usable While</th>
                                    <th class="py-1.5 pr-4">Grants Immunity To</th>
                                    <th class="py-1.5 pr-4">Dodge/Parry/Block Immune</th>
                                    <th class="py-1.5 pr-4">Note</th>
                                    <th class="py-1.5 pr-4">spell_id</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($specGroup['spells'] as $spell)
                                    <tr class="border-b border-line/40">
                                        <td class="py-1.5 pr-4">
                                            <button type="button"
                                                    wire:click="$dispatch('show-spell-detail', { spellId: {{ $spell->id }}, classId: {{ $classGroup['classId'] ?? 'null' }}, specId: {{ $specGroup['specId'] ?? 'null' }} })"
                                                    class="text-ink hover:text-gold underline decoration-dotted underline-offset-2 transition-colors">
                                                {{ $spell->display_name }}
                                            </button>
                                        </td>
                                        <td class="py-1.5 pr-4 text-violet">
                                            @if ($spell->usable_while_cc)
                                                {{ collect(explode(',', $spell->usable_while_cc))->map(fn ($t) => $ccTokenLabel[$t] ?? $t)->implode(', ') }}
                                            @else
                                                <span class="text-ink-subtle">—</span>
                                            @endif
                                        </td>
                                        <td class="py-1.5 pr-4 text-violet">
                                            @if ($spell->grantsCcImmunity->isNotEmpty())
                                                {{ $spell->grantsCcImmunity->implode(', ') }}
                                            @else
                                                <span class="text-ink-subtle">—</span>
                                            @endif
                                        </td>
                                        <td class="py-1.5 pr-4">
                                            @if ($spell->bypasses_active_defense)
                                                <span class="badge-red">Yes</span>
                                            @else
                                                <span class="text-ink-subtle">—</span>
                                            @endif
                                        </td>
                                        <td class="py-1.5 pr-4 text-ink-subtle text-xs max-w-md">{{ $spell->cc_immunity_note ?? '—' }}</td>
                                        <td class="py-1.5 pr-4 text-ink-subtle">{{ $spell->spell_id }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach

    @if ($grouped->isEmpty())
        <p class="text-ink-muted">No spells tagged yet — re-run <code>import:spelldata</code> after the 2026-09-02 migration.</p>
    @endif

    <livewire:spell-detail-modal/>
</div>
