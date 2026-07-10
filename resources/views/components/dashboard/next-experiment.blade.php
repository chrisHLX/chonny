{{-- resources/views/components/dashboard/next-experiment.blade.php --}}
@props(['profile' => null, 'activeNextStep' => null])

@php
    $practiceGoal = $profile['next_practice_goal'] ?? '';
    if (!$activeNextStep && !$practiceGoal) return;

    // A module-type step is a bigger, structured commitment — not a self-reported "experiment" —
    // so the card's framing switches with it rather than showing two separate module cards
    // (this replaces the old, ungrounded recommended-next-step.blade.php card entirely).
    $isModuleStep = $activeNextStep && $activeNextStep->step_type === \App\Enums\StepType::Module;
    $cardLabel = $isModuleStep ? 'Recommended Next Step' : 'Your Next Experiment';
    $cardIcon = $isModuleStep ? 'icon-compass' : 'icon-lightning-circle';
@endphp

<div class="linear-card p-5 relative overflow-hidden border border-gold/20">
    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/20"/>
    <x-mc-icon :name="$cardIcon" class="w-6 h-6 text-gold mb-2"/>
    <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-2">{{ $cardLabel }}</p>

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
    @elseif ($practiceGoal)
        <p class="text-[13px] text-ink leading-relaxed">{{ $practiceGoal }}</p>
    @endif
</div>
