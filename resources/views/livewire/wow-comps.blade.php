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

    $selectedMembers = collect($comp)->filter(fn ($m) => $m['spec']);
    $compTitle = $selectedMembers->isNotEmpty()
        ? $selectedMembers->pluck('class.name')->implode(' / ')
        : 'Build a 3v3 Team';
    $compSubtitle = $selectedMembers->map(fn ($m) => "{$m['label']}: {$m['class']->name} ({$m['spec']->name})")->implode(' • ');
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 space-y-5" x-data="{ openSpellId: null }">
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">WoW Comps</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">{{ $compTitle }}</h1>
        <p class="text-[12px] text-ink-muted mt-1">
            {{ $compSubtitle ?: "Pick a class + spec for each slot to compare spell kits side by side." }}
        </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        @foreach ($slots as $index => $slot)
            @php $isHealer = $slot['label'] === 'Healer'; @endphp
            <div class="linear-card p-4 space-y-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg {{ $isHealer ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-violet/10 border border-violet/20' }} flex items-center justify-center shrink-0">
                        <x-mc-icon name="badge-wow" class="w-5 h-5 {{ $isHealer ? 'text-emerald-400' : 'text-violet' }}"/>
                    </div>
                    <div class="min-w-0">
                        <span class="{{ $isHealer ? 'badge-green' : 'badge-blue' }}">{{ strtoupper($slot['label']) }}</span>
                        <p class="text-[13px] font-semibold text-ink truncate mt-0.5">
                            {{ $comp[$index]['class']?->name ?? 'Choose class…' }}
                        </p>
                        @if ($comp[$index]['spec'])
                            <p class="text-[11px] text-ink-muted truncate">{{ $comp[$index]['spec']->name }}</p>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <select wire:model.live="slots.{{ $index }}.classId" class="form-select !text-[12px] !py-1.5">
                        <option value="">Class…</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="slots.{{ $index }}.specId" class="form-select !text-[12px] !py-1.5" @if (!$slot['classId']) disabled @endif>
                        <option value="">Spec…</option>
                        @foreach ($this->specializationsFor($slot['classId']) as $spec)
                            <option value="{{ $spec->id }}">{{ $spec->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endforeach
    </div>

    @if ($selectedMembers->isNotEmpty())
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_300px] gap-5 items-start">
            {{-- Side-by-side spell kit comparison --}}
            <div class="space-y-6 min-w-0">
                <div class="grid grid-cols-3 gap-3 px-1">
                    @foreach ($comp as $member)
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide truncate">
                            {{ $member['spec'] ? "{$member['spec']->name} {$member['class']->name}" : '—' }}
                        </p>
                    @endforeach
                </div>

                @foreach ($groupOrder as $groupKey => $groupLabel)
                    @php
                        $groupHasAny = $selectedMembers->contains(fn ($m) => collect($m['entries'])->contains(fn ($e) => ($e['spell']->is_passive ? 'passive' : 'active') === $groupKey));
                    @endphp
                    @continue(!$groupHasAny)

                    <div>
                        <p class="text-[12px] uppercase tracking-wide text-gold font-bold mb-2 pl-1">{{ $groupLabel }}</p>

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
                                                    <span class="flex-1 min-w-0 text-[12px] text-ink truncate">{{ $entry['spell']->name }}</span>
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
            </div>

            {{-- Main Cooldowns --}}
            <div class="linear-card p-4 lg:sticky lg:top-4">
                <p class="text-[11px] uppercase tracking-wide text-ink font-bold flex items-center gap-1.5">
                    <x-mc-icon name="icon-hourglass" class="w-3.5 h-3.5 text-gold"/>
                    Main Cooldowns
                </p>
                <p class="text-[11px] text-ink-subtle mt-1 mb-3">Key cooldowns for this comp (20s+, longest first).</p>

                @foreach ($comp as $mi => $member)
                    @continue(!$member['spec'])
                    <div class="mb-4 last:mb-0">
                        <p class="text-[10px] uppercase tracking-wide text-ink-subtle font-semibold mb-1.5">
                            {{ $member['spec']->name }} {{ $member['class']->name }}
                        </p>
                        @forelse ($member['mainCooldowns'] as $entry)
                            @php $modalKey = "m{$mi}-s{$entry['spell']->id}"; @endphp
                            <button type="button"
                                    @click="openSpellId = '{{ $modalKey }}'"
                                    class="w-full flex items-center justify-between gap-2 py-1 px-1 -mx-1 rounded hover:bg-surface-2 transition-colors">
                                <span class="flex items-center gap-2 min-w-0">
                                    <x-spell-icon :spell="$entry['spell']" size="w-6 h-6"/>
                                    <span class="text-[12px] text-ink truncate">{{ $entry['spell']->name }}</span>
                                </span>
                                <span class="text-[11px] text-gold whitespace-nowrap">{{ $cooldownDisplay($entry) }}</span>
                            </button>
                        @empty
                            <p class="text-[11px] text-ink-subtle">No notable cooldowns found.</p>
                        @endforelse
                    </div>
                @endforeach
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
                    <div x-show="openSpellId === '{{ $modalKey }}'" x-cloak
                         class="linear-card max-w-md w-full p-5 relative max-h-[85vh] overflow-y-auto"
                         @click.stop>
                        <button type="button" @click="openSpellId = null" class="absolute top-3 right-3 text-ink-subtle hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        <div class="flex items-start gap-3 mb-3 pr-6">
                            <x-spell-icon :spell="$spell" size="w-11 h-11" class="rounded-lg shrink-0"/>
                            <div class="min-w-0">
                                <p class="text-[15px] font-semibold text-ink">{{ $spell->name }}</p>
                                <p class="text-[10px] text-ink-subtle font-mono">#{{ $spell->spell_id }}</p>
                                <span class="{{ $categoryBadge[$entry['category']] ?? 'badge-gray' }} mt-1">{{ $entry['category'] }}</span>
                            </div>
                        </div>

                        <p class="text-[13px] text-ink-muted leading-relaxed">{{ $entry['description']['text'] ?: 'No description available.' }}</p>

                        @if ($entry['description']['uncertain'])
                            <p class="text-[10px] text-ink-subtle italic mt-1.5">Some values above vary by condition or aren't fully known — check in-game.</p>
                        @endif
                        @if (!empty($entry['formulaModifiers']) && $entry['formulaModifiers']->isNotEmpty())
                            <p class="text-[10px] text-ink-subtle mt-1.5"><span class="font-semibold">Scales with:</span> {{ $entry['formulaModifiers']->pluck('name')->implode(', ') }}</p>
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
                                    <div class="text-[12px] text-ink-muted mb-1 last:mb-0">{{ $mod['spell']->name }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    @endif
</div>
