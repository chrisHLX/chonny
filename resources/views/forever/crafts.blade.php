{{-- WoW Forever's Crafting Profits (/wow/forever/crafts, 2026-11-26): what each craft earns on one auction
     house over its last week of shared scans. ForeverCraftsController, ForeverCraftsService (the sums),
     ForeverRecipes (the addon's own recipe list). The crafts go to the browser as compact JSON and are
     drawn there, 100 at a time: all 1,200 drawn as HTML came to 3.3 MB. Rows, not a wide table, so a
     phone reads them. --}}
@php
    $cooldown = function (?int $sec): ?string {
        if (! $sec) {
            return null;
        }
        if ($sec < 3600) {
            return round($sec / 60).' min cooldown';
        }
        $h = $sec / 3600;

        return $h < 48 ? round($h).' hour cooldown' : round($h / 24).' day cooldown';
    };
    $crafts = $result['crafts'] ?? [];
    $hours = $result['hours'] ?? 0;
    // On the auction house in at least half the week's readings: a market, not one listing.
    $steadyFrom = max(1, (int) ceil($hours / 2));
    $professions = collect($crafts)->pluck('profession')->unique()->sort()->values();
    // Material names once, in $mats; a row's materials point into it: [count, name index, unit price, vendor].
    $mats = [];
    $matIndex = function (string $name) use (&$mats): int {
        $at = array_search($name, $mats, true);

        return $at === false ? array_push($mats, $name) - 1 : $at;
    };
    $rows = collect($crafts)->map(fn ($c) => array_filter([
        'n' => $c['name'], 'r' => $c['recipe'], 'p' => $c['profession'], 'k' => $c['skill'], 't' => $c['trainer'] ? 1 : 0,
        'y' => $c['yield'], 'c' => $c['cost'], 's' => $c['sells'], 'e' => $c['earns'], 'g' => $c['profit'],
        'm' => $c['margin'] === null ? null : (int) round($c['margin'] * 100), 'l' => $c['listed'], 'v' => $c['seen'],
        'cd' => $cooldown($c['cooldown']), 'cg' => $c['cooldownGroup'] ?: null,
        'x' => array_map(fn ($m) => [$m['count'], $matIndex($m['name']), $m['unit'], $m['vendor'] ? 1 : 0], $c['mats']),
        'st' => $c['seen'] >= $steadyFrom ? 1 : 0,
    ], fn ($v) => $v !== null))->values();
    $profitable = collect($crafts)->filter(fn ($c) => $c['profit'] > 0 && $c['seen'] >= $steadyFrom)->count();
    $stale = isset($result['to']) && $result['to']->lt(now()->subDays(3));
@endphp
<x-app-layout title="Crafting Profits in WoW Forever: the Most Profitable Crafts This Week" description="What every WoW Forever craft earns on the auction house this week: materials, sale price after the cut, and profit per craft, from the scans players share through MindCollector.">
<div class="max-w-5xl mx-auto px-4 py-6 sm:py-10"
     x-data="{
        prof: 'all',
        search: '',
        rare: false,
        losing: false,
        open: null,
        limit: 100,
        rows: [],
        mats: [],
        get visible() {
            const q = this.search.toLowerCase();
            return this.rows.filter(r => (this.prof === 'all' || r.p === this.prof)
                && (q === '' || r.q.includes(q)) && (this.rare || r.st) && (this.losing || r.g > 0));
        },
        money(c) {
            const sign = c < 0 ? '-' : '';
            c = Math.abs(c);
            const g = Math.floor(c / 10000), s = Math.floor((c % 10000) / 100), cp = c % 100;
            if (g > 0) return sign + g.toLocaleString('en') + 'g' + (s ? ' ' + s + 's' : '');
            return sign + (s > 0 ? s + 's' + (cp ? ' ' + cp + 'c' : '') : cp + 'c');
        },
        num(x) { return Math.round(x * 10) / 10; },
     }"
     x-init="mats = @js($mats); rows = @js($rows).map(r => ({ r: null, m: null, cd: null, cg: null, ...r, q: (r.n + ' ' + (r.r || '')).toLowerCase() })); $watch('prof', () => limit = 100); $watch('search', () => limit = 100)">

    <section class="page-hero">
        <p class="page-eyebrow">WoW Forever · Auction House</p>
        <h1 class="page-title">Crafting Profits</h1>
        <p class="text-[16px] text-ink-muted mt-4 max-w-2xl leading-relaxed">
            What each craft earns on the auction house this week, from the scans players share through the
            MindCollector app.
        </p>
    </section>

    @if (! $result)
        <div class="mt-8 linear-card p-6 text-[14px] text-ink-muted leading-relaxed">
            No one has shared an auction house scan yet. Scans come from the MindCollector app's
            <a href="{{ route('wow.forever') }}#prices" class="text-gold hover:underline">Auction House addon</a>,
            and this page fills in from the first one.
        </div>
    @else
        {{-- Which auction house --}}
        @if (count($markets) > 1)
            <nav class="mt-6 flex flex-wrap gap-2" aria-label="Auction houses">
                @foreach ($markets as $m)
                    @php $on = $m['key'] === $market['key']; @endphp
                    <a href="{{ route('wow.forever.crafts', ['market' => $m['key']]) }}" wire:navigate
                       class="rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition-colors {{ $on ? 'bg-surface-3 text-ink ring-1 ring-line-gold' : 'text-ink-muted hover:text-ink hover:bg-surface-2' }}"
                       @if ($on) aria-current="page" @endif>
                        {{ $m['label'] }}
                        <span class="text-ink-subtle font-normal">· {{ $m['scans'] }} {{ Str::plural('scan', $m['scans']) }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        <div class="mt-4 text-[13px] text-ink-muted leading-relaxed">
            <p>
                {{ $market['label'] }} auction house,
                {{ $result['from']->format('j M') }} to {{ $result['to']->format('j M') }}:
                {{ $result['scans'] }} {{ Str::plural('scan', $result['scans']) }}
                by {{ $result['players'] }} {{ Str::plural('player', $result['players']) }}.
                {{ $profitable }} {{ Str::plural('craft', $profitable) }} make a profit on items that list steadily.
            </p>
            @if ($stale)
                <p class="mt-2 text-amber-400">
                    No one has scanned this auction house since {{ $result['to']->format('j M') }}, so prices may have moved.
                </p>
            @endif
        </div>

        {{-- Filters --}}
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1">
                <button type="button" @click="prof = 'all'" class="tab-btn" :class="prof === 'all' ? 'tab-active' : 'tab-inactive'">All</button>
                @foreach ($professions as $p)
                    <button type="button" @click="prof = @js($p)" class="tab-btn" :class="prof === @js($p) ? 'tab-active' : 'tab-inactive'">{{ $p }}</button>
                @endforeach
            </div>
            <div class="relative w-full sm:w-56">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink-subtle pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" x-model="search" placeholder="Search crafts…" class="form-input !pl-9" aria-label="Search crafts">
            </div>
        </div>
        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-[13px] text-ink-muted">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="rare" class="rounded border-line bg-surface-2 text-gold focus:ring-gold">
                Include items rarely on the AH
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="losing" class="rounded border-line bg-surface-2 text-gold focus:ring-gold">
                Include crafts that lose money
            </label>
        </div>

        {{-- The crafts --}}
        <div class="mt-4 linear-card overflow-hidden">
            <template x-for="c in visible.slice(0, limit)" :key="c.n + '|' + c.r + '|' + c.k">
                <div class="border-b border-line last:border-b-0">
                    <button type="button" @click="open = open === c ? null : c" :aria-expanded="open === c"
                            class="w-full text-left grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2/50 transition-colors">
                        <div class="min-w-0">
                            <p class="text-[14px] font-semibold text-ink">
                                <span x-text="c.n"></span><span class="text-ink-subtle font-normal" x-show="c.y > 1" x-text="' ×' + num(c.y)"></span>
                            </p>
                            <p class="text-[12px] text-ink-subtle mt-0.5"
                               x-text="(c.r ? c.r + ' · ' : '') + c.p + ' ' + c.k + ' · ' + (c.t ? 'from a trainer' : 'needs the recipe')"></p>
                            <p class="mt-1" x-show="c.cd">
                                <span class="badge-blue" :title="c.cg ? 'Shared: making any ' + c.cg + ' craft starts this cooldown for all of them.' : ''"
                                      x-text="c.cd + (c.cg ? ', shared' : '')"></span>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-[15px] font-bold whitespace-nowrap" :class="c.g > 0 ? 'text-emerald-400' : 'text-rose-400'"
                               x-text="(c.g > 0 ? '+' : '') + money(c.g)"></p>
                            <p class="text-[12px] text-ink-subtle whitespace-nowrap" x-show="c.m !== null"
                               x-text="(c.m > 0 ? '+' : '') + c.m + '% on mats'"></p>
                        </div>
                        <p class="col-span-2 text-[12px] text-ink-muted"
                           x-text="'Mats ' + money(c.c) + ' · sells ' + money(c.s) + (c.y > 1 ? ' each' : '') + ' · ' + c.l.toLocaleString('en') + ' listed · on the AH in ' + c.v + ' of {{ $hours }} scans'"></p>
                    </button>
                    <div x-show="open === c" class="px-5 pb-4 -mt-1 text-[12px] text-ink-muted">
                        <ul class="space-y-0.5">
                            <template x-for="x in c.x" :key="x[1]">
                                <li>
                                    <span x-text="x[0] + ' × ' + mats[x[1]] + ' · ' + money(x[2] * x[0])"></span>
                                    <span class="text-ink-subtle" x-show="x[0] > 1 || x[3]"
                                          x-text="'(' + [x[0] > 1 ? money(x[2]) + ' each' : '', x[3] ? 'vendor' : ''].filter(Boolean).join(', ') + ')'"></span>
                                </li>
                            </template>
                        </ul>
                        <p class="mt-2"
                           x-text="'Sells ' + money(c.s) + (c.y > 1 ? ' each, ' + num(c.y) + ' a craft' : '') + ', ' + money(c.e) + ' after the 5% cut, less ' + money(c.c) + ' in mats.'"></p>
                    </div>
                </div>
            </template>
            <div x-show="visible.length === 0" x-cloak class="p-6 text-center text-[13px] text-ink-muted">
                No crafts match.
            </div>
            <button type="button" x-show="visible.length > limit" x-cloak @click="limit += 100"
                    class="w-full px-5 py-3 text-[13px] font-semibold text-gold hover:bg-surface-2/50 border-t border-line"
                    x-text="'Show more (' + (visible.length - limit) + ' left)'"></button>
        </div>

        <div class="mt-5 text-[12px] text-ink-subtle leading-relaxed space-y-2 max-w-3xl">
            <p>
                Profit is what the item sells for, less the auction house's 5% cut, times how many one craft makes,
                minus what the materials cost to buy. A price is the middle of the cheapest listing across the week's
                scans, so one odd listing doesn't move it. Materials a vendor sells use the vendor's price when it's
                lower.
            </p>
            <p>
                Materials you can make yourself are priced at what they cost to buy, and the auction house deposit
                isn't counted. A craft with a cooldown earns its profit once per cooldown. In game, the Auction House
                addon works out the same profit from the newest prices, and makes the materials you can make.
            </p>
        </div>
    @endif
</div>
</x-app-layout>
