@php
    $selectedSpec = $classSpecs->flatMap->specializations->firstWhere('id', $specId);
    $selectedClass = $classes->firstWhere('id', $classId);
    $selectedColor = $selectedClass ? (config('wow_classes.colors')[$selectedClass->slug] ?? '#8A8A9A') : null;
    $pageTitle = $selectedSpec && $selectedClass
        ? "{$selectedSpec->name} {$selectedClass->name} — Top Damage Rotations"
        : 'Top Damage Rotations';
    $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
@endphp

<div class="max-w-5xl mx-auto px-4 py-8 space-y-5" x-data="{ classPickerOpen: false, pendingSpec: false }">
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Top Damage Rotations</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">{{ $pageTitle }}</h1>
        <p class="text-[12px] text-ink-muted mt-1">
            The single highest-damage real burst window found for a spec at a chosen length — a real example, not a "most common" claim. Same data and method as WoW Comps' Top DPS Rotation tab, just pick your own class/spec and time window here.
        </p>
    </div>

    {{-- Class/spec picker — same shared grid modal as WowComps/SpellExplorer (see
         SpellExplorer.blade.php's own docblock: "matches WowComps' picker exactly"). Single
         selection — this page only ever looks at one spec at a time. --}}
    <div class="linear-card p-4 max-w-md">
        <button type="button"
                @click="classPickerOpen = true"
                :disabled="pendingSpec"
                :class="pendingSpec && 'opacity-60 cursor-wait'"
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
                <span class="text-[13px] text-ink-muted">Choose class &amp; spec…</span>
            @endif
            <template x-if="pendingSpec">
                <svg class="animate-spin w-4 h-4 text-gold ml-auto flex-shrink-0" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </template>
            <template x-if="!pendingSpec">
                <svg class="w-3.5 h-3.5 text-ink-subtle ml-auto flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </template>
        </button>
        <p x-show="pendingSpec" x-cloak class="text-[10px] text-gold-light mt-1.5">Loading…</p>
    </div>

    <div x-show="classPickerOpen" x-cloak x-transition.opacity.duration.100ms x-data="{ search: '' }"
         class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="classPickerOpen = false; search = ''">
        <div class="linear-card max-w-2xl w-full p-5 relative max-h-[85vh] overflow-y-auto">
            <button type="button" @click="classPickerOpen = false; search = ''" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10">
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
                                            pendingSpec = true;
                                            classPickerOpen = false;
                                            search = '';
                                            $wire.selectSpec({{ $class->id }}, {{ $spec->id }}).finally(() => pendingSpec = false);
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

    @if ($specId)
        {{-- Length selector — 6/12/20/30s, matches offensive-rotations.php's
             BURST_LENGTHS_SECONDS minus 10s (only ever the interim bracket WowComps' own tab
             used before 12s replaced it — not offered as a separate choice here). --}}
        <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1 w-fit">
            @foreach (\App\Livewire\TopDamageRotations::LENGTHS as $len)
                <button type="button" wire:click="selectLength({{ $len }})"
                        class="tab-btn" :class="'{{ $length === $len ? 'tab-active' : 'tab-inactive' }}'">
                    {{ $len }}s
                </button>
            @endforeach
        </div>

        {{-- Rotation display — identical shape to WowComps' "Peak Burst Example" card (see that
             class's getOffensiveRotationsProperty() docblock), just single-spec instead of
             looped over 3 comp members. Click-to-detail uses the shared SpellDetailModal
             component instead of WowComps' own inline per-entry modal block, since this page
             has no pre-computed "full entries" list to key a matching modal content block
             against — SpellDetailModal computes everything on demand from just a spell_id. --}}
        <div class="linear-card p-5">
            @if (!$rotation)
                <p class="text-[12px] text-ink-subtle italic">No arena-log data for this spec yet.</p>
            @else
                <div class="flex items-baseline justify-between gap-3 mb-4">
                    <p class="text-[11px] uppercase tracking-wide text-ink-subtle font-semibold">
                        {{ number_format($rotation['windows']) }} cooldown windows across {{ $rotation['matches'] }} matches
                    </p>
                </div>

                @if (!$rotation['window'])
                    <p class="text-[12px] text-ink-subtle italic">Not enough match evidence for a {{ $length }}s rotation on this spec yet.</p>
                @else
                    @php $window = $rotation['window']; @endphp
                    <div class="flex items-baseline gap-2 mb-3">
                        <p class="text-[10px] uppercase tracking-wider text-gold font-semibold">Peak Burst Example</p>
                        <span class="text-[10px] text-ink-subtle">
                            {{ number_format($window['damage']) }} damage in {{ $fmtSeconds($window['durationSeconds']) }}{{ $window['killed'] ? ' — killed the target' : '' }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($window['steps'] as $si => $step)
                            @if ($si > 0)
                                <svg class="w-3.5 h-3.5 text-ink-subtle flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                </svg>
                            @endif
                            @php
                                $stepSpell = $step['spell'] ?? null;
                                $isAnchor = in_array($step['name'], $rotation['anchors'] ?? [], true);
                                // Same DR-greying rule as WowComps' rotation tab — a second use
                                // of the same CC inside one go is diminished (100/50/immune, see
                                // CLAUDE.md's DR rules), so it genuinely isn't worth the first.
                                $isDrDimmed = ($step['isRepeat'] ?? false) && ($step['isCc'] ?? false);
                            @endphp
                            @if ($stepSpell)
                                <button type="button"
                                        wire:click="$dispatch('show-spell-detail', { spellId: {{ $stepSpell->id }}, classId: {{ $classId }}, specId: {{ $specId }} })"
                                        class="linear-card !p-2.5 w-36 flex-shrink-0 text-left hover:border-gold/40 transition-colors {{ $isAnchor ? '!border-gold/50' : '' }} {{ $isDrDimmed ? 'opacity-45' : '' }}"
                                        @if ($isDrDimmed) title="Second use of this CC in the same go — diminished (50%)" @endif>
                                    <div class="flex items-center gap-2">
                                        <x-spell-icon :spell="$stepSpell" size="w-8 h-8"/>
                                        <span class="min-w-0">
                                            <span class="block text-[11px] {{ $isDrDimmed ? 'text-ink-muted' : 'text-ink' }} font-semibold truncate">{{ $stepSpell->display_name }}</span>
                                            @if ($isAnchor)
                                                <span class="badge-gold !text-[8px] !px-1 !py-0 mt-0.5">CD</span>
                                            @elseif ($isDrDimmed)
                                                <span class="badge-gray !text-[8px] !px-1 !py-0 mt-0.5">DR</span>
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
                @endif
            @endif
        </div>
    @endif

    <livewire:spell-detail-modal/>
</div>
