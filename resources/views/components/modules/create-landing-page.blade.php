@props(['modulePages', 'module', 'allQuestions'])

<div class="linear-card p-6">
    <h3 class="page-section-title mb-1">Landing Page</h3>
    <p class="page-section-desc">
        Write a description for the AI to generate an introductory guide for <strong class="text-ink-muted">{{ $module->name }}</strong>.
        E.g. "Introduce key Protoss strategies for beginners."
    </p>

    <form action="{{ route('modules-pagex.createLandingPage', $module->id) }}" method="POST" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="descriptionC" :value="'Guide Summary / AI Prompt'" />
            <textarea name="description" id="descriptionC" rows="5"
                      class="form-textarea mt-1.5"
                      placeholder="Describe what the guide should cover...">{{ old('description', $modulePages->first()?->content) }}</textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
        </div>

        <x-primary-button>Create Page</x-primary-button>
    </form>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>
