<div wire:poll.1s="incrementElapsed" class="p-4 max-w-xl mx-auto bg-white shadow rounded">
    @if (!$started)
        {{-- ✅ Module selection --}}
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
        {{-- ✅ Quiz complete --}}
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
        {{-- ✅ Active question --}}
        @php $question = $questions[$currentIndex]; @endphp

        <div class="mb-2 text-sm text-gray-600">
            Time elapsed: <strong>{{ $elapsed }}s</strong>
        </div>

        <h2 class="text-xl font-semibold mb-2">{{ $question->question }}</h2>

        <div wire:key="question-{{ $currentIndex }}">
            <form wire:submit.prevent="submit">

                @switch($question->type)

                    {{-- Multiple choice --}}
                    @case('mcq')
                        @foreach ($question->answer['options'] as $index => $option)
                            <label class="block mb-1" wire:key="question-{{ $currentIndex }}-option-{{ $index }}">
                                <input
                                    type="radio"
                                    name="question_{{ $currentIndex }}"
                                    wire:model="answer"
                                    value="{{ $option }}"
                                >
                                {{ $option }}
                            </label>
                        @endforeach
                    @break

                    {{-- True / False --}}
                    @case('true_false')
                        <label class="block mb-1">
                            <input type="radio" name="question_{{ $currentIndex }}" wire:model="answer" value="true"> True
                        </label>
                        <label class="block mb-1">
                            <input type="radio" name="question_{{ $currentIndex }}" wire:model="answer" value="false"> False
                        </label>
                    @break

                    {{-- Open-ended --}}
                    @case('open')
                        <textarea wire:model="answer" class="w-full border rounded p-2" rows="3"></textarea>
                    @break

                    {{-- Matching Pairs --}}
                    @case('matching_pairs')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <h4 class="font-semibold">Items</h4>
                                <ul>
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li class="mb-2">{{ $key }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <h4 class="font-semibold">Match To</h4>
                                <ul>
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li class="mb-2">
                                            <select
                                                wire:model="answer.{{ $key }}"
                                                class="border rounded p-1 w-full"
                                            >
                                                <option value="">-- Select --</option>
                                                @foreach ($question->answer['pairs']['values'] as $value)
                                                    <option value="{{ $value }}">{{ $value }}</option>
                                                @endforeach
                                            </select>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @break

                    {{-- Ordering (Drag + Drop) --}}
                    @case('ordering')
                    <ul id="ordering-list-{{ $question->id }}" class="list-group">
                        @foreach($question->answer['steps'] as $step)
                            <li class="list-group-item" data-value="{{ $step }}">
                                {{ $step }}
                            </li>
                        @endforeach
                    </ul>

                    <input type="hidden" 
                        name="answers[{{ $question->id }}]" 
                        id="ordering-input-{{ $question->id }}">
                    @break


                @endswitch

                <button type="submit"
                    class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
                    @if (empty($answer)) disabled @endif>
                    Submit
                </button>
            </form>
        </div>

        {{-- Feedback --}}
        @if ($feedback)
            <div class="mt-3 text-lg">{{ $feedback }}</div>
        @endif
    @endif
</div>
