<div class="p-6 max-w-2xl mx-auto bg-white rounded-2xl shadow-lg border border-gray-100 text-gray-600"
     x-data="{ elapsed: 0, started: @entangle('started'), completed: @entangle('completed') }"
     x-init="if (started) setInterval(() => elapsed++, 1000)">
     
    {{-- START SCREEN --}}
    @if (!$started)
        <div class="text-center space-y-4" x-transition>
            <h2 class="text-3xl font-bold text-gray-800">🧠 Ready to Test Your Knowledge?</h2>
            <p class="text-gray-600">Choose a module from your favorite game below.</p>

            @if($subjects->count() > 1)
                <div class="mb-4 flex space-x-2">
                    @foreach($subjects as $subject)
                        <button wire:click="$set('selectedSubject', {{ $subject->id }})"
                                class="px-3 py-1 rounded
                                    {{ $selectedSubject == $subject->id ? 'bg-blue-600 text-white' : 'bg-gray-200' }}">
                            {{ $subject->name }}
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- SUBJECTS & MODULES --}}
            <div class="mt-6 space-y-6">
                @foreach ($subjects as $subject)
                    @if (!$selectedSubject || $selectedSubject == $subject->id)
                        <div class="border border-gray-200 rounded-2xl shadow-sm p-5 bg-white">
                            <h3 class="text-2xl font-semibold text-indigo-700 mb-3">
                                🎮 {{ $subject->name }}
                            </h3>

                            <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-4">
                                @foreach ($modules->where('subject_id', $subject->id) as $module)
                                    <div
                                        wire:click="$set('selectedModule', {{ $module->id }})"
                                        class="cursor-pointer border rounded-xl p-4 text-left transition
                                            {{ $selectedModule == $module->id ? 'bg-green-100 border-green-400' : 'hover:bg-gray-50' }}">
                                        <h4 class="font-semibold text-gray-800">{{ $module->name }}</h4>
                                        <p class="text-sm text-gray-600 mt-1">
                                            {{ $module->proficiencies->first()->name ?? 'link proficiency' }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>


            {{-- START BUTTON --}}
            <div class="mt-6">
                <button
                    wire:click="startQuiz"
                    class="w-full py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 shadow transition-transform hover:scale-105 disabled:opacity-50"
                    @disabled(!$selectedModule)>
                    ▶️ Start Quiz
                </button>
            </div>
        </div>
    
    {{-- FEEDBACK SCREEN --}}    
    @elseif (!empty($feedback))
            <div class="mt-6 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 rounded-lg"
                x-transition>
                @php
                    $hasMissingContent = collect($contents)->contains(fn($c) => empty($c['review_content']));
                @endphp
                @if ($hasMissingContent)
                <div 
                    wire:poll.5s="checkReviewContent"
                    x-data="{
                        messages: [
                            'Analyzing your mistakes...',
                            'Reviewing similar questions...',
                            'Finding learning patterns...',
                            'Generating targeted review...',
                            'Almost done...'
                        ],
                        index: 0,
                        progress: 0,
                        nextMessage() {
                            this.index = (this.index + 1) % this.messages.length;
                            this.progress = Math.min(100, this.progress + Math.random() * 10);
                        },
                        init() {
                            setInterval(() => this.nextMessage(), 2500);
                        }
                    }"
                    class="p-4 bg-blue-50 border border-blue-300 rounded-lg mt-4"
                >
                    <p class="font-semibold text-blue-700 flex items-center">
                        <span class="animate-pulse mr-2">🤖</span>
                        <span x-text="messages[index]"></span>
                    </p>

                    <div class="w-full bg-blue-200 h-2 rounded mt-2 overflow-hidden">
                        <div class="bg-blue-600 h-2 transition-all duration-700" 
                            :style="{ width: progress + '%' }">
                        </div>
                    </div>

                    <ul class="text-xs text-gray-600 mt-3 space-y-1">
                        @foreach ($contents as $content)
                            <li>
                                Question {{ $content['question_id'] }}: 
                                @if ($content['review_content'])
                                    <span class="text-green-600">✅ Ready</span>
                                @else
                                    <span class="text-yellow-500 animate-pulse">⏳ Pending</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                @else
                    @foreach ($contents as $content)
                        <p class="mb-2">- {{ $content['review_content'] }}</p>
                    @endforeach
                    <div class="mt-6">
                        <button
                            wire:click="startReviewQuiz"
                            class="w-full py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 shadow transition-transform hover:scale-105 disabled:opacity-50"
                            @disabled(!$selectedModule)>
                            ▶️ Start Quiz
                        </button>
                    </div>
                @endif

            </div>
            

    {{-- COMPLETION SCREEN --}}
    @elseif ($completed || $status == "completed")
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
           @if (count($wrongQuestions ?? []) > 0)
            <div class="grid grid-cols-1 text-center mb-6">
                <div class="p-4 bg-red-100 rounded-lg">
                    <p class="text-sm text-gray-600 mb-6">Wrong Questions</p>
                    @foreach ($wrongQuestions as $Question)
                        <p class="text-sm text-red-700 mb-4">{{ $Question->question }}</p>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="flex flex-col sm:flex-row justify-center gap-4">
                @if ($difficulty === 'final')
                    @if ($currentIndex === $questions->count())
                        <!-- All review questions correct -->
                        <a href="{{ route('collection.index') }}"
                            class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow">
                            🎉 View Progress
                        </a>
                    @else
                        <!-- All review questions correct -->
                        <p>You have already completed this module!</p>
                        <a href="{{ route('collection.index') }}"
                            class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow">
                            🎉 View Progress
                        </a>
                    @endif
                @else
                    @if ($score === $questions->count())
                        <button wire:click="retryModule"
                            class="px-5 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 shadow">
                            🔁 Next Level
                        </button>
                    @else
                        <button wire:click="retryModule"
                            class="px-5 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 shadow">
                            🔁 Next Level
                        </button>
                    @endif
                @endif
            </div>

        </div>

    {{-- QUESTION SCREEN --}}
    @else
        @php $question = $questions[$currentIndex]; @endphp
                    
        {{-- Progress Bar --}}
        
        <div class="mb-4">
            
            <p class="w-full text-center py-3 text-gray-700 font-semibold">
            Proficiency: {{ $proficiency ?? 'link proficiency' }}
            </p>
            <div class="flex justify-between text-sm text-gray-600 mb-1">
                <span>Question {{ $currentIndex + 1 }} / {{ $questions->count() }}</span>
                <span>Level: {{ $difficulty }}</span>
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

                <div class="flex flex-col gap-3 mt-5">
                    <button type="submit"
                        class="w-full py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 shadow disabled:opacity-50 disabled:cursor-not-allowed transition"
                        :disabled="!$wire.answer">
                        Submit Answer
                    </button>
                </div>


            </form>
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