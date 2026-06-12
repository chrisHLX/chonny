@props(['module', 'allQuestions'])

<div>
    <h1 class="font-display text-[18px] font-bold text-ink mb-1">Generate an Introductory Guide</h1>
    <p class="text-[12px] text-ink-muted mb-5">{{ $module->name }}</p>
</div>

<div class="linear-card p-5">
    <h3 class="text-[13px] font-semibold text-ink mb-1.5">Landing Page Generator</h3>
    <p class="text-[12px] text-ink-muted leading-relaxed mb-4">
        Write a short description of what this guide will cover. For example:
        <em class="text-ink-subtle">"This module is designed to help new StarCraft players understand Protoss fundamentals, including build orders, unit compositions, and economy management."</em>
    </p>

    <form action="{{ route('modules.generateLandingPage', $module) }}" method="POST" class="space-y-4">
        @csrf
        <div>
            <x-input-label for="description" :value="'Guide Summary / AI Prompt'" />
            <textarea name="description" id="descriptionC" rows="5" class="form-textarea mt-1"
                      placeholder="Explain what the guide should focus on…"></textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
        </div>
        <button type="submit" class="btn-primary">
            Create Landing Page
        </button>
    </form>
</div>
