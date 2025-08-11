<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Edit Module: {{ $module->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-10">

            @foreach($modulePages as $moduleP)
                {!! $moduleP->content !!}
            @endforeach
            
            {{-- Update Module --}}
            <x-modules.update-form :module="$module" :all-questions="$allQuestions" />

            {{-- Create Question --}}
            <x-modules.create-question-form :module="$module" />
            
            {{-- Generate Landing Page --}}
            <x-modules.generate-landing-page :module="$module" :allQuestions="$allQuestions" />

            

        </div>
    </div>
</x-app-layout>
