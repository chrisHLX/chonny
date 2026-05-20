<x-app-layout>
    @foreach ($questions as $question)
        <div class="mb-4">
            <div class="flex justify-between mb-1">
                <span class="font-semibold">{{ $question->question }}</span>
            </div>
        </div>
        @endforeach
        <div class="mb-4">
            <div class="flex justify-between mb-1">
                <span class="font-semibold">{{ $aiSummary }}:</span>
            </div>
</x-app-layout>