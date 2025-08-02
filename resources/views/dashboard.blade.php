<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>   
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    {{ __("Welcome $user->name You're logged in!") }}
                </div>
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Your Modules</h3>
                    @if($modules->isEmpty())
                        <p>You have no modules assigned.</p>
                    @else
                        <ul class="list-disc pl-5">
                            @foreach($modules as $module)
                                <li>
                                        {{ $module->name }} Score: ({{ $module->pivot->score }}) Status: {{ $module->pivot->status }}
                                </li>
                            @endforeach
                    @endif
            </div>
        </div>
    </div>

    <!-- created modules -->     
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($createdModules->isEmpty())
                        <p>You have not created any modules.</p>
                    @else
                        <h1 class="text-lg font-semibold mb-4">Created Modules</h1>
                            @foreach($createdModules as $module)
                                    {{ $module->name }} - {{ $module->description }}
                                    <form action="{{ route('modules.destroy', $module) }}" method="POST" onsubmit="return confirm('Are you sure?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="bg-red-600 text-white px-4 py-2 rounded">Delete</button>
                                    </form>
                                    <!-- Edit Button (GET method) -->
                                    <a href="{{ route('modules.edit', $module) }}" class="bg-green-600 text-white px-4 py-2 rounded inline-block ml-2">
                                        Edit
                                    </a>
                            @endforeach
                    @endif
                </div>  
            </div>
        </div>
    </div>

</x-app-layout>
