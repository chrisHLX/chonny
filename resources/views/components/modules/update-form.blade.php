@props(['module', 'allQuestions'])

@php
    $selectedIds = old('question_ids', $module->questions->pluck('id')->toArray());
@endphp

<div class="linear-card p-6">
    <h3 class="page-section-title mb-4">Edit Module</h3>

    <form method="POST" action="{{ route('modules.update', $module) }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="name" :value="'Module Name'" />
            <x-text-input id="name" type="text" name="name" :value="old('name', $module->name)" required autofocus />
            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="description" :value="'Description'" />
            <textarea id="description" name="description" rows="4"
                      class="form-textarea mt-1.5">{{ old('description', $module->description) }}</textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
        </div>

        <div x-data="{ open: false }" class="relative">
            <x-input-label :value="'Attach Existing Questions'" />
            <button type="button" @click="open = !open"
                    class="mt-1.5 w-full flex items-center justify-between px-3 py-2 text-[13px] text-ink-muted bg-surface-1 border border-line rounded-md hover:border-line-strong transition-colors">
                <span>Selected: {{ count($selectedIds) }} questions</span>
                <svg :class="open ? 'rotate-180' : ''" class="w-3.5 h-3.5 transition-transform shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="open" @click.away="open = false"
                 class="absolute z-10 mt-1 w-full bg-surface-2 border border-line rounded-lg shadow-xl max-h-56 overflow-y-auto"
                 style="display:none">
                @foreach($allQuestions->sortBy('question') as $question)
                    <label class="flex items-start gap-2.5 px-3 py-2.5 hover:bg-surface-3 cursor-pointer transition-colors">
                        <input type="checkbox" name="question_ids[]" value="{{ $question->id }}"
                               {{ in_array($question->id, $selectedIds) ? 'checked' : '' }}
                               class="form-checkbox mt-0.5 shrink-0">
                        <div class="flex-1 min-w-0">
                            <span class="text-[12px] text-ink-muted leading-snug">{{ $question->question }}</span>
                            @php $skillType = $question->skill_type ?? 'recall'; @endphp
                            <span @class([
                                'inline-block ml-1.5 text-[10px] px-1.5 py-0.5 rounded font-medium',
                                'bg-violet-500/10 text-violet-400' => $skillType === 'recall',
                                'bg-amber-500/10 text-amber-400' => $skillType === 'analysis',
                                'bg-gold/10 text-gold' => $skillType === 'application',
                            ])>{{ $skillType }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('question_ids')" class="mt-1.5" />
        </div>

        <x-primary-button>Update Module</x-primary-button>
    </form>
</div>
