<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Edit Module: {{ $module->name }}
        </h2>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto space-y-8">
        {{-- Update Module --}}
        <form method="POST" action="{{ route('modules.update', $module) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block font-medium">Module Name</label>
                <input type="text" name="name" id="name" value="{{ old('name', $module->name) }}" class="w-full border px-2 py-1">
                @error('name') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="description" class="block font-medium">Description</label>
                <textarea name="description" id="description" class="w-full border px-2 py-1" rows="4">{{ old('description', $module->description) }}</textarea>
                @error('description') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="question_ids" class="block font-medium">Attach Existing Questions</label>
                <select name="question_ids[]" id="question_ids" multiple class="w-full border px-2 py-1">
                    @foreach($allQuestions->sortBy('question') as $question)
                        <option value="{{ $question->id }}" {{ $module->questions->contains($question->id) ? 'selected' : '' }}>
                            {{ $question->question }}
                        </option>
                    @endforeach
                </select>
                @error('question_ids') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Update Module</button>
        </form>

        <hr>

        {{-- Add New Question --}}
        <h3 class="text-lg font-semibold mb-2">Add a New Question</h3>

        <form method="POST" action="{{ route('questions.store') }}" x-data="{ type: 'multiple_choice' }">
            @csrf
            <input type="hidden" name="module_id" value="{{ $module->id }}">

            <div class="mb-4">
                <label for="question_type">Question Type</label>
                <select name="question_type" id="question_type" x-model="type" class="w-full border px-2 py-1">
                    <option value="multiple_choice">Multiple Choice</option>
                    <option value="true_false">True / False</option>
                    <option value="open">Open</option>
                </select>
            </div>

            <div class="mb-4">
                <label for="question_text">Question</label>
                <input type="text" name="question_text" id="question_text" class="w-full border px-2 py-1" required>
            </div>

            {{-- Multiple Choice --}}
            <div x-show="type === 'multiple_choice'" class="mb-4 space-y-2">
                <label for="correct_option">Correct Answer</label>
                <input type="text" name="correct_option" class="w-full border px-2 py-1" placeholder="Correct answer">

                <label>Wrong Answers</label>
                <input type="text" name="wrong_options[]" class="w-full border px-2 py-1" placeholder="Wrong option 1">
                <input type="text" name="wrong_options[]" class="w-full border px-2 py-1" placeholder="Wrong option 2">
                <input type="text" name="wrong_options[]" class="w-full border px-2 py-1" placeholder="Wrong option 3">
            </div>

            {{-- True / False --}}
            <div x-show="type === 'true_false'" class="mb-4">
                <label for="answer_true_false">Answer</label>
                <select name="answer" id="answer_true_false" class="w-full border px-2 py-1">
                    <option value="true">True</option>
                    <option value="false">False</option>
                </select>
            </div>

            {{-- Open Question --}}
            <div x-show="type === 'open'" class="mb-4">
                <label for="ideal_answer">Ideal Answer</label>
                <textarea name="answer" id="ideal_answer" class="w-full border px-2 py-1" rows="3"></textarea>
            </div>

            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded mt-2">Add Question</button>
        </form>

    </div>
</x-app-layout>
