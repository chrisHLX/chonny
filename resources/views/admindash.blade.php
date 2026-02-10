

<x-app-layout>
    <div class="container-fluid">
        <h1 class="mb-4">Laravel Logs</h1>
        <p class="text-muted mb-4">
            Showing the most recent entries from:
            <code>{{ $logFile }}</code>
        </p>

        @if (empty($logs) || (count($logs) === 1 && str_contains($logs[0], 'not found')))
            <div class="alert alert-warning">
                No log entries found or file is empty.
            </div>
        @else
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span>Latest log entries (newest on top)</span>
                    <small>Showing ~{{ count($logs) }} lines</small>
                </div>
                <div class="card-body p-0">
                    <pre class="m-0 p-3 bg-dark text-light" style="font-size: 13px; white-space: pre-wrap; word-break: break-all; max-height: 75vh; overflow-y: auto;">
{{ implode("\n", $logs) }}
                    </pre>
                </div>
            </div>
        @endif

        <div class="mt-4 text-muted small">
            <p>
                Tip: Refresh the page to see new log entries.<br>
                Want more control? Consider packages like <code>laravel-log-viewer</code> or <code>rap2hpoutre/laravel-log-viewer</code>.
            </p>
        </div>
    </div>


</x-app-layout>