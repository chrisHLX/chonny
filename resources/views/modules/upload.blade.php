<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-2xl mx-auto">

            <div class="mb-6">
                <h1 class="text-[17px] font-semibold text-ink">Upload Module</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">
                    Create a module + its quiz question bank from two files instead of the manual forms.
                    See <code>module-upload-format.md</code> in the repo for the exact format.
                </p>
            </div>

            <x-input-error :messages="$errors->get('upload')" class="mb-4" />
            <x-input-error :messages="$errors->get('content_file')" class="mb-4" />
            <x-input-error :messages="$errors->get('questions_file')" class="mb-4" />

            <div class="linear-card p-6">
                <form method="POST" action="{{ route('modules.upload.store') }}" enctype="multipart/form-data" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="content_file" :value="'Content file (.md)'" />
                        <input id="content_file" type="file" name="content_file" accept=".md" required
                               class="form-input mt-1.5">
                        <p class="text-[11px] text-ink-subtle mt-1">Front matter (title/subject/proficiency) + the module's page-1 markdown body.</p>
                    </div>

                    <div>
                        <x-input-label for="questions_file" :value="'Questions file (.yaml) — optional'" />
                        <input id="questions_file" type="file" name="questions_file" accept=".yaml,.yml"
                               class="form-input mt-1.5">
                        <p class="text-[11px] text-ink-subtle mt-1">Leave blank for knowledge-only content with no quiz yet.</p>
                    </div>

                    <div class="pt-2">
                        <x-primary-button>Upload</x-primary-button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
