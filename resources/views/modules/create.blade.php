<x-app-layout>
<div class="bg-gray-900 text-gray-200 min-h-screen py-10">
    <div class="max-w-7xl mx-auto px-6 lg:px-8 space-y-10">
        <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
            <div class="max-w-4xl mx-auto p-4 space-y-6">
                <h2 class="text-2xl font-semibold mb-4">Create New Module</h2>

                <!-- Validation Errors -->
                <x-input-error :messages="$errors->all()" class="mb-4" />

                <form method="POST" action="{{ route('modules.store') }}" class="space-y-4">
                    @csrf

                    <!-- Module Name -->
                    <div>
                        <x-input-label class="text-white" for="name" :value="__('Module Name')" />
                        <x-text-input class="text-gray-700" id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <!-- Description -->
                    <div>
                        <x-input-label class="text-white" for="description" :value="__('Description')" />
                        <x-text-input class="text-gray-700" id="description" class="block mt-1 w-full" type="text" name="description" :value="old('description')" required />
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>

                    <!-- Subject Selection -->
                    <div>
                        <x-input-label class="text-white" for="subject_id" :value="__('Subject')" />
                        <select id="subject_id" name="subject_id" class="text-gray-700 block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            <option value="">-- Select Subject --</option>
                            @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}" {{ old('subject_id') == $subject->id ? 'selected' : '' }}>
                                    {{ $subject->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('subject_id')" class="mt-1" />
                    </div>
                    <!-- Need to add a field here that requires the subject_id selected above in order to show the right 
                     proficiencies. Why? Because the proficiency changes depending on the subject the user wants to create 
                     the module for. 

                     So I kind of want to do something like the following
                    @if( $subject->id == 1 )  that means the subject is sc2
                        {{ $items = proficiencies::where('subject_id', $subject->id }}
                        foreach($items as item)
                            {
                                <select>$item</select>
                            }
                    @endif

                    thats my psuedo code what is should produce is a selectable list of proficiencies
                    beginner
                    casual
                    intermediate
                    advanced
                    expert

                    then I will send that through witht the form data and attach proficiencies to the newly created module
                    -->
                    <!-- Submit Button -->
                    <div class="pt-4">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded shadow">
                            Create Module
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
