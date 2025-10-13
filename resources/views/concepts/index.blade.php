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

            @foreach($concepts as $concept)
                <div class="bg-gray-800 rounded-2xl shadow-lg p-6 border border-gray-700/40">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold text-white">{{ $concept->name }}</h3>
                        @if($concept->questions->isNotEmpty())
                            <span class="text-blue-400 font-semibold text-sm">
                                {{ $concept->questions->count() }} Questions
                            </span>
                        @endif
                    </div>

                    @if($concept->description)
                        <p class="text-gray-400 mt-2">{{ $concept->description }}</p>
                    @endif

                    @if($concept->questions->isNotEmpty())
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($concept->questions as $question)
                                <span class="bg-blue-600 text-white text-sm px-3 py-1 rounded-full shadow-sm hover:bg-blue-500 transition">
                                    {{ $question->question }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-2 text-gray-500 italic">No questions tagged for this concept yet.</p>
                    @endif
                </div>
            @endforeach

        </div>
    </div>
</x-app-layout>
