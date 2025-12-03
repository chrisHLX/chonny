<x-app-layout>
    <h1>this is the collection Page</h2>
    @foreach ($modules as $module)
        <div class="bg-gray-800 p-4 rounded shadow mb-4">
            <h3 class="text-lg font-semibold">{{ $module->name }}</h3>
            <p class="text-gray-300">{{ $module->description }}</p>
            <small class="text-gray-500">{{ $module->subject }} — {{ $module->proficiency }}</small>
        </div>
    @endforeach
    
</x-app-layout>