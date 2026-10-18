@php
    $change = function (int $now, int $before) {
        if ($before === 0) {
            return $now > 0 ? 'new' : null;
        }
        $pct = (int) round(100 * ($now - $before) / $before);

        return ($pct > 0 ? '+' : '').$pct.'%';
    };
    $label = fn (string $page) => $labels[$page] ?? $page;
@endphp

<div class="max-w-6xl mx-auto px-4 py-6 space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl text-ink">Overview</h1>
            <p class="text-[13px] text-ink-muted mt-1 max-w-2xl">
                People only: a visitor is a session where a browser ran a page, or someone was signed in.
                Crawlers and scripts are counted separately at the bottom.
            </p>
        </div>
        <div class="flex gap-1.5">
            @foreach ($periods as $key => $text)
                <button type="button" wire:click="$set('period', '{{ $key }}')"
                        class="chip {{ $period === (string) $key ? 'chip-on' : '' }}">{{ $text }}</button>
            @endforeach
        </div>
    </div>

    {{-- The headline numbers, each against the period before. --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        @foreach ([
            ['Visitors', $now['visitors'], $before['visitors'], $now['signedIn'].' signed in'],
            ['Accounts created', $now['path']['Created an account'], $before['path']['Created an account'], implode(' · ', array_map(fn ($k, $v) => "$k $v", array_keys($now['signups']), $now['signups']))],
            ['Apps connected', $now['path']['Connected the app'], $before['path']['Connected the app'], $now['path']['Downloaded the app'].' downloads'],
            ['Games uploaded', $now['games'], $before['games'], $now['path']['Uploaded a game'].' players'],
        ] as [$title, $value, $prev, $sub])
            <div class="linear-card p-4">
                <p class="text-[11px] uppercase tracking-wider text-ink-subtle">{{ $title }}</p>
                <p class="text-[28px] font-bold text-ink leading-tight mt-1 tabular-nums">{{ number_format($value) }}</p>
                <p class="text-[12px] text-ink-muted mt-1">
                    @if ($c = $change($value, $prev))<span class="{{ str_starts_with($c, '-') ? 'text-red-400' : 'text-green-400' }}">{{ $c }}</span> vs before · @endif{{ $sub }}
                </p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        {{-- From a visit to a game uploaded. --}}
        <div class="linear-card p-5">
            <h2 class="text-[15px] font-semibold text-ink">From a visit to a game</h2>
            <p class="text-[12px] text-ink-subtle mt-0.5">Each step on its own count for the period; accounts and apps can come from visits before it.</p>
            <div class="mt-3 space-y-2">
                @php $top = max(1, $now['path']['Visited the site']); @endphp
                @foreach ($now['path'] as $step => $n)
                    <div>
                        <div class="flex justify-between text-[13px]"><span class="text-ink">{{ $step }}</span><span class="text-ink tabular-nums">{{ number_format($n) }}</span></div>
                        <div class="h-1.5 rounded-full bg-surface-2 mt-1 overflow-hidden"><div class="h-full bg-gold" style="width: {{ min(100, round(100 * $n / $top)) }}%"></div></div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Where they came from: every visitor once, so the rows add up to the total. --}}
        <div class="linear-card p-5">
            <h2 class="text-[15px] font-semibold text-ink">Where visitors came from</h2>
            <p class="text-[12px] text-ink-subtle mt-0.5">Each visitor's first outside link. Typed addresses, bookmarks and links from apps (Discord, the MindCollector app) send none.</p>
            <table class="w-full text-[13px] mt-3">
                @forelse ($now['sources'] as $host => $n)
                    <tr class="border-t border-line"><td class="py-1.5 text-ink">{{ $host }}</td><td class="py-1.5 text-right tabular-nums text-ink">{{ $n }}</td>
                        <td class="py-1.5 text-right text-ink-subtle w-14 tabular-nums">{{ round(100 * $n / max(1, $now['visitors'])) }}%</td></tr>
                @empty
                    <tr><td class="py-2 text-ink-subtle">No visitors in this period.</td></tr>
                @endforelse
                <tr class="border-t border-line-strong"><td class="py-1.5 text-ink-muted">Total</td><td class="py-1.5 text-right tabular-nums text-ink font-semibold">{{ $now['visitors'] }}</td><td></td></tr>
            </table>
        </div>

        <div class="linear-card p-5">
            <h2 class="text-[15px] font-semibold text-ink">Pages visitors opened</h2>
            <table class="w-full text-[13px] mt-3">
                @foreach ($now['pages'] as $page => $n)
                    <tr class="border-t border-line"><td class="py-1.5 text-ink">{{ $label($page) }}</td><td class="py-1.5 text-right tabular-nums text-ink">{{ $n }}</td></tr>
                @endforeach
            </table>
        </div>

        <div class="linear-card p-5">
            <h2 class="text-[15px] font-semibold text-ink">Where visitors landed first</h2>
            <table class="w-full text-[13px] mt-3">
                @foreach ($now['landing'] as $page => $n)
                    <tr class="border-t border-line"><td class="py-1.5 text-ink">{{ $label($page) }}</td><td class="py-1.5 text-right tabular-nums text-ink">{{ $n }}</td></tr>
                @endforeach
            </table>
            @if ($now['interests'])
                <p class="text-[12px] text-ink-muted mt-3">{{ $now['interests'] }} asked to be told when a coming game opens.</p>
            @endif
        </div>
    </div>

    <div class="linear-card p-5 overflow-x-auto">
        <h2 class="text-[15px] font-semibold text-ink">By day</h2>
        <table class="w-full text-[13px] mt-3 min-w-[560px]">
            <tr class="text-[11px] uppercase tracking-wider text-ink-subtle">
                <th class="text-left py-1 font-medium">Day</th><th class="text-right font-medium">Visitors</th><th class="text-right font-medium">App page</th>
                <th class="text-right font-medium">Downloads</th><th class="text-right font-medium">Accounts</th><th class="text-right font-medium">Apps connected</th><th class="text-right font-medium">Games</th>
            </tr>
            @foreach ($daily as $d)
                <tr class="border-t border-line tabular-nums">
                    <td class="py-1.5 text-ink">{{ $d['day'] }}</td><td class="text-right text-ink">{{ $d['visitors'] }}</td><td class="text-right text-ink-muted">{{ $d['appPage'] }}</td>
                    <td class="text-right text-ink-muted">{{ $d['downloads'] }}</td><td class="text-right text-ink-muted">{{ $d['signups'] }}</td><td class="text-right text-ink-muted">{{ $d['connected'] }}</td><td class="text-right text-ink-muted">{{ $d['games'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    <p class="text-[12px] text-ink-subtle">
        Not counted as visitors in this period: {{ number_format($now['bots']) }} page views from crawlers that say so, and
        {{ number_format($now['scripts']) }} sessions in which no browser ever ran a page (scripts that pass as browsers).
        The detail is on Page usage, Search pages and Matches above.
    </p>
</div>
