@props(['module', 'conceptsList'])

<div class="linear-card p-6">
    <h3 class="page-section-title mb-4">Add Question</h3>

    <form action="{{ route('questions.store') }}" method="POST" x-data="{ type: 'mcq' }" class="space-y-5">
        @csrf
        <input type="hidden" name="module_id" value="{{ $module->id }}">

        <div>
            <x-input-label for="question" :value="'Question'" />
            <x-text-input type="text" name="question" id="question" required />
            <x-input-error :messages="$errors->get('question')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label :value="'Type'" />
            <select name="type" x-model="type" class="form-select mt-1.5">
                <option value="mcq">Multiple Choice</option>
                <option value="true_false">True / False</option>
                <option value="matching_pairs">Matching Pairs</option>
                <option value="ordering">Ordering</option>
            </select>
        </div>

        <div>
            <x-input-label :value="'Difficulty'" />
            <select name="difficulty" class="form-select mt-1.5">
                <option value="easy" selected>Easy</option>
                <option value="medium">Medium</option>
                <option value="hard">Hard</option>
            </select>
        </div>

        <div>
            <x-input-label for="concepts" :value="'Concepts'" />
            <select id="concepts" name="concepts[]" multiple class="form-select mt-1.5 h-32">
                @foreach($conceptsList as $concept)
                    <option value="{{ $concept->id }}"
                        {{ collect(old('concepts'))->contains($concept->id) ? 'selected' : '' }}>
                        {{ $concept->name }}
                    </option>
                @endforeach
            </select>
            <p class="text-[11px] text-ink-subtle mt-1">Hold Ctrl / Cmd to select multiple.</p>
            <x-input-error :messages="$errors->get('concepts')" class="mt-1.5" />
        </div>

        {{-- MCQ --}}
        <template x-if="type === 'mcq'">
            <div class="space-y-4">
                <div>
                    <x-input-label :value="'Correct Answer'" />
                    <x-text-input type="text" name="answer[correct]" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label :value="'Incorrect Answers'" />
                    <div class="space-y-2 mt-1.5">
                        <x-text-input type="text" name="answer[incorrect][]" placeholder="Option 2" />
                        <x-text-input type="text" name="answer[incorrect][]" placeholder="Option 3" />
                        <x-text-input type="text" name="answer[incorrect][]" placeholder="Option 4" />
                    </div>
                </div>
            </div>
        </template>

        {{-- True/False --}}
        <template x-if="type === 'true_false'">
            <div>
                <x-input-label :value="'Correct Answer'" />
                <select name="answer[correct]" class="form-select mt-1.5">
                    <option value="1">True</option>
                    <option value="0">False</option>
                </select>
            </div>
        </template>

        {{-- Matching Pairs --}}
        <div x-show="type === 'matching_pairs'" x-data="{ pairs: [{},{},{},{}] }">
            <x-input-label :value="'Pairs'" />
            <div class="space-y-2 mt-1.5">
                <template x-for="(pair, i) in pairs" :key="i">
                    <div class="flex gap-2">
                        <input type="text" :name="'answer[correct][' + i + '][key]'" placeholder="Key"
                               class="flex-1 bg-surface-1 border border-line text-ink rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-accent focus:border-accent transition-colors">
                        <input type="text" :name="'answer[correct][' + i + '][value]'" placeholder="Value"
                               class="flex-1 bg-surface-1 border border-line text-ink rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-accent focus:border-accent transition-colors">
                    </div>
                </template>
            </div>
            <button type="button" @click="pairs.push({})"
                    class="mt-2 text-[12px] text-accent hover:text-accent-hover transition-colors">
                + Add pair
            </button>
        </div>

        {{-- Ordering --}}
        <template x-if="type === 'ordering'">
            <div class="space-y-2">
                <x-input-label :value="'Steps (in correct order)'" />
                <template x-for="i in 4" :key="i">
                    <x-text-input type="text" :name="'answer[steps][]'" placeholder="Step" class="mt-1 block w-full" />
                </template>
            </div>
        </template>

        <x-primary-button>Create Question</x-primary-button>
    </form>
</div>
