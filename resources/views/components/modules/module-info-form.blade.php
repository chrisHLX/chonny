@props(['module'])

<div class="bg-white shadow-sm sm:rounded-lg p-6">
    <h3 class="text-lg font-semibold mb-4">Module Information</h3>

    <form method="POST" action="{{ route('modules.update', $module) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="name" :value="'Module Name'" />
            <x-text-input id="name" name="name" type="text" class="block mt-1 w-full"
                :value="old('name', $module->name)" required autofocus />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="description" :value="'Description'" />
            <textarea id="description" name="description" rows="4"
                class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50">{{ old('description', $module->description) }}</textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-2" />
        </div>

        <x-primary-button>Update Module</x-primary-button>
    </form>
</div>
