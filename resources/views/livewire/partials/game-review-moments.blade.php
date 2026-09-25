{{--
    The moments in a round — when somebody committed, and what happened because they did.

    Found from the cooldowns rather than from a clock: see App\Http\Services\ArenaMomentService.
    A moment's window runs from the first commitment to a tail after the last, so its length is
    whatever the players made it.
--}}
@php
    $kindTone = [
        'offensive' => 'text-red-300',
        'mixed' => 'text-amber-300',
        'defensive' => 'text-blue-300',
        'cooldown' => 'text-ink-muted',
    ];

    $clock = fn ($s) => sprintf('%d:%02d', intdiv((int) $s, 60), (int) $s % 60);
@endphp

@if (($round['moments'] ?? []) === [])
    <p class="text-sm text-ink-subtle">
        No commitments detected in this round — nobody pressed anything on a real cooldown.
    </p>
@else
    <div class="space-y-3">
        @foreach ($round['moments'] as $index => $moment)
            @php
                $yourCommits = $moment['committed'][1] ?? [];
                $theirCommits = $moment['committed'][2] ?? [];
                $pressure = $moment['pressure'];
                $pressuredYou = $pressure && ($pressure['side'] ?? null) === 1;
            @endphp

            <div class="rounded-lg border border-line bg-surface-2 p-4 space-y-3">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="text-ink font-medium">
                        {{ $clock($moment['from']) }} – {{ $clock($moment['to']) }}
                    </span>
                    <span class="text-xs text-ink-subtle">{{ $moment['seconds'] }}s</span>
                    <span @class([
                        'text-xs px-1.5 py-0.5 rounded',
                        'bg-violet-subtle text-violet' => $moment['kind'] === 'trade',
                        'bg-surface-3 text-ink-muted' => $moment['kind'] !== 'trade',
                    ])>{{ $moment['kind'] }}</span>

                    @if ($moment['deaths'])
                        <span class="text-xs px-1.5 py-0.5 rounded bg-red-950/60 text-red-300">
                            {{ implode(', ', array_map(fn ($d) => \Illuminate\Support\Str::before($d, '-'), $moment['deaths'])) }} died
                        </span>
                    @endif
                </div>

                {{-- What each side spent --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ([['Your team', $yourCommits], ['Them', $theirCommits]] as [$label, $commits])
                        <div>
                            <p class="text-xs uppercase tracking-wide text-ink-subtle mb-1">{{ $label }}</p>
                            @if ($commits === [])
                                <p class="text-sm text-ink-subtle">spent nothing</p>
                            @else
                                <ul class="space-y-0.5">
                                    @foreach ($commits as $c)
                                        <li class="text-sm">
                                            <span class="text-ink-subtle tabular-nums text-xs">+{{ $c['at'] }}s</span>
                                            <span class="text-ink-muted">{{ \Illuminate\Support\Str::before($c['who'], '-') }}</span>
                                            <span class="{{ $kindTone[$c['kind']] ?? 'text-ink' }}">{{ $c['spell'] }}</span>
                                            @if ($c['kind'] === 'defensive')
                                                <span class="text-ink-subtle text-xs">def</span>
                                            @elseif ($c['kind'] === 'mixed')
                                                <span class="text-ink-subtle text-xs">both</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($pressure)
                    <p class="text-sm">
                        <span class="text-ink-subtle">Pressure:</span>
                        <span @class(['font-medium', 'text-red-300' => $pressuredYou, 'text-ink' => ! $pressuredYou])>
                            {{ \Illuminate\Support\Str::before($pressure['who'], '-') }}
                        </span>
                        <span class="text-ink-muted">({{ $pressure['spec'] }})</span>
                        <span class="text-ink tabular-nums">
                            {{ round($pressure['from']) }}% → {{ round($pressure['low']) }}%
                        </span>
                        <span class="text-ink-subtle">· took {{ number_format($pressure['damageTaken']) }}</span>
                    </p>
                @endif

                @foreach ($moment['controlOnHealer'] as $control)
                    <p class="text-sm">
                        <span class="text-ink-subtle">Healer control:</span>
                        <span class="text-ink">{{ $control['spell'] }}</span>
                        <span class="text-ink-muted">
                            on {{ \Illuminate\Support\Str::before($control['on'], '-') }},
                            {{ $control['dr'] }}, held {{ $control['held'] }}s
                        </span>
                        @if ($control['brokenBy'])
                            {{-- The name is the actionable part: a habit, not an accident. --}}
                            <span class="text-amber-300">
                                — broken by
                                {{ implode(', ', array_map(fn ($b) => \Illuminate\Support\Str::before($b, '-'), $control['brokenBy'])) }}
                            </span>
                        @endif
                    </p>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
