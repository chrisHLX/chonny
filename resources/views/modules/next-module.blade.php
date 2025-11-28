<x-app-layout>
    <div class="max-w-3xl mx-auto p-6">

        <h1 class="text-3xl font-bold text-white mb-6">
            Recommended Next Modules
        </h1>

        @if(empty($suggestions))
            <p class="text-gray-300">No suggestions were found for this module.</p>
        @else

            <div class="space-y-6">

                @foreach ($suggestions as $s)
                    <div class="bg-gray-800 p-6 rounded-xl shadow-md border border-gray-700">

                        {{-- Module Name --}}
                        <h3 class="text-2xl font-semibold text-white mb-2">
                            {{ $s['name'] }}
                        </h3>

                        {{-- Description --}}
                        <p class="text-gray-300 mb-4">
                            {{ $s['description'] }}
                        </p>

                        {{-- Meta Info --}}
                        <div class="text-sm text-gray-400 mb-6">
                            <span class="mr-2">📘 {{ $s['subject'] }}</span>
                            <span>🎯 {{ $s['proficiency'] }}</span>
                        </div>

                        {{-- Add Module Button --}}
                 
                            <button
                                type="submit"
                                class="bg-green-500 hover:bg-green-600 text-white font-semibold px-5 py-2 rounded-lg">
                                ➕ Add This Module
                            </button>
                      

                    </div>
                @endforeach

            </div>

        @endif

    </div>
</x-app-layout>
