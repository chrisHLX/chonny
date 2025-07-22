<x-app-layout>
    <div class="max-w-4xl mx-auto p-4">
        <h1 class="text-2xl font-bold">Modules</h1>
        <p class="text-gray-600">Explore the available modules below.</p>

        <div class="mt-8 space-y-6">
            @foreach ($modules as $module)
                <div class="p-4 bg-white shadow rounded">
                    <h2 class="text-xl font-semibold">{{ $module->name }}</h2>
                    <p class="text-gray-600">{{ $module->description }}</p>
                    @foreach ($module->users as $user)
                        <span class="inline-block bg-blue-100 text-blue-800 text-xs font-semibold mr-2 px-2.5 py-0.5 rounded">{{ $user->name }}: {{ $user->pivot->score }}%</span>  
                    @endforeach

                    @if ($module->questions)
                        @foreach ($module->questions as $question)
                            <div class="mt-2">
                                <h3 class="text-lg font-medium">{{ $question->question }}</h3>
                                    <p class="text-gray-700">{{ json_encode($question->answer, JSON_PRETTY_PRINT) }}</p>
                            </div>
                        @endforeach
                    @endif
                </div>
            @endforeach
        </div>
</x-app-layout>
