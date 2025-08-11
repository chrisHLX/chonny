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
                <!-- Display user concepts and mastery -->
                 <div class="p-6">
                    @foreach($concepts as $concept)
                        <div class="mb-4">
                            <div class="flex justify-between">
                                <span class="font-semibold">{{ $concept->name }}</span>
                                <span>{{ $concept->mastery_for_user }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-4">
                                <div class="bg-green-500 h-4 rounded-full" style="width: {{ $concept->mastery_for_user }}%"></div>
                            </div>
                        </div>
                    @endforeach

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
    </div>
<!-- created modules -->
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                @if ($createdModules->isEmpty())
                    <p class="mb-4">You have not created any modules.</p>
                    <a href="{{ route('modules.create') }}" 
                       class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md shadow">
                        Create Module
                    </a>
                @else
                    <h1 class="text-lg font-semibold mb-6">Created Modules</h1>

                    <!-- Grid layout for modules -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($createdModules as $module)
                            <div class="border rounded-lg p-4 shadow-sm bg-gray-50 flex flex-col justify-between">
                                <div>
                                    <h2 class="text-md font-bold text-gray-800">{{ $module->name }}</h2>
                                    <p class="text-gray-600 text-sm mt-1">{{ $module->description }}</p>
                                </div>
                                <div class="mt-4 flex gap-2">
                                    <!-- Delete Form -->
                                    <form action="{{ route('modules.destroy', $module) }}" method="POST" 
                                          onsubmit="return confirm('Are you sure?');" class="flex-1">
                                        @csrf
                                        @method('DELETE')
                                        <button class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md shadow">
                                            Delete
                                        </button>
                                    </form>
                                    <!-- Edit Button -->
                                    <a href="{{ route('modules.edit', $module) }}" 
                                       class="flex-1 text-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md shadow">
                                        Edit
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Create button at bottom -->
                    <div class="mt-6">
                        <a href="{{ route('modules.create') }}" 
                           class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md shadow">
                            Create Module
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

</x-app-layout>
