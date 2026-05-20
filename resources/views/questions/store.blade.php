<x-app-layout>
    <div class="max-w-2xl mx-auto mt-10 bg-white p-6 rounded shadow">
        <h2 class="text-xl font-semibold mb-4">Add a Question to Module: {{ $module->name }}</h2>

        <form action="{{ route('questions.store') }}" method="POST">
            @csrf

            <input type="hidden" name="module_id" value="{{ $module->id }}">

            <div class="mb-4">
                <label class="block text-gray-700">Question Text</label>
                <input type="text" name="question" class="w-full border border-gray-300 p-2 rounded" required>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700">Correct Answer</label>
                <input type="text" name="correct_answer" class="w-full border border-gray-300 p-2 rounded" required>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700">Other Answers (optional)</label>
                <input type="text" name="wrong_answers[]" class="w-full border border-gray-300 p-2 rounded mb-2">
                <input type="text" name="wrong_answers[]" class="w-full border border-gray-300 p-2 rounded mb-2">
                <input type="text" name="wrong_answers[]" class="w-full border border-gray-300 p-2 rounded">
            </div>

            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Question</button>
        </form>
    </div>
</x-app-layout>