{{-- resources/views/components/dashboard/next-experiment.blade.php --}}
@props(['profile' => null])

@php
    if (!$profile) return;

    $practiceGoal = $profile['next_practice_goal'] ?? '';
@endphp

@if ($practiceGoal)
    <div class="linear-card p-5 relative overflow-hidden border border-gold/20">
        <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/20"/>
        <x-mc-icon name="icon-lightning-circle" class="w-6 h-6 text-gold mb-2"/>
        <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-2">Your Next Experiment</p>
        <p class="text-[13px] text-ink leading-relaxed">{{ $practiceGoal }}</p>
    </div>
@endif
