{{-- resources/views/components/dashboard/next-experiment.blade.php --}}
@props([
    'profile' => null,
    'activeNextStep' => null,
    'activeNextStepQuestNumber' => null,
    'concludedNextStep' => null,
    'concludedIsPositiveTrend' => false,
    'nextStepContentExhausted' => false,
])

@php
    $practiceGoal = $profile['next_practice_goal'] ?? '';
    if (!$activeNextStep && !$practiceGoal && !$concludedNextStep && !$nextStepContentExhausted) return;

    $isModuleStep = $activeNextStep && $activeNextStep->step_type === \App\Enums\StepType::Module;
    $isConcludedState = $concludedNextStep && !$activeNextStep;
    $isExhaustedState = $nextStepContentExhausted && !$activeNextStep && !$concludedNextStep;

    // Quest framing (Task 3) applies to both step types — a module-type step is a quest to
    // complete that module, a task-type step an in-game quest. The concluded state isn't an
    // active quest, so it gets its own eyebrow label rather than reusing "Your Current Quest".
    $cardLabel = $isConcludedState ? 'Investigation Concluded' : ($isExhaustedState ? "You're All Caught Up" : 'Your Current Quest');
    $cardIcon = $isConcludedState ? 'icon-delta' : ($isExhaustedState ? 'icon-leaf' : ($isModuleStep ? 'icon-compass' : 'icon-lightning-circle'));

    // Lineage: which concept this quest targets and its position in the insight+concept chain
    // (already computed in DashboardController from insight_id/concept_id/previous_step_id —
    // no new AI call or schema, just surfacing what was already on the row).
    $questConceptName = $activeNextStep?->concept?->name;
    $questSuffix = $isModuleStep ? '' : ' investigation';
@endphp

<div class="linear-card p-5 relative overflow-hidden border border-gold/20">
    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/20"/>
    <x-mc-icon :name="$cardIcon" class="w-6 h-6 text-gold mb-2"/>
    <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-1">{{ $cardLabel }}</p>

    @if ($activeNextStep && $activeNextStepQuestNumber && $questConceptName)
        <p class="text-[11px] text-ink-subtle mb-2">Quest {{ $activeNextStepQuestNumber }} &middot; {{ $questConceptName }}{{ $questSuffix }}</p>
    @endif

    @if ($activeNextStep && $activeNextStep->step_type === \App\Enums\StepType::Task)
        <p class="text-[13px] font-medium text-ink mb-1">{{ $activeNextStep->title }}</p>
        <p class="text-[13px] text-ink-muted leading-relaxed mb-3">{{ $activeNextStep->instructions }}</p>

        @if ($activeNextStep->status === \App\Enums\NextStepStatus::Pending)
            <livewire:next-step-reflection :next-step-id="$activeNextStep->id" :key="'reflection-'.$activeNextStep->id" />
        @elseif ($activeNextStep->status === \App\Enums\NextStepStatus::Attempted)
            <p class="text-[12px] text-ink-muted italic">Reflection submitted — Mindcollector is thinking it over. Your next task will appear here soon.</p>
        @endif
    @elseif ($activeNextStep && $activeNextStep->step_type === \App\Enums\StepType::Module && $activeNextStep->module)
        <p class="text-[13px] font-medium text-ink mb-1">{{ $activeNextStep->title }}</p>
        <p class="text-[13px] text-ink-muted leading-relaxed mb-3">{{ $activeNextStep->instructions }}</p>

        @if ($activeNextStep->status === \App\Enums\NextStepStatus::Pending)
            <a href="{{ route('modules.show', $activeNextStep->module) }}"
               class="btn-primary w-full text-[12px] inline-flex items-center justify-center gap-2">
                Start Module
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @endif
    @elseif ($concludedNextStep)
        @php $conceptName = $concludedNextStep->concept?->name ?? 'this area'; @endphp
        @if ($concludedIsPositiveTrend)
            <p class="text-[13px] font-medium text-ink mb-1">Investigation complete</p>
            <p class="text-[13px] text-ink-muted leading-relaxed">
                You've run 3 experiments on {{ $conceptName }} and reported how they went. Mindcollector's read: this may no longer be your biggest gap. Deeper re-profiling based on your accumulated evidence is coming.
            </p>
        @else
            <p class="text-[13px] font-medium text-ink mb-1">Finding filed</p>
            <p class="text-[13px] text-ink-muted leading-relaxed">
                You've given {{ $conceptName }} three genuine attempts. This angle doesn't seem to be the way in — that's useful information, not a failure. Mindcollector will use it to try a different approach.
            </p>
        @endif
    @elseif ($isExhaustedState)
        <p class="text-[13px] font-medium text-ink mb-1">You've completed everything available here</p>
        <p class="text-[13px] text-ink-muted leading-relaxed mb-3">
            Every module currently matching your growth areas in this subject is done. More content is on the way — check back soon, or browse the library for anything else that catches your eye.
        </p>
        <a href="{{ route('modules.index') }}"
           class="btn-secondary w-full text-[12px] inline-flex items-center justify-center gap-2">
            Browse modules
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    @elseif ($practiceGoal)
        <p class="text-[13px] text-ink leading-relaxed">{{ $practiceGoal }}</p>
    @endif
</div>
