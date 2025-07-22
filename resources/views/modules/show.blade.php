<x-app-layout>
    <div class="max-w-4xl mx-auto p-4">
        <h1 class="text-2xl font-bold">{{ $module->name }}</h1>
        <p class="text-gray-600">{{ $module->description }}</p>

        <form method="POST" action="{{ route('modules.start', $module) }}" class="mt-4">
            @csrf
            <button class="px-4 py-2 bg-green-600 text-white rounded">Start Module</button>
        </form>

        <div class="mt-8">
            <h2 class="text-xl font-semibold mb-2">Practice Questions (Easy)</h2>
            <ul class="space-y-2">
                @foreach ($questions as $question)
                    <li class="p-3 bg-white shadow rounded">
                        {{ $question->question }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>
