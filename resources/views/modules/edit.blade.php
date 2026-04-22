<x-app-layout>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-10">
            <h1 class="text-4xl">Module Name: {{ $module->name }}</h1>
            <h1 class="text-2xl">Proficiency Level: {{ $module->proficiencies()?->first()->name }}</h1>
            <div class="bg-white text-gray-800 shadow-sm sm:rounded-lg p-6">
                
                @foreach($modulePages as $modulePage)
                <div class="prose">    
                    {!! Str::markdown($modulePage->content) !!}
                </div>

                    <form action="{{ route('module-page.destroyPage', ['modulePage' => $modulePage->id]) }}"
                        method="POST" class="mt-2"
                        onsubmit="return confirm('Delete this page?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                            Delete Page
                        </button>
                    </form>

                    <form action="{{ route('modules.generate-questions', $module->id) }}" method="POST">
                        @csrf
                        <x-primary-button>
                            ✨ Generate AI Questions from Content
                        </x-primary-button>
                    </form>
                @endforeach
        </div>
                
            {{-- Update Module --}}
            <x-modules.update-form :module="$module" :all-questions="$allQuestions" />

            {{-- Create Question --}}
            <x-modules.create-question-form :module="$module" :conceptsList="$conceptsList"/>
            
            {{-- Create Landing Page --}}
            <x-modules.create-landing-page :modulePages="$modulePages" :module="$module" :allQuestions="$allQuestions" />

            
        <form action="{{ route('modules.destroy', $module) }}"
                        method="POST" class="mt-2"
                        onsubmit="return confirm('Delete this module?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                            Delete Module
                        </button>
        </form>
        </div>
    </div>
</x-app-layout>
