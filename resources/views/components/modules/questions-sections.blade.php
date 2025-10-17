@props(['module', 'questions'])

<div class="space-y-6">
    {{-- Add Question Form --}}
    <x-modules.create-question-form :module="$module" />

    {{-- Attach Existing Questions --}}
    <x-modules.attach-questions :module="$module" :all-questions="$questions" />
</div>
