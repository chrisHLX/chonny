<x-app-layout>
    <div class="max-w-4xl mx-auto p-4">
        <h1 class="text-2xl font-bold">Modules</h1>
        <p class="text-blue-100">Explore the available modules below.</p>

        <div class="mt-8 space-y-6">
            @foreach ($modules as $module)
                <div class="p-4 bg-gray-800 shadow rounded">
                    <h2 class="text-xl font-semibold">{{ $module->name }} </h2>
                    <h2 class="text-l font-semibold">Proficiency Level: {{ $module->proficiencies()->first()->name ?? 'None' }}</h2>
                    <p class="text-gray-400">{{ $module->description }}</p>
                    

                    {{-- Display Users and Scores --}}
                    @foreach ($module->users as $user)
                        <span class="inline-block bg-blue-100 text-blue-800 text-xs font-semibold mr-2 px-2.5 py-0.5 rounded">
                            {{ $user->name }}: {{ $user->pivot->score }}%
                        </span>  
                    @endforeach

                    {{-- Add Button if user doesn't already have this module --}}
                    @if (! $module->users->contains(Auth::user()))
                        <form action="{{ route('modules.assign', $module->id) }}" method="POST" class="mt-4">
                            @csrf
                            <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
                                Add Module
                            </button>
                            @if ($module->created_by === Auth::id())
                                <button class="bg-gray-600 text-white px-4 py-2 rounded">Delete</button>
                            @endif
                            
                        </form>
                    @endif

                    {{-- Pages --}}
                    @if ($module->modulePages && !$module->modulePages->isEmpty())
                        <form action="{{ route('modules.page', $module->id) }}" method="GET" class="mt-4">
                            @csrf
                            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                                View Pages
                            </button>
                        </form>
                    @else
                        <h1>No Pages</h1>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
