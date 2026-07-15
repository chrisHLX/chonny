{{-- resources/views/components/dashboard/recommended-focus.blade.php --}}
@props([
    'activeNextStep' => null,
])

@php
    $isModuleRecommendation = $activeNextStep
        && $activeNextStep->step_type === \App\Enums\StepType::Module
        && $activeNextStep->module;

    if (!$isModuleRecommendation) return;

    $conceptName = $activeNextStep->concept?->name;
@endphp

<div class="linear-card p-5 relative overflow-hidden border border-gold/20">
    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/20"/>
    <x-mc-icon name="icon-compass" class="w-6 h-6 text-gold mb-2"/>
    <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-1">Recommended Focus</p>

    @if ($conceptName)
        <p class="text-[11px] text-ink-subtle mb-2">{{ $conceptName }}</p>
    @endif

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
</div>
