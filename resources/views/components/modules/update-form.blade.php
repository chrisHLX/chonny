@props(['module', 'allQuestions'])

@php
    $selectedIds = old('question_ids', $module->questions->pluck('id')->toArray());
@endphp

<div class="bg-white shadow-sm sm:rounded-lg p-6">
    <form method="POST" action="{{ route('modules.update', $module) }}" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- Module Name --}}
        <div>
            <x-input-label for="name" :value="'Module Name'" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name"
                :value="old('name', $module->name)" required autofocus />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        {{-- Description --}}
        <div>
            <x-input-label for="description" :value="'Description'" />
            <textarea id="description" name="description" rows="4" class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50">{{ old('description', $module->description) }}</textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-2" />
        </div>

        {{-- Attach Questions --}}
        <div x-data="{ open: false }" class="relative">
            <x-input-label for="question_ids" :value="'Attach Existing Questions'" />
            <div @click="open = !open" class="cursor-pointer border rounded-md p-2 bg-white shadow-sm">
                <span x-text="'Selected: ' + {{ json_encode($selectedIds) }}.length + ' questions'"></span>
            </div>
            <div x-show="open" @click.away="open = false" class="absolute bg-white border mt-1 rounded-md shadow-lg z-50 max-h-60 overflow-y-auto w-full">
                @foreach($allQuestions->sortBy('question') as $question)
                    <label class="flex items-start p-2 space-x-2 hover:bg-gray-100">
                        <input type="checkbox" name="question_ids[]" value="{{ $question->id }}"
                            {{ in_array($question->id, $selectedIds) ? 'checked' : '' }}
                            class="rounded text-indigo-600 border-gray-300 focus:ring-indigo-500" />
                        <span class="text-sm text-gray-700">{{ $question->question }}</span>
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('question_ids')" class="mt-2" />
        </div>

        <x-primary-button>Update Module</x-primary-button>
    </form>
</div>
