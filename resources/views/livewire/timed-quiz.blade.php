<div class="p-4 max-w-xl mx-auto bg-white shadow rounded">
    @if (!$started)
        {{-- START SCREEN --}}
        <div class="mb-4">
            <label for="module" class="block font-semibold mb-1">Select a Module:</label>
            <select wire:model="selectedModule" id="module" class="w-full border rounded p-2">
                <option value="">-- Choose a module --</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}">{{ $module->name }}</option>
                @endforeach
            </select>
        </div>
        <button wire:click="startQuiz" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
            Start Quiz
        </button>
    
    @elseif ($completed)
        {{-- COMPLETION SCREEN --}}
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


        {{-- Show the timer without causing Livewire renders --}}
        <div class="mb-2 text-sm text-gray-600" x-data="{ elapsed: 0 }" x-init="setInterval(() => elapsed++, 1000)">
            Time elapsed: <strong x-text="elapsed + 's'"></strong>
        </div>

        @php $question = $questions[$currentIndex]; @endphp
        <h2 class="text-xl font-semibold mb-2">{{ $question->question }}</h2>

        <div wire:key="question-{{ $question->id }}">
                {{-- timer lives inside the form so we can pass it on submit --}}
                <form 
                    x-data="{ elapsed: 0 }"
                    x-init="setInterval(() => elapsed++, 1000)"
                    x-on:submit.prevent="$wire.submit({ elapsed })"
                >

                @switch($question->type)
                    @case('mcq')
                        @foreach ($question->answer['options'] as $index => $option)
                            <label class="block mb-1" wire:key="mcq-{{ $question->id }}-{{ $index }}">
                                <input type="radio" wire:model="answer" value="{{ $option }}">
                                {{ $option }}
                            </label>
                        @endforeach
                        @break

                    @case('true_false')
                        <label class="block mb-1">
                            <input type="radio" wire:model="answer" value="true"> True
                        </label>
                        <label class="block mb-1">
                            <input type="radio" wire:model="answer" value="false"> False
                        </label>
                        @break

                    @case('open')
                        <textarea wire:model="answer" class="w-full border rounded p-2" rows="3" required></textarea>
                        @break

                    @case('matching_pairs')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-white p-6 rounded-2xl shadow-sm border">
                            <!-- Left side: Keys -->
                            <div>
                                <h4 class="font-semibold text-lg mb-3 border-b pb-2">Items</h4>
                                <ul class="space-y-3">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li class="px-3 py-2 rounded-lg bg-gray-50 border text-gray-700">
                                            {{ $key }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <!-- Right side: Values -->
                            <div>
                                <h4 class="font-semibold text-lg mb-3 border-b pb-2">Match To</h4>
                                <ul class="space-y-3">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li>
                                            <select 
                                                wire:model="answer.{{ $key }}" 
                                                class="w-full border-gray-300 rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white shadow-sm"
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


                    @case('ordering')
                        <ul id="ordering-list-{{ $question->id }}"
                            class="list-group"
                            x-sortable
                            wire:ignore
                            x-init="
                                // ✅ send initial order to Livewire
                                $wire.set('answer', [...$el.children].map(e => e.dataset.value))
                            "
                            x-on:sorted="
                                $wire.set('answer', [...$el.children].map(e => e.dataset.value));
                            "
                        >
                            @foreach($question->answer['steps'] as $step)
                                <li class="list-group-item" data-value="{{ $step }}">
                                    {{ $step }}
                                </li>
                            @endforeach
                        </ul>
                        @break
                @endswitch

                <button type="submit"
                    class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
                    :disabled="!$wire.answer">
                    Submit
                </button>
            </form>
        </div>

        @if ($feedback)
            <div class="mt-3 text-lg">{{ $feedback }}</div>
        @endif
    @endif
    <style>
        .list-group { list-style: none; padding: 0; }
        .list-group-item { padding: 0.5rem 1rem; margin-bottom: 0.5rem; background-color: #fff; border: 1px solid #ccc; border-radius: 0.25rem; cursor: grab; user-select: none; }
        /* Clickable controls (pointer) */
        input[type="radio"],
        input[type="checkbox"],
        select,
        button {
            cursor: pointer;
        }

        /* Typing areas (text cursor) */
        textarea,
        input[type="text"],
        input[type="email"],
        input[type="number"],
        input[type="password"] {
            cursor: text;
        }

    </style>
</div>
