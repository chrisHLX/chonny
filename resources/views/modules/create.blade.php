<x-app-layout>
    <div class="max-w-4xl mx-auto p-4 space-y-6">
        <h2 class="text-2xl font-semibold mb-4">Create New Module</h2>

        <!-- Validation Errors -->
        <x-input-error :messages="$errors->all()" class="mb-4" />

        <form method="POST" action="{{ route('modules.store') }}" class="space-y-4">
            @csrf

            <!-- Module Name -->
            <div>
                <x-input-label for="name" :value="__('Module Name')" />
                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <!-- Description -->
            <div>
                <x-input-label for="description" :value="__('Description')" />
                <x-text-input id="description" class="block mt-1 w-full" type="text" name="description" :value="old('description')" required />
                <x-input-error :messages="$errors->get('description')" class="mt-1" />
            </div>

            <!-- Subject Selection -->
            <div>
                <x-input-label for="subject_id" :value="__('Subject')" />
                <select id="subject_id" name="subject_id" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                    <option value="">-- Select Subject --</option>
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" {{ old('subject_id') == $subject->id ? 'selected' : '' }}>
                            {{ $subject->name }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('subject_id')" class="mt-1" />
            </div>

            <!-- Submit Button -->
            <div class="pt-4">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded shadow">
                    Create Module
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
