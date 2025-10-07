<div class="p-6 max-w-2xl mx-auto bg-white rounded-2xl shadow-lg border border-gray-100"
     x-data="{ elapsed: 0, started: @entangle('started'), completed: @entangle('completed') }"
     x-init="if (started) setInterval(() => elapsed++, 1000)">
     
    {{-- START SCREEN --}}
    @if (!$started)
        <div class="text-center space-y-4" x-transition>
            <h2 class="text-3xl font-bold text-gray-800">🧠 Ready to Test Your Knowledge?</h2>
            <p class="text-gray-600">Choose a module below to begin your quiz.</p>

            <div class="mt-6">
                <label for="module" class="block font-semibold mb-1 text-gray-700">Select Module:</label>
                <select wire:model="selectedModule" id="module"
                    class="w-full border-gray-300 rounded-lg p-3 text-gray-800 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 shadow-sm">
                    <option value="">-- Choose a module --</option>
                    @foreach ($modules as $module)
                        <option value="{{ $module->id }}">{{ $module->name }}</option>
                    @endforeach
                </select>
            </div>

            <button wire:click="startQuiz"
                class="w-full py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 shadow transition-transform hover:scale-105">
                ▶️ Start Quiz
            </button>
        </div>

    {{-- COMPLETION SCREEN --}}
    @elseif ($completed)
        <div x-transition>
            <h2 class="text-3xl font-bold text-center mb-4 text-green-700">🎉 Quiz Complete!</h2>

            <div class="grid grid-cols-2 gap-4 text-center mb-6">
                <div class="p-4 bg-green-100 rounded-lg">
                    <p class="text-sm text-gray-600">Score</p>
                    <p class="text-3xl font-bold text-green-700">{{ $score }} / {{ $questions->count() }}</p>
                </div>
                <div class="p-4 bg-blue-100 rounded-lg">
                    <p class="text-sm text-gray-600">Total Time</p>
                    <p class="text-3xl font-bold text-blue-700">{{ $this->totalTime }}s</p>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row justify-center gap-4">
                @if ($score === $questions->count())
                    <button wire:click="unlockNewModule"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow">
                        🎉 Unlock Next Module
                    </button>
                @elseif ($attemptNumber >= 5)
                    <button wire:click="generateRevisionModule"
                        class="px-5 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 shadow">
                        🔄 Generate Revision Module
                    </button>
                @else
                    <button wire:click="retryModule"
                        class="px-5 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 shadow">
                        🔁 Try Again
                    </button>
                @endif
            </div>
        </div>

    {{-- QUESTION SCREEN --}}
    @else
        @php $question = $questions[$currentIndex]; @endphp

        {{-- Progress Bar --}}
        <div class="mb-4">
            <div class="flex justify-between text-sm text-gray-600 mb-1">
                <span>Question {{ $currentIndex + 1 }} / {{ $questions->count() }}</span>
                <span>{{ $this->totalTime }}s total</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
                <div class="bg-blue-600 h-2.5 rounded-full transition-all duration-500"
                     style="width: {{ (($currentIndex + 1) / $questions->count()) * 100 }}%">
                </div>
            </div>
        </div>

        {{-- Question Card --}}
        <div class="p-5 bg-gray-50 rounded-xl shadow-sm border border-gray-100"
             wire:key="question-{{ $question->id }}" x-transition>
            <h2 class="text-xl font-semibold mb-4 text-gray-800">
                <span class="text-blue-600 font-bold">Q{{ $currentIndex + 1 }}.</span>
                {{ $question->question }}
            </h2>

            <form x-data="{ elapsed: 0 }"
                x-init="setInterval(() => elapsed++, 1000)"
                x-on:submit.prevent="$wire.submit({ elapsed })">

                @switch($question->type)
                    @case('mcq')
                        <div class="space-y-2">
                            @foreach ($question->answer['options'] as $index => $option)
                                <label class="flex items-center gap-2 p-2 border rounded-lg hover:bg-blue-50 transition cursor-pointer">
                                    <input type="radio" wire:model="answer" value="{{ $option }}" class="text-blue-600">
                                    <span>{{ $option }}</span>
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case('true_false')
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 p-2 border rounded-lg hover:bg-blue-50 cursor-pointer">
                                <input type="radio" wire:model="answer" value="true" class="text-blue-600"> True
                            </label>
                            <label class="flex items-center gap-2 p-2 border rounded-lg hover:bg-blue-50 cursor-pointer">
                                <input type="radio" wire:model="answer" value="false" class="text-blue-600"> False
                            </label>
                        </div>
                        @break

                    @case('open')
                        <textarea wire:model="answer"
                            class="w-full border rounded-lg p-3 mt-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            rows="3" required placeholder="Type your answer..."></textarea>
                        @break

                    @case('matching_pairs')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-white p-4 rounded-2xl shadow-sm border">
                            <div>
                                <h4 class="font-semibold mb-3 text-gray-700 border-b pb-2">Items</h4>
                                <ul class="space-y-2">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li class="px-3 py-2 rounded bg-gray-100 border text-gray-800">{{ $key }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-3 text-gray-700 border-b pb-2">Match To</h4>
                                <ul class="space-y-2">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li>
                                            <select wire:model="answer.{{ $key }}"
                                                class="w-full border-gray-300 rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white shadow-sm">
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

                    @case('ordering')
                        <ul id="ordering-list-{{ $question->id }}" class="list-group" x-sortable wire:ignore
                            x-init="$wire.set('answer', [...$el.children].map(e => e.dataset.value))"
                            x-on:sorted="$wire.set('answer', [...$el.children].map(e => e.dataset.value))">
                            @foreach($question->answer['steps'] as $step)
                                <li class="list-group-item" data-value="{{ $step }}">
                                    {{ $step }}
                                </li>
                            @endforeach
                        </ul>
                        @break
                @endswitch

                <button type="submit"
                    class="mt-5 w-full py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 shadow disabled:opacity-50 disabled:cursor-not-allowed transition"
                    :disabled="!$wire.answer">
                    Submit Answer
                </button>
            </form>

            @if ($feedback)
                <div class="mt-4 p-3 rounded text-center font-semibold
                    {{ $feedback === 'Correct!' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                    {{ $feedback }}
                </div>
            @endif
        </div>
    @endif

    <style>
        .list-group { list-style: none; padding: 0; }
        .list-group-item {
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            background-color: #f9fafb;
            border: 1px solid #ddd;
            border-radius: 0.5rem;
            cursor: grab;
            user-select: none;
        }
        input[type="radio"], input[type="checkbox"], select, button { cursor: pointer; }
        textarea, input[type="text"], input[type="email"], input[type="number"], input[type="password"] { cursor: text; }
    </style>
</div>
