<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-6">
        <h3 class="text-2xl font-bold mb-6">AI Requests</h3>

        @forelse ($ai_requests as $request)
            <div class="p-6 mb-6 border border-gray-200 rounded-lg bg-white shadow-sm">
                <div class="mb-2">
                    <h4 class="text-lg font-semibold text-gray-800">{{ $request->purpose }}</h4>
                    <p class="text-sm text-gray-600">{{ $request->prompt }}</p>
                </div>

                <div class="mt-4">
                    <h5 class="text-sm font-medium text-gray-700 mb-1">Response:</h5>
                    <pre class="text-sm text-gray-800 bg-gray-50 p-3 rounded overflow-x-auto whitespace-pre-wrap">
{{ json_encode($request->response, JSON_PRETTY_PRINT) }}
                    </pre>
                </div>

                <div class="mt-4">
                    <h5 class="text-sm font-medium text-gray-700 mb-1">Metadata:</h5>
                    <pre class="text-sm text-gray-800 bg-gray-50 p-3 rounded overflow-x-auto whitespace-pre-wrap">
{{ json_encode($request->metadata, JSON_PRETTY_PRINT) }}
                    </pre>
                </div>
            </div>
        @empty
            <div class="text-center text-gray-500 mt-10">
                <p>No AI requests found.</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
