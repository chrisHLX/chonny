<!-- resources/views/replays/show.blade.php -->
<x-app-layout>
<div class="max-w-xl mx-auto p-4 bg-white rounded shadow">
    <h1 class="text-2xl font-bold mb-4">Replay Review: {{ $replay->original_name }}</h1>

    <p>Status: <strong>{{ ucfirst($replay->status) }}</strong></p>

    @if($replay->ai_feedback)
        <div class="mt-4 p-4 bg-gray-100 rounded">
            <h2 class="text-xl font-semibold mb-2">AI Feedback</h2>
            <pre class="whitespace-pre-wrap">{{ $replay->ai_feedback }}</pre>
        </div>
    @else
        <p class="mt-4 italic">Review not ready yet.</p>
    @endif
</div>
</x-app-layout>

