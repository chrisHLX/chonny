@php
    // Same badge/accent maps as WowComps/spell-detail-modal/<x-spells.table> — reused verbatim
    // so this page's spell cards look like every other spell card on the site, per direct
    // instruction ("style each guide the same way we have been... use those blocks from wow
    // comps").
    $categoryBadge = config('spell_display.category_badges');
    $fmtSeconds = fn (float $s) => rtrim(rtrim(number_format($s, 2), '0'), '.').'s';
    $splitUnit = fn (string $s) => [rtrim($s, 's'), 's'];
    // Accepts array OR AppSupportSpellProfile — the latter is ArrayAccess, which PHP's `array`
    // type hint does not satisfy. Left untyped rather than union-typed so this keeps working if
    // the entry shape moves again.
    $cooldownDisplay = fn ($entry) => ($entry['cooldown']['seconds'] ?? null) !== null ? $fmtSeconds($entry['cooldown']['seconds']) : null;
@endphp

<div class="max-w-5xl mx-auto px-4 py-8 space-y-5" x-data="{ openSpellId: null }">

    {{-- Header --}}
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Claude's Guides</p>
        <h1 class="font-display text-[28px] font-bold leading-tight mt-1 text-ink">
            {{ $guide['title'] ?? "Pick a class" }}
        </h1>
        @if ($guide)
            <p class="text-[12.5px] text-ink-muted mt-1.5 max-w-2xl">{{ $guide['subtitle'] ?? '' }}</p>
        @else
            <p class="text-[12.5px] text-ink-muted mt-1.5 max-w-2xl">
                Experimental, AI-synthesized PvP reads — real game data, Claude's own reasoning on top of it,
                clearly labelled as such. Pick a class/spec below to see one.
            </p>
        @endif
    </div>

    {{-- Guide picker — only ever lists guides that actually exist --}}
    <div class="linear-card px-4 py-3">
        <p class="text-[10px] uppercase tracking-wide text-ink-subtle font-semibold mb-2">Available guides</p>
        <div class="flex flex-wrap gap-2">
            @forelse ($availableGuides as $g)
                @php $isCurrent = $guide && $guide['classSlug'] === $g['classSlug'] && $guide['specSlug'] === $g['specSlug']; @endphp
                <button type="button"
                        wire:click="selectGuide('{{ $g['classSlug'] }}', '{{ $g['specSlug'] }}')"
                        class="text-[12px] px-3 py-1.5 rounded-full border transition-colors
                               {{ $isCurrent ? 'border-gold text-ink bg-gold-subtle' : 'border-line text-ink-muted hover:border-line-strong hover:text-ink' }}">
                    {{ $g['title'] }}
                </button>
            @empty
                <p class="text-[12px] text-ink-subtle">No guides written yet.</p>
            @endforelse
        </div>
    </div>

    @if ($guide)
        @php
            $classColor = config('wow_classes.colors')[$guide['classSlug']] ?? '#8A8A9A';
        @endphp

        @foreach ($guide['sections'] as $section)
            @if ($section['type'] === 'prose')
                <div class="linear-card px-6 py-5">
                    <h2 class="font-display text-[16px] font-bold text-ink" style="color: {{ $classColor }}">{{ $section['heading'] }}</h2>
                    <div class="mt-2 space-y-3">
                        @foreach ($section['paragraphs'] as $p)
                            <p class="text-[13px] text-ink-muted leading-relaxed">{{ $p }}</p>
                        @endforeach
                    </div>
                </div>

            @elseif ($section['type'] === 'spellGrid')
                @php $entries = $this->resolveSpellEntries($section['spellIds'], $guide['classSlug'], $guide['specSlug']); @endphp
                @if (!empty($entries))
                    <div class="linear-card px-6 py-5">
                        <h2 class="font-display text-[16px] font-bold text-ink">{{ $section['heading'] }}</h2>
                        @if (!empty($section['intro']))
                            <p class="text-[11.5px] text-ink-muted mt-0.5 mb-3">{{ $section['intro'] }}</p>
                        @endif
                        <div class="flex flex-wrap gap-2.5">
                            @foreach ($entries as $entry)
                                @php
                                    $spell = $entry['spell'];
                                    $modalKey = "guide-s{$spell->id}";
                                    [$cdValue, $cdUnit] = $splitUnit($cooldownDisplay($entry) ?? '—');
                                @endphp
                                <button type="button"
                                        @click="openSpellId = openSpellId === '{{ $modalKey }}' ? null : '{{ $modalKey }}'"
                                        class="linear-card !p-3 w-44 flex-shrink-0 text-left hover:border-gold/40 transition-colors">
                                    <div class="flex items-center gap-2 mb-2">
                                        <x-spell-icon :spell="$spell" size="w-8 h-8"/>
                                        <span class="text-[12px] text-ink font-semibold truncate">{{ $spell->display_name }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1">
                                        <span class="{{ $categoryBadge[$entry['category']] ?? 'badge-gray' }} !text-[9px]">{{ $entry['category'] }}</span>
                                        {{-- Build-resolved (SpellProfile::drCategory), not the raw column: a few spells flip CC type on a talent. --}}
                                        @if ($entry['drCategory'])
                                            <span class="badge-blue !text-[9px]">{{ $entry['drCategory'] }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3 mt-2.5 pt-2.5 border-t border-line">
                                        <div class="flex flex-col leading-none">
                                            <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">CD</span>
                                            <span class="text-[15px] font-bold text-ink tabular-nums">{{ $cdValue }}<span class="text-[10px] font-bold text-ink">{{ $cdUnit }}</span></span>
                                        </div>
                                        @if (($entry['charges']['charges'] ?? null) !== null && $entry['charges']['charges'] > 1)
                                            <div class="flex flex-col leading-none">
                                                <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">Charges</span>
                                                <span class="text-[15px] font-bold text-ink tabular-nums">{{ $entry['charges']['charges'] }}</span>
                                            </div>
                                        @endif
                                        @if ($spell->pvp_duration_seconds)
                                            <div class="flex flex-col leading-none">
                                                <span class="text-[9px] uppercase tracking-wider text-ink-subtle font-semibold mb-1">PvP Dur</span>
                                                <span class="text-[15px] font-bold text-violet-hover tabular-nums">{{ rtrim(rtrim(number_format($spell->pvp_duration_seconds, 1), '0'), '.') }}s</span>
                                            </div>
                                        @endif
                                    </div>
                                </button>

                                {{-- Inline expandable detail — same "click a spell, see its real
                                     description" pattern as every other spell card on the site,
                                     kept local to this page (no dependency on the shared
                                     SpellDetailModal component, so this page stays fully
                                     self-contained per the isolation instruction). --}}
                                <div x-show="openSpellId === '{{ $modalKey }}'" x-cloak
                                     class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
                                     @click.self="openSpellId = null">
                                    <div class="linear-card max-w-md w-full p-5 relative" @click.stop>
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
                                        <p class="text-[13px] text-ink-muted leading-relaxed">{{ $entry['description']['text'] ?? 'No description available.' }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            @elseif ($section['type'] === 'sequence' && ($section['useRealBurstWindow'] ?? false))
                <div class="linear-card px-6 py-5">
                    <div class="flex items-center gap-2 mb-3">
                        <h2 class="font-display text-[15px] font-bold text-ink">{{ $section['heading'] }}</h2>
                        @if (!empty($section['tag']))
                            <span class="badge-gold !text-[9px]">{{ $section['tag'] }}</span>
                        @endif
                    </div>
                    @if (!empty($section['intro']))
                        <p class="text-[11.5px] text-ink-muted mt-0.5 mb-3">{{ $section['intro'] }}</p>
                    @endif
                    @if ($burstWindow && !empty($burstWindow['steps']))
                        <ol class="flex flex-wrap items-center gap-1.5">
                            @foreach ($burstWindow['steps'] as $step)
                                <li class="flex items-center gap-1.5 text-[11px] px-2 py-1 rounded-md border border-line-strong text-ink">
                                    @if ($step['spell'] ?? null)
                                        <x-spell-icon :spell="$step['spell']" size="w-4 h-4"/>
                                    @endif
                                    {{ $step['displayName'] ?? $step['name'] }}
                                </li>
                                @if (!$loop->last)<li class="text-ink-subtle text-[10px]">→</li>@endif
                            @endforeach
                        </ol>
                    @else
                        <p class="text-[12px] text-ink-subtle">No real burst-window data on file yet for this spec.</p>
                    @endif
                </div>

            @elseif ($section['type'] === 'rotationBlock')
                <div class="linear-card px-6 py-5">
                    <div class="flex items-center gap-2 mb-3">
                        <h2 class="font-display text-[15px] font-bold text-ink">{{ $section['heading'] }}</h2>
                        @if (!empty($section['tag']))
                            <span class="badge-gray !text-[9px]">{{ $section['tag'] }}</span>
                        @endif
                    </div>
                    <ol class="flex flex-wrap items-center gap-1.5">
                        @foreach ($section['steps'] as $step)
                            @php $stepEntry = $this->resolveSingleAbility($step['spellId'], $guide['classSlug'], $guide['specSlug']); @endphp
                            @if ($stepEntry)
                                <li class="flex items-center gap-1.5 text-[11px] px-2 py-1 rounded-md border border-line-strong text-ink">
                                    <x-spell-icon :spell="$stepEntry['spell']" size="w-4 h-4"/>
                                    {{ $stepEntry['spell']->display_name }}
                                    @if (!empty($step['tag']))
                                        <span class="text-[9px] text-ink-subtle">({{ $step['tag'] }})</span>
                                    @endif
                                </li>
                                @if (!$loop->last)<li class="text-ink-subtle text-[10px]">→</li>@endif
                            @endif
                        @endforeach
                    </ol>
                </div>
            @endif
        @endforeach

        {{-- Source + disclaimer footer --}}
        <div class="linear-card px-6 py-4 border-l-2 border-gold/40">
            <p class="text-[10px] uppercase tracking-wide text-gold/80 font-semibold mb-1.5">About this guide</p>
            <p class="text-[11.5px] text-ink-muted leading-relaxed">{{ $guide['disclaimer'] ?? '' }}</p>
            @if (!empty($guide['dataSources']))
                @php $range = $guide['dataSources']['ratingRange'] ?? null; @endphp
                <p class="text-[10px] text-ink-subtle font-mono mt-2">
                    {{ $guide['dataSources']['playstyleSample'] ?? '?' }} archived matches ·
                    @if ($range)
                        rating {{ min($range) }}–{{ max($range) }} ·
                    @endif
                    patch {{ $guide['dataSources']['patch'] ?? '?' }}
                </p>
            @endif
        </div>
    @endif
</div>
