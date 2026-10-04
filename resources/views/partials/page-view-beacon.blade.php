{{-- A browser confirms the page view it was served by running this (TrackController::seen,
     PageViewEvent::scopeConfirmed). A script that fetches the HTML and never runs it cannot. --}}
@php($pageViewId = (int) request()->attributes->get('page_view_id', 0))
@if ($pageViewId > 0)
    <script>
        fetch('{{ route('track.seen', absolute: false) }}', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' }, body: JSON.stringify({ id: {{ $pageViewId }}, sig: '{{ \App\Models\PageViewEvent::signature($pageViewId) }}' }) }).catch(() => {});
    </script>
@endif
