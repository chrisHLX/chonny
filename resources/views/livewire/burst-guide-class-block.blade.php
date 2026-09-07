@php
    // Same badge maps as WowComps/Claude's Guides/Top 10 CC Chains — reused verbatim so a spell
    // card here looks like every other spell card on the site.
    $categoryBadge = config('spell_display.category_badges');
    $drBadge = config('spell_display.dr_badges');

    $fmtSeconds = fn ($s) => rtrim(rtrim(number_format((float) $s, 2), '0'), '.').'s';
    $fmtNumber = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');

    // What each phase MEANS. The builder decides which phase a step is in (from its measured
    // median timing); these strings only explain the phase a player is looking at.
    $phaseMeta = [
        'setup' => ['label' => 'Set up', 'note' => 'Before you commit — this is what stops the damage being healed or walked away from.'],
        'commit' => ['label' => 'Commit', 'note' => 'Press these together. The window starts here.'],
        'execute' => ['label' => 'Execute', 'note' => 'Spend the window.'],
    ];

    // Curated spells.chain_target where one exists, else inferred from dr_category — see
    // BurstGuideBuilder::inferControlTarget().
    $controlTargetLabel = [
        'kill_target' => 'on the kill target',
        'healer' => 'on their healer',
        'both' => 'either target',
        'peel' => 'peel / positioning',
    ];
    $controlTargetHint = [
        'kill_target' => "Doesn't break on damage, so it holds while you burst.",
        'healer' => 'Breaks on damage — it cannot sit on the target you are bursting.',
        'both' => "Survives damage, so it works on either — on the kill target to hold them for the burst, or on their healer to set up further control.",
        'peel' => 'Movement control — positioning and peeling rather than a hard lock.',
    ];
@endphp

{{-- Livewire requires exactly one persistent root element on every render, even when the
     visible content is entirely conditional (see SpellDetailModal's own precedent for this
     exact pattern) — a class with no resolvable data renders this wrapper empty, not "no root
     tag at all". --}}
<div>
@if ($guide['class'] && !empty($guide['specs']))
    @php $classColor = config('wow_classes.colors')[$guide['class']->slug] ?? '#8A8A9A'; @endphp
    <div class="linear-card px-6 py-5">
        @if ($showClassHeader ?? true)
        <div class="flex items-center gap-2.5 mb-4">
            <x-class-icon :class="$guide['class']" size="w-7 h-7"/>
            <h2 class="font-display text-[18px] font-bold" style="color: {{ $classColor }}">{{ $guide['class']->name }}</h2>
        </div>
        @endif

        <div class="space-y-7">
            @foreach ($guide['specs'] as $s)
                @php
                    $window = $s['window'];
                    $evidence = $s['evidence'];
                @endphp
                <div>
                    {{-- Spec header: the two numbers that make a burst plan actionable, plus the
                         evidence behind them. --}}
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-1">
                        <x-spec-icon :spec="$s['spec']" :color="$classColor" size="w-5 h-5"/>
                        <p class="text-[12.5px] font-semibold text-ink">{{ $s['spec']->name }}</p>

                        @if (!empty($window['goLengthSeconds']))
                            <span class="badge-gold !text-[9px]"
                                  title="{{ $window['goLengthBasis'] === 'buff-duration'
                                      ? 'The real duration of the damage buff you open with.'
                                      : 'Measured: how long it takes to get every cooldown out, across every real window on file.' }}">
                                {{ $fmtNumber($window['goLengthSeconds']) }}s window
                            </span>
                        @endif
                        @if (!empty($window['globals']))
                            <span class="badge-blue !text-[9px]" title="Window length divided by this spec's own measured global cooldown ({{ $evidence['gcdSeconds'] ?? '?' }}s).">
                                ~{{ $window['globals'] }} globals
                            </span>
                        @endif
                        @if (!empty($window['anchorCooldownSeconds']))
                            <span class="badge-gray !text-[9px]" title="Cooldown of the ability this whole plan is built around.">
                                every {{ $fmtNumber($window['anchorCooldownSeconds']) }}s
                            </span>
                        @endif
                    </div>

                    @php
                        // Built in PHP, not inline: a Blade directive written directly against the
                        // preceding word ("matches@if(...)") does not compile and renders as
                        // literal "@if (...)" text on the page.
                        $kills = !empty($evidence['killWindows'])
                            ? ', '.number_format($evidence['killWindows']).' of which ended in a kill'
                            : '';
                    @endphp
                    <p class="text-[10px] text-ink-subtle mb-3">
                        Aggregated from {{ number_format($evidence['anchoredWindows'] ?? 0) }} real burst windows
                        across {{ number_format($evidence['matches'] ?? 0) }} matches{{ $kills }}.
                        @if (($window['goLengthBasis'] ?? null) === 'measured')
                            Window length measured from the data — this spec has no opener whose buff duration bounds it.
                        @endif
                        @if (isset($evidence['gcdMeasured']) && !$evidence['gcdMeasured'])
                            Global cooldown could not be measured for this spec; the game's base 1.5s is assumed.
                        @endif
                    </p>

                    <div class="space-y-3">
                        @foreach ($s['phases'] as $phaseKey => $steps)
                            <div>
                                <div class="flex items-baseline gap-2 mb-1.5">
                                    <span class="text-[10px] uppercase tracking-wider font-bold text-gold">{{ $phaseMeta[$phaseKey]['label'] }}</span>
                                    <span class="text-[10px] text-ink-subtle">{{ $phaseMeta[$phaseKey]['note'] }}</span>
                                </div>

                                <div class="overflow-x-auto pb-1">
                                    <ol class="flex items-stretch gap-1.5 w-max">
                                        @foreach ($steps as $entry)
                                            <li>
                                                <x-burst-step
                                                    :entry="$entry"
                                                    :class-id="$guide['class']->id"
                                                    :spec-id="$s['spec']->id"
                                                    :category-badge="$categoryBadge"
                                                    :dr-badge="$drBadge"
                                                    :control-target-label="$controlTargetLabel"
                                                    :control-target-hint="$controlTargetHint"/>
                                            </li>
                                            @if (!$loop->last)
                                                <li class="flex items-center text-ink-subtle text-[11px] shrink-0">→</li>
                                            @endif
                                        @endforeach
                                    </ol>
                                </div>
                            </div>
                        @endforeach

                        @if (!empty($s['fill']))
                            <div>
                                <div class="flex items-baseline gap-2 mb-1.5">
                                    <span class="text-[10px] uppercase tracking-wider font-bold text-violet">Fill</span>
                                    <span class="text-[10px] text-ink-subtle">Spend every remaining global on these — the number is how many times per window, on average.</span>
                                </div>
                                <div class="overflow-x-auto pb-1">
                                    <ul class="flex items-stretch gap-1.5 w-max">
                                        @foreach ($s['fill'] as $entry)
                                            <li>
                                                <x-burst-step
                                                    :entry="$entry"
                                                    :class-id="$guide['class']->id"
                                                    :spec-id="$s['spec']->id"
                                                    :category-badge="$categoryBadge"
                                                    :dr-badge="$drBadge"
                                                    :control-target-label="$controlTargetLabel"
                                                    :control-target-hint="$controlTargetHint"/>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif

                        @if (!empty($s['alsoPressed']))
                            {{-- Surfaced rather than hidden: these show up often in real windows but
                                 are not part of dealing damage (mobility, defensives, utility). --}}
                            <p class="text-[10px] text-ink-subtle pt-0.5">
                                <span class="font-semibold text-ink-muted">Also pressed here, but not part of the damage:</span>
                                @foreach ($s['alsoPressed'] as $entry)
                                    <button type="button"
                                            wire:click="$dispatch('show-spell-detail', { spellId: {{ $entry['spell']->id }}, classId: {{ $guide['class']->id }}, specId: {{ $s['spec']->id }} })"
                                            class="hover:text-gold transition-colors underline decoration-dotted underline-offset-2">{{ $entry['spell']->display_name }}</button>{{ $loop->last ? '' : ' ·' }}
                                @endforeach
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
</div>
