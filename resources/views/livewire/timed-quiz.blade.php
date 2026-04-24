<div class="py-6 max-w-2xl mx-auto">

    {{-- START SCREEN --}}
    @if (!$started)
        <div class="linear-card p-8 text-center space-y-6" x-transition>
            <div>
                <h2 class="text-[17px] font-semibold text-ink">Ready to test your knowledge?</h2>
                <p class="text-[13px] text-ink-muted mt-1">Select a module below to begin.</p>
            </div>

            @if($subjects->count() > 1)
                <div class="flex flex-wrap justify-center gap-2">
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

            <div class="space-y-3 mt-2 text-left">
                @foreach ($subjects as $subject)
                    @if (!$selectedSubject || $selectedSubject == $subject->id)
                        <div>
                            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2">{{ $subject->name }}</p>
                            <div class="grid sm:grid-cols-2 gap-2">
                                @foreach ($modules->where('subject_id', $subject->id) as $module)
                                    <button wire:click="$set('selectedModule', {{ $module->id }})"
                                            class="text-left p-3.5 border rounded-lg transition-colors
                                                {{ $selectedModule == $module->id
                                                    ? 'border-accent bg-accent/5'
                                                    : 'border-line hover:bg-surface-2 hover:border-line-strong' }}">
                                        <p class="text-[13px] font-medium {{ $selectedModule == $module->id ? 'text-ink' : 'text-ink-muted' }}">{{ $module->name }}</p>
                                        <p class="text-[11px] text-ink-subtle mt-0.5">{{ $module->proficiencies->first()->name ?? '—' }}</p>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <button wire:click="startQuiz"
                    class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    @disabled(!$selectedModule)>
                Start Quiz
            </button>
        </div>

    {{-- FEEDBACK / AI REVIEW SCREEN --}}
    @elseif (!empty($feedback))
        <div class="linear-card p-6" x-transition>
            @php
                $hasMissingContent = collect($contents)->contains(fn($c) => empty($c['review_content']));
            @endphp

            @if ($hasMissingContent)
                <div wire:poll.5s="checkReviewContent"
                     x-data="{
                         messages: ['Analyzing your mistakes...','Reviewing similar questions...','Finding learning patterns...','Generating targeted review...','Almost done...'],
                         index: 0,
                         progress: 20,
                         nextMessage() { this.index = (this.index + 1) % this.messages.length; this.progress = Math.min(92, this.progress + Math.random() * 12); },
                         init() { setInterval(() => this.nextMessage(), 2500); }
                     }">
                    <div class="flex items-center gap-3 mb-4">
                        <svg class="animate-spin w-4 h-4 text-accent shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                        </svg>
                        <p class="text-[13px] font-medium text-ink" x-text="messages[index]"></p>
                    </div>

                    <div class="w-full bg-surface-3 rounded-full h-1 mb-4 overflow-hidden">
                        <div class="bg-accent h-1 rounded-full transition-all duration-700"
                             :style="{ width: progress + '%' }"></div>
                    </div>

                    <ul class="space-y-1.5">
                        @foreach ($contents as $content)
                            <li class="flex items-center gap-2 text-[12px]">
                                @if ($content['review_content'])
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shrink-0"></span>
                                    <span class="text-ink-muted">Question {{ $content['question_id'] }} ready</span>
                                @else
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse shrink-0"></span>
                                    <span class="text-ink-subtle">Question {{ $content['question_id'] }} pending</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div>
                    <h3 class="text-[14px] font-semibold text-ink mb-3">Review</h3>
                    <div class="space-y-2 mb-6">
                        @foreach ($contents as $content)
                            <p class="text-[13px] text-ink-muted leading-relaxed">{{ $content['review_content'] }}</p>
                        @endforeach
                    </div>
                    <button wire:click="startReviewQuiz"
                            class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        Start Review Quiz
                    </button>
                </div>
            @endif
        </div>

    {{-- COMPLETION SCREEN --}}
    @elseif ($completed || $status == "completed")
        <div class="linear-card p-6" x-transition>
            <h2 class="text-[17px] font-semibold text-ink text-center mb-6">Quiz Complete</h2>

            <div class="grid grid-cols-2 gap-3 mb-6">
                <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-lg p-4 text-center">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Score</p>
                    <p class="text-2xl font-semibold text-emerald-400">{{ $score }} / {{ $questions->count() }}</p>
                </div>
                <div class="bg-accent/10 border border-accent/20 rounded-lg p-4 text-center">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Time</p>
                    <p class="text-2xl font-semibold text-accent">{{ $this->totalTime }}s</p>
                </div>
            </div>

            @if (count($wrongQuestions ?? []) > 0)
                <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-4 mb-6">
                    <p class="text-[12px] font-medium text-red-400 mb-3">Incorrect answers</p>
                    <ul class="space-y-2">
                        @foreach ($wrongQuestions as $q)
                            <li class="text-[13px] text-ink-muted">{{ $q->question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex flex-col sm:flex-row justify-center gap-3">
                @if ($difficulty === 'final')
                    <a href="{{ route('collection.index') }}"
                       class="inline-flex items-center justify-center px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        View Progress
                    </a>
                @else
                    <button wire:click="retryModule"
                            class="inline-flex items-center justify-center px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        Next Level
                    </button>
                @endif
            </div>
        </div>

    {{-- QUESTION SCREEN --}}
    @else
        @php $question = $questions[$currentIndex]; @endphp

        <div class="mb-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[12px] text-ink-subtle">{{ $proficiency ?? '' }}</span>
                <span class="text-[12px] text-ink-subtle">{{ $currentIndex + 1 }} / {{ $questions->count() }}</span>
            </div>
            <div class="w-full bg-surface-3 rounded-full h-1 overflow-hidden">
                <div class="bg-accent h-1 rounded-full transition-all duration-500"
                     style="width: {{ (($currentIndex + 1) / $questions->count()) * 100 }}%"></div>
            </div>
            <div class="flex justify-end mt-1">
                <span class="text-[11px] text-ink-subtle">{{ $difficulty }}</span>
            </div>
        </div>

        <div class="linear-card p-6" wire:key="question-{{ $question->id }}" x-transition>
            <p class="text-[15px] font-medium text-ink leading-relaxed mb-5">
                <span class="text-accent font-semibold mr-1">{{ $currentIndex + 1 }}.</span>
                {{ $question->question }}
            </p>

            <form x-data="{ elapsed: 0 }"
                  x-init="setInterval(() => elapsed++, 1000)"
                  x-on:submit.prevent="$wire.submit({ elapsed })">

                @switch($question->type)
                    @case('mcq')
                        <div class="space-y-2">
                            @foreach ($question->answer['options'] as $option)
                                <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                       :class="$wire.answer === @js($option)
                                           ? 'border-accent bg-accent/5 text-ink'
                                           : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong'">
                                    <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                         :class="$wire.answer === @js($option) ? 'border-accent bg-accent' : 'border-line-strong'"></div>
                                    <input type="radio" wire:model="answer" value="{{ $option }}" class="sr-only">
                                    <span class="text-[13px]">{{ $option }}</span>
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case('true_false')
                        <div class="space-y-2">
                            @foreach(['true' => 'True', 'false' => 'False'] as $val => $lbl)
                                <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                       :class="$wire.answer === @js($val)
                                           ? 'border-accent bg-accent/5 text-ink'
                                           : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong'">
                                    <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                         :class="$wire.answer === @js($val) ? 'border-accent bg-accent' : 'border-line-strong'"></div>
                                    <input type="radio" wire:model="answer" value="{{ $val }}" class="sr-only">
                                    <span class="text-[13px]">{{ $lbl }}</span>
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case('open')
                        <textarea wire:model="answer" rows="3" required
                                  placeholder="Type your answer..."
                                  class="form-textarea mt-1"></textarea>
                        @break

                    @case('matching_pairs')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2 pb-2 border-b border-line">Items</p>
                                <ul class="space-y-2">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li class="px-3 py-2 text-[13px] text-ink bg-surface-2 border border-line rounded-md">{{ $key }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2 pb-2 border-b border-line">Match To</p>
                                <ul class="space-y-2">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li>
                                            <select wire:model="answer.{{ $key }}" class="form-select">
                                                <option value="">— Select —</option>
                                                @foreach ($question->answer['pairs']['values'] as $value)
                                                    <option value="{{ $value }}">{{ $value }}</option>
                                                @endforeach
                                            </select>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                        @break

                    @case('ordering')
                        <ul id="ordering-list-{{ $question->id }}" class="ordering-list" x-sortable wire:ignore
                            x-init="$wire.set('answer', [...$el.children].map(e => e.dataset.value))"
                            x-on:sorted="$wire.set('answer', [...$el.children].map(e => e.dataset.value))">
                            @foreach($question->answer['steps'] as $step)
                                <li class="ordering-item" data-value="{{ $step }}">
                                    <svg class="w-3.5 h-3.5 text-ink-subtle shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                                    </svg>
                                    {{ $step }}
                                </li>
                            @endforeach
                        </ul>
                        @break
                @endswitch

                <div class="mt-5">
                    <button type="submit"
                            class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :disabled="!$wire.answer && !$el.closest('form').querySelector('.ordering-list')">
                        Submit Answer
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
