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
                <div class="linear-card overflow-hidden">
                    <div class="px-6 pt-5 pb-4 border-b border-border flex items-start justify-between gap-4">
                        <div>
                            <p class="page-section-title">Content Preview</p>
                            <p class="page-section-desc mt-0.5">Rendered view of the source material used to generate questions.</p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <form action="{{ route('module-page.destroyPage', ['modulePage' => $modulePage->id]) }}"
                                  method="POST" onsubmit="return confirm('Delete this page?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger">Delete Page</button>
                            </form>
                        </div>
                    </div>
                    <div class="px-6 py-5 prose prose-invert max-w-none text-[13px]">
                        {!! Str::markdown($modulePage->content) !!}
                    </div>
                </div>
            @endforeach

            {{-- Edit module --}}
            <x-modules.update-form :module="$module" :all-questions="$allQuestions" />

            {{-- Generate questions --}}
            <x-modules.generate-questions-form :module="$module" :modulePages="$modulePages" />

            {{-- Landing page --}}
            <x-modules.create-landing-page :modulePages="$modulePages" :module="$module" :allQuestions="$allQuestions" />

        </div>
    </div>
</x-app-layout>
