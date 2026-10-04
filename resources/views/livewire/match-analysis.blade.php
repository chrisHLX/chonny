{{-- One persistent root (rule 25): never wrapped in an @if. --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-8">

    <header class="space-y-2">
        <a href="{{ route('game-review') }}" class="text-sm text-ink-muted hover:text-gold">&larr; Match Review</a>
        <h1 class="font-display italic text-3xl sm:text-4xl text-ink">Your analysis</h1>
        <p class="text-ink-muted max-w-3xl">
            Your uploaded games, read back: who you really played, what was different between the games
            you won and the ones you lost, and what to change. Every number comes from your own combat
            logs. Small samples are marked as leads: they describe these games, not the game.
        </p>
    </header>

    @if ($gameView)
        @php $classColors = config('wow_classes.colors', []); @endphp
        <button type="button" wire:click="closeGame" class="text-sm text-ink-muted hover:text-gold">&larr; Back to the session</button>

        @if ($gameView['outdated'] ?? false)
            <div class="linear-card p-6 text-ink-muted">
                This game was analysed before games were stored as goes. Upload it again to see it drawn out.
            </div>
        @else
            @php $r = $gameView['row']; @endphp
            <div class="linear-card p-5">
                <div class="flex flex-wrap items-baseline gap-3">
                    <h2 class="font-display text-2xl {{ $r['won'] ? 'text-gold' : 'text-ink' }}">{{ $r['won'] ? 'Won' : 'Lost' }} at {{ $r['time'] }}</h2>
                    <span class="text-[12.5px] text-ink-muted">{{ $r['level'] }} level · MMR {{ $r['mmr']['us'] ?? '–' }} against {{ $r['mmr']['them'] ?? '–' }}</span>
                </div>
                <div class="grid sm:grid-cols-2 gap-4 mt-4">
                    @foreach (['us' => 'Your team', 'them' => 'Their team'] as $side => $label)
                        <div>
                            <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle mb-1.5">{{ $label }}</p>
                            @foreach ($gameView['players'][$side] ?? [] as $p)
                                <p class="text-[13px]"><span style="color: {{ $classColors[$p['class'] ?? ''] ?? '#8A8A9A' }}">{{ $p['spec'] }}</span> <span class="text-ink-subtle text-[11.5px]">{{ $p['xp'] }}</span></p>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @if ($gameView['deaths'] !== [])
                    <div class="mt-4 pt-4 border-t border-line space-y-1.5">
                        @foreach ($gameView['deaths'] as $d)
                            <p class="flex items-center gap-2 text-[13px]">
                                <span class="text-ink-subtle tabular-nums w-9">{{ $d['at'] }}</span>
                                @if ($d['blow'])
                                    <x-spell-icon :spell="$gameView['icons'][$d['blow']['spell']] ?? (object) ['icon_name' => null, 'display_name' => $d['blow']['spell']]" size="w-5 h-5"/>
                                @endif
                                <span class="{{ $d['side'] === 'us' ? 'text-ink' : 'text-gold' }}">
                                    {{ $d['side'] === 'us' ? 'Your' : 'Their' }} {{ $d['who']['spec'] ?? '' }} died{{ $d['blow'] ? ' to '.$d['blow']['spell'] : '' }}
                                </span>
                                @if ($d['side'] === 'us')
                                    <span class="text-ink-subtle text-[12px]">· your healer {{ $d['healer'] }}</span>
                                @endif
                            </p>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="space-y-4">
                @forelse ($gameView['goes'] as $go)
                    <x-review.go :go="$go" :icons="$gameView['icons']"/>
                @empty
                    <p class="text-ink-muted">No goes in this game: nobody pressed an offensive cooldown.</p>
                @endforelse
            </div>
        @endif
    @elseif ($sessions === [])
        <div class="linear-card p-6 text-ink-muted">
            No analysed games yet. Upload your games on
            <a href="{{ route('game-review') }}" class="text-gold hover:text-gold-light">Match Review</a>;
            each one is analysed as it arrives.
        </div>
    @else
        <div class="flex flex-wrap gap-2">
            @foreach ($sessions as $s)
                @php $k = $s['date'].'|'.$s['bracket'].'|'.$s['team']; @endphp
                <button type="button" wire:click="open('{{ $k }}')"
                        class="{{ $session === $k ? 'btn-secondary' : 'btn-ghost' }} text-left">
                    <span class="block text-[13px] text-ink">{{ \Illuminate\Support\Carbon::parse($s['date'])->format('j M') }} · {{ $s['bracket'] }} · {{ $s['games'] }} games</span>
                    <span class="block text-[11px] text-ink-subtle">{{ $s['label'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($analysis && ! $gameView)
        @php $wp = $analysis['whoYouPlayed']; @endphp

        {{-- Summary line --}}
        <div class="linear-card p-5">
            <p class="text-[15px] text-ink">
                <span class="font-semibold">{{ $analysis['record']['won'] }} won, {{ $analysis['record']['lost'] }} lost</span>
                <span class="text-ink-muted">as</span>
                @foreach ($analysis['team'] as $p)
                    <span class="text-ink">{{ $p['spec'] }}</span> <span class="text-ink-subtle text-[12px]">({{ $p['xp'] }})</span>@if (! $loop->last), @endif
                @endforeach
            </p>
            @if ($analysis['pendingExperience'] > 0)
                <p class="text-[12px] text-ink-subtle mt-2">Still looking up {{ $analysis['pendingExperience'] }} players' experience from Blizzard. Refresh in a minute.</p>
            @endif
        </div>

        {{-- Your takeaway --}}
        @if ($analysis['takeaways'] !== [])
            <section class="linear-card border-line-gold p-5">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-gold font-semibold mb-3">Your takeaway</h2>
                <ul class="space-y-2 text-[14px] text-ink">
                    @foreach ($analysis['takeaways'] as $t)
                        <li>{{ $t }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Where the losses came from: a rough split, and the ledger behind it --}}
        @php $f = $analysis['faults'] ?? []; @endphp
        @if ($f['outdated'] ?? false)
            <div class="linear-card p-5 text-[13px] text-ink-muted">These games were analysed before mistakes were stored with their owners. Upload them again to see where the losses came from.</div>
        @elseif (! empty($f['shares']))
            @php
                $classColors = config('wow_classes.colors', []);
                $shareColor = fn ($sh) => $sh['class'] ? ($classColors[$sh['class']] ?? '#8A8A9A') : ($sh['ours'] ? '#52525F' : '#7B6EE8');
            @endphp
            <section class="linear-card p-5">
                <h2 class="text-[17px] font-semibold text-ink">Where the losses came from</h2>
                <p class="text-[12.5px] text-ink-muted mt-1 mb-4">
                    A rough split, not a verdict. Every mistake the log can pin on a button counts toward whoever pressed it;
                    what the other team did well is theirs. It cannot see positioning, calls, or a mistake nobody pressed a button for.
                </p>

                <div class="flex h-3 rounded overflow-hidden mb-3">
                    @foreach ($f['shares'] as $sh)
                        <div style="width: {{ $sh['share'] }}%; background: {{ $shareColor($sh) }}" title="{{ $sh['owner'] }}: {{ $sh['share'] }}%"></div>
                    @endforeach
                </div>
                <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5">
                    @foreach ($f['shares'] as $sh)
                        <p class="flex items-center gap-2 text-[13px]">
                            <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background: {{ $shareColor($sh) }}"></span>
                            <span class="flex-1 {{ $sh['ours'] ? 'text-ink' : 'text-ink-muted' }}">{{ $sh['owner'] }}</span>
                            <span class="tabular-nums font-semibold text-ink">{{ $sh['share'] }}%</span>
                        </p>
                    @endforeach
                </div>

                <details class="mt-4 pt-4 border-t border-line">
                    <summary class="cursor-pointer text-[12.5px] text-gold">The ledger: every item, loss by loss</summary>
                    <div class="mt-3 space-y-4">
                        @foreach ($f['games'] as $g)
                            <div>
                                <p class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle mb-1.5">Lost at {{ $g['time'] }}</p>
                                <ul class="space-y-1">
                                    @foreach ($g['items'] as $i)
                                        <li class="flex items-center gap-2.5 text-[12.5px]">
                                            @if ($i['spell'])
                                                <x-spell-icon :spell="$f['icons'][$i['spell']] ?? (object) ['icon_name' => null, 'display_name' => $i['spell']]" size="w-5 h-5"/>
                                            @else
                                                <span class="w-5 h-5 shrink-0"></span>
                                            @endif
                                            <span class="flex-1 text-ink-muted">{{ $i['text'] }}</span>
                                            <span class="shrink-0 text-[11.5px]" style="color: {{ $i['class'] ? ($classColors[$i['class']] ?? '#8A8A9A') : '#8A8A9A' }}">{{ $i['owner'] }}</span>
                                            <span class="shrink-0 text-[11px] text-ink-subtle tabular-nums w-6 text-right">+{{ $i['weight'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                        <p class="text-[11.5px] text-ink-subtle">
                            Weights: locked out when a teammate died with the Medallion already on cooldown {{ $f['weights']['locked_trinket_used'] }}, with it available {{ $f['weights']['locked_trinket_unused'] }};
                            a defensive spent while they were not in a go, unless you were locked out just after {{ $f['weights']['defensive_outside'] }};
                            a burst that landed with their healer free {{ $f['weights']['burst_healer_free'] }} (the team).
                            Theirs: {{ $f['weights']['them_experience'] }} for 3+ more Gladiator seasons, {{ $f['weights']['them_mmr'] }} for 50+ more MMR,
                            {{ $f['weights']['them_answered'] }} when your goes forced defensives and none killed.
                        </p>
                    </div>
                </details>
            </section>
        @endif

        {{-- The patterns, as abilities --}}
        @if (($analysis['patterns']['outdated'] ?? false))
            <div class="linear-card p-5 text-[13px] text-ink-muted">These games were analysed before goes were stored in full. Upload them again to see the patterns as abilities.</div>
        @elseif (! empty($analysis['patterns']['sections']))
            @foreach ($analysis['patterns']['sections'] as $pattern)
                <section class="linear-card p-5">
                    <h2 class="text-[17px] font-semibold text-ink">{{ $pattern['title'] }}</h2>
                    <p class="text-[12.5px] text-ink-muted mt-1 mb-4">{{ $pattern['note'] }}</p>
                    <div class="grid {{ count($pattern['columns']) > 1 ? 'md:grid-cols-2' : '' }} gap-5">
                        @foreach ($pattern['columns'] as $col)
                            <div>
                                <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle mb-2">{{ $col['label'] }}</p>
                                <div class="grid {{ count($pattern['columns']) > 1 ? 'grid-cols-1' : 'sm:grid-cols-2 lg:grid-cols-3' }} gap-2">
                                    @forelse ($col['chips'] as $chip)
                                        <x-review.chip :chip="$chip" :icons="$analysis['patterns']['icons']"/>
                                    @empty
                                        <p class="text-[12px] text-ink-subtle">None</p>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif

        {{-- Who you played --}}
        <section>
            <h2 class="page-section-title mb-3">Who you played</h2>
            <div class="overflow-x-auto linear-card">
                <table class="w-full text-[13px]">
                    <thead class="text-ink-subtle text-left">
                        <tr class="border-b border-line">
                            <th class="p-3"></th><th class="p-3">Their MMR</th><th class="p-3">MMR gap</th>
                            <th class="p-3">Their Gladiator seasons</th><th class="p-3">Their best 3v3 rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['won' => 'Games won', 'lost' => 'Games lost'] as $k => $label)
                            <tr class="border-b border-line last:border-0">
                                <td class="p-3 text-ink">{{ $label }} ({{ $wp[$k]['games'] }})</td>
                                <td class="p-3 tabular-nums">{{ $wp[$k]['theirMmr'] ?? '–' }}</td>
                                <td class="p-3 tabular-nums">{{ isset($wp[$k]['mmrDiff']) ? sprintf('%+d', $wp[$k]['mmrDiff']) : '–' }}</td>
                                <td class="p-3 tabular-nums">{{ $wp[$k]['theirGladSeasons'] ?? '–' }}</td>
                                <td class="p-3 tabular-nums">{{ $wp[$k]['theirBestExp'] ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-[12px] text-ink-subtle mt-2">
                Gladiator seasons are per Battle.net account and lifetime; the rating is the character's highest ever.
                Early in a season MMR runs low, so experience says more about the level you played at.
            </p>
        </section>

        {{-- What differed --}}
        <section>
            <h2 class="page-section-title mb-3">What was different</h2>
            <div class="space-y-2">
                @foreach ($analysis['comparisons'] as $c)
                    <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                        <p class="flex-1 text-[13.5px] text-ink">{{ $c['label'] }}</p>
                        <div class="flex gap-4 text-[13px] shrink-0">
                            @foreach (['left', 'right'] as $side)
                                <span class="tabular-nums">
                                    <span class="text-ink-subtle">{{ $c[$side]['label'] }}</span>
                                    <span class="text-ink font-semibold">{{ $c[$side]['value'] ?? '–' }}{{ $c[$side]['value'] !== null ? $c['unit'] : '' }}</span>
                                    <span class="text-ink-subtle text-[11px]">({{ $c[$side]['n'] }} {{ $c['of'] }})</span>
                                </span>
                            @endforeach
                            @if ($c['lead'])
                                <span class="badge-gray self-center">lead</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- The review table --}}
        <section>
            <h2 class="page-section-title mb-1">Every game</h2>
            <p class="text-[12.5px] text-ink-muted mb-3">Open a game to see it drawn out as its goes.</p>
            <div class="overflow-x-auto linear-card">
                <table class="w-full text-[12.5px]">
                    <thead class="text-ink-subtle text-left">
                        <tr class="border-b border-line">
                            <th class="p-2.5">Game</th><th class="p-2.5">Level</th><th class="p-2.5">Result</th><th class="p-2.5">MMR (you / them)</th>
                            <th class="p-2.5">Their team (healer first)</th><th class="p-2.5">Your goes (kill after)</th>
                            <th class="p-2.5">Defensives before the first death (you / them)</th><th class="p-2.5">First death</th><th class="p-2.5">Your healer then</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($analysis['games'] as $g)
                            <tr wire:click="openGame({{ $g['id'] }})" class="border-b border-line last:border-0 align-top cursor-pointer hover:bg-surface-2" title="Open this game as its goes">
                                <td class="p-2.5 tabular-nums text-ink">{{ $g['time'] }}</td>
                                <td class="p-2.5">{{ $g['level'] }}</td>
                                <td class="p-2.5 {{ $g['won'] ? 'text-gold' : 'text-ink-muted' }}">{{ $g['won'] ? 'Won' : 'Lost' }}</td>
                                <td class="p-2.5 tabular-nums">{{ $g['mmr']['us'] ?? '–' }} / {{ $g['mmr']['them'] ?? '–' }}</td>
                                <td class="p-2.5">
                                    @foreach ($g['enemies'] as $e)
                                        <span class="block">{{ $e['spec'] }} <span class="text-ink-subtle">{{ $e['xp'] }}</span></span>
                                    @endforeach
                                </td>
                                <td class="p-2.5 tabular-nums">{{ $g['goes'] }} ({{ $g['goesKill'] }})</td>
                                <td class="p-2.5 tabular-nums">{{ $g['defensives']['us'] }} / {{ $g['defensives']['them'] }}</td>
                                <td class="p-2.5">
                                    @if ($g['firstDeath'])
                                        {{ $g['firstDeath']['side'] === 'us' ? 'Your' : 'Their' }} {{ $g['firstDeath']['spec'] }}@if ($g['firstDeath']['to']), to {{ $g['firstDeath']['to'] }}@endif
                                    @else
                                        –
                                    @endif
                                </td>
                                <td class="p-2.5">{{ $g['ourHealerAtDeath'] ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
