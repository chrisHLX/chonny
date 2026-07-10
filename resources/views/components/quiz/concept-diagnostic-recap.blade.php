{{-- resources/views/components/quiz/concept-diagnostic-recap.blade.php --}}
{{--
    Deterministic "mirror" for the quiz completion screen — no AI call. Shows which concepts this
    module just tested, flags the ones that were a diagnosed growth area, and echoes the same
    "X / Y concepts assessed" framing used in the dashboard's Knowledge Profile section, so
    finishing a module visibly ties back to the diagnostic instead of ending in a dead end.
--}}
@props(['moduleId' => null])

@php
    $module = \App\Models\Module::with('subject')->find($moduleId);
    if (!$module || !$module->subject || !auth()->check()) return;

    $testedConcepts = $module->questions()->with('concepts:id,name')->get()
        ->flatMap(fn ($q) => $q->concepts)
        ->unique('id')
        ->values();

    if ($testedConcepts->isEmpty()) return;

    $user = auth()->user();

    $insight = \App\Models\UserProfileInsight::where('user_id', $user->id)
        ->where('subject_id', $module->subject_id)
        ->latest('generated_at')
        ->first();

    $growthConceptIds = $insight ? $insight->growthAreaConcepts()->get()->pluck('id')->all() : [];

    $masteryByConceptId = \App\Models\UserConceptMastery::where('user_id', $user->id)
        ->whereIn('concept_id', $testedConcepts->pluck('id'))
        ->pluck('mastery_percentage', 'concept_id');

    $subjectConceptIds = \App\Models\Concept::where('subject_id', $module->subject_id)->pluck('id');
    $assessedCount = \App\Models\UserConceptMastery::where('user_id', $user->id)
        ->whereIn('concept_id', $subjectConceptIds)
        ->distinct('concept_id')
        ->count('concept_id');
    $totalCount = $subjectConceptIds->count();
@endphp

<div class="linear-card p-5 relative overflow-hidden border border-violet/20">
    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-violet/20"/>
    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-violet/20"/>
    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-violet/20"/>
    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-violet/20"/>
    <p class="text-[11px] font-semibold text-violet uppercase tracking-wide mb-3">What This Updated</p>

    <ul class="space-y-2 mb-4">
        @foreach ($testedConcepts as $concept)
            @php $isGrowthArea = in_array($concept->id, $growthConceptIds, true); @endphp
            <li class="flex items-center justify-between gap-3 text-[13px]">
                <span class="flex items-center gap-2 text-ink min-w-0">
                    <svg class="w-3.5 h-3.5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span class="truncate">{{ $concept->name }}</span>
                    @if ($isGrowthArea)
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-violet/10 text-violet border border-violet/20 whitespace-nowrap">growth area</span>
                    @endif
                </span>
                <span class="text-ink-subtle text-[12px] shrink-0">
                    {{ isset($masteryByConceptId[$concept->id]) ? round($masteryByConceptId[$concept->id]) . '%' : 'assessed' }}
                </span>
            </li>
        @endforeach
    </ul>

    @if ($totalCount > 0)
        <p class="text-[12px] text-ink-subtle border-t border-line pt-3">
            Concept Knowledge for {{ $module->subject->name }}:
            <span class="text-ink font-medium">{{ $assessedCount }} / {{ $totalCount }}</span> concepts now assessed.
        </p>
    @endif
</div>
