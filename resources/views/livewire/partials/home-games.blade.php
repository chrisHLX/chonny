{{-- The top of the signed-in home page (2026-11-03): the player's own games, in whichever of four
     states the account is in. Built by App\Http\Services\RecentGamesService from the Your games
     index. Each state ends on the one next step it needs. --}}
@php
    $coach = route('coach');
@endphp

@if ($g['state'] === 'ready')
    @php $s = $g['session']; @endphp
    <section class="linear-card p-5 sm:p-6 mb-8" aria-labelledby="latest-session">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="latest-session" class="text-[11px] uppercase tracking-[0.13em] text-gold font-semibold">Your Latest Session</h2>
            <a href="{{ $coach }}" class="text-[12.5px] text-ink-subtle hover:text-gold">All {{ number_format($g['total']) }} games &rarr;</a>
        </div>

        <div class="flex flex-wrap items-end gap-x-6 gap-y-2 mt-2">
            <p class="text-[30px] sm:text-[34px] font-extrabold leading-none tabular-nums">
                <span class="text-green-400">{{ $s['won'] }}</span><span class="text-ink-subtle">&ndash;</span><span class="text-red-400">{{ $s['lost'] }}</span>
            </p>
            <p class="text-[13.5px] text-ink-muted pb-0.5">
                {{ $s['count'] }} {{ Str::plural('game', $s['count']) }} on {{ $s['day'] }}, {{ $s['from'] }} to {{ $s['to'] }}
                @if ($s['mmrFrom'] && $s['mmrTo'])
                    <span class="text-ink-subtle">&middot;</span> team MMR {{ $s['mmrFrom'] }} &rarr;
                    <span class="{{ $s['mmrTo'] >= $s['mmrFrom'] ? 'text-green-400' : 'text-red-400' }} font-semibold">{{ $s['mmrTo'] }}</span>
                @endif
            </p>
        </div>

        @if ($g['pending'] > 0)
            <p class="text-[13px] text-ink mt-3 rounded-md border border-gold/40 bg-gold/5 px-3 py-2">
                {{ $g['pending'] }} new {{ Str::plural('round', $g['pending']) }} arrived and {{ $g['pending'] === 1 ? 'is' : 'are' }} being read. They appear here in about a minute.
            </p>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,360px)] mt-5">
            {{-- The session's games, newest first: the result and the comp you met as icons. --}}
            <div class="min-w-0">
                @foreach ($s['games'] as $game)
                    <a href="{{ $coach }}#game={{ $game['file'] }}"
                       class="flex items-center gap-3 py-2 border-b border-line last:border-0 group">
                        <span class="w-11 text-[12.5px] text-ink-subtle tabular-nums shrink-0">{{ $game['time'] }}</span>
                        @if ($game['shuffle'])
                            <span class="w-12 text-center text-[11.5px] font-semibold rounded px-1.5 py-0.5 shrink-0 {{ $game['result'] > 0 ? 'bg-green-500/15 text-green-400' : ($game['result'] < 0 ? 'bg-red-500/15 text-red-400' : 'bg-surface-2 text-ink-muted') }}">{{ $game['record'][0] }}-{{ $game['record'][1] }}</span>
                        @else
                            <span class="w-12 text-center text-[11.5px] font-semibold rounded px-1.5 py-0.5 shrink-0 {{ $game['result'] > 0 ? 'bg-green-500/15 text-green-400' : 'bg-red-500/15 text-red-400' }}">{{ $game['result'] > 0 ? 'Won' : 'Lost' }}</span>
                        @endif
                        <span class="flex items-center gap-1 shrink-0">
                            @forelse ($game['against'] as $spec)
                                @if ($spec['icon'])
                                    <img src="{{ $spec['icon'] }}" alt="{{ $spec['name'] }}" title="{{ $spec['name'] }}" loading="lazy" class="w-6 h-6 rounded border border-line object-cover">
                                @else
                                    <span title="{{ $spec['name'] }}" class="w-6 h-6 rounded border border-line bg-surface-2 text-[10px] text-ink-muted flex items-center justify-center">{{ Str::substr($spec['name'], 0, 2) }}</span>
                                @endif
                            @empty
                                <span class="text-[12.5px] text-ink-muted">Solo Shuffle</span>
                            @endforelse
                        </span>
                        <span class="flex-1 min-w-0 text-[12.5px] text-ink-subtle truncate">{{ $game['bracket'] }}@if ($game['you']) &middot; {{ $game['you'] }}@endif</span>
                        <span class="text-[12.5px] text-ink-subtle group-hover:text-gold shrink-0" aria-hidden="true">&rarr;</span>
                    </a>
                @endforeach
                @if ($s['count'] > count($s['games']))
                    <a href="{{ $coach }}" class="inline-block text-[12.5px] text-ink-subtle hover:text-gold mt-2">and {{ $s['count'] - count($s['games']) }} more from this session &rarr;</a>
                @endif
            </div>

            {{-- What to look at next: the one thing the Improve page names, and the last loss. --}}
            <div class="space-y-3 min-w-0">
                @if ($c = $g['character'])
                    <a href="{{ $coach }}#improve={{ $c['file'] }}" class="block rounded-lg border border-line-gold bg-gold/5 p-4 hover:border-gold transition-colors">
                        <p class="text-[11px] uppercase tracking-[0.13em] text-gold font-semibold">Where You Stand</p>
                        <p class="text-[13px] text-ink-muted mt-1">{{ $c['name'] }}, {{ $c['spec'] }}, over {{ $c['games'] }} {{ Str::plural('game', $c['games']) }}</p>
                        @if ($f = $c['focus'])
                            <p class="text-[16px] font-bold text-ink mt-2 leading-snug">{{ $f['title'] }}</p>
                            <p class="text-[13px] text-ink mt-1">You {{ $f['you'] }}, other {{ $c['spec'] }}s {{ $f['others'] }}</p>
                            <p class="text-[12.5px] text-ink-muted mt-1">{{ $f['what'] }}</p>
                        @elseif ($c['focusKnown'])
                            <p class="text-[13.5px] text-ink mt-2">On what decides games, nothing stands out behind other {{ $c['spec'] }}s.</p>
                        @endif
                        <p class="text-[12.5px] text-gold mt-2">Open where you stand &rarr;</p>
                    </a>
                @endif
                @if ($l = $g['lastLoss'])
                    <a href="{{ $coach }}#game={{ $l['file'] }}" class="block rounded-lg border border-line p-4 hover:border-gold transition-colors">
                        <p class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle font-semibold">Your Last Loss</p>
                        <p class="text-[13.5px] text-ink mt-1">{{ $l['time'] }}@if ($l['against']) against {{ implode(', ', $l['against']) }}@endif</p>
                        <p class="text-[12.5px] text-gold mt-2">See how each death happened &rarr;</p>
                    </a>
                @endif
            </div>
        </div>
    </section>

@elseif ($g['state'] === 'building')
    <section class="linear-card border-line-gold p-5 sm:p-6 mb-8">
        <h2 class="text-[11px] uppercase tracking-[0.13em] text-gold font-semibold">Your Games</h2>
        <p class="text-[20px] font-bold text-ink mt-1">{{ $g['rounds'] }} {{ Str::plural('round', $g['rounds']) }} arrived</p>
        <p class="text-[14px] text-ink-muted mt-1">Their pages are being built. That takes about a minute; this page shows them once they are ready.</p>
        <a href="{{ $coach }}" class="btn-secondary inline-flex mt-4">Open Your Games</a>
    </section>

@elseif ($g['state'] === 'waiting')
    <section class="linear-card border-line-gold p-5 sm:p-6 mb-8">
        <h2 class="text-[11px] uppercase tracking-[0.13em] text-gold font-semibold">Your Games</h2>
        <p class="text-[20px] font-bold text-ink mt-1">The app is connected</p>
        <p class="text-[14px] text-ink-muted mt-1 max-w-2xl">Play an arena game with the app running. The game appears here about a minute after it ends: who died, what killed them, and where you stand.</p>
        <div class="flex flex-wrap items-center gap-3 mt-4">
            <a href="{{ route('wow.sample') }}" class="btn-secondary">See a sample game</a>
            <a href="{{ $coach }}#upload" class="text-[13px] text-ink-subtle hover:text-gold">Or upload a combat log &rarr;</a>
        </div>
    </section>

@else
    <section class="page-hero !py-6 mb-8">
        <p class="page-eyebrow">MindCollector for Windows</p>
        <p class="text-[22px] sm:text-[26px] font-extrabold text-ink leading-tight mt-1">Get every arena game you play reviewed</p>
        <ol class="grid gap-3 sm:grid-cols-3 mt-4">
            <li class="flex gap-3"><span class="w-7 h-7 rounded-full bg-gold text-black text-[13px] font-bold flex items-center justify-center shrink-0">1</span><span class="text-[13.5px] text-ink-muted">Install the app on the PC you play on.</span></li>
            <li class="flex gap-3"><span class="w-7 h-7 rounded-full bg-gold text-black text-[13px] font-bold flex items-center justify-center shrink-0">2</span><span class="text-[13.5px] text-ink-muted">Connect it with the key from Your Games &gt; Upload.</span></li>
            <li class="flex gap-3"><span class="w-7 h-7 rounded-full bg-gold text-black text-[13px] font-bold flex items-center justify-center shrink-0">3</span><span class="text-[13.5px] text-ink-muted">Play. Each game appears here about a minute after it ends.</span></li>
        </ol>
        <div class="flex flex-wrap items-center gap-3 mt-5">
            <a href="{{ route('app') }}" wire:navigate class="btn-primary">Get the app</a>
            <a href="{{ route('wow.sample') }}" class="btn-secondary">See a sample game</a>
        </div>
    </section>
@endif
