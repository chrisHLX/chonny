<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-2xl mx-auto">

            <div class="mb-6">
                <h1 class="text-[17px] font-semibold text-ink">Create Module</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Add a new learning module to the platform.</p>
            </div>

            <x-input-error :messages="$errors->all()" class="mb-4" />

            <div class="linear-card p-6">
                <form method="POST" action="{{ route('modules.store') }}" class="space-y-5"
                      x-data="{
                          categoryId: '{{ old('category_id') }}',
                          subjectId:  '{{ old('subject_id') }}',
                          subjects:   [],
                          proficiencies: [],
                          loadSubjects() {
                              this.subjects = [];
                              this.proficiencies = [];
                              this.subjectId = '';
                              if (!this.categoryId) return;
                              fetch(`/subjects/by-category/${this.categoryId}`)
                                  .then(r => r.json())
                                  .then(data => { this.subjects = data; });
                          },
                          loadProficiencies() {
                              this.proficiencies = [];
                              if (!this.subjectId) return;
                              fetch(`/proficiencies/by-subject/${this.subjectId}`)
                                  .then(r => r.json())
                                  .then(data => { this.proficiencies = data; });
                          },
                      }"
                      x-init="
                          if (categoryId) loadSubjects();
                      ">
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

                    {{-- Step 1: Category --}}
                    <div>
                        <x-input-label for="category_id" :value="__('Category')" />
                        <select id="category_id" name="category_id" class="form-select mt-1.5"
                                x-model="categoryId" @change="loadSubjects()">
                            <option value="">— Select Category —</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('category_id')" class="mt-1.5" />
                    </div>

                    {{-- Step 2: Subject (unlocks after category) --}}
                    <div>
                        <x-input-label for="subject_id" :value="__('Subject')" />
                        <select id="subject_id" name="subject_id" class="form-select mt-1.5"
                                x-model="subjectId" @change="loadProficiencies()"
                                :disabled="!categoryId">
                            <option value="">
                                <span x-text="categoryId ? '— Select Subject —' : '— Select Category First —'"></span>
                            </option>
                            <template x-for="subject in subjects" :key="subject.id">
                                <option :value="subject.id" x-text="subject.name"
                                        :selected="subject.id == '{{ old('subject_id') }}'"></option>
                            </template>
                        </select>
                        <x-input-error :messages="$errors->get('subject_id')" class="mt-1.5" />
                    </div>

                    {{-- Step 3: Proficiency (unlocks after subject) --}}
                    <div>
                        <x-input-label for="proficiency_id" :value="__('Proficiency')" />
                        <select id="proficiency_id" name="proficiency_id" class="form-select mt-1.5"
                                :disabled="!subjectId">
                            <option value="">
                                <span x-text="subjectId ? '— Select Proficiency —' : (categoryId ? '— Select Subject First —' : '— Select Category First —')"></span>
                            </option>
                            <template x-for="prof in proficiencies" :key="prof.id">
                                <option :value="prof.id" x-text="prof.name"
                                        :selected="prof.id == '{{ old('proficiency_id') }}'"></option>
                            </template>
                        </select>
                        <x-input-error :messages="$errors->get('proficiency_id')" class="mt-1.5" />
                    </div>

                    <div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="hidden" name="attribute_as_mindcollector" value="0">
                            <input type="checkbox" name="attribute_as_mindcollector" value="1" class="form-checkbox"
                                   {{ old('attribute_as_mindcollector', true) ? 'checked' : '' }}>
                            <span class="text-[13px] text-ink">Attribute as MindCollector</span>
                        </label>
                        <p class="text-[11px] text-ink-subtle mt-1">Shows "by MindCollector" on the module page instead of your own account name.</p>
                    </div>

                    <div class="pt-2">
                        <x-primary-button>Create Module</x-primary-button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
