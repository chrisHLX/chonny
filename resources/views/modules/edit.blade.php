<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-4xl mx-auto space-y-6">

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-[17px] font-semibold text-ink">{{ $module->name }}</h1>
                    @if($module->proficiencies()->first())
                        <p class="text-[13px] text-ink-muted mt-0.5">{{ $module->proficiencies()->first()->name }}</p>
                    @endif
                </div>
                <form action="{{ route('modules.destroy', $module) }}" method="POST"
                      onsubmit="return confirm('Delete this module?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger">Delete Module</button>
                </form>
            </div>

            {{-- Module pages --}}
            @foreach($modulePages as $modulePage)
                <div class="linear-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <p class="page-section-title">Page Content</p>
                        <div class="flex gap-2">
                            <form action="{{ route('modules.generate-questions', $module->id) }}" method="POST">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                    Generate AI Questions
                                </button>
                            </form>
                            <form action="{{ route('module-page.destroyPage', ['modulePage' => $modulePage->id]) }}"
                                  method="POST" onsubmit="return confirm('Delete this page?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger">Delete Page</button>
                            </form>
                        </div>
                    </div>
                    <div class="prose prose-invert max-w-none text-[13px]">
                        {!! Str::markdown($modulePage->content) !!}
                    </div>
                </div>
            @endforeach

            {{-- Edit module --}}
            <x-modules.update-form :module="$module" :all-questions="$allQuestions" />

            {{-- Add question --}}
            <x-modules.create-question-form :module="$module" :conceptsList="$conceptsList"/>

            {{-- Landing page --}}
            <x-modules.create-landing-page :modulePages="$modulePages" :module="$module" :allQuestions="$allQuestions" />

        </div>
    </div>
</x-app-layout>
