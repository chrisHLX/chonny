@php
    $name = fn (array $c) => $c['nick'] ?? collect($c['classes'])->map(fn ($s) => \App\Http\Services\CompLeaderboardService::className($s))->implode(' + ');
    $start = $board['season']['start'];
    $rateClass = fn (?int $r) => ($r ?? 0) >= 55 ? 'text-green-400' : (($r ?? 100) <= 45 ? 'text-red-300' : 'text-ink');
@endphp
<div class="linear-card p-5">
    @if ($selected)
        {{-- A comp is picked: the page is about it now, so the preview is one line, that comp in real games. --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="flex -space-x-1 shrink-0">
                @foreach ($pickedLabel['classes'] as $slug)
                    @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-6 h-6"/> @endif
                @endforeach
            </span>
            <p class="text-[13px] text-ink-muted flex-1 min-w-[200px]">
                @if ($picked)
                    <span class="font-semibold text-ink">{{ $name($picked) }}</span> in real games this season:
                    <span class="text-ink tabular-nums">{{ $picked['games'] }}</span> {{ \Illuminate\Support\Str::plural('game', $picked['games']) }},
                    @if ($picked['rate'] !== null)
                        <span class="tabular-nums {{ $rateClass($picked['rate']) }}">{{ $picked['rate'] }}%</span> won
                    @endif
                    @if ($picked['mmr'])
                        at <span class="text-ink tabular-nums">{{ $picked['mmr'] }}</span> MMR
                    @endif
                @else
                    No games of these three classes uploaded yet this season.
                @endif
            </p>
            <span class="flex flex-wrap gap-2">
                @if ($picked)
                    <a href="{{ route('comps.played', ['key' => $picked['key']]) }}" wire:navigate class="btn-secondary text-[12.5px]">See its games</a>
                @endif
                <a href="{{ route('leaderboards') }}" wire:navigate class="btn-ghost text-[12.5px]">Leaderboards &rarr;</a>
            </span>
        </div>
    @else
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">This season's top comps</h2>
            <span class="text-[12px] text-ink-subtle">
                From {{ number_format($board['games']) }} 3v3 games uploaded to MindCollector since {{ $start->format('j M') }}
            </span>
        </div>

        @if ($comps === [])
            <p class="text-[13px] text-ink-subtle mt-2">No 3v3 games uploaded yet this season.</p>
        @else
            <p class="text-[12.5px] text-ink-muted mb-2">Pick one to see how it plays.</p>
            {{-- Fixed-width numbers, no unit in each cell, and a name that wraps: at phone width the name needs the room. --}}
            <div class="flex items-center gap-2 sm:gap-3 px-1.5 pb-1 text-[10.5px] uppercase tracking-wider text-ink-subtle">
                <span class="flex-1">Comp</span>
                <span class="w-10 sm:w-12 text-right">Games</span>
                <span class="w-10 sm:w-12 text-right">Won</span>
                <span class="w-10 sm:w-12 text-right">MMR</span>
            </div>
            <div class="divide-y divide-line border-t border-line">
                @foreach ($comps as $c)
                    <button type="button" wire:click="pick('{{ $c['key'] }}')" wire:loading.attr="disabled"
                            class="w-full flex items-center gap-2 sm:gap-3 py-2 px-1.5 rounded text-left hover:bg-surface-2 transition-colors">
                        <span class="flex -space-x-1 shrink-0">
                            @foreach ($c['classes'] as $slug)
                                @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-6 h-6"/> @endif
                            @endforeach
                        </span>
                        <span class="flex-1 min-w-0 text-[13px] font-semibold text-ink leading-tight">{{ $name($c) }}</span>
                        <span class="w-10 sm:w-12 text-right text-[12.5px] tabular-nums text-ink shrink-0">{{ $c['games'] }}</span>
                        <span class="w-10 sm:w-12 text-right text-[12.5px] tabular-nums shrink-0 {{ $rateClass($c['rate']) }}">{{ $c['rate'] !== null ? $c['rate'].'%' : '-' }}</span>
                        <span class="w-10 sm:w-12 text-right text-[12.5px] tabular-nums text-ink-muted shrink-0">{{ $c['mmr'] ?? '-' }}</span>
                    </button>
                @endforeach
            </div>
            <a href="{{ route('leaderboards') }}" wire:navigate class="btn-secondary inline-flex mt-4 text-[12.5px]">
                See all {{ count($board['comps']) }} comps on the leaderboard &rarr;
            </a>
        @endif
    @endif
</div>
