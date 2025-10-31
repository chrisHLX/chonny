<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-bold text-gray-800">📊 Your Learning Progress</h2>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto text-gray-600 space-y-8">

        <!-- Modules Overview -->
        <div class="bg-white shadow-lg p-6 rounded-lg">
            <h3 class="text-xl font-bold mb-4 border-b pb-2">Module Progress</h3>

            @forelse ($modules as $module)
                <div class="mb-6">
                    <div class="flex justify-between items-center">
                        <div class="font-semibold text-lg">{{ $module->name }}</div>
                        <span class="text-sm px-2 py-1 rounded 
                            {{ $module->pivot->score >= 80 ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                            {{ ucfirst($module->pivot->status) }}
                        </span>
                    </div>
                    <div class="text-sm text-gray-600 mb-2">{{ $module->description }}</div>

                    <!-- Progress Bar -->
                    <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                        <div class="h-4 rounded-full bg-blue-500 transition-all duration-500"
                             style="width: {{ $module->pivot->score }}%"></div>
                    </div>
                    <div class="text-sm text-gray-700 mt-1">Score: <strong>{{ $module->pivot->score }}%</strong></div>
                </div>
            @empty
                <p class="text-gray-500">You haven't started any modules yet.</p>
            @endforelse
        </div>

        <!-- Answered Questions Stats -->
        <div class="bg-white shadow-lg text-gray-600 p-6 rounded-lg">
            <h3 class="text-xl font-bold mb-4 border-b pb-2">Question History</h3>

            @forelse ($answeredQuestions as $question)
                <div class="mb-4 p-4 border rounded-lg hover:shadow transition duration-200">
                    <div class="font-medium text-gray-800 mb-1">{{ $question->question }}</div>
                    <div class="flex flex-wrap items-center gap-4 text-sm text-gray-700 mb-1">
                        <span class="px-2 py-1 bg-gray-100 rounded">Attempts: {{ $question->pivot->attempts }}</span>
                        <span class="px-2 py-1 bg-green-100 rounded">Correct: {{ $question->pivot->correct_count }}</span>
                        <span class="px-2 py-1 bg-blue-100 rounded">
                            Accuracy: {{ round(($question->pivot->correct_count / $question->pivot->attempts) * 100, 1) }}%
                        </span>
                        <span class="px-2 py-1 bg-purple-100 rounded">Time: {{ $question->pivot->total_time_spent }}s</span>
                    </div>
                    <div class="text-xs text-gray-500 flex flex-wrap gap-2">
                        @if($question->concepts->isNotEmpty())
                            <span class="bg-yellow-100 px-2 py-0.5 rounded">Concepts: {{ $question->concepts->pluck('name')->join(', ') }}</span>
                        @endif
                        @if($question->units->isNotEmpty())
                            <span class="bg-indigo-100 px-2 py-0.5 rounded">Units: {{ $question->units->pluck('name')->join(', ') }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-gray-500">No questions attempted yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
