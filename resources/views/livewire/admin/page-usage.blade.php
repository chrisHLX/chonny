<div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
    <div class="max-w-6xl mx-auto space-y-6">

        {{-- Header --}}
        <div>
            <h1 class="text-[17px] font-semibold text-ink">Page Usage</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">WoW Comps, Spell Explorer, and Burst Windows — page views and which classes/specs actually get looked at.</p>
        </div>

        {{-- ── Summary row ── --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            @foreach($pages as $page => $label)
                <div class="linear-card p-5">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">{{ $label }}</p>
                    <p class="text-[22px] font-semibold text-ink leading-none">{{ number_format($summary[$page]['views']) }}</p>
                    <p class="text-[12px] text-ink-muted mt-1">page views</p>
                    <p class="text-[11px] text-ink-subtle mt-2">{{ number_format($summary[$page]['selections']) }} class/spec selections made</p>
                </div>
            @endforeach
        </div>

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
</div>
