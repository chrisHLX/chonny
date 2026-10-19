{{-- How the pages written for search are doing (App\Livewire\Admin\SearchPages). --}}
<div class="min-h-full py-8 px-4 sm:px-6 lg:px-10 xl:px-16">
    <div class="max-w-6xl mx-auto space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Search pages</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">
                    The spec guides at <a href="{{ route('spec-pvp.index') }}" class="text-gold hover:text-gold-light">/wow/pvp</a>:
                    who reads them, how many came from a search engine, and what they did next.
                </p>
            </div>
            <select wire:model.live="period" class="form-select !w-auto !py-1.5 !text-[12px]">
                @foreach ($periods as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- ── Totals ── --}}
        <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
            @foreach ([
                ['Readers', $total['readers'], 'browser-confirmed sessions'],
                ['From search', $total['fromSearch'], 'arrived from a search engine'],
                ['Crawler visits', $total['crawler'], 'Google, Bing and others reading'],
            ] as [$label, $value, $sub])
                <div class="linear-card p-4">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider">{{ $label }}</p>
                    <p class="text-[24px] font-semibold text-ink leading-none tabular-nums mt-2">{{ number_format($value) }}</p>
                    <p class="text-[11px] text-ink-muted mt-1.5">{{ $sub }}</p>
                </div>
            @endforeach
            @foreach ($next as $page => $label)
                <div class="linear-card p-4">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider">{{ $label }}</p>
                    <p class="text-[24px] font-semibold text-ink leading-none tabular-nums mt-2">{{ number_format($total['then'][$page]) }}</p>
                    <p class="text-[11px] text-ink-muted mt-1.5">
                        @if ($total['readers'] > 0)
                            {{ round(100 * $total['then'][$page] / $total['readers']) }}% of readers, after reading
                        @else
                            readers who went on to it
                        @endif
                    </p>
                </div>
            @endforeach
        </div>

        {{-- ── Per page ── --}}
        <div class="linear-card p-5">
            <p class="text-[12px] font-medium text-ink mb-3">Each spec guide</p>
            <div class="overflow-x-auto">
                <table class="w-full text-[12px] min-w-[640px]">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wide text-ink-subtle text-left whitespace-nowrap">
                            <th class="font-medium py-1.5 pr-3">Page</th>
                            <th class="font-medium py-1.5 px-3 text-right">Readers</th>
                            <th class="font-medium py-1.5 px-3 text-right">From search</th>
                            <th class="font-medium py-1.5 px-3 text-right">Crawler visits</th>
                            @foreach ($next as $label)
                                <th class="font-medium py-1.5 px-3 text-right">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr>
                            <td class="py-2 pr-3"><a href="{{ route('spec-pvp.index') }}" class="text-ink hover:text-gold">Every spec (/wow/pvp)</a></td>
                            <td class="py-2 px-3 text-right text-ink tabular-nums">{{ $hub['readers'] }}</td>
                            <td class="py-2 px-3 text-right text-ink tabular-nums">{{ $hub['fromSearch'] }}</td>
                            <td class="py-2 px-3 text-right text-ink-muted tabular-nums">{{ $hub['crawler'] }}</td>
                            @foreach ($next as $page => $label)
                                <td class="py-2 px-3 text-right text-ink-muted tabular-nums">{{ $hub['then'][$page] }}</td>
                            @endforeach
                        </tr>
                        @forelse ($pages as $r)
                            <tr>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    <a href="{{ route('spec-pvp.show', [$r['spec']->gameClass->slug, $r['spec']->slug]) }}" class="hover:underline"
                                       style="color: {{ $classColors[$r['spec']->gameClass->slug] ?? '#B3B3B3' }}">{{ $r['spec']->name }} {{ $r['spec']->gameClass->name }}</a>
                                </td>
                                <td class="py-2 px-3 text-right text-ink tabular-nums">{{ $r['readers'] }}</td>
                                <td class="py-2 px-3 text-right text-ink tabular-nums">{{ $r['fromSearch'] }}</td>
                                <td class="py-2 px-3 text-right text-ink-muted tabular-nums">{{ $r['crawler'] }}</td>
                                @foreach ($next as $page => $label)
                                    <td class="py-2 px-3 text-right text-ink-muted tabular-nums">{{ $r['then'][$page] }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-3 text-ink-subtle">No spec guide has been opened in this period yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <div class="linear-card p-5">
                <p class="text-[12px] font-medium text-ink mb-3">Search engines readers came from</p>
                @forelse ($engines as $host => $n)
                    <div class="flex items-baseline justify-between text-[12px] py-1">
                        <span class="text-ink">{{ $host }}</span>
                        <span class="text-ink-muted tabular-nums">{{ $n }}</span>
                    </div>
                @empty
                    <p class="text-[12px] text-ink-subtle">None yet.</p>
                @endforelse
            </div>
            <div class="linear-card p-5">
                <p class="text-[12px] font-medium text-ink">What people searched for</p>
                <p class="text-[12px] text-ink-muted mt-2">
                    A visit from Google doesn't say what was searched. The searches each page shows up for, how often it is
                    clicked and where it ranks come from Google Search Console, once mindcollector.com is verified there and the
                    site is given read access. Until then this box stays empty rather than guess.
                </p>
            </div>
        </div>
    </div>
</div>
