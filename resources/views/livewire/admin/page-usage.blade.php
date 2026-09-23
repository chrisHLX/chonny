<div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
    <div class="max-w-6xl mx-auto space-y-5">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">Page usage</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">
                Real visitors, with crawlers counted separately rather than mixed in.
            </p>
        </div>

        {{-- ── The headline ──
             Until 2026-09-24 this page opened with all-time totals, no window and no bot filter,
             and about half of every number was a crawler. Two windows, visitors first. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach ($overview as $days => $o)
                <div class="linear-card p-5">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider">{{ $o['label'] }}</p>

                    <div class="flex items-baseline gap-2.5 mt-2">
                        <p class="text-[26px] font-semibold text-ink leading-none tabular-nums">{{ number_format($o['views']) }}</p>
                        @if ($o['change'] !== null)
                            <span @class([
                                'text-[12px] font-medium tabular-nums',
                                'text-green-400' => $o['change'] > 0,
                                'text-red-400' => $o['change'] < 0,
                                'text-ink-subtle' => $o['change'] === 0,
                            ])>{{ $o['change'] > 0 ? '+' : '' }}{{ $o['change'] }}%</span>
                        @endif
                    </div>
                    <p class="text-[12px] text-ink-muted mt-1">
                        views from {{ number_format($o['sessions']) }} visitor session(s)
                    </p>

                    <div class="flex flex-wrap gap-x-4 gap-y-1 mt-3 pt-3 border-t border-line text-[11px]">
                        <span class="text-ink-subtle">
                            crawlers <span class="text-ink-muted tabular-nums">{{ number_format($o['bots']) }}</span>
                            @if ($o['views'] + $o['bots'] > 0)
                                <span class="text-ink-subtle">({{ round(100 * $o['bots'] / ($o['views'] + $o['bots'])) }}% of all traffic)</span>
                            @endif
                        </span>
                        @if ($o['unclassified'] > 0)
                            <span class="text-ink-subtle" title="Logged before the user agent was read. Not assumed human — about half of comparable traffic was crawler.">
                                unclassified <span class="text-ink-muted tabular-nums">{{ number_format($o['unclassified']) }}</span>
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ── Fourteen days, both lines ──
             The two moved in opposite directions through September and only the combined figure
             was visible, which made crawler discovery look like growth. --}}
        @php
            $peak = max(1, $daily->max(fn ($d) => max($d['human'], $d['bot'])));
        @endphp
        <div class="linear-card p-5">
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <p class="text-[12px] font-medium text-ink">Last 14 days</p>
                <div class="flex items-center gap-3 text-[11px]">
                    <span class="flex items-center gap-1.5 text-ink-muted"><span class="w-2.5 h-2.5 rounded-sm bg-gold"></span>visitors</span>
                    <span class="flex items-center gap-1.5 text-ink-muted"><span class="w-2.5 h-2.5 rounded-sm bg-line-strong"></span>crawlers</span>
                </div>
            </div>

            @if ($daily->isEmpty())
                <p class="text-[12px] text-ink-subtle">Nothing logged yet.</p>
            @else
                <div class="flex items-end gap-1.5 h-28">
                    @foreach ($daily as $d)
                        <div class="flex-1 flex flex-col justify-end gap-0.5 group relative">
                            <div class="w-full rounded-t-sm bg-gold" style="height: {{ max(2, round(100 * $d['human'] / $peak)) }}%"
                                 title="{{ $d['day'] }} — {{ $d['human'] }} visitor views"></div>
                            <div class="w-full rounded-b-sm bg-line-strong" style="height: {{ max(1, round(60 * $d['bot'] / $peak)) }}%"
                                 title="{{ $d['day'] }} — {{ $d['bot'] }} crawler views"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between text-[10px] text-ink-subtle mt-1.5">
                    <span>{{ $daily->first()['day'] }}</span>
                    <span>{{ $daily->last()['day'] }}</span>
                </div>
            @endif
        </div>

        {{-- ── What people actually look at ── --}}
        <div class="linear-card p-5">
            <p class="text-[12px] font-medium text-ink mb-3">Pages, by real visitors &mdash; last 30 days</p>
            <div class="overflow-x-auto">
                <table class="w-full text-[12px]">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wide text-ink-subtle text-left">
                            <th class="font-medium py-1.5 pr-3">Page</th>
                            <th class="font-medium py-1.5 px-3 text-right">Views</th>
                            <th class="font-medium py-1.5 px-3 text-right">Sessions</th>
                            <th class="font-medium py-1.5 pl-3 text-right">Crawlers</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($topPages as $row)
                            <tr>
                                <td class="py-1.5 pr-3">
                                    <span class="text-ink">{{ $row['label'] }}</span>
                                    @unless ($row['tracked'])
                                        <span class="text-[10px] text-ink-subtle" title="Logged but not listed in PageUsage::PAGES">&middot; untracked</span>
                                    @endunless
                                </td>
                                <td class="py-1.5 px-3 text-right text-ink tabular-nums">{{ number_format($row['views']) }}</td>
                                <td class="py-1.5 px-3 text-right text-ink-muted tabular-nums">{{ number_format($row['sessions']) }}</td>
                                <td class="py-1.5 pl-3 text-right text-ink-subtle tabular-nums">{{ number_format($row['bots']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Where they came from ── --}}
        <div class="linear-card p-5">
            <p class="text-[12px] font-medium text-ink mb-1">Referrers &mdash; last 30 days</p>
            <p class="text-[11px] text-ink-subtle mb-3">
                Own-domain referrals are excluded: internal navigation is not a referral, and it buried the real sources.
            </p>
            @if ($referrers->isEmpty())
                <p class="text-[12px] text-ink-subtle">Nothing yet. Referrers have only been recorded since 2026-09-24.</p>
            @else
                <div class="flex flex-col gap-1">
                    @foreach ($referrers as $r)
                        <div class="flex items-baseline justify-between gap-3 text-[12px]">
                            <span class="text-ink truncate">{{ $r->referrer_host }}</span>
                            <span class="text-ink-muted tabular-nums shrink-0">{{ number_format($r->c) }} views &middot; {{ number_format($r->sessions) }} sessions</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── The per-page detail, folded ──
             All of this is still here and still correct; it was simply competing with the
             headline for attention. NOTE: these breakdowns are NOT bot-filtered — they count
             attributed class/spec selections, which a crawler does not generate (it never
             clicks a spec), so they were always closer to real than the view counts were. --}}
        <x-fold title="Class and spec breakdowns" note="Which classes and specs get picked, per page">
            <div class="space-y-5 pt-2">
        {{-- ── Top classes ── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            @foreach($pages as $page => $label)
                <div class="linear-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-line">
                        <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Most-checked classes — {{ $label }}</p>
                    </div>

                    @if($topClasses[$page]->isEmpty())
                        <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No selections recorded yet.</p>
                    @else
                        @php $max = $topClasses[$page]->max('count') ?: 1; @endphp
                        <div class="divide-y divide-line">
                            @foreach($topClasses[$page] as $row)
                                <div class="px-5 py-3">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <p class="text-[13px] text-ink">{{ $row->name }}</p>
                                        <p class="text-[12px] text-ink-muted">{{ number_format($row->count) }}</p>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                        <div class="h-full rounded-full bg-accent" style="width: {{ round(($row->count / $max) * 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ── Top class+spec combos ── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            @foreach($pages as $page => $label)
                <div class="linear-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-line">
                        <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Most-checked specs — {{ $label }}</p>
                    </div>

                    @if($topSpecs[$page]->isEmpty())
                        <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No selections recorded yet.</p>
                    @else
                        <table class="w-full">
                            <tbody>
                                @foreach($topSpecs[$page] as $row)
                                    <tr class="{{ !$loop->last ? 'border-b border-line' : '' }}">
                                        <td class="px-5 py-2.5 text-[13px] text-ink">{{ $row->spec_name }}</td>
                                        <td class="px-5 py-2.5 text-[12px] text-ink-subtle">{{ $row->class_name }}</td>
                                        <td class="px-5 py-2.5 text-[13px] text-ink-muted text-right">{{ number_format($row->count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ── WoW Comps: tab usage ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">WoW Comps — tab opens</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">Counts a switch into a tab (not the default tab on load, not re-clicks of the current one).</p>
            </div>

            @if($tabBreakdown->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No tab opens recorded yet.</p>
            @else
                @php $tabMax = $tabBreakdown->max('count') ?: 1; @endphp
                <div class="divide-y divide-line">
                    @foreach($tabBreakdown as $row)
                        <div class="px-5 py-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-[13px] text-ink">{{ $row->label }}</p>
                                <p class="text-[12px] text-ink-muted">{{ number_format($row->count) }}</p>
                            </div>
                            <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                <div class="h-full rounded-full bg-accent" style="width: {{ round(($row->count / $tabMax) * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Home feed + Buy me a coffee ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Home feed &amp; support</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">Real switches between feed tabs (not the tab landed on), "Show more" clicks, and clicks through to Buy me a coffee. Home page views themselves are in the table above.</p>
            </div>
            <div class="divide-y divide-line">
                @foreach($homeEngagement as $row)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <p class="text-[13px] text-ink">{{ $row->label }}</p>
                        <p class="text-[12px] text-ink-muted tabular-nums">{{ number_format($row->count) }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── PvP Guides: tab usage ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Class Guides — tab opens</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">Which of the four per-spec views people actually switch to. Excludes the tab landed on at page load and re-clicks of the current one.</p>
            </div>

            @if($pvpGuidesTabBreakdown->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No tab opens recorded yet.</p>
            @else
                @php $pvpTabMax = $pvpGuidesTabBreakdown->max('count') ?: 1; @endphp
                <div class="divide-y divide-line">
                    @foreach($pvpGuidesTabBreakdown as $row)
                        <div class="px-5 py-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-[13px] text-ink">{{ $row->label }}</p>
                                <p class="text-[12px] text-ink-muted">{{ number_format($row->count) }}</p>
                            </div>
                            <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                <div class="h-full rounded-full bg-accent" style="width: {{ round(($row->count / $pvpTabMax) * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── WoW Comps: "Common picks" preset usage ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">WoW Comps — preset picks</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">How often each "Common picks" starter comp is loaded. Each also counts toward the class/spec/slot breakdowns above.</p>
            </div>

            @if($presetBreakdown->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No preset picks recorded yet.</p>
            @else
                @php $presetMax = $presetBreakdown->max('count') ?: 1; @endphp
                <div class="divide-y divide-line">
                    @foreach($presetBreakdown as $row)
                        <div class="px-5 py-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-[13px] text-ink">{{ $row->label }}</p>
                                <p class="text-[12px] text-ink-muted">{{ number_format($row->count) }}</p>
                            </div>
                            <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                <div class="h-full rounded-full bg-accent" style="width: {{ round(($row->count / $presetMax) * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Spell Counters: matchup picks ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Spell Counters — matchup picks</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">Which simulated 1v1 gets selected most. No class_id/spec_id here (a duel involves two specs) — same reason WoW Comps' tab/preset breakdowns live outside the main table above.</p>
            </div>

            @if($countersMatchupBreakdown->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No matchup selections recorded yet.</p>
            @else
                @php $countersMax = $countersMatchupBreakdown->max('count') ?: 1; @endphp
                <div class="divide-y divide-line">
                    @foreach($countersMatchupBreakdown as $row)
                        <div class="px-5 py-3">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-[13px] text-ink">{{ str_replace(['_vs_', '-'], [' vs ', ' / '], $row->matchup) }}</p>
                                <p class="text-[12px] text-ink-muted">{{ number_format($row->count) }}</p>
                            </div>
                            <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                <div class="h-full rounded-full bg-accent" style="width: {{ round(($row->count / $countersMax) * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── WoW Comps: popularity by slot role ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">WoW Comps — most-picked specs by slot</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">A spec's popularity here is scoped to which role it was picked for, not overall interest.</p>
            </div>

            @if($slotBreakdown->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No slot selections recorded yet.</p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-line">
                    @foreach($slotBreakdown->sortKeys() as $slot => $data)
                        <div class="px-5 py-4">
                            <p class="text-[12px] font-medium text-ink mb-3">{{ $data['label'] }}</p>
                            <div class="space-y-2">
                                @foreach($data['top'] as $row)
                                    <div class="flex items-center justify-between">
                                        <p class="text-[12px] text-ink-muted">{{ $row->spec_name }} <span class="text-ink-subtle">({{ $row->class_name }})</span></p>
                                        <p class="text-[12px] text-ink">{{ number_format($row->count) }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
            </div>
        </x-fold>

    </div>
</div>
