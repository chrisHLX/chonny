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
                    {{ __("Welcome $user->name You're logged in! your id is $user->id") }}
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
</x-app-layout>
