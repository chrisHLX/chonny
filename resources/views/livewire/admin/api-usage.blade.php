<div
    class="min-h-full py-8 px-6 lg:px-10 xl:px-16"
    x-data="{
        showModal: false,
        tab: 'template',
        record: null,
        open(r) { this.record = r; this.tab = 'template'; this.showModal = true; },
        close() { this.showModal = false; }
    }"
    @keydown.escape.window="close()"
>
    <div class="max-w-6xl mx-auto space-y-6">

        {{-- Header --}}
        <div>
            <h1 class="text-[17px] font-semibold text-ink">API Usage</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">AI request log, spend tracking, and prompt inspection.</p>
        </div>

        {{-- ── Summary row ── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

            {{-- Total this month --}}
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">This month</p>
                <p class="text-[22px] font-semibold text-ink leading-none">${{ number_format($summary['cost'], 4) }}</p>
                <p class="text-[12px] text-ink-muted mt-1">{{ number_format($summary['calls']) }} {{ Str::plural('call', $summary['calls']) }}</p>
            </div>

            @foreach($summary['providers'] as $provider => $stats)
                <div class="linear-card p-5">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">{{ $provider }}</p>
                    <p class="text-[22px] font-semibold text-ink leading-none">${{ number_format($stats['cost'], 4) }}</p>
                    <p class="text-[12px] text-ink-muted mt-1">{{ number_format($stats['calls']) }} {{ Str::plural('call', $stats['calls']) }}</p>
                </div>
            @endforeach

        </div>

        {{-- ── 30-day chart ── --}}
        <div class="linear-card p-5">
            <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider mb-4">Daily spend — last 30 days</p>

            <div class="flex items-end gap-px h-20">
                @foreach($chartData as $day)
                    <div class="flex-1 flex flex-col justify-end group/bar relative"
                         title="{{ $day['label'] }}: ${{ number_format($day['cost'], 6) }}">
                        <div class="w-full rounded-t-sm bg-accent/50 group-hover/bar:bg-accent transition-colors"
                             style="height: {{ max($day['pct'], 2) }}%"></div>
                    </div>
                @endforeach
            </div>

            {{-- x-axis labels every 7 days --}}
            <div class="flex mt-1.5">
                @foreach($chartData as $i => $day)
                    <div class="flex-1 text-center">
                        @if($i % 7 === 0 || $loop->last)
                            <span class="text-[9px] text-ink-subtle">{{ $day['label'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Per-purpose breakdown ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Spend by purpose</p>
            </div>

            @if($purposeBreakdown->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No data yet.</p>
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-line">
                            <th class="px-5 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Purpose</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Calls</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Total cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($purposeBreakdown as $row)
                            <tr class="{{ !$loop->last ? 'border-b border-line' : '' }} hover:bg-surface-2 transition-colors">
                                <td class="px-5 py-3 text-[13px] text-ink font-mono">{{ $row['purpose'] ?? '—' }}</td>
                                <td class="px-5 py-3 text-[13px] text-ink-muted text-right">{{ number_format($row['calls']) }}</td>
                                <td class="px-5 py-3 text-[13px] text-ink text-right">${{ number_format($row['cost'], 6) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Main request log ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Request log</p>
            </div>

            @if($requests->isEmpty())
                <p class="px-5 py-12 text-center text-[13px] text-ink-subtle">No API requests recorded yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px]">
                        <thead>
                            <tr class="border-b border-line bg-surface-2">
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider whitespace-nowrap">Created</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">User</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Model</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Purpose</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Tokens</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Cost</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Duration</th>
                                <th class="px-4 py-2.5 text-center text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Prompt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requests as $req)
                                @php
                                    $meta         = $req->metadata ?? [];
                                    $tokens       = ($meta['input_tokens'] ?? 0) + ($meta['output_tokens'] ?? 0);
                                    $costUsd      = $meta['cost_usd'] ?? null;
                                    $model        = $meta['model'] ?? '—';
                                    $duration     = $req->duration_ms !== null
                                                        ? number_format($req->duration_ms / 1000, 2) . 's'
                                                        : 'pending';
                                    $rawResp      = $req->response;
                                    $respStr      = is_array($rawResp)
                                                        ? json_encode($rawResp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                                        : (string) $rawResp;
                                    $templateStr  = (string) ($req->template_prompt ?? '');
                                @endphp
                                <tr wire:key="req-{{ $req->id }}"
                                    class="{{ !$loop->last ? 'border-b border-line' : '' }} hover:bg-surface-2 transition-colors">

                                    <td class="px-4 py-3 text-[12px] text-ink-muted whitespace-nowrap">
                                        {{ $req->created_at->format('M j, g:ia') }}
                                    </td>

                                    <td class="px-4 py-3 text-[12px] text-ink-muted whitespace-nowrap">
                                        {{ $req->user->name ?? ('uid:' . ($req->user_id ?? '—')) }}
                                    </td>

                                    <td class="px-4 py-3 text-[12px] text-ink font-mono whitespace-nowrap">
                                        {{ $model }}
                                    </td>

                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-surface-3 text-ink-muted border border-line whitespace-nowrap">
                                            {{ $req->purpose }}
                                        </span>
                                    </td>

                                    <td class="px-4 py-3 text-[12px] text-ink-muted text-right whitespace-nowrap">
                                        {{ $tokens > 0 ? number_format($tokens) : '—' }}
                                    </td>

                                    <td class="px-4 py-3 text-[12px] text-ink text-right whitespace-nowrap">
                                        {{ $costUsd !== null ? '$' . number_format((float) $costUsd, 6) : '—' }}
                                    </td>

                                    <td class="px-4 py-3 text-[12px] text-right whitespace-nowrap
                                               {{ $duration === 'pending' ? 'text-ink-subtle italic' : 'text-ink-muted' }}">
                                        {{ $duration }}
                                    </td>

                                    <td class="px-4 py-3 text-center">
                                        <button
                                            @click="open({
                                                template: @js($templateStr),
                                                prompt:   @js((string) $req->prompt),
                                                response: @js($respStr)
                                            })"
                                            class="px-2.5 py-1 text-[11px] font-medium text-accent border border-accent/30 rounded-md hover:bg-accent/10 transition-colors whitespace-nowrap">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($requests->hasPages())
                    <div class="px-5 py-4 border-t border-line">
                        {{ $requests->links() }}
                    </div>
                @endif
            @endif
        </div>

    </div>

    {{-- ── Prompt detail modal ── --}}
    <div x-show="showModal"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4">

        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-black/60" @click="close()"></div>

        {{-- Panel --}}
        <div class="relative w-full max-w-3xl max-h-[85vh] flex flex-col bg-surface-1 border border-line rounded-xl shadow-2xl overflow-hidden">

            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-line shrink-0">
                <p class="text-[14px] font-semibold text-ink">Prompt Detail</p>
                <button @click="close()"
                        class="text-ink-subtle hover:text-ink transition-colors p-1 -mr-1 rounded-md hover:bg-surface-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Tabs --}}
            <div class="flex border-b border-line px-6 shrink-0">
                <button @click="tab = 'template'"
                        :class="tab === 'template'
                            ? 'text-ink border-accent'
                            : 'text-ink-muted border-transparent hover:text-ink hover:border-line'"
                        class="px-4 py-2.5 text-[13px] font-medium border-b-2 -mb-px transition-colors">
                    Template
                </button>
                <button @click="tab = 'rendered'"
                        :class="tab === 'rendered'
                            ? 'text-ink border-accent'
                            : 'text-ink-muted border-transparent hover:text-ink hover:border-line'"
                        class="px-4 py-2.5 text-[13px] font-medium border-b-2 -mb-px transition-colors">
                    Rendered
                </button>
            </div>

            {{-- Scrollable body --}}
            <div class="overflow-y-auto flex-1 px-6 py-5 space-y-6">

                {{-- Prompt tab panels --}}
                <div>
                    <p class="text-[11px] font-medium text-ink-subtle uppercase tracking-wider mb-2">Prompt</p>

                    <div x-show="tab === 'template'">
                        <template x-if="record?.template">
                            <pre class="whitespace-pre-wrap text-[11px] font-mono text-ink-muted bg-surface-2 border border-line rounded-lg p-4 leading-relaxed"
                                 x-text="record.template"></pre>
                        </template>
                        <template x-if="!record?.template">
                            <p class="text-[12px] text-ink-subtle italic px-4 py-3 bg-surface-2 border border-line rounded-lg">
                                Not available — this record was created before template tracking was added.
                            </p>
                        </template>
                    </div>

                    <div x-show="tab === 'rendered'">
                        <pre class="whitespace-pre-wrap text-[11px] font-mono text-ink-muted bg-surface-2 border border-line rounded-lg p-4 leading-relaxed"
                             x-text="record?.prompt"></pre>
                    </div>
                </div>

                {{-- Response --}}
                <div>
                    <p class="text-[11px] font-medium text-ink-subtle uppercase tracking-wider mb-2">Response</p>
                    <pre class="whitespace-pre-wrap text-[11px] font-mono text-ink-muted bg-surface-2 border border-line rounded-lg p-4 leading-relaxed max-h-64 overflow-y-auto"
                         x-text="record?.response || '—'"></pre>
                </div>

            </div>
        </div>
    </div>

</div>
