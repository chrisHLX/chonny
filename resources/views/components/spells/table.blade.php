@props(['entries', 'title' => 'Spells', 'description' => 'Full detail for every ability, plus what modifies or enhances each one — pulled live from the current game data.'])

@php
    $baselineModifierNames = collect($entries)
        ->flatMap(fn ($entry) => $entry['modifiers']['baseline'])
        ->pluck('spell.name')
        ->unique()
        ->sort()
        ->values()
        ->all();
@endphp

@if (!empty($entries))
    <div class="linear-card overflow-hidden">
        <div class="px-5 py-4 border-b border-line">
            <p class="page-section-title">{{ $title }}</p>
            <p class="text-[11px] text-ink-subtle mt-0.5">{{ $description }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-line-strong">
                        <th class="pl-5 pr-4 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Spell</th>
                        <th class="pr-4 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Description</th>
                        <th class="pr-4 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">CD</th>
                        <th class="pr-5 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">What Modifies/Enhances It</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Heuristic grouping (ModuleSpellReferenceService::categorize()) — view-layer
                         only, computed from each spell's own spell_effects, not authoritative for
                         every multi-purpose spell. See that method's docblock. --}}
                    @php
                        $groupedSpellRefs = collect($entries)->groupBy('category');
                        $spellCategoryOrder = ['Crowd Control', 'Defensive', 'Utility', 'Offensive', 'Other'];
                    @endphp
                    @foreach ($spellCategoryOrder as $spellCategoryName)
                        @continue(!$groupedSpellRefs->has($spellCategoryName))
                        <tr class="bg-surface-2">
                            <td colspan="4" class="pl-5 pr-4 py-1.5 text-[10px] uppercase tracking-wide text-gold font-semibold border-b border-line-strong">
                                {{ $spellCategoryName }}
                            </td>
                        </tr>
                        @foreach ($groupedSpellRefs->get($spellCategoryName) as $entry)
                        @php
                            $spell = $entry['spell'];
                            $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
                            $cooldown = $entry['cooldown'];
                            $cooldownDisplay = $cooldown['seconds'] !== null ? $fmtSeconds($cooldown['seconds']) : null;
                            $cooldownChanged = $cooldown['seconds'] !== null
                                && $cooldown['base_seconds'] !== null
                                && round($cooldown['seconds'], 2) !== round($cooldown['base_seconds'], 2);
                            $charges = $entry['charges'];
                            $chargesChanged = $charges['charges'] !== null
                                && $charges['base_charges'] !== null
                                && $charges['charges'] !== $charges['base_charges'];
                            $relTypeBadge = fn (string $type) => match ($type) {
                                'modifies_charges' => 'badge-amber',
                                'modifies_cooldown' => 'badge-amber',
                                'modifies_charge_rate' => 'badge-amber',
                                'hasted_cooldown' => 'badge-blue',
                                'bypasses_cooldown' => 'badge-blue',
                                'replaces' => 'badge-green',
                                'mentions' => 'badge-gray',
                                default => 'badge-blue',
                            };
                            $relTypeLabel = fn (string $type) => match ($type) {
                                'modifies_charges' => 'Charges',
                                'modifies_cooldown' => 'Cooldown',
                                'modifies_charge_rate' => 'Charge Rate',
                                'hasted_cooldown' => 'Haste-Scaled',
                                'bypasses_cooldown' => 'Bypasses CD',
                                'replaces' => 'Replaces',
                                'mentions' => 'Proc',
                                'modifies' => 'Effect',
                                default => \Illuminate\Support\Str::headline($type),
                            };
                            $fmtModifierValue = function (array $mod) {
                                $sign = $mod['modifier_value'] > 0 ? '+' : '';
                                $number = rtrim(rtrim(number_format((float) $mod['modifier_value'], 2), '0'), '.');

                                return match ($mod['modifier_unit']) {
                                    'percent' => "{$sign}{$number}%",
                                    'charges' => "{$sign}{$number} ".(abs((float) $mod['modifier_value']) === 1.0 ? 'charge' : 'charges'),
                                    default => "{$sign}{$number}s",
                                };
                            };
                        @endphp
                        <tr class="border-b border-line align-top">
                            <td class="pl-5 pr-4 py-3 min-w-[10rem]">
                                <p class="text-[13px] font-semibold text-ink">{{ $spell->name }}</p>
                                <span class="text-[10px] text-ink-subtle font-mono">#{{ $spell->spell_id }}</span>
                            </td>
                            <td class="pr-4 py-3 text-[12px] text-ink-muted max-w-md">
                                {{ $entry['description']['text'] ?: '—' }}
                                @if ($entry['description']['uncertain'])
                                    <span class="text-[10px] text-ink-subtle italic block mt-0.5">Some values above vary by condition or aren't fully known — check in-game.</span>
                                @endif
                            </td>
                            <td class="pr-4 py-3 text-[12px] text-ink whitespace-nowrap">
                                {{ $cooldownDisplay ?? '—' }}
                                @if ($cooldownChanged)
                                    <span class="text-[10px] text-ink-subtle line-through ml-1">{{ $fmtSeconds($cooldown['base_seconds']) }}</span>
                                @endif
                                @if ($charges['charges'] !== null && $charges['charges'] > 1)
                                    <span class="text-ink-subtle">
                                        &middot; {{ $charges['charges'] }} charges
                                        @if ($chargesChanged)
                                            <span class="text-[10px] line-through">({{ $charges['base_charges'] }})</span>
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td class="pr-5 py-3 text-[12px]">
                                @forelse ($entry['modifiers']['named'] as $mod)
                                    <div class="mb-1 last:mb-0 flex items-center gap-1.5 flex-wrap">
                                        <span class="{{ $relTypeBadge($mod['relationship_type']) }}">{{ $relTypeLabel($mod['relationship_type']) }}</span>
                                        <span class="text-ink-muted">{{ $mod['spell']->name }}</span>
                                        @if ($mod['modifier_value'] !== null && $mod['modifier_unit'] !== null)
                                            <span class="text-[10px] text-gold">{{ $fmtModifierValue($mod) }}</span>
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-ink-subtle">—</span>
                                @endforelse
                            </td>
                        </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (!empty($baselineModifierNames))
            <div class="px-5 py-3 border-t border-line bg-surface-2">
                <p class="text-[10px] text-ink-subtle">
                    <span class="font-semibold uppercase tracking-wide">Also affected by baseline class passives</span>
                    (apply broadly, not specific to any one ability above):
                    {{ implode(', ', $baselineModifierNames) }}
                </p>
            </div>
        @endif
    </div>
@endif
