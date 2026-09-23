@php
    // Chart geometry. The blade draws; it does not compute.
    $plotW = 700;
    $plotH = 130;
    $padL = 30;
    $padR = 12;
    $padT = 10;
    $padB = 20;
    $innerW = $plotW - $padL - $padR;
    $innerH = $plotH - $padT - $padB;

    $horizon = $chart['horizon'] ?? 360;

    $xFor = fn ($t) => $padL + ($horizon > 0 ? ($t / $horizon) * $innerW : 0);
    $yFor = fn ($v, $max) => $padT + $innerH - ($max > 0 ? ($v / $max) * $innerH : 0);

    $poly = function (array $series, float $max) use ($xFor, $yFor) {
        $points = [];
        foreach ($series as $point) {
            $points[] = round($xFor($point['t']), 1).','.round($yFor($point['v'], $max), 1);
        }

        return implode(' ', $points);
    };

    $clock = fn ($seconds) => sprintf('%d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    $sideName = fn ($side) => $side === 'a' ? 'Team A' : 'Team B';
    $drBadge = config('spell_display.dr_badges', []);
@endphp

<div class="max-w-6xl mx-auto px-4 py-8 space-y-5"
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

    <header>
        <h1 class="font-display italic text-3xl text-ink">Matchup Lab</h1>
        <p class="text-[13px] text-ink-muted mt-1">Pick two teams. See who runs out of defensives first, and when.</p>
    </header>

    {{-- ---------- Pick the comps ---------- --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @foreach (['a', 'b'] as $side)
            @php
                $slots = $side === 'a' ? $this->teamA : $this->teamB;
                $colour = $teamColours[$side];
            @endphp
            <div class="linear-card p-3 space-y-2.5" style="border-color: {{ $colour }}33">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[11px] font-bold uppercase tracking-widest" style="color: {{ $colour }}">
                        {{ $sideName($side) }}
                    </p>
                    <div class="flex flex-wrap gap-1 justify-end">
                        @foreach ($presets as $preset)
                            <button type="button"
                                    wire:click="applyPreset('{{ $side }}', '{{ $preset['key'] }}')"
                                    class="text-[10px] px-2 py-0.5 rounded border border-line hover:border-gold/40 text-ink-muted hover:text-ink transition-colors">
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
                                class="flex items-center gap-2 p-1.5 rounded-lg border border-line hover:border-gold/40 transition-colors text-left">
                            @if ($spec)
                                <x-spec-icon :spec="$spec" :color="$classColour" size="w-8 h-8"/>
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-semibold text-ink truncate leading-tight">{{ $spec->name }}</span>
                                    <span class="block text-[10px] text-ink-muted truncate leading-tight">{{ $class->name }}</span>
                                </span>
                            @else
                                <div class="w-8 h-8 rounded-md border border-line-strong bg-surface-2 flex items-center justify-center shrink-0">
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

    {{-- ---------- How well is it played ---------- --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-[11px] text-ink-subtle uppercase tracking-widest mr-1">Played like</span>
        @foreach ($executionSettings as $key => $setting)
            <button type="button"
                    wire:click="setExecution('{{ $key }}')"
                    title="{{ $setting['summary'] }}"
                    @class([
                        'text-[12px] px-3 py-1.5 rounded-lg border transition-colors',
                        'border-gold bg-gold-subtle text-gold-light font-semibold' => $execution === $key,
                        'border-line text-ink-muted hover:text-ink' => $execution !== $key,
                    ])>
                {{ $setting['label'] }}
            </button>
        @endforeach
        <span class="text-[11px] text-ink-subtle basis-full sm:basis-auto sm:ml-2">
            {{ $executionSettings[$execution]['summary'] }}
        </span>
    </div>

    @if ($missingProfiles !== [])
        <div class="linear-card p-4 border-amber-500/30">
            <p class="text-[12px] font-semibold text-ink mb-1">No data yet for {{ implode(', ', $missingProfiles) }}</p>
            <p class="text-[11px] text-ink-muted">
                Running this without one player's defensives would show a shorter pool than they have, so it is not run at all.
                Rebuild with <code class="text-gold-light">php artisan wow:build-matchup-profiles</code>.
            </p>
        </div>
    @elseif (! $result)
        <div class="linear-card p-6">
            <p class="text-[13px] text-ink">Pick three specs on each side.</p>
            <p class="text-[12px] text-ink-muted max-w-xl mt-1.5 leading-relaxed">
                Every cooldown both teams hold goes on one six-minute clock. Where one side attacks and
                nobody on the other side has a button left, that is a window.
            </p>
        </div>
    @endif

    @if ($result)
        @php
            $verdict = $result['verdict'];
            $favoured = $verdict['favoured'];
            $teamA = $result['teams']['a'];
            $teamB = $result['teams']['b'];
        @endphp

        {{-- ---------- The answer ---------- --}}
        <div class="linear-card p-4 space-y-3"
             @if ($favoured) style="border-color: {{ $teamColours[$favoured] }}66" @endif>
            <h2 class="font-display italic text-2xl text-ink">{{ $verdict['headline'] }}</h2>
            <p class="text-[12px] text-ink-muted leading-relaxed max-w-3xl">{{ $verdict['detail'] }}</p>

            @php
                $chips = [
                    ['Goes every', ($teamA['controlCadence'] ?? '—').'s', ($teamB['controlCadence'] ?? '—').'s'],
                    ['All cooldowns every', ($teamA['cooldownCadence'] ?? '—').'s', ($teamB['cooldownCadence'] ?? '—').'s'],
                    ['Reaches', $teamA['reach'].' of 3', $teamB['reach'].' of 3'],
                    ['Buttons held', (string) $teamA['poolSize'], (string) $teamB['poolSize']],
                ];
            @endphp
            <div class="flex flex-wrap gap-1.5 pt-1">
                @foreach ($chips as [$label, $a, $b])
                    <span class="inline-flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-lg bg-surface-2 border border-line">
                        <span class="text-ink-subtle">{{ $label }}</span>
                        <span class="font-semibold tabular-nums" style="color: {{ $teamColours['a'] }}">{{ $a }}</span>
                        <span class="text-ink-subtle">/</span>
                        <span class="font-semibold tabular-nums" style="color: {{ $teamColours['b'] }}">{{ $b }}</span>
                    </span>
                @endforeach
            </div>

            @if ($verdict['reasons'] !== [])
                <ul class="space-y-1 pt-1">
                    @foreach ($verdict['reasons'] as $reason)
                        <li class="flex gap-2 text-[11.5px]">
                            <span class="w-1 rounded-full shrink-0" style="background: {{ $teamColours[$reason['favours']] }}"></span>
                            <span class="text-ink-muted"><span class="text-ink font-medium">{{ $reason['term'] }}.</span> {{ $reason['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- ---------- The round, go by go ----------
             Rendered like a guide sequence on purpose: the time, then the abilities with their
             icons, then what it forced out of them. Same reading order as
             <x-guides.section-steps>, so somebody who has read a guide here already knows how to
             read this. --}}
        <div class="space-y-2">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[13px] font-semibold text-ink">The round</h2>
                <span class="text-[11px] text-ink-subtle">{{ count($result['events']) }} goes in six minutes</span>
            </div>

            <ul class="flex flex-col gap-2">
                @foreach ($timeline['shown'] as $event)
                    <x-matchup.go-row :event="$event" :colours="$teamColours" :links="$spellLinks" :dr-badge="$drBadge"/>
                @endforeach
                @foreach ($timeline['after'] as $event)
                    <x-matchup.go-row :event="$event" :colours="$teamColours" :links="$spellLinks" :dr-badge="$drBadge"/>
                @endforeach
            </ul>

            @if ($timeline['dropped'] > 0)
                <p class="text-[11px] text-ink-subtle pt-1">
                    {{ $timeline['dropped'] }} more goes follow the same pattern. The charts below cover the whole six minutes.
                </p>
            @endif
        </div>

        {{-- ---------- Everything else, folded away ---------- --}}
        @if ($chart)
            @php
                $threatA = $poly($chart['threat']['a'], 100);
                $threatB = $poly($chart['threat']['b'], 100);
                $answerMax = max(1, $chart['maxAnswers']);
                $answersA = $poly($chart['answers']['a'], $answerMax);
                $answersB = $poly($chart['answers']['b'], $answerMax);
                $minuteMarks = [];
                for ($m = 60; $m <= $horizon; $m += 60) {
                    $minuteMarks[] = $m;
                }
                $panels = [
                    ['title' => 'What each side can commit', 'max' => 100, 'a' => $threatA, 'b' => $threatB],
                    ['title' => 'Buttons left on their thinnest player', 'max' => $answerMax, 'a' => $answersA, 'b' => $answersB],
                ];
            @endphp

            <x-matchup.fold title="Both teams on one clock" note="Two charts, one time axis.">
                <div class="flex items-center gap-4 pb-1">
                    @foreach (['a', 'b'] as $side)
                        <span class="flex items-center gap-1.5 text-[11px] text-ink-muted">
                            <span class="w-3 h-0.5 rounded-full" style="background: {{ $teamColours[$side] }}"></span>
                            {{ $sideName($side) }}
                        </span>
                    @endforeach
                    <span class="flex items-center gap-1.5 text-[11px] text-ink-muted">
                        <span class="w-3 border-t border-dashed border-ink-subtle"></span> Window opens
                    </span>
                </div>

                @foreach ($panels as $panel)
                    <div class="space-y-0.5 pt-2">
                        <p class="text-[11px] text-ink-muted">{{ $panel['title'] }}</p>
                        <svg viewBox="0 0 {{ $plotW }} {{ $plotH }}" class="w-full h-auto">
                            @foreach ($minuteMarks as $mark)
                                <line x1="{{ round($xFor($mark), 1) }}" y1="{{ $padT }}"
                                      x2="{{ round($xFor($mark), 1) }}" y2="{{ $padT + $innerH }}"
                                      stroke="#1E1E26" stroke-width="1"/>
                                <text x="{{ round($xFor($mark), 1) }}" y="{{ $plotH - 6 }}"
                                      text-anchor="middle" font-size="9" fill="#52525F">{{ $clock($mark) }}</text>
                            @endforeach
                            <line x1="{{ $padL }}" y1="{{ $padT + $innerH }}" x2="{{ $plotW - $padR }}" y2="{{ $padT + $innerH }}"
                                  stroke="#2C2C38" stroke-width="1"/>
                            <text x="{{ $padL - 5 }}" y="{{ $padT + 8 }}" text-anchor="end" font-size="9" fill="#52525F">{{ $panel['max'] }}</text>
                            <text x="{{ $padL - 5 }}" y="{{ $padT + $innerH }}" text-anchor="end" font-size="9" fill="#52525F">0</text>

                            @foreach ($chart['bands'] as $band)
                                <line x1="{{ round($xFor($band['t']), 1) }}" y1="{{ $padT }}"
                                      x2="{{ round($xFor($band['t']), 1) }}" y2="{{ $padT + $innerH }}"
                                      stroke="{{ $teamColours[$band['side']] }}" stroke-width="1.5" stroke-dasharray="3 3"/>
                            @endforeach

                            <polyline points="{{ $panel['a'] }}" fill="none" stroke="{{ $teamColours['a'] }}" stroke-width="2"
                                      stroke-linejoin="round" stroke-linecap="round"/>
                            <polyline points="{{ $panel['b'] }}" fill="none" stroke="{{ $teamColours['b'] }}" stroke-width="2"
                                      stroke-linejoin="round" stroke-linecap="round"/>
                        </svg>
                    </div>
                @endforeach
            </x-matchup.fold>
        @endif

        @php
            $rungColours = [
                'control the source' => 'badge-green',
                'spend a cooldown' => 'badge-blue',
                'immunity' => 'badge-gold',
                'trinket' => 'badge-gray',
            ];
            $rows = array_slice($result['triggers'][$triggerSide], 0, 6);
        @endphp

        <x-matchup.fold title="When they press this, press that"
                        note="Cheapest thing that works, first. Trinket last.">
            <div class="flex gap-1 pb-2">
                @foreach (['a', 'b'] as $side)
                    <button type="button" wire:click="setTriggerSide('{{ $side }}')"
                            @class([
                                'text-[11px] px-2.5 py-1 rounded-lg border transition-colors',
                                'border-gold bg-gold-subtle text-gold-light' => $triggerSide === $side,
                                'border-line text-ink-muted hover:text-ink' => $triggerSide !== $side,
                            ])>
                        Answering {{ $sideName($side) }}
                    </button>
                @endforeach
            </div>

            @if ($rows === [])
                <p class="text-[11px] text-ink-muted">This side has no classified offensive cooldowns.</p>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
                    @foreach ($rows as $row)
                        <div class="rounded-lg border border-line p-2.5 space-y-1.5">
                            <div class="flex items-center gap-2">
                                <x-spell-icon :spell="(object) ['icon_name' => $row['icon'], 'display_name' => $row['threat']]" size="w-7 h-7"/>
                                <div class="min-w-0">
                                    <p class="text-[12px] font-semibold text-ink truncate">{{ $row['threat'] }}</p>
                                    <p class="text-[10px] text-ink-muted truncate tabular-nums">{{ $row['cooldown'] }}s CD{{ $row['duration'] ? ' · '.$row['duration'].'s' : '' }}</p>
                                </div>
                            </div>
                            <ol class="space-y-1">
                                @foreach ($row['options'] as $option)
                                    <li class="flex items-center gap-1.5 text-[11px]">
                                        <span class="{{ $rungColours[$option['rung']] ?? 'badge-gray' }} !text-[9px] w-[92px] justify-center shrink-0">{{ $option['rung'] }}</span>
                                        <x-spell-icon :spell="(object) ['icon_name' => $option['icon'], 'display_name' => $option['spell']]" size="w-5 h-5"/>
                                        <span class="text-ink truncate">{{ $option['spell'] }}</span>
                                        @if ($option['covers'] === true)
                                            <span class="ml-auto text-[10px] text-green-400 shrink-0">outlasts</span>
                                        @elseif ($option['covers'] === false)
                                            <span class="ml-auto text-[10px] text-amber-400 shrink-0">too short</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-matchup.fold>

        <x-matchup.fold title="The numbers behind it">
            <div class="overflow-x-auto">
                <table class="w-full text-[11px]">
                    <thead>
                        <tr class="text-ink-subtle uppercase tracking-wide text-[10px] text-left">
                            <th class="font-medium py-1.5 pr-3">Player</th>
                            <th class="font-medium py-1.5 px-3">Buttons</th>
                            <th class="font-medium py-1.5 px-3">Spent</th>
                            <th class="font-medium py-1.5 px-3">Hard CC</th>
                            <th class="font-medium py-1.5 pl-3">Cooldowns</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach (['a', 'b'] as $side)
                            @foreach ($result['teams'][$side]['players'] as $player)
                                <tr>
                                    <td class="py-1.5 pr-3 whitespace-nowrap">
                                        <span class="w-1.5 h-1.5 rounded-full inline-block mr-1.5" style="background: {{ $teamColours[$side] }}"></span>
                                        <span class="text-ink">{{ $player['name'] }}</span>
                                    </td>
                                    <td class="py-1.5 px-3 text-ink tabular-nums">{{ $player['answers'] }}</td>
                                    <td class="py-1.5 px-3 text-ink-muted tabular-nums">{{ $player['answersSpent'] }}</td>
                                    <td class="py-1.5 px-3 text-ink-muted tabular-nums">{{ $player['hardControl'] }}</td>
                                    <td class="py-1.5 pl-3 text-ink-muted tabular-nums">{{ $player['offensive'] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-matchup.fold>
    @endif

    <x-matchup.fold title="What this can't see">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2.5">
            @foreach ($limitations as $limitation)
                <div>
                    <p class="text-[11px] font-semibold text-ink">{{ $limitation['title'] }}</p>
                    <p class="text-[11px] text-ink-muted leading-snug">{{ $limitation['body'] }}</p>
                </div>
            @endforeach
        </div>
        <p class="text-[11px] text-ink-muted pt-2.5 mt-2.5 border-t border-line">
            Think a reading here is wrong? Argue with
            <a href="{{ route('brain') }}" wire:navigate class="text-gold hover:text-gold-light underline decoration-gold/30">the model behind it</a>
            — a correction there changes every guide downstream.
        </p>
    </x-matchup.fold>

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
