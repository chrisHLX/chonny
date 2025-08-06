@props(['module'])

<div class="bg-white shadow-sm sm:rounded-lg p-6">
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

        {{-- True/False --}}
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

        <x-primary-button class="mt-4">Create Question</x-primary-button>
    </form>
</div>
