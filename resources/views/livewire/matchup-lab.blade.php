@php
    // All chart geometry in one place. The blade below draws; it does not compute.
    $plotW = 700;
    $plotH = 150;
    $padL = 34;
    $padR = 12;
    $padT = 10;
    $padB = 20;
    $innerW = $plotW - $padL - $padR;
    $innerH = $plotH - $padT - $padB;

    $horizon = $chart['horizon'] ?? 360;

    $xFor = fn ($t) => $padL + ($horizon > 0 ? ($t / $horizon) * $innerW : 0);
    $yFor = fn ($v, $max) => $padT + $innerH - ($max > 0 ? ($v / $max) * $innerH : 0);

    // Polyline point strings, built here rather than inline so the SVG markup stays readable.
    $poly = function (array $series, float $max) use ($xFor, $yFor) {
        $points = [];
        foreach ($series as $point) {
            $points[] = round($xFor($point['t']), 1).','.round($yFor($point['v'], $max), 1);
        }

        return implode(' ', $points);
    };

    $clock = fn ($seconds) => sprintf('%d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    $sideName = fn ($side) => $side === 'a' ? 'Team A' : 'Team B';
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 space-y-6"
     x-data="{
        picker: null,
        search: '',
        open(side, index) { this.picker = { side, index }; },
        specAllowed(role) {
            if (!this.picker) return true;
            return this.picker.index === 0 ? role === 'healer' : role !== 'healer';
        },
        anyRoleAllowed(roles) {
            return roles.split(' ').some(role => this.specAllowed(role));
        },
     }">

    <header class="space-y-2">
        <h1 class="font-display italic text-3xl text-ink">Matchup Lab</h1>
        <p class="text-[13px] text-ink-muted max-w-3xl leading-relaxed">
            Pick two teams and see both sides' cooldowns on one clock. The page answers one question —
            <span class="text-ink">whose kill window opens first, and why</span> — in the vocabulary of
            <a href="{{ route('brain') }}" wire:navigate class="text-gold hover:text-gold-light underline decoration-gold/30">the Brain</a>:
            the answer pool, globals denied, and the cadence each comp can actually go on.
        </p>
    </header>

    {{-- ---------- The two comps ---------- --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach (['a', 'b'] as $side)
            @php
                $slots = $side === 'a' ? $this->teamA : $this->teamB;
                $colour = $teamColours[$side];
            @endphp
            <div class="linear-card p-4 space-y-3" style="border-color: {{ $colour }}33">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[11px] font-bold uppercase tracking-widest" style="color: {{ $colour }}">
                        {{ $sideName($side) }}
                    </p>
                    <div class="flex flex-wrap gap-1 justify-end">
                        @foreach ($presets as $preset)
                            <button type="button"
                                    wire:click="applyPreset('{{ $side }}', '{{ $preset['key'] }}')"
                                    class="text-[10px] px-2 py-1 rounded border border-line hover:border-gold/40 text-ink-muted hover:text-ink transition-colors">
                                {{ $preset['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    @foreach ($slots as $index => $slot)
                        @php
                            $spec = $chosen[$side][$index] ?? null;
                            $class = $spec?->gameClass;
                            $classColour = $class ? (config('wow_classes.colors')[$class->slug] ?? '#8A8A9A') : null;
                        @endphp
                        <button type="button"
                                @click="open('{{ $side }}', {{ $index }})"
                                class="flex flex-col items-center gap-1.5 p-2 rounded-lg border border-line hover:border-gold/40 transition-colors">
                            @if ($spec)
                                <x-spec-icon :spec="$spec" :color="$classColour" size="w-10 h-10"/>
                                <span class="text-[11px] font-semibold text-ink text-center leading-tight">{{ $spec->name }}</span>
                                <span class="text-[10px] text-ink-muted text-center leading-tight">{{ $class->name }}</span>
                            @else
                                <div class="w-10 h-10 rounded-md border border-line-strong bg-surface-2 flex items-center justify-center">
                                    <x-mc-icon name="badge-wow" class="w-4 h-4 text-ink-subtle"/>
                                </div>
                                <span class="text-[11px] text-ink-muted">{{ $slot['label'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- ---------- Execution setting ---------- --}}
    <div class="linear-card p-4 space-y-3">
        <div class="flex items-baseline gap-2 flex-wrap">
            <p class="text-[12px] font-semibold text-ink">How well is it being played?</p>
            <p class="text-[11px] text-ink-muted">
                Not a rating. The same matchup read at two of these is two different plans.
            </p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            @foreach ($executionSettings as $key => $setting)
                <button type="button"
                        wire:click="setExecution('{{ $key }}')"
                        @class([
                            'text-left p-3 rounded-lg border transition-colors',
                            'border-gold bg-gold-subtle' => $execution === $key,
                            'border-line hover:border-line-strong' => $execution !== $key,
                        ])>
                    <span @class([
                        'block text-[12px] font-semibold mb-1',
                        'text-gold-light' => $execution === $key,
                        'text-ink' => $execution !== $key,
                    ])>{{ $setting['label'] }}</span>
                    <span class="block text-[11px] text-ink-muted leading-snug">{{ $setting['summary'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @if ($missingProfiles !== [])
        <div class="linear-card p-4 border-amber-500/30">
            <p class="text-[12px] font-semibold text-ink mb-1">No matchup profile for {{ implode(', ', $missingProfiles) }}</p>
            <p class="text-[11px] text-ink-muted leading-relaxed">
                This matchup is not being simulated rather than being simulated with a member's answers missing —
                an absent pool would read as a short one and quietly move the verdict.
                Regenerate with <code class="text-gold-light">php artisan wow:build-matchup-profiles</code>.
            </p>
        </div>
    @elseif (! $result)
        <div class="linear-card p-6 space-y-3">
            <p class="text-[13px] text-ink">Pick three specs on each side to read the matchup.</p>
            <p class="text-[12px] text-ink-muted max-w-2xl leading-relaxed">
                Every cooldown both teams hold goes on one 6-minute clock. Where one side's threat rises while some
                enemy player has nothing left to press, that is a window — a stretch where a go is a kill attempt
                rather than a strip. The point is not the verdict; it is which term produced it.
            </p>
        </div>
    @endif

    @if ($result)
        @php
            $verdict = $result['verdict'];
            $favoured = $verdict['favoured'];
        @endphp

        {{-- ---------- Verdict ---------- --}}
        <div class="linear-card p-5 space-y-4"
             @if ($favoured) style="border-color: {{ $teamColours[$favoured] }}66" @endif>
            <div class="space-y-1">
                <p class="text-[10px] uppercase tracking-widest text-ink-subtle">Structural read — not a prediction</p>
                <h2 class="font-display italic text-2xl text-ink">{{ $verdict['headline'] }}</h2>
                <p class="text-[12px] text-ink-muted leading-relaxed max-w-3xl">{{ $verdict['detail'] }}</p>
            </div>

            @if ($verdict['reasons'] !== [])
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    @foreach ($verdict['reasons'] as $reason)
                        <div class="flex gap-2.5 p-2.5 rounded-lg bg-surface-2 border border-line">
                            <span class="w-1 rounded-full flex-shrink-0" style="background: {{ $teamColours[$reason['favours']] }}"></span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold text-ink">
                                    {{ $reason['term'] }}
                                    <span class="text-ink-subtle font-normal">— {{ $sideName($reason['favours']) }}</span>
                                </p>
                                <p class="text-[11px] text-ink-muted leading-snug">{{ $reason['text'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ---------- The two charts ----------
             Deliberately two charts sharing one x axis rather than one chart with two y scales.
             Threat is a 0-100 availability index and answers are a count of buttons; on one pair
             of axes their crossing point would look like it meant something and would mean
             nothing. --}}
        @if ($chart)
            @php
                $bandsJson = json_encode($chart['bands']);
                $threatPointsA = $poly($chart['threat']['a'], 100);
                $threatPointsB = $poly($chart['threat']['b'], 100);
                $answerMax = max(1, $chart['maxAnswers']);
                $answerPointsA = $poly($chart['answers']['a'], $answerMax);
                $answerPointsB = $poly($chart['answers']['b'], $answerMax);

                // One hover model for both charts: index into the sampled series, so the
                // crosshair reads the same instant on each.
                $hoverSeries = [];
                foreach ($chart['threat']['a'] as $i => $point) {
                    $hoverSeries[] = [
                        't' => $point['t'],
                        'x' => round($xFor($point['t']), 1),
                        'ta' => $point['v'],
                        'tb' => $chart['threat']['b'][$i]['v'] ?? 0,
                        'aa' => $chart['answers']['a'][$i]['v'] ?? 0,
                        'ab' => $chart['answers']['b'][$i]['v'] ?? 0,
                    ];
                }
                $hoverJson = json_encode($hoverSeries);

                $minuteMarks = [];
                for ($m = 60; $m <= $horizon; $m += 60) {
                    $minuteMarks[] = $m;
                }
            @endphp

            <div class="linear-card p-5 space-y-5"
                 x-data="{
                    series: {{ $hoverJson }},
                    hover: null,
                    track(event) {
                        const svg = event.currentTarget;
                        const rect = svg.getBoundingClientRect();
                        const x = ((event.clientX - rect.left) / rect.width) * {{ $plotW }};
                        let best = null;
                        for (const point of this.series) {
                            if (best === null || Math.abs(point.x - x) < Math.abs(best.x - x)) best = point;
                        }
                        this.hover = best;
                    },
                    clock(seconds) {
                        return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
                    },
                 }">

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-[13px] font-semibold text-ink">Six minutes of this matchup</p>
                        <p class="text-[11px] text-ink-muted">Hover to read both charts at the same second.</p>
                    </div>
                    {{-- Legend is always present for two series, and both lines are direct-labelled
                         below, so identity is never carried by colour alone. --}}
                    <div class="flex items-center gap-4">
                        @foreach (['a', 'b'] as $side)
                            <span class="flex items-center gap-1.5 text-[11px] text-ink-muted">
                                <span class="w-3 h-0.5 rounded-full" style="background: {{ $teamColours[$side] }}"></span>
                                {{ $sideName($side) }}
                            </span>
                        @endforeach
                        <span class="flex items-center gap-1.5 text-[11px] text-ink-muted">
                            <span class="w-3 h-3 rounded-sm border border-dashed border-ink-subtle"></span>
                            Kill window
                        </span>
                    </div>
                </div>

                @foreach ([
                    ['key' => 'threat', 'title' => 'Threat — what each side can commit', 'max' => 100, 'suffix' => '', 'a' => $threatPointsA, 'b' => $threatPointsB, 'note' => 'Availability and reach, not damage. 100 means every cooldown up and all three of them reachable at once.'],
                    ['key' => 'answers', 'title' => 'Answers the thinnest player can press', 'max' => $answerMax, 'suffix' => '', 'a' => $answerPointsA, 'b' => $answerPointsB, 'note' => 'Per player, not per team — the kill target is whoever is shortest, and a player in CC reads zero whatever they hold.'],
                ] as $panel)
                    <div class="space-y-1">
                        <p class="text-[12px] font-semibold text-ink">{{ $panel['title'] }}</p>
                        <p class="text-[10px] text-ink-subtle leading-snug max-w-2xl">{{ $panel['note'] }}</p>

                        <svg viewBox="0 0 {{ $plotW }} {{ $plotH }}" class="w-full h-auto select-none"
                             @mousemove="track($event)" @mouseleave="hover = null">

                            {{-- Recessive grid: minutes only. --}}
                            @foreach ($minuteMarks as $mark)
                                <line x1="{{ round($xFor($mark), 1) }}" y1="{{ $padT }}"
                                      x2="{{ round($xFor($mark), 1) }}" y2="{{ $padT + $innerH }}"
                                      stroke="#1E1E26" stroke-width="1"/>
                                <text x="{{ round($xFor($mark), 1) }}" y="{{ $plotH - 6 }}"
                                      text-anchor="middle" font-size="9" fill="#52525F">{{ $clock($mark) }}</text>
                            @endforeach
                            <line x1="{{ $padL }}" y1="{{ $padT + $innerH }}" x2="{{ $plotW - $padR }}" y2="{{ $padT + $innerH }}"
                                  stroke="#2C2C38" stroke-width="1"/>
                            <text x="{{ $padL - 6 }}" y="{{ $padT + 8 }}" text-anchor="end" font-size="9" fill="#52525F">{{ $panel['max'] }}</text>
                            <text x="{{ $padL - 6 }}" y="{{ $padT + $innerH }}" text-anchor="end" font-size="9" fill="#52525F">0</text>

                            {{-- Kill windows, on both charts, so the two read as one picture. --}}
                            @foreach ($chart['bands'] as $band)
                                <line x1="{{ round($xFor($band['t']), 1) }}" y1="{{ $padT }}"
                                      x2="{{ round($xFor($band['t']), 1) }}" y2="{{ $padT + $innerH }}"
                                      stroke="{{ $teamColours[$band['side']] }}" stroke-width="1.5" stroke-dasharray="3 3" opacity="0.9"/>
                            @endforeach

                            <polyline points="{{ $panel['a'] }}" fill="none" stroke="{{ $teamColours['a'] }}" stroke-width="2"
                                      stroke-linejoin="round" stroke-linecap="round"/>
                            <polyline points="{{ $panel['b'] }}" fill="none" stroke="{{ $teamColours['b'] }}" stroke-width="2"
                                      stroke-linejoin="round" stroke-linecap="round"/>

                            {{-- Crosshair. --}}
                            <template x-if="hover">
                                <g>
                                    <line :x1="hover.x" y1="{{ $padT }}" :x2="hover.x" y2="{{ $padT + $innerH }}"
                                          stroke="#8A8A9A" stroke-width="1" stroke-dasharray="2 2"/>
                                    <circle :cx="hover.x"
                                            :cy="{{ $padT + $innerH }} - (hover.{{ $panel['key'] === 'threat' ? 'ta' : 'aa' }} / {{ max(1, $panel['max']) }}) * {{ $innerH }}"
                                            r="4" fill="{{ $teamColours['a'] }}" stroke="#111116" stroke-width="2"/>
                                    <circle :cx="hover.x"
                                            :cy="{{ $padT + $innerH }} - (hover.{{ $panel['key'] === 'threat' ? 'tb' : 'ab' }} / {{ max(1, $panel['max']) }}) * {{ $innerH }}"
                                            r="4" fill="{{ $teamColours['b'] }}" stroke="#111116" stroke-width="2"/>
                                </g>
                            </template>
                        </svg>
                    </div>
                @endforeach

                <div class="h-10">
                    <template x-if="hover">
                        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-[11px] px-3 py-2 rounded-lg bg-surface-2 border border-line">
                            <span class="text-ink font-semibold tabular-nums" x-text="clock(hover.t)"></span>
                            <span class="text-ink-muted">
                                Threat <span class="text-ink tabular-nums" x-text="hover.ta"></span> / <span class="text-ink tabular-nums" x-text="hover.tb"></span>
                            </span>
                            <span class="text-ink-muted">
                                Thinnest list <span class="text-ink tabular-nums" x-text="hover.aa"></span> / <span class="text-ink tabular-nums" x-text="hover.ab"></span>
                            </span>
                            <span class="text-ink-subtle">Team A / Team B</span>
                        </div>
                    </template>
                </div>
            </div>
        @endif

        {{-- ---------- The terms behind the read ---------- --}}
        <div class="linear-card p-5 space-y-3">
            <p class="text-[13px] font-semibold text-ink">The terms</p>
            <div class="overflow-x-auto">
                <table class="w-full text-[11px]">
                    <thead>
                        <tr class="text-ink-subtle uppercase tracking-wide text-[10px]">
                            <th class="text-left font-medium py-1.5 pr-3">Term</th>
                            <th class="text-left font-medium py-1.5 px-3" style="color: {{ $teamColours['a'] }}">Team A</th>
                            <th class="text-left font-medium py-1.5 px-3" style="color: {{ $teamColours['b'] }}">Team B</th>
                            <th class="text-left font-medium py-1.5 pl-3">What it is</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @php
                            $terms = [
                                ['Go cadence', fn ($t) => $t['controlCadence'] === null ? 'no hard control' : $t['controlCadence'].'s', 'How often the team can bring coordinated control. Derived from the slowest of each member\'s cheapest hard CC — never assumed to be 30s.'],
                                ['Cooldown cadence', fn ($t) => $t['cooldownCadence'] === null ? '—' : $t['cooldownCadence'].'s', 'How often a go can have every damage cooldown in it. The DPS anchors only; a healer\'s four-minute button is not what a comp aligns to.'],
                                ['Reach', fn ($t) => $t['reach'].' of 3', 'How many of them the team can deny a global to at once, if everything is up.'],
                                ['Answer pool', fn ($t) => $t['poolSize'].' buttons', 'Every defensive cooldown, immunity and trinket across the three players.'],
                                ['Goes sent', fn ($t) => (string) $t['goes'], 'Over six minutes, at this execution setting.'],
                            ];
                        @endphp
                        @foreach ($terms as [$label, $value, $explain])
                            <tr>
                                <td class="py-1.5 pr-3 text-ink font-medium whitespace-nowrap">{{ $label }}</td>
                                <td class="py-1.5 px-3 text-ink tabular-nums whitespace-nowrap">{{ $value($result['teams']['a']) }}</td>
                                <td class="py-1.5 px-3 text-ink tabular-nums whitespace-nowrap">{{ $value($result['teams']['b']) }}</td>
                                <td class="py-1.5 pl-3 text-ink-muted leading-snug">{{ $explain }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ---------- What happened, go by go ---------- --}}
        @php
            $shown = array_slice($result['events'], 0, 14);
        @endphp
        <div class="linear-card p-5 space-y-3">
            <div>
                <p class="text-[13px] font-semibold text-ink">Go by go</p>
                <p class="text-[11px] text-ink-muted">The first {{ count($shown) }} of {{ count($result['events']) }} commitments, and what each one cost them.</p>
            </div>
            <div class="space-y-1.5">
                @foreach ($shown as $event)
                    <div @class([
                            'flex flex-wrap items-baseline gap-x-2.5 gap-y-1 px-3 py-2 rounded-lg border text-[11px]',
                            'border-line bg-surface-2' => ! $event['killWindow'],
                            'border-gold/50 bg-gold-subtle' => $event['killWindow'],
                        ])>
                        <span class="tabular-nums text-ink-subtle w-9">{{ $clock($event['t']) }}</span>
                        <span class="font-semibold" style="color: {{ $teamColours[$event['side']] }}">{{ $sideName($event['side']) }}</span>
                        <span class="text-ink-muted">onto</span>
                        <span class="text-ink">{{ $event['target'] ?? 'nobody reachable' }}</span>

                        @if ($event['denied'] !== [])
                            <span class="text-ink-subtle">·</span>
                            <span class="text-ink-muted">
                                denied
                                {{-- The DR suffix is built in PHP, not with an inline @if. A
                                     directive glued to a word character ("...}}s@if") is not
                                     compiled at all while its @endif is, which unbalances the
                                     block and throws an "unexpected endif" from somewhere else
                                     in the file. This has bitten this codebase before. --}}
                                @foreach ($event['denied'] as $denied)
                                    @php
                                        $deniedName = $denied['role'] === 'healer' ? 'their healer' : $denied['player'];
                                        $deniedFor = $denied['seconds'].'s'.($denied['diminished'] ? ' (DR)' : '');
                                    @endphp
                                    <span class="text-ink">{{ $deniedName }}</span><span class="text-ink-subtle">&nbsp;{{ $deniedFor }}</span>{{ ! $loop->last ? ',' : '' }}
                                @endforeach
                            </span>
                        @endif

                        @if ($event['spent'] !== [])
                            <span class="text-ink-subtle">·</span>
                            <span class="text-ink-muted">
                                forced <span class="text-ink">{{ implode(', ', array_column($event['spent'], 'spell')) }}</span>
                            </span>
                        @elseif ($event['peeledBy'])
                            <span class="text-ink-subtle">·</span>
                            <span class="text-ink-muted">peeled off by <span class="text-ink">{{ $event['peeledBy']['spell'] }}</span>, nothing spent</span>
                        @endif

                        @if ($event['killWindow'])
                            <span class="badge-gold ml-auto">Kill window opens</span>
                        @elseif ($event['lockedOut'])
                            <span class="badge-amber ml-auto">Still locked out</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ---------- Trigger table ---------- --}}
        <div class="linear-card p-5 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <p class="text-[13px] font-semibold text-ink">When they press this, press that</p>
                    <p class="text-[11px] text-ink-muted leading-relaxed">
                        A ranked list, not a single answer — cheapest thing that works, first. Control the caster before
                        you spend a cooldown, and keep the trinket for last, because a trinket only buys time to press
                        something else. <span class="text-ink-subtle">The ordering is reasoned, not observed; the abilities are real.</span>
                    </p>
                </div>
                <div class="flex gap-1">
                    @foreach (['a', 'b'] as $side)
                        <button type="button" wire:click="setTriggerSide('{{ $side }}')"
                                @class([
                                    'text-[11px] px-3 py-1.5 rounded-lg border transition-colors',
                                    'border-gold bg-gold-subtle text-gold-light' => $triggerSide === $side,
                                    'border-line text-ink-muted hover:text-ink' => $triggerSide !== $side,
                                ])>
                            {{ $sideName($side === 'a' ? 'b' : 'a') }} answering {{ $sideName($side) }}
                        </button>
                    @endforeach
                </div>
            </div>

            @php
                $rungColours = [
                    'control the source' => 'badge-green',
                    'spend a cooldown' => 'badge-blue',
                    'immunity' => 'badge-gold',
                    'trinket' => 'badge-gray',
                ];
                $rows = array_slice($result['triggers'][$triggerSide], 0, 6);
            @endphp

            @if ($rows === [])
                <p class="text-[11px] text-ink-muted">This side has no classified offensive cooldowns to answer.</p>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    @foreach ($rows as $row)
                        <div class="rounded-lg border border-line p-3 space-y-2">
                            <div class="flex items-center gap-2">
                                <x-spell-icon :spell="(object) ['icon_name' => $row['icon'], 'display_name' => $row['threat']]" size="w-7 h-7"/>
                                <div class="min-w-0">
                                    <p class="text-[12px] font-semibold text-ink truncate">{{ $row['threat'] }}</p>
                                    <p class="text-[10px] text-ink-muted truncate">
                                        {{ $row['by'] }} · {{ $row['cooldown'] }}s cooldown{{ $row['duration'] ? ', '.$row['duration'].'s long' : '' }}
                                    </p>
                                </div>
                            </div>
                            <ol class="space-y-1">
                                @foreach ($row['options'] as $option)
                                    <li class="flex items-center gap-2 text-[11px]">
                                        <span class="{{ $rungColours[$option['rung']] ?? 'badge-gray' }} !text-[9px] w-[104px] justify-center">{{ $option['rung'] }}</span>
                                        <x-spell-icon :spell="(object) ['icon_name' => $option['icon'], 'display_name' => $option['spell']]" size="w-5 h-5"/>
                                        <span class="text-ink truncate">{{ $option['spell'] }}</span>
                                        <span class="text-ink-subtle whitespace-nowrap">{{ $option['by'] }}</span>
                                        @if ($option['covers'] === true)
                                            <span class="ml-auto text-[10px] text-green-400 whitespace-nowrap">outlasts it</span>
                                        @elseif ($option['covers'] === false)
                                            <span class="ml-auto text-[10px] text-amber-400 whitespace-nowrap">expires first</span>
                                        @else
                                            <span class="ml-auto text-[10px] text-ink-subtle whitespace-nowrap">duration not recorded</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    @endif

    {{-- ---------- What this cannot see ---------- --}}
    <div class="linear-card p-5 space-y-3">
        <p class="text-[13px] font-semibold text-ink">What this cannot see</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
            @foreach ($limitations as $limitation)
                <div>
                    <p class="text-[11px] font-semibold text-ink">{{ $limitation['title'] }}</p>
                    <p class="text-[11px] text-ink-muted leading-snug">{{ $limitation['body'] }}</p>
                </div>
            @endforeach
        </div>
        <p class="text-[11px] text-ink-muted pt-1 border-t border-line">
            If a reading here is wrong, the model behind it is the thing to argue with —
            <a href="{{ route('brain') }}" wire:navigate class="text-gold hover:text-gold-light underline decoration-gold/30">the Brain</a>
            takes comments per section, and a correction there changes every guide downstream of it.
        </p>
    </div>

    {{-- ---------- Spec picker ---------- --}}
    <div x-show="picker !== null" x-cloak x-transition.opacity.duration.100ms
         class="fixed inset-0 z-50 bg-surface-0/80 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="picker = null; search = ''">
        <div class="linear-card max-w-2xl w-full p-5 relative max-h-[85vh] overflow-y-auto">
            <button type="button" @click="picker = null; search = ''" class="absolute top-3 right-3 text-ink-subtle hover:text-ink z-10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <input type="text" x-model="search" placeholder="Search a class or spec…" class="form-input !text-[12px] !py-1.5 mb-4 w-full">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                @foreach ($classSpecs as $class)
                    @php
                        $classColour = config('wow_classes.colors')[$class->slug] ?? '#8A8A9A';
                        $classRoles = $class->specializations->map(fn ($s) => $specRoles[$s->id] ?? 'dps')->unique()->implode(' ');
                    @endphp
                    <div data-search-group="{{ Str::lower($class->name.' '.$class->specializations->pluck('name')->implode(' ')) }}"
                         x-show="(search === '' || $el.dataset.searchGroup.includes(search.toLowerCase())) && anyRoleAllowed('{{ $classRoles }}')">
                        <p class="text-[11px] uppercase tracking-wide font-bold mb-2" style="color: {{ $classColour }}">{{ $class->name }}</p>
                        <div class="flex flex-wrap gap-2.5">
                            @foreach ($class->specializations as $spec)
                                <button type="button"
                                        data-search="{{ Str::lower($class->name.' '.$spec->name) }}"
                                        data-role="{{ $specRoles[$spec->id] ?? 'dps' }}"
                                        x-show="(search === '' || $el.dataset.search.includes(search.toLowerCase())) && specAllowed($el.dataset.role)"
                                        @click="
                                            const target = picker;
                                            picker = null;
                                            search = '';
                                            $wire.selectSpec(target.side, target.index, {{ $class->id }}, {{ $spec->id }});
                                        "
                                        title="{{ $spec->name }} {{ $class->name }}"
                                        class="rounded-md hover:ring-2 hover:ring-gold/60 transition-shadow">
                                    <x-spec-icon :spec="$spec" :color="$classColour" size="w-12 h-12"/>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
