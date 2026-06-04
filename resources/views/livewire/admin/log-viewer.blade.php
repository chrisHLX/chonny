<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-6xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Log Viewer</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Last 800 lines of laravel.log, newest first.</p>
            </div>

            <div class="flex items-center gap-3">
                {{-- Debug toggle --}}
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <div class="relative">
                        <input type="checkbox" wire:model.live="showDebug" class="sr-only peer">
                        <div class="w-8 h-4 rounded-full bg-surface-3 peer-checked:bg-accent transition-colors"></div>
                        <div class="absolute top-0.5 left-0.5 w-3 h-3 rounded-full bg-white transition-transform peer-checked:translate-x-4"></div>
                    </div>
                    <span class="text-[12px] text-ink-muted">Show DEBUG</span>
                </label>

                {{-- Refresh --}}
                <button wire:click="refresh"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted border border-line hover:border-ink-subtle hover:text-ink rounded-md transition-colors">
                    <svg wire:loading.remove wire:target="refresh" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <svg wire:loading wire:target="refresh" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    Refresh
                </button>
            </div>
        </div>

        {{-- Legend --}}
        <div class="flex items-center gap-4 text-[11px]">
            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-500 inline-block"></span> ERROR</span>
            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span> WARNING</span>
            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-surface-3 inline-block"></span> INFO</span>
            @if ($showDebug)
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-500/50 inline-block"></span> DEBUG</span>
            @endif
            <span class="ml-auto text-ink-subtle">{{ count($entries) }} entries</span>
        </div>

        {{-- Log entries --}}
        @if (empty($entries))
            <div class="linear-card p-10 text-center text-[13px] text-ink-subtle">
                No log entries found.
            </div>
        @else
            <div class="linear-card overflow-hidden divide-y divide-line">
                @foreach ($entries as $entry)
                    @if ($entry['level'] === 'DEBUG' && !$showDebug)
                        @continue
                    @endif

                    @php
                        $rowClass = match ($entry['level']) {
                            'ERROR'   => 'border-l-2 border-red-500 bg-red-500/5',
                            'WARNING' => 'border-l-2 border-amber-400 bg-amber-400/5',
                            'DEBUG'   => 'border-l-2 border-blue-500/40 opacity-60',
                            default   => '',
                        };
                        $levelClass = match ($entry['level']) {
                            'ERROR'   => 'text-red-400 bg-red-500/10',
                            'WARNING' => 'text-amber-400 bg-amber-400/10',
                            'DEBUG'   => 'text-blue-400/70 bg-blue-500/10',
                            default   => 'text-ink-subtle bg-surface-2',
                        };
                    @endphp

                    <div x-data="{ open: false }"
                         class="px-4 py-2.5 {{ $rowClass }} hover:bg-surface-2/50 transition-colors">

                        {{-- Summary row --}}
                        <button x-on:click="open = !open"
                                class="w-full flex items-start gap-3 text-left">

                            <span class="shrink-0 mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide {{ $levelClass }}">
                                {{ $entry['level'] }}
                            </span>

                            @if ($entry['timestamp'])
                                <span class="shrink-0 text-[11px] text-ink-subtle tabular-nums mt-0.5">
                                    {{ $entry['timestamp'] }}
                                </span>
                            @endif

                            <span class="flex-1 text-[12px] text-ink-muted font-mono truncate">
                                {{ Str::limit(explode("\n", $entry['raw'])[0], 160) }}
                            </span>

                            @if (substr_count($entry['raw'], "\n") > 0)
                                <svg x-bind:class="open ? 'rotate-180' : ''"
                                     class="w-3.5 h-3.5 text-ink-subtle shrink-0 mt-0.5 transition-transform duration-150"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            @endif
                        </button>

                        {{-- Expanded full entry --}}
                        @if (substr_count($entry['raw'], "\n") > 0)
                            <div x-show="open" x-cloak class="mt-2 pl-0">
                                <pre class="text-[11px] text-ink-muted font-mono whitespace-pre-wrap break-all bg-surface-1 rounded p-3 max-h-72 overflow-y-auto">{{ $entry['raw'] }}</pre>
                            </div>
                        @endif

                    </div>
                @endforeach
            </div>
        @endif

    </div>
</div>
