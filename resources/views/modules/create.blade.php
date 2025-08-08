<x-app-layout>
    <div class="max-w-4xl mx-auto p-4">
        <x-input-error :messages="$errors->get('name')" />
        <form method="POST" action="{{ route('modules.store') }}">
            @csrf
   
            <div>
                <x-input-label for="Name" :value="__('Module Name')" />
                <x-text-input id="Name" class="block mt-1 w-full" type="text" name="name" />
            </div>
            
            <div>
                <x-input-label for="description" :value="__('Description')" />
                <x-text-input id="description" class="block mt-1 w-full" type="text" name="description" />
            </div>

            <div>
                <br/>
                <button class="bg-blue-600 text-white px-4 py-2 rounded">Create Module</button>
            </div>
        </form>
    </div>
</x-app-layout>
