<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="font-display text-3xl text-ink">CC Data Review</h1>
        <p class="text-ink-muted text-sm mt-1">Every spell currently tagged with <code class="text-gold">dr_category</code>, grouped by class then spec. Read-only — a spot-check tool for the Synergies-tab curation data, not the Synergies tab itself.</p>
        <p class="text-ink-subtle text-xs mt-1">Duration shown is <code>spells.duration_seconds</code> — already confirmed unreliable for real PvP CC duration (e.g. Polymorph shows 60s). Shown as-is for review, not treated as correct yet.</p>
    </div>

    @php
        $categoryBadge = [
            'Stun' => 'badge-red',
            'Incapacitate' => 'badge-amber',
            'Disorient' => 'badge-blue',
            'Root' => 'badge-green',
            'Silence' => 'badge-gray',
            'Knockback' => 'badge-gold',
            'Disarm' => 'badge-gray',
        ];
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
                                    <th class="py-1.5 pr-4">DR Category</th>
                                    <th class="py-1.5 pr-4">Cast Type</th>
                                    <th class="py-1.5 pr-4">Cooldown</th>
                                    <th class="py-1.5 pr-4">Duration</th>
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
                                        <td class="py-1.5 pr-4"><span class="{{ $categoryBadge[$spell->dr_category] ?? 'badge-gray' }}">{{ $spell->dr_category }}</span></td>
                                        <td class="py-1.5 pr-4 text-ink-muted">{{ $spell->cast_type ?? '—' }}</td>
                                        <td class="py-1.5 pr-4 text-ink-muted">{{ $spell->cooldown_seconds !== null ? $spell->cooldown_seconds.'s' : '—' }}</td>
                                        <td class="py-1.5 pr-4 text-ink-muted">{{ $spell->duration_seconds !== null ? $spell->duration_seconds.'s' : '—' }}</td>
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
        <p class="text-ink-muted">No spells tagged with dr_category yet.</p>
    @endif

    <livewire:spell-detail-modal/>
</div>
