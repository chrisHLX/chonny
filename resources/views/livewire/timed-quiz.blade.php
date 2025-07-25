<div wire:poll.1s="incrementElapsed" class="p-4 max-w-xl mx-auto bg-white shadow rounded">
    @if (!$started)
        <div class="mb-4">
            <label for="module" class="block font-semibold mb-1">Select a Module:</label>
            <select wire:model="selectedModule" id="module" class="w-full border rounded p-2">
                <option value="">-- Choose a module --</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}">{{ $module->name }}</option>
                @endforeach
            </select>
        </div>

        <button
            wire:click="startQuiz"
            class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
        >
            Start Quiz
        </button>
    @elseif ($completed)
        <h2 class="text-2xl font-bold mb-4">Quiz Complete!</h2>
        <p class="text-xl">Your Score: {{ $score }} / {{ $questions->count() }}</p>
        <p class="text-lg text-gray-600">Total Time: {{ $this->totalTime }}s</p>    
        <h2 class="text-2xl font-bold mb-4">Quiz Complete!</h2>
        <p class="text-xl">Your Score: {{ $score }} / {{ $questions->count() }}</p>
        <p class="text-lg text-gray-600">Total Time: {{ $this->totalTime }}s</p>

        <h3 class="mt-4 font-semibold text-lg">Per-Question Times:</h3>
        <ul class="list-disc pl-6">
            @foreach ($questionTimes as $i => $time)
                <li>Q{{ $i + 1 }}: {{ $time }}s</li>
            @endforeach
        </ul>
    @else
        @php $question = $questions[$currentIndex]; @endphp

        <div class="mb-2 text-sm text-gray-600">Time elapsed: <strong>{{ $elapsed }}s</strong></div>

        <h2 class="text-xl font-semibold mb-2">{{ $question->question }}</h2>
        <div wire:key="question-{{ $currentIndex }}">
            <form wire:submit.prevent="submit">
                @if ($question->type === 'mcq')
                    @foreach ($question->answer['options'] as $index => $option)
                        <label class="block mb-1" wire:key="question-{{ $currentIndex }}-option-{{ $index }}">
                            <input
                                type="radio"
                                name="question_{{ $currentIndex }}" {{-- ✅ Add unique name --}}
                                wire:model="answer"
                                value="{{ $option }}"
                            >
                            {{ $option }}
                        </label>
                    @endforeach
                @elseif ($question->type === 'true_false')
                    <label class="block mb-1">
                        <input type="radio" name="question_{{ $currentIndex }}" wire:model="answer" value="true"> True
                    </label>
                    <label class="block mb-1">
                        <input type="radio" name="question_{{ $currentIndex }}" wire:model="answer" value="false"> False
                    </label>
                @elseif ($question->type === 'open')
                    <textarea wire:model="answer" class="w-full border rounded p-2" rows="3"></textarea>
                @endif

                <button type="submit"
                    class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
                    @if (empty($answer)) disabled @endif>
                    Submit
                </button>
            </form>
        </div>
        
        @if ($feedback)
            <div class="mt-3 text-lg">{{ $feedback }}</div>
        @endif
    @endif
</div>
