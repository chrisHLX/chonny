@php
    // The blade formats; it does not compute. Everything numeric comes from the artifact.
    $n = fn ($v) => number_format((int) $v);
    $m = fn ($v) => $v >= 1000000 ? round($v / 1000000, 1).'M' : number_format((int) $v);
    $clock = fn ($s) => sprintf('%d:%02d', intdiv((int) $s, 60), (int) $s % 60);

    $statLabels = [
        'intellect' => 'Intellect',
        'haste' => 'Haste',
        'mastery' => 'Mastery',
        'versatility' => 'Versatility',
        'armor' => 'Armor',
        'secondaryTotal' => 'Secondary total',
    ];

    $outputRows = [
        'healingEffective' => 'Effective healing',
        'absorbDone' => 'Absorbed',
        'healingOverheal' => 'Overheal',
        'damageDone' => 'Damage done',
        'damageTaken' => 'Damage taken',
    ];
@endphp

{{-- One persistent root (rule 25): never wrapped in an @if, so the component always has a root. --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-8">

    <header class="space-y-2">
        <h1 class="font-display italic text-3xl sm:text-4xl text-ink">Match Review</h1>
        <p class="text-ink-muted max-w-3xl">
            A played game read back from its own combat log — who won each round, what every player
            actually put out, and where two players of the same spec differed. The same-spec
            comparison is the one that means anything: identical kit, so what is left is build,
            gear and play.
        </p>
    </header>

    @include('livewire.partials.game-review-upload')

    @if ($reviews === [])
        <div class="linear-card p-6">
            <h2 class="text-ink font-semibold mb-2">No games yet</h2>
            <p class="text-ink-muted text-sm">
                Install the MindCollector addon, play some Solo Shuffle, then upload your combat log
                above. The addon turns combat logging on when an arena starts and off when it ends,
                so the file stays small and holds your games and nothing else.
            </p>
        </div>
    @else
        {{-- Game picker --}}
        <div class="flex flex-wrap gap-2">
            @foreach ($reviews as $r)
                <button type="button"
                        wire:click="open('{{ $r['id'] }}')"
                        @class([
                            'text-left px-3 py-2 rounded-lg border text-sm transition',
                            'border-line-gold bg-gold-subtle text-ink' => $r['id'] === $reviewId,
                            'border-line bg-surface-1 text-ink-muted hover:border-line-strong' => $r['id'] !== $reviewId,
                        ])>
                    <span class="block font-medium">{{ $r['bracket'] }}</span>
                    <span class="block text-xs text-ink-subtle">
                        @if ($r['playedAt'])
                            {{ \Illuminate\Support\Carbon::parse($r['playedAt'])->format('j M Y H:i') }}
                        @endif
                        @if ($r['record'])
                            · {{ $r['record']['won'] }}-{{ $r['record']['lost'] }}
                        @endif
                        @if ($r['mirrors'] > 0)
                            · {{ $r['mirrors'] }} mirror{{ $r['mirrors'] === 1 ? '' : 's' }}
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($review)
        {{-- Rounds --}}
        <section class="linear-card p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="page-section-title">
                    {{ $review['bracket'] }}
                    <span class="text-ink-muted font-normal text-base">
                        · {{ $review['record']['won'] }}-{{ $review['record']['lost'] }}
                    </span>
                </h2>
                @if ($review['playedAt'])
                    <span class="text-xs text-ink-subtle">
                        {{ \Illuminate\Support\Carbon::parse($review['playedAt'])->format('j M Y, H:i') }}
                    </span>
                @endif
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($review['rounds'] as $round)
                    <div @class([
                        'rounded-lg border px-3 py-2 text-sm min-w-[8.5rem]',
                        'border-green-900/60 bg-green-950/30' => $round['result'] === 'won',
                        'border-red-900/60 bg-red-950/30' => $round['result'] === 'lost',
                        'border-line bg-surface-2' => $round['result'] === null,
                    ])>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-ink font-medium">
                                @if (count($review['rounds']) > 1)
                                    Round {{ $round['sequence'] }}
                                @else
                                    Match
                                @endif
                            </span>
                            <span @class([
                                'text-xs font-semibold uppercase tracking-wide',
                                'text-green-400' => $round['result'] === 'won',
                                'text-red-400' => $round['result'] === 'lost',
                                'text-ink-subtle' => $round['result'] === null,
                            ])>{{ $round['result'] ?? 'unknown' }}</span>
                        </div>
                        <div class="text-xs text-ink-subtle mt-1">{{ $clock($round['durationSeconds']) }}</div>
                        @if ($round['killedName'])
                            <div class="text-xs text-ink-muted mt-1 truncate" title="Died: {{ $round['killedName'] }}">
                                died: {{ \Illuminate\Support\Str::before($round['killedName'], '-') }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Mirrors --}}
        @forelse ($review['mirrors'] as $mirror)
            @php
                $a = $mirror['a'];
                $b = $mirror['b'];
                $nameA = \Illuminate\Support\Str::before($a['name'], '-').($a['isYou'] ? ' (you)' : '');
                $nameB = \Illuminate\Support\Str::before($b['name'], '-').($b['isYou'] ? ' (you)' : '');
            @endphp

            <section class="linear-card p-5 sm:p-6 space-y-5">
                <div>
                    <h2 class="page-section-title">{{ $mirror['specLabel'] }} mirror</h2>
                    <p class="page-section-desc">
                        {{ $nameA }} against {{ $nameB }} —
                        opposed in {{ $mirror['roundsOpposed'] }} round{{ $mirror['roundsOpposed'] === 1 ? '' : 's' }}@if ($mirror['roundsTogether'] > 0), on the same team in {{ $mirror['roundsTogether'] }}@endif.
                        Same spec, so the kit is identical.
                    </p>
                </div>

                <div class="rounded-lg border border-line-gold bg-gold-subtle px-4 py-3">
                    <span class="text-ink-muted text-sm">{{ $mirror['primaryMetricLabel'] }}:</span>
                    <span class="text-ink font-semibold">
                        {{ abs($mirror['primaryDeltaPercent']) }}% {{ $mirror['primaryDeltaPercent'] >= 0 ? 'more' : 'less' }}
                    </span>
                    <span class="text-ink-muted text-sm">for {{ $nameA }}</span>
                </div>

                {{-- Output --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-ink-subtle border-b border-line">
                                <th class="py-2 pr-4 font-medium">Output</th>
                                <th class="py-2 px-3 font-medium text-right">{{ $nameA }}</th>
                                <th class="py-2 px-3 font-medium text-right">{{ $nameB }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($outputRows as $key => $label)
                                <tr class="border-b border-line/60">
                                    <td class="py-2 pr-4 text-ink-muted">{{ $label }}</td>
                                    <td class="py-2 px-3 text-right text-ink tabular-nums">{{ $n($a['totals'][$key] ?? 0) }}</td>
                                    <td class="py-2 px-3 text-right text-ink tabular-nums">{{ $n($b['totals'][$key] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Per round --}}
                @if (count($review['rounds']) > 1)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-ink-subtle border-b border-line">
                                    <th class="py-2 pr-4 font-medium">Healing + absorbs by round</th>
                                    @foreach ($review['rounds'] as $round)
                                        <th class="py-2 px-2 font-medium text-right">R{{ $round['sequence'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ([[$nameA, $a], [$nameB, $b]] as [$label, $side])
                                    <tr class="border-b border-line/60">
                                        <td class="py-2 pr-4 text-ink-muted">{{ $label }}</td>
                                        @foreach ($review['rounds'] as $round)
                                            @php
                                                $r = $side['perRound'][$round['sequence']] ?? null;
                                            @endphp
                                            <td class="py-2 px-2 text-right text-ink tabular-nums">
                                                {{ $r ? $m(($r['healingEffective'] ?? 0) + ($r['absorbDone'] ?? 0)) : '—' }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Gear and stats --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-line bg-surface-2 p-4">
                        <h3 class="text-ink font-medium mb-3 text-sm">Gear</h3>
                        @if ($mirror['gearDiff']['unavailable'] ?? true)
                            <p class="text-ink-subtle text-sm">Not readable from this log.</p>
                        @else
                            <dl class="space-y-1 text-sm">
                                @foreach ([[$nameA, $mirror['gearDiff']['a']], [$nameB, $mirror['gearDiff']['b']]] as [$label, $g])
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-ink-muted truncate">{{ $label }}</dt>
                                        <dd class="text-ink tabular-nums whitespace-nowrap">
                                            median {{ $g['median'] }} · max {{ $g['max'] }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                            <p class="text-xs text-ink-subtle mt-2">
                                Median, not mean — a shirt or tabard sits at item level 1.
                            </p>
                        @endif
                    </div>

                    <div class="rounded-lg border border-line bg-surface-2 p-4">
                        <h3 class="text-ink font-medium mb-3 text-sm">Stats <span class="text-ink-subtle font-normal">(ratings)</span></h3>
                        @if ($mirror['statDiff']['unavailable'] ?? true)
                            <p class="text-ink-subtle text-sm">
                                The stat block did not verify against this client build, so it is not shown
                                rather than shown wrong.
                            </p>
                        @else
                            <table class="w-full text-sm">
                                <tbody>
                                    @foreach ($mirror['statDiff']['rows'] as $row)
                                        <tr class="border-b border-line/40 last:border-0">
                                            <td class="py-1 text-ink-muted">{{ $statLabels[$row['stat']] ?? $row['stat'] }}</td>
                                            <td class="py-1 text-right text-ink tabular-nums">{{ $n($row['a']) }}</td>
                                            <td class="py-1 text-right text-ink tabular-nums">{{ $n($row['b']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>

                {{-- Talents --}}
                @if ($mirror['talentDiff']['unavailable'] ?? true)
                    <p class="text-ink-subtle text-sm">Talents could not be resolved for one side of this pair.</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ([[$nameA, $mirror['talentDiff']['onlyA'], $mirror['pvpTalentDiff']['onlyA']],
                                  [$nameB, $mirror['talentDiff']['onlyB'], $mirror['pvpTalentDiff']['onlyB']]] as [$label, $only, $pvpOnly])
                            <div class="rounded-lg border border-line bg-surface-2 p-4">
                                <h3 class="text-ink font-medium mb-2 text-sm">Only {{ $label }}</h3>
                                @if ($only === [] && $pvpOnly === [])
                                    <p class="text-ink-subtle text-sm">Nothing unique.</p>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($only as $t)
                                            <span class="badge-gray">{{ $t['name'] }}@if (($t['rank'] ?? 1) > 1) <span class="text-ink-subtle">·{{ $t['rank'] }}</span>@endif</span>
                                        @endforeach
                                        @foreach ($pvpOnly as $p)
                                            <span class="badge-gold" title="PvP talent">{{ $p }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if (($mirror['pvpTalentDiff']['shared'] ?? []) !== [])
                        <p class="text-sm text-ink-muted">
                            Both took: {{ implode(', ', $mirror['pvpTalentDiff']['shared']) }}
                        </p>
                    @endif
                @endif

                @if ($a['buildChangedMidGame'] || $b['buildChangedMidGame'])
                    <p class="text-sm text-amber-400">
                        A build changed between rounds in this game. The talents shown are from the first
                        round each player appears in.
                    </p>
                @endif
            </section>
        @empty
            <section class="linear-card p-5 sm:p-6">
                <h2 class="page-section-title">No mirror in this game</h2>
                <p class="page-section-desc">
                    No spec appeared on both sides, so there is no comparison where the kit is held
                    constant. The per-player output below is still measured, but two different specs
                    putting out different amounts is their kit, not their play.
                </p>
            </section>
        @endforelse

        {{-- Everyone --}}
        <section class="linear-card p-5 sm:p-6 space-y-4">
            <h2 class="page-section-title">Everyone in this game</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-ink-subtle border-b border-line">
                            <th class="py-2 pr-4 font-medium">Player</th>
                            <th class="py-2 px-3 font-medium">Spec</th>
                            <th class="py-2 px-3 font-medium text-right">Heal + absorb</th>
                            <th class="py-2 px-3 font-medium text-right">Overheal</th>
                            <th class="py-2 px-3 font-medium text-right">Damage</th>
                            <th class="py-2 px-3 font-medium text-right">Taken</th>
                            <th class="py-2 pl-3 font-medium text-right">Deaths</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($review['players'] as $p)
                            <tr @class(['border-b border-line/60', 'bg-gold-subtle/40' => $p['isYou']])>
                                <td class="py-2 pr-4 text-ink whitespace-nowrap">
                                    {{ \Illuminate\Support\Str::before($p['name'], '-') }}
                                    @if ($p['isYou'])
                                        <span class="text-gold text-xs">(you)</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-ink-muted whitespace-nowrap">{{ $p['spec']['label'] }}</td>
                                <td class="py-2 px-3 text-right text-ink tabular-nums">
                                    {{ $n(($p['totals']['healingEffective'] ?? 0) + ($p['totals']['absorbDone'] ?? 0)) }}
                                </td>
                                <td class="py-2 px-3 text-right text-ink-muted tabular-nums">{{ $n($p['totals']['healingOverheal'] ?? 0) }}</td>
                                <td class="py-2 px-3 text-right text-ink tabular-nums">{{ $n($p['totals']['damageDone'] ?? 0) }}</td>
                                <td class="py-2 px-3 text-right text-ink-muted tabular-nums">{{ $n($p['totals']['damageTaken'] ?? 0) }}</td>
                                <td class="py-2 pl-3 text-right text-ink-muted tabular-nums">{{ $p['totals']['deaths'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Honest limits --}}
        <section class="linear-card p-5 sm:p-6">
            <h2 class="page-section-title">What this cannot tell you</h2>
            <ul class="mt-3 space-y-2 text-sm text-ink-muted list-disc pl-5">
                @foreach ($limitations as $limit)
                    <li>{{ $limit }}</li>
                @endforeach
            </ul>
        </section>
    @elseif ($reviews !== [])
        <div class="linear-card p-6">
            <p class="text-ink-muted text-sm">That review is no longer on file. Pick another game above.</p>
        </div>
    @endif
</div>
