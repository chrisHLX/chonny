<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Edit Module: {{ $module->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-10">

            {{-- Update Module --}}
            <div class="bg-white overflow-visable shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('modules.update', $module) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" :value="'Module Name'" />
                        <x-text-input id="name" class="block mt-1 w-full" type="text" name="name"
                            :value="old('name', $module->name)" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="'Description'" />
                        <textarea id="description" name="description" rows="4" class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50">{{ old('description', $module->description) }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    @php
                        $selectedIds = old('question_ids', $module->questions->pluck('id')->toArray());
                    @endphp

                    <div x-data="{ open: false }" class="relative">
                        <x-input-label for="question_ids" :value="'Attach Existing Questions'" />

                        <div @click="open = !open" class="cursor-pointer border rounded-md p-2 bg-white shadow-sm">
                            <span x-text="'Selected: ' + {{ json_encode($selectedIds) }}.length + ' questions'"></span>
                        </div>

                        <div x-show="open" @click.away="open = false" class="absolute bg-white border mt-1 rounded-md shadow-lg z-50 max-h-60 overflow-y-auto w-full">
                            @foreach($allQuestions->sortBy('question') as $question)
                                <label class="flex items-start p-2 space-x-2 hover:bg-gray-100">
                                    <input type="checkbox"
                                        name="question_ids[]"
                                        value="{{ $question->id }}"
                                        {{ in_array($question->id, $selectedIds) ? 'checked' : '' }}
                                        class="rounded text-indigo-600 border-gray-300 focus:ring-indigo-500" />
                                    <span class="text-sm text-gray-700">{{ $question->question }}</span>
                                </label>
                            @endforeach
                        </div>

                        <x-input-error :messages="$errors->get('question_ids')" class="mt-2" />
                    </div>


                    <x-primary-button>
                        Update Module
                    </x-primary-button>
                </form>
            </div>

            {{-- Add New Question --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Add a New Question</h3>

                <form action="{{ route('questions.store') }}" method="POST" x-data="{ type: 'mcq' }" class="space-y-6">
                    @csrf
                    <input type="hidden" name="module_id" value="{{ $module->id }}">

                    {{-- Question --}}
                    <div>
                        <x-input-label for="question" :value="'Question'" />
                        <x-text-input type="text" name="question" id="question" class="block mt-1 w-full" required />
                        <x-input-error :messages="$errors->get('question')" class="mt-2" />
                    </div>

                    {{-- Type --}}
                    <div>
                        <x-input-label for="type" :value="'Type'" />
                        <select name="type" id="type" x-model="type"
                            class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required>
                            <option value="mcq">Multiple Choice</option>
                            <option value="true_false">True / False</option>
                            <option value="open">Open Ended</option>
                        </select>
                    </div>

                    {{-- Difficulty --}}
                    <div>
                        <x-input-label for="difficulty" :value="'Difficulty'" />
                        <select name="difficulty" id="difficulty"
                            class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="easy" selected>Easy</option>
                            <option value="medium">Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </div>

                    {{-- MCQ --}}
                    <template x-if="type === 'mcq'">
                        <div class="space-y-4">
                            <div>
                                <x-input-label :value="'Correct Answer'" />
                                <x-text-input type="text" name="answer[correct]" class="block mt-1 w-full" />
                            </div>

                            <div>
                                <x-input-label :value="'Incorrect Answers'" />
                                <div class="space-y-2">
                                    <x-text-input type="text" name="answer[incorrect][]" class="block w-full" />
                                    <x-text-input type="text" name="answer[incorrect][]" class="block w-full" />
                                    <x-text-input type="text" name="answer[incorrect][]" class="block w-full" />
                                </div>
                            </div>
                        </div>
                    </template>


                    {{-- True / False --}}
                    <template x-if="type === 'true_false'">
                        <div>
                            <x-input-label :value="'Correct Answer'" />
                            <select name="answer[correct]"
                                class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="1">True</option>
                                <option value="0">False</option>
                            </select>
                        </div>
                    </template>


                    {{-- Open Ended --}}
                    <template x-if="type === 'open'">
                        <div>
                            <x-input-label :value="'Expected Answer'" />
                            <textarea name="answer[text]" rows="3"
                                class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50"></textarea>
                        </div>
                    </template>


                    <x-primary-button class="mt-4">
                        Create Question
                    </x-primary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
