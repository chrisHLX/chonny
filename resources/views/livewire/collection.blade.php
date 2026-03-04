<div class="py-6 max-w-5xl mx-auto text-gray-600 space-y-8">

    <!-- HEADER -->
    <h2 class="text-3xl font-bold text-white mt-6">
        📊 Your Learning Collection
    </h2>

     {{-- Subject Toggle Component --}}
    <x-context-bar-livewire :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" routeName="collection.index"/>


    <!-- CARDS -->
    <div class="py-6 space-y-4">
        <h2 class="text-2xl font-bold text-white">Your Cards</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse($this->cards as $card)
                <div 
                    wire:click="selectCard({{ $card->id }})"
                    class="cursor-pointer transition transform hover:scale-[1.02]
                        h-full flex
                        {{ $selectedCardId === $card->id 
                            ? 'ring-4 ring-blue-500 rounded-xl' 
                            : '' }}"
                >
                    <x-card :card="$card" class="flex-1 w-full bg-white"/>
                </div>
            @empty
                <p class="text-gray-400">
                    You haven't generated any cards yet. Complete a module to get one.
                </p>
            @endforelse
        </div>
    </div>

    {{-- If no card selected, don't show data --}}
    @if($selectedCardId)

        <!-- MODULE PROGRESS -->
        <div class="bg-white shadow-lg p-6 rounded-lg">
            <h3 class="text-xl font-bold mb-4 border-b pb-2">
                Module Progress
            </h3>

            @forelse ($this->modules as $module)
                <div class="mb-6">
                    <div class="flex justify-between items-center">
                        <div class="font-semibold text-lg">
                            {{ $module->name }}
                        </div>

                        <span class="text-sm px-2 py-1 rounded
                            {{ $module->pivot->score >= 80 
                                ? 'bg-green-100 text-green-800' 
                                : 'bg-yellow-100 text-yellow-800' }}">
                            {{ ucfirst($module->pivot->status) }}
                        </span>
                    </div>

                    <div class="text-sm text-gray-600 mb-2">
                        {{ $module->description }}
                    </div>

                    <!-- Progress Bar -->
                    <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                        <div class="h-4 rounded-full bg-blue-500 transition-all duration-500"
                             style="width: {{ $module->pivot->score }}%">
                        </div>
                    </div>

                    <div class="text-sm text-gray-700 mt-1">
                        Score: <strong>{{ $module->pivot->score }}%</strong>
                    </div>
                </div>
            @empty
                <p class="text-gray-500">
                    No modules linked to this card yet.
                </p>
            @endforelse
        </div>

        {{-- QUESTION TABS --}}
@if($selectedCardId)
    <div class="bg-white shadow-lg text-gray-600 p-6 rounded-lg">
        <h3 class="text-xl font-bold mb-4 border-b pb-2">
            Questions
        </h3>

        {{-- Buttons / Tabs --}}
        <div class="flex space-x-2 mb-4">
            <button
                wire:click="$set('activeQuestionTab', 'history')"
                class="px-4 py-2 rounded-md font-semibold transition
                    {{ $activeQuestionTab === 'history'
                        ? 'bg-blue-500 text-white'
                        : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                Question History
            </button>

            <button
                wire:click="$set('activeQuestionTab', 'wrong')"
                class="px-4 py-2 rounded-md font-semibold transition
                    {{ $activeQuestionTab === 'wrong'
                        ? 'bg-blue-500 text-white'
                        : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                Wrong Questions
            </button>
        </div>

        {{-- TAB CONTENT --}}
        <div class="space-y-4">
            {{-- Question History --}}
            @if($activeQuestionTab === 'history')
                @forelse ($this->answeredQuestions as $question)
                    <div class="mb-4 p-4 border rounded-lg hover:shadow transition duration-200">
                        <div class="font-medium text-gray-800 mb-1">
                            {{ $question->question }}
                        </div>

                        <div class="flex flex-wrap items-center gap-4 text-sm text-gray-700 mb-1">
                            <span class="px-2 py-1 bg-gray-100 rounded">
                                Attempts: {{ $question->pivot->attempts }}
                            </span>

                            <span class="px-2 py-1 bg-green-100 rounded">
                                Correct: {{ $question->pivot->correct_count }}
                            </span>

                            <span class="px-2 py-1 bg-blue-100 rounded">
                                Accuracy:
                                {{ $question->pivot->attempts > 0
                                    ? round(($question->pivot->correct_count / $question->pivot->attempts) * 100, 1)
                                    : 0 }}%
                            </span>

                            <span class="px-2 py-1 bg-purple-100 rounded">
                                Time: {{ $question->pivot->total_time_spent }}s
                            </span>
                        </div>

                        <div class="text-xs text-gray-500 flex flex-wrap gap-2">
                            @if($question->concepts->isNotEmpty())
                                <span class="bg-yellow-100 px-2 py-0.5 rounded">
                                    Concepts:
                                    {{ $question->concepts->pluck('name')->join(', ') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500">No questions attempted for this card yet.</p>
                @endforelse
            @endif

            {{-- Wrong Questions --}}
            @if($activeQuestionTab === 'wrong')
                @forelse ($this->wrongQuestions as $question)
                    <div class="mb-4 p-4 border rounded-lg hover:shadow transition duration-200">
                        <div class="font-medium text-gray-800 mb-1">
                            {{ $question->question }}
                        </div>

                        @php
                            $content = $question->contents->first();
                        @endphp

                        @if($content)
                            <div class="text-sm text-gray-700 mb-1">
                                {{ $content->content }}
                            </div>

                            <div class="font-medium text-gray-800 mb-1">
                                Source {{ $content->source }}
                            </div>
                        @endif
                        <!--
                        <button
                            wire:click="regenerateExplanation({{ $question->id }})"
                            class="px-4 py-2 rounded-md font-semibold transition
                                {{ $activeQuestionTab === 'wrong'
                                    ? 'bg-blue-500 text-white'
                                    : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                            Regenerate Explanation
                        </button>
                                -->
                    </div>
                @empty
                    <p class="text-gray-500">No wrong questions for this card.</p>
                @endforelse
            @endif
        </div>
    </div>
@endif

    @endif
</div>
