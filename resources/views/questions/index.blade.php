<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Questions Guide') }}
        </h2>
    </x-slot>
    <div class="max-w-4xl mx-auto mt-8">
        @foreach ($questions as $question)
            <div class="p-4 mb-4 border rounded bg-white shadow">
                <h2 class="font-semibold">{{ $question->question }}</h2>
                <p class="text-sm text-gray-500">Type: {{ $question->type }} | Difficulty: {{ $question->difficulty }}</p>

                <p class="mt-2"><strong>Answer:</strong></p>
                <pre class="bg-gray-100 p-2 rounded text-sm">{{ json_encode($question->answer, JSON_PRETTY_PRINT) }}</pre>

                @if($question->units->count())
                    <p class="mt-2 text-sm"><strong>Units:</strong> {{ $question->units->pluck('name')->join(', ') }}</p>
                @endif

                @if($question->concepts->count())
                    <p class="text-sm"><strong>Concepts:</strong> {{ $question->concepts->pluck('name')->join(', ') }}</p>
                @endif
            </div>
        @endforeach
    </div>
    

</x-app-layout>
