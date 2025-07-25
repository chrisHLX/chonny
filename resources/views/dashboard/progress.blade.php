<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">Your Progress</h2>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto space-y-8">

        <!-- Modules Overview -->
        <div class="bg-white shadow p-4 rounded">
            <h3 class="text-lg font-bold mb-2">Module Progress</h3>
            @forelse ($modules as $module)
                <div class="mb-3">
                    <div class="font-semibold">{{ $module->name }}</div>
                    <div class="text-sm text-gray-600">{{ $module->description }}</div>
                    <div class="mt-1 text-sm">
                        Score: <strong>{{ $module->pivot->score }}%</strong> |
                        Status: <em>{{ ucfirst($module->pivot->status) }}</em>
                    </div>
                </div>
            @empty
                <p>You haven't started any modules yet.</p>
            @endforelse
        </div>

        <!-- Answered Questions Stats -->
        <div class="bg-white shadow p-4 rounded">
            <h3 class="text-lg font-bold mb-2">Question History</h3>
            @forelse ($answeredQuestions as $question)
                <div class="mb-3 border-b pb-2">
                    <div class="font-medium">{{ $question->question }}</div>
                    <div class="text-sm text-gray-700">
                        Attempts: {{ $question->pivot->attempts }},
                        Correct: {{ $question->pivot->correct_count }},
                        Accuracy: {{ round(($question->pivot->correct_count / $question->pivot->attempts) * 100, 1) }}%,
                        Time Spent: {{ $question->pivot->total_time_spent }}s
                    </div>
                    <div class="text-xs text-gray-500">
                        Concepts:
                        {{ $question->concepts->pluck('name')->join(', ', ' and ') }}
                        |
                        Units:
                        {{ $question->units->pluck('name')->join(', ', ' and ') }}
                    </div>
                </div>
            @empty
                <p>No questions attempted yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
