@props(['module', 'conceptsList'])

<div class="bg-white text-gray-800 shadow-sm sm:rounded-lg p-6">
    <h3 class="text-lg text-gray-800 font-semibold mb-4">Add a New Question</h3>

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
        <select name="type" id="type" x-model="type"
            class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required>
            <option value="mcq">Multiple Choice</option>
            <option value="true_false">True / False</option>
            <option value="matching_pairs">Matching Pairs</option>
            <option value="ordering">Ordering</option>
        </select>

        {{-- Concepts --}}
        <div class="mt-4">
            <x-input-label for="concepts" :value="__('Concepts')" />
            <select id="concepts" name="concepts[]" multiple
                class="block w-full mt-1 border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 h-40">
                @foreach($conceptsList as $concept)
                    <option value="{{ $concept->id }}"
                        {{ (collect(old('concepts'))->contains($concept->id)) ? 'selected' : '' }}>
                        {{ $concept->name }}
                    </option>
                @endforeach
            </select>
            <p class="text-sm text-gray-500 mt-1">Hold Ctrl (Windows) or Command (Mac) to select multiple concepts.</p>
            <x-input-error :messages="$errors->get('concepts')" class="mt-1" />
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

        {{-- Matching Pairs --}}
        <div x-show="type === 'matching_pairs'" x-data="{ pairs: [ {}, {}, {}, {} ] }">
            <template x-for="(pair, i) in pairs" :key="i">
                <div class="flex space-x-2">
                    <input type="text" :name="'answer[correct][' + i + '][key]'" placeholder="Key"
                        class="w-1/2 border rounded p-2" />
                    <input type="text" :name="'answer[correct][' + i + '][value]'" placeholder="Value"
                        class="w-1/2 border rounded p-2" />
                </div>
            </template>

            <button type="button" @click="pairs.push({})"
                    class="mt-2 bg-gray-200 px-2 py-1 rounded">
                + Add Pair
            </button>
        </div>



        {{-- Ordering --}}
        <template x-if="type === 'ordering'">
            <div class="space-y-2">
                <template x-for="i in 4" :key="i">
                    <x-text-input type="text" :name="'answer[steps][]'" placeholder="Step" class="block w-full" />
                </template>
            </div>
        </template>



        <x-primary-button class="mt-4">Create Question</x-primary-button>
    </form>
</div>
