<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-5xl mx-auto space-y-4">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">Laravel Logs</h1>
                <p class="text-[12px] text-ink-subtle mt-0.5 font-mono">{{ $logFile }}</p>
            </div>

            @if (empty($logs) || (count($logs) === 1 && str_contains($logs[0], 'not found')))
                <div class="linear-card px-5 py-8 text-center">
                    <p class="text-[13px] text-ink-subtle">No log entries found or file is empty.</p>
                </div>
            @else
                <div class="linear-card overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-line">
                        <p class="text-[12px] font-semibold text-ink-subtle uppercase tracking-wide">Latest entries (newest first)</p>
                        <span class="badge-gray">~{{ count($logs) }} lines</span>
                    </div>
                    <pre class="p-5 text-[12px] text-ink-muted font-mono leading-relaxed overflow-x-auto max-h-[75vh] overflow-y-auto whitespace-pre-wrap break-all">{{ implode("\n", $logs) }}</pre>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
