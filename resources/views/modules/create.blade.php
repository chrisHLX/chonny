<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-2xl mx-auto">

            <div class="mb-6">
                <h1 class="text-[17px] font-semibold text-ink">Create Module</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Add a new learning module to the platform.</p>
            </div>

            <x-input-error :messages="$errors->all()" class="mb-4" />

            <div class="linear-card p-6">
                <form method="POST" action="{{ route('modules.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" :value="__('Module Name')" />
                        <x-text-input id="name" type="text" name="name" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('Description')" />
                        <x-text-input id="description" type="text" name="description" :value="old('description')" required />
                        <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="subject_id" :value="__('Subject')" />
                        <select id="subject_id" name="subject_id" class="form-select mt-1.5">
                            <option value="">— Select Subject —</option>
                            @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}" {{ old('subject_id') == $subject->id ? 'selected' : '' }}>
                                    {{ $subject->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('subject_id')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="proficiency_id" :value="__('Proficiency')" />
                        <select id="proficiency_id" name="proficiency_id" class="form-select mt-1.5">
                            <option value="">— Select Subject First —</option>
                        </select>
                        <x-input-error :messages="$errors->get('proficiency_id')" class="mt-1.5" />
                    </div>

                    <div class="pt-2">
                        <x-primary-button>Create Module</x-primary-button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script>
        document.getElementById('subject_id').addEventListener('change', function () {
            const subjectId = this.value;
            const proficiencySelect = document.getElementById('proficiency_id');
            proficiencySelect.innerHTML = '<option value="">Loading...</option>';

            if (!subjectId) {
                proficiencySelect.innerHTML = '<option value="">— Select Subject First —</option>';
                return;
            }

            fetch(`/proficiencies/by-subject/${subjectId}`)
                .then(r => r.json())
                .then(data => {
                    let opts = '<option value="">— Select Proficiency —</option>';
                    data.forEach(item => { opts += `<option value="${item.id}">${item.name}</option>`; });
                    proficiencySelect.innerHTML = opts;
                });
        });
    </script>
</x-app-layout>
