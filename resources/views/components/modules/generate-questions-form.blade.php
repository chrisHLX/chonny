@props(['module', 'modulePages'])

@php $hasContent = $modulePages->isNotEmpty(); @endphp

<div class="linear-card overflow-hidden">

    <div class="px-6 pt-5 pb-4 border-b border-border">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="page-section-title">Generate Questions</h3>
                <p class="page-section-desc mt-0.5">
                    Use AI to generate quiz questions directly from your module content.
                    Select the types and difficulty focus you want.
                </p>
            </div>
            @if($hasContent)
                <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-accent/10 text-accent border border-accent/20">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Ready to generate
                </span>
            @endif
        </div>
    </div>

    @if(! $hasContent)

        <div class="px-6 py-10 flex flex-col items-center text-center gap-3">
            <div class="w-10 h-10 rounded-full bg-surface-1 border border-border flex items-center justify-center">
                <svg class="w-5 h-5 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div>
                <p class="text-[13px] font-medium text-ink">No content yet</p>
                <p class="text-[12px] text-ink-muted mt-0.5">Add module content above before generating questions.<br>The AI needs source material to create questions from.</p>
            </div>
        </div>

    @else

        <form action="{{ route('modules.generate-questions', $module->id) }}" method="POST">
            @csrf

            <div class="px-6 pt-5 space-y-6">

                {{-- Question types --}}
                <div>
                    <x-input-label value="Question Types" />
                    <p class="text-[11px] text-ink-muted mb-3 mt-0.5">Select which types to generate. At least one required.</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach([
                            'mcq'            => ['label' => 'Multiple Choice', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                            'true_false'     => ['label' => 'True / False',    'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                            'matching_pairs' => ['label' => 'Matching Pairs',  'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
                            'ordering'       => ['label' => 'Ordering',        'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h16'],
                        ] as $value => $meta)
                            <label class="relative flex flex-col gap-2 p-3 rounded-lg border border-border bg-surface-1 cursor-pointer hover:border-accent/50 transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                                <input type="checkbox" name="types[]" value="{{ $value }}" checked class="sr-only">
                                <div class="flex items-center justify-between">
                                    <svg class="w-4 h-4 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $meta['icon'] }}"/>
                                    </svg>
                                    {{-- checkmark --}}
                                    <svg class="w-3.5 h-3.5 text-accent opacity-0 [input:checked~&]:opacity-100 transition-opacity" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <span class="text-[12px] font-medium text-ink leading-tight">{{ $meta['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Difficulty --}}
                <div>
                    <x-input-label value="Difficulty Focus" />
                    <p class="text-[11px] text-ink-muted mb-3 mt-0.5">
                        Override the default proficiency-based difficulty mix, or leave on Auto.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach([
                            ''       => 'Auto (proficiency)',
                            'easy'   => 'Easy',
                            'medium' => 'Medium',
                            'hard'   => 'Hard',
                        ] as $value => $label)
                            <label class="relative cursor-pointer">
                                <input type="radio" name="difficulty" value="{{ $value }}"
                                       {{ $value === '' ? 'checked' : '' }}
                                       class="sr-only peer">
                                <span class="inline-flex items-center px-3 py-1.5 rounded-md border border-border text-[12px] font-medium text-ink-muted
                                             peer-checked:border-accent peer-checked:text-accent peer-checked:bg-accent/5
                                             hover:border-accent/40 transition-colors">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- AI Model --}}
                <x-ai-model-selector />

            </div>

            {{-- Footer --}}
            <div class="px-6 py-4 mt-5 bg-bg-subtle/40 border-t border-border flex items-center justify-between gap-4">
                <p class="text-[11px] text-ink-muted leading-relaxed max-w-xs">
                    Each selected type is queued as a separate job.
                    Generation may take a minute — the module status will update when complete.
                </p>
                <button type="submit"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors shrink-0">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Generate Questions
                </button>
            </div>

        </form>

    @endif
</div>
