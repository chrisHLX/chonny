{{-- resources/views/components/dashboard/next-experiment.blade.php --}}
@props(['profile' => null, 'activeNextStep' => null])

@php
    $practiceGoal = $profile['next_practice_goal'] ?? '';
    if (!$activeNextStep && !$practiceGoal) return;
@endphp

<div class="linear-card p-5 relative overflow-hidden border border-gold/20">
    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/20"/>
    <x-mc-icon name="icon-lightning-circle" class="w-6 h-6 text-gold mb-2"/>
    <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-2">Your Next Experiment</p>

    @if ($activeNextStep && $activeNextStep->step_type === \App\Enums\StepType::Task)
        <p class="text-[13px] font-medium text-ink mb-1">{{ $activeNextStep->title }}</p>
        <p class="text-[13px] text-ink-muted leading-relaxed mb-3">{{ $activeNextStep->instructions }}</p>

        @if ($activeNextStep->status === \App\Enums\NextStepStatus::Pending)
            <livewire:next-step-reflection :next-step-id="$activeNextStep->id" :key="'reflection-'.$activeNextStep->id" />
        @elseif ($activeNextStep->status === \App\Enums\NextStepStatus::Attempted)
            <p class="text-[12px] text-ink-muted italic">Reflection submitted — Mindcollector is thinking it over. Your next task will appear here soon.</p>
        @endif
    @elseif ($practiceGoal)
        <p class="text-[13px] text-ink leading-relaxed">{{ $practiceGoal }}</p>
    @endif
</div>
