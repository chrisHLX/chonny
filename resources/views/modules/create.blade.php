<x-app-layout>
<div class="bg-gray-900 text-gray-700 min-h-screen py-10">
    <div class="max-w-7xl mx-auto px-6 lg:px-8 space-y-10">
        <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
            <div class="max-w-4xl mx-auto p-4 space-y-6">
                <h2 class="text-2xl text-gray-200 font-semibold mb-4">Create New Module</h2>

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
                    <!-- Proficiency Selection -->
                    <div>
                        <x-input-label class="text-white" for="proficiency_id" :value="__('Proficiency')" />

                        <select id="proficiency_id" name="proficiency_id"
                            class="text-gray-700 block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            <option value="">-- Select Subject First --</option>
                        </select>

                        <x-input-error :messages="$errors->get('proficiency_id')" class="mt-1" />
                    </div>
                    <script>
                        document.getElementById('subject_id').addEventListener('change', function () {
                            const subjectId = this.value;

                            // Clear current proficiencies
                            const proficiencySelect = document.getElementById('proficiency_id');
                            proficiencySelect.innerHTML = '<option value="">Loading...</option>';

                            if(!subjectId) {
                                proficiencySelect.innerHTML = '<option value="">-- Select Subject First --</option>';
                                return;
                            }

                            fetch(`/proficiencies/by-subject/${subjectId}`)
                                .then(response => response.json())
                                .then(data => {
                                    let options = '<option value="">-- Select Proficiency --</option>';

                                    data.forEach(item => {
                                        options += `<option value="${item.id}">${item.name}</option>`;
                                    });

                                    proficiencySelect.innerHTML = options;
                                });
                        });
                    </script>

                  
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
