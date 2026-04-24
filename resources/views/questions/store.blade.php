<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-xl mx-auto">

            <div class="mb-6">
                <h1 class="text-[17px] font-semibold text-ink">Add Question</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Module: {{ $module->name }}</p>
            </div>

            <div class="linear-card p-6">
                <form action="{{ route('questions.store') }}" method="POST" class="space-y-5">
                    @csrf
                    <input type="hidden" name="module_id" value="{{ $module->id }}">

                    <div>
                        <x-input-label :value="'Question Text'" />
                        <x-text-input type="text" name="question" required />
                    </div>

                    <div>
                        <x-input-label :value="'Correct Answer'" />
                        <x-text-input type="text" name="correct_answer" required />
                    </div>

                    <div>
                        <x-input-label :value="'Other Answers (optional)'" />
                        <div class="space-y-2 mt-1.5">
                            <x-text-input type="text" name="wrong_answers[]" placeholder="Option 2" />
                            <x-text-input type="text" name="wrong_answers[]" placeholder="Option 3" />
                            <x-text-input type="text" name="wrong_answers[]" placeholder="Option 4" />
                        </div>
                    </div>

                    <x-primary-button>Save Question</x-primary-button>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
