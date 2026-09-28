{{-- One persistent root (rule 25): never wrapped in an @if. --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">

    <header class="space-y-2">
        <a href="{{ route('game-review') }}" class="text-sm text-ink-muted hover:text-gold">&larr; Match Review</a>
        <h1 class="font-display italic text-3xl sm:text-4xl text-ink">Raw analysis</h1>
        <p class="text-ink-muted max-w-3xl">
            The unedited output of the review tools, exactly as they printed it: the review table,
            every go as a chain, drain, timing, overcommitment, utilities and the kill read. The
            interpretation of these numbers lives in the analysis write-up; this is what it rests on.
        </p>
    </header>

    @if ($outputs === [])
        <div class="linear-card p-6 text-ink-muted">
            No tool output has been uploaded for your account yet.
        </div>
    @else
        @foreach ($outputs as $i => $output)
            <details class="linear-card" @if ($i === 0) open @endif>
                <summary class="cursor-pointer px-5 py-3 flex items-baseline justify-between gap-4">
                    <span class="text-ink font-medium">{{ $output['name'] }}</span>
                    <span class="text-xs text-ink-subtle">{{ \Illuminate\Support\Carbon::createFromTimestamp($output['updated'])->diffForHumans() }}</span>
                </summary>
                <pre class="px-5 pb-5 text-xs leading-relaxed text-ink-muted overflow-x-auto whitespace-pre">{{ $output['body'] }}</pre>
            </details>
        @endforeach
    @endif
</div>
