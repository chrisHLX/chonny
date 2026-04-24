<div class="py-6 max-w-3xl mx-auto space-y-6" x-transition>

    <div>
        <h2 class="text-[17px] font-semibold text-ink">Select a Module</h2>
        <p class="text-[13px] text-ink-muted mt-0.5">Choose a module to start the quiz.</p>
    </div>

    @if($subjects->count() > 1)
        <div class="flex flex-wrap gap-2">
            @foreach($subjects as $subject)
                <button wire:click="$set('selectedSubject', {{ $subject->id }})"
                        class="px-3 py-1.5 rounded-full text-[12px] font-medium transition-colors
                            {{ $selectedSubject == $subject->id
                                ? 'bg-accent text-white'
                                : 'bg-surface-2 text-ink-muted hover:bg-surface-3 border border-line' }}">
                    {{ $subject->name }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="space-y-4">
        @foreach($subjects as $subject)
            @if(!$selectedSubject || $selectedSubject == $subject->id)
                <div class="linear-card p-5">
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">{{ $subject->name }}</p>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach($this->modules->where('subject_id', $subject->id) as $module)
                            <button wire:click="$set('selectedModule', {{ $module->id }})"
                                    class="text-left p-3.5 border rounded-lg transition-colors
                                        {{ $selectedModule == $module->id
                                            ? 'border-accent bg-accent/5'
                                            : 'border-line hover:bg-surface-2 hover:border-line-strong' }}">
                                <p class="text-[13px] font-medium {{ $selectedModule == $module->id ? 'text-ink' : 'text-ink-muted' }} truncate">{{ $module->name }}</p>
                                <p class="text-[11px] text-ink-subtle mt-0.5">{{ $module->proficiencies->first()->name ?? '—' }}</p>
                                <div class="mt-1.5">
                                    @if(optional($module->pivot)->status === 'completed')
                                        <span class="badge-green">Completed</span>
                                    @elseif(optional($module->pivot)->status === 'in_progress')
                                        <span class="badge-amber">In Progress</span>
                                    @else
                                        <span class="badge-gray">Not started</span>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div>
        @if($selectedModule && $this->modules->where('id', $selectedModule)->first()?->pivot->status === 'completed')
            <p class="text-[12px] text-ink-subtle mb-3">You've already completed this module. Retake to reinforce your knowledge.</p>
        @endif
        <button wire:click="startQuiz"
                class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                @disabled(!$selectedModule)>
            Start Quiz
        </button>
    </div>
</div>
