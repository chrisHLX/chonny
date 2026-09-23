@php
    $drBadge = config('spell_display.dr_badges', []);
    $classColors = config('wow_classes.colors', []);
@endphp

<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">

    <header class="space-y-2">
        <div class="flex items-center gap-2.5 flex-wrap">
            <h1 class="font-display italic text-3xl text-ink">{{ $document['title'] ?? 'Strategy' }}</h1>
            @if (($document['status'] ?? null) === 'teaser')
                <span class="badge-amber">Teaser</span>
            @endif
        </div>
        <p class="text-[13px] text-ink-muted">{{ $document['subtitle'] ?? '' }}</p>
    </header>

    @if (! empty($document['intro']))
        <p class="text-[12.5px] text-ink-muted leading-relaxed max-w-2xl">{{ $document['intro'] }}</p>
    @endif

    @if ($unresolved !== [])
        {{-- Shown, not swallowed: a sequence silently missing a step is worse than one that says
             so. Same rule the guides follow for an ability that no longer resolves. --}}
        <div class="linear-card p-3 border-amber-500/30">
            <p class="text-[11px] text-ink-muted">
                <span class="text-ink font-medium">Not in the current patch data:</span>
                {{ implode(', ', $unresolved) }}
            </p>
        </div>
    @endif

    @foreach ($concepts as $concept)
        <article class="linear-card p-5 space-y-4">

            <div class="space-y-1.5">
                <div class="flex items-baseline gap-2.5 flex-wrap">
                    <h2 class="font-display italic text-2xl text-ink">{{ $concept['name'] }}</h2>
                    <span class="text-[11px] text-ink-subtle uppercase tracking-widest">{{ $concept['origin'] }}</span>
                </div>
                <p class="text-[13px] text-ink leading-relaxed max-w-2xl">{{ $concept['idea'] }}</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                {{-- Left: what it is in arena, in words. --}}
                <div class="space-y-3">
                    <p class="text-[10px] uppercase tracking-widest text-ink-subtle">In arena</p>
                    <p class="text-[12.5px] text-ink-muted leading-relaxed">{{ $concept['arena'] }}</p>
                </div>

                {{-- Right: the same thing as abilities. This is the point of the page — the idea
                     and the sequence that IS it, side by side, with the real icons. --}}
                <div class="space-y-2">
                    <p class="text-[10px] uppercase tracking-widest text-ink-subtle">The sequence</p>

                    <ol class="flex flex-col gap-1.5">
                        @foreach ($concept['sequence'] as $i => $step)
                            @php
                                $classColour = $classColors[$step['classSlug']] ?? '#8A8A9A';
                                $isThem = ($step['side'] ?? null) === 'them';
                                $href = ! empty($step['spellId']) && isset($spellLinks[$step['spellId']])
                                    ? route('spell.show', ['spellId' => $spellLinks[$step['spellId']]])
                                    : null;
                            @endphp

                            <li @class([
                                    'flex items-start gap-2.5 p-2 rounded border bg-surface-2',
                                    'border-line' => ! $isThem,
                                    'border-line-strong border-dashed opacity-80' => $isThem,
                                ])>
                                <span class="font-display text-[13px] text-gold tabular-nums pt-0.5 w-3 shrink-0">{{ $i + 1 }}</span>

                                @if ($step['resolved'])
                                    <x-spell-icon :spell="(object) ['icon_name' => $step['icon'], 'display_name' => $step['spell']]" size="w-7 h-7"/>
                                @else
                                    <div class="w-7 h-7 rounded border border-line-strong bg-surface-3 shrink-0"></div>
                                @endif

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        @if ($href)
                                            <a href="{{ $href }}" wire:navigate class="text-[12.5px] font-medium text-ink hover:text-gold transition-colors">{{ $step['spell'] }}</a>
                                        @else
                                            <span class="text-[12.5px] font-medium text-ink">{{ $step['spell'] }}</span>
                                        @endif

                                        @if ($step['drCategory'])
                                            <span class="{{ $drBadge[$step['drCategory']] ?? 'badge-gray' }} !text-[9px]">{{ $step['drCategory'] }}</span>
                                        @endif

                                        @if ($step['duration'])
                                            <span class="badge-amber !text-[9px] tabular-nums">{{ $step['duration'] }}s</span>
                                        @endif

                                        @if ($step['cooldown'])
                                            <span class="text-[10px] text-ink-subtle tabular-nums">{{ $step['cooldown'] }}s CD</span>
                                        @endif

                                        @if ($isThem)
                                            <span class="badge-gray !text-[9px]">them</span>
                                        @endif
                                    </div>

                                    <p class="text-[10px] mt-0.5" style="color: {{ $classColour }}">{{ $step['specName'] }}</p>

                                    @if (! empty($step['note']))
                                        <p class="text-[11.5px] text-ink-muted leading-snug mt-1">{{ $step['note'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 pt-1 border-t border-line">
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-ink-subtle mb-1">The part people get wrong</p>
                    <p class="text-[11.5px] text-ink-muted leading-snug">{{ $concept['tell'] }}</p>
                </div>
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-ink-subtle mb-1">Where else it shows up</p>
                    <p class="text-[11.5px] text-ink-muted leading-snug">{{ $concept['elsewhere'] }}</p>
                </div>
            </div>

            @if (! empty($concept['model']))
                <p class="text-[11px] text-ink-subtle">
                    Derived in
                    <a href="{{ route('brain') }}" wire:navigate class="text-gold/80 hover:text-gold-light underline decoration-gold/20">the model</a>
                    &mdash; {{ $concept['model'] }}.
                </p>
            @endif
        </article>
    @endforeach

    @if (! empty($document['notMapped']))
        <div class="linear-card p-5">
            <p class="text-[12.5px] font-semibold text-ink mb-1.5">{{ $document['notMapped']['heading'] }}</p>
            <p class="text-[12px] text-ink-muted leading-relaxed max-w-2xl">{{ $document['notMapped']['body'] }}</p>
        </div>
    @endif

    <div class="linear-card p-4">
        <p class="text-[11.5px] text-ink-muted leading-relaxed">
            <span class="text-ink font-medium">This is a teaser.</span>
            Three concepts to look at, not a finished section. If the shape is right it grows; if the
            idea reads as decoration rather than as something that makes a game clearer, better to
            find that out at three than at thirty.
        </p>
    </div>
</div>
