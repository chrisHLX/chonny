@props(['entries', 'title' => 'Spells', 'description' => 'Full detail for every ability, plus what modifies or enhances each one — pulled live from the current game data.'])

@php
    $baselineModifierNames = collect($entries)
        ->flatMap(fn ($entry) => $entry['modifiers']['baseline'])
        ->pluck('spell.display_name')
        ->unique()
        ->sort()
        ->values()
        ->all();
@endphp

@if (!empty($entries))
    <div class="linear-card overflow-hidden">
        @if ($title || $description)
            <div class="px-5 py-4 border-b border-line">
                @if ($title)
                    <p class="page-section-title">{{ $title }}</p>
                @endif
                @if ($description)
                    <p class="text-[11px] text-ink-subtle mt-0.5">{{ $description }}</p>
                @endif
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-line-strong">
                        <th class="pl-5 pr-4 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Spell</th>
                        <th class="pr-4 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Description</th>
                        <th class="pr-4 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Cooldown</th>
                        <th class="pr-5 py-2 text-[10px] uppercase tracking-wide text-ink-subtle font-semibold">Modifies / Enhances</th>
                    </tr>
                </thead>
                <tbody data-role="spell-tbody">
                    {{-- Top-level split: Blizzard's own "Passive (6)" Attributes marker
                         (spells.is_passive) — "Active Abilities" vs "Buffs & Passives". Was
                         previously has-a-cooldown vs no-cooldown, a stand-in that broke for real
                         actively-cast spells with no cooldown timer at all (e.g. Mind Control —
                         balanced by its channel time and diminishing returns, not a cooldown —
                         which filed under "Buffs & Passives" despite being something you press on
                         an enemy player; found 2026-08-06). The existing category grouping
                         (ModuleSpellReferenceService::categorize() — view-layer only, not
                         authoritative for every multi-purpose spell) nests inside each. --}}
                    @php
                        $cooldownGroups = collect($entries)->groupBy(fn ($e) => $e['spell']->is_passive ? 'passive' : 'active');
                        $cooldownGroupOrder = ['active' => 'Active Abilities', 'passive' => 'Buffs & Passives'];
                        $groupIcon = [
                            'active' => '<svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
                            'passive' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>',
                        ];
                        $spellCategoryOrder = ['Crowd Control', 'Defensive', 'Utility', 'Offensive', 'Other'];
                        $categoryBadge = [
                            'Crowd Control' => 'badge-blue',
                            'Defensive' => 'badge-red',
                            'Utility' => 'badge-amber',
                            'Offensive' => 'badge-orange',
                            'Other' => 'badge-gray',
                        ];
                        $categoryAccent = [
                            'Crowd Control' => 'text-violet',
                            'Defensive' => 'text-rose-400',
                            'Utility' => 'text-amber-400',
                            'Offensive' => 'text-orange-400',
                            'Other' => 'text-ink-subtle',
                        ];
                    @endphp
                    @foreach ($cooldownGroupOrder as $cooldownKey => $cooldownLabel)
                        @continue(!$cooldownGroups->has($cooldownKey))
                        <tr class="bg-surface-3" data-role="group-header" data-group="{{ $cooldownKey }}">
                            <td colspan="4" class="pl-5 pr-4 py-2 text-[12px] uppercase tracking-wide text-ink font-bold border-b border-line-gold">
                                <span class="inline-flex items-center gap-2 text-gold">
                                    {!! $groupIcon[$cooldownKey] !!}
                                    <span class="text-ink">{{ $cooldownLabel }}</span>
                                </span>
                            </td>
                        </tr>
                        @php
                            $groupedSpellRefs = $cooldownGroups->get($cooldownKey)->groupBy('category');
                        @endphp
                        @foreach ($spellCategoryOrder as $spellCategoryName)
                        @continue(!$groupedSpellRefs->has($spellCategoryName))
                        <tr class="bg-surface-2" data-role="category-header" data-group="{{ $cooldownKey }}" data-category="{{ $spellCategoryName }}">
                            <td colspan="4" class="pl-5 pr-4 py-1.5 text-[10px] uppercase tracking-wide font-semibold border-b border-line-strong {{ $categoryAccent[$spellCategoryName] ?? 'text-gold' }}">
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
                        @php $isSelected = $entry['isSelected'] ?? true; @endphp
                        <tr class="border-b border-line align-top hover:bg-surface-2/50 transition-colors {{ $isSelected ? '' : 'opacity-50' }}"
                            data-role="spell-row"
                            data-group="{{ $cooldownKey }}"
                            data-category="{{ $spellCategoryName }}"
                            data-has-cooldown="{{ $cooldownDisplay !== null ? '1' : '0' }}"
                            data-search="{{ strtolower($spell->display_name) }}">
                            <td class="pl-5 pr-4 py-3 min-w-[10rem]">
                                <div class="flex items-center gap-2">
                                    <x-spell-icon :spell="$spell" size="w-8 h-8" />
                                    <div>
                                        <p class="text-[13px] font-semibold text-ink">{{ $spell->display_name }}</p>
                                        <span class="block text-[10px] text-ink-subtle font-mono">#{{ $spell->spell_id }}</span>
                                        <span class="{{ $categoryBadge[$spellCategoryName] ?? 'badge-gray' }} mt-1">{{ $spellCategoryName }}</span>
                                        @unless ($isSelected)
                                            <span class="badge-gray mt-1" title="Not selected in this talent profile.">Not selected</span>
                                        @endunless
                                    </div>
                                </div>
                            </td>
                            <td class="pr-4 py-3 text-[12px] text-ink-muted max-w-md">
                                {{ $entry['description']['text'] ?: '—' }}
                                @if ($entry['description']['uncertain'])
                                    <span class="text-[10px] text-ink-subtle italic block mt-0.5">Some values above vary by condition or aren't fully known — check in-game.</span>
                                @endif
                                @if (!empty($entry['formulaModifiers']) && $entry['formulaModifiers']->isNotEmpty())
                                    <span class="text-[10px] text-ink-subtle block mt-0.5">
                                        <span class="font-semibold">Scales with:</span> {{ $entry['formulaModifiers']->pluck('display_name')->implode(', ') }}
                                    </span>
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
                                        <span class="text-ink-muted">{{ $mod['spell']->display_name }}</span>
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
