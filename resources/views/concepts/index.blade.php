<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-2xl text-white leading-tight">
                {{ __('Concepts') }}
            </h2>
        </div>
    </x-slot>
    
    <div class="bg-gray-900 text-gray-200 min-h-screen py-10">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 space-y-6">
            <!-- Welcome Card -->
            <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
                <h1 class="text-3xl font-bold mb-2">{{ $user->name }} is at Concept Level Here</h1>
                <p class="text-blue-100">
                    Ready to keep improving your knowledge? Let’s dive in. Add a Module to get started! -- module link here -- 
                </p>
                <p class="text-gray-400 mt-4">
                    You have mastered X out of Y concepts. <-- put user progress or points for the subject here
                </p>
            </div>
            <x-context-bar :subjects="$subjects" :current-subject-id="$currentSubjectId" :category-id="$categoryId" />
            @foreach($concepts as $concept)
                <div class="bg-gray-800 rounded-2xl shadow-lg p-6 border border-gray-700/40">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold text-white">{{ $concept->name }}</h3>
                    </div>

                    @if($concept->description)
                        <p class="text-gray-400 mt-2">{{ $concept->description }}</p>
                    @endif

                    @if($concept->questions->isNotEmpty())
                        <div class="mt-4 flex flex-wrap gap-2">
                            <span class="text-blue-400 font-semibold text-sm">
                                {{ $concept->questions->count() }} Available Points
                            </span>
                        </div>
                    @else
                        <p class="mt-2 text-gray-500 italic">No questions tagged for this concept yet.</p>
                    @endif
                </div>
            @endforeach

        </div>
    </div>
</x-app-layout>
