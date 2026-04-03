<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Edit Module: {{ $module->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-10">
        
            <div class="bg-white text-gray-800 shadow-sm sm:rounded-lg p-6">
                @foreach($modulePages as $modulePage)
                    {!! Str::markdown($modulePage->content) !!}

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
                @endforeach
        </div>
                
            {{-- Update Module --}}
            <x-modules.update-form :module="$module" :all-questions="$allQuestions" />

            {{-- Create Question --}}
            <x-modules.create-question-form :module="$module" :conceptsList="$conceptsList"/>
            
            {{-- Create Landing Page --}}
            <x-modules.create-landing-page :modulePages="$modulePages" :module="$module" :allQuestions="$allQuestions" />

            {{-- Generate Landing Page --}}
            <x-modules.generate-landing-page :module="$module" :allQuestions="$allQuestions" />

            

        </div>
    </div>
</x-app-layout>
