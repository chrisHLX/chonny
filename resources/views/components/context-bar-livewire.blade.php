@props(['categoryId' => null, 'currentSubjectId' => null, 'routeName' => null])

@php
    $categoryId = $categoryId ?? request('category_id');
    $subjectId  = request('subject_id') ?? $currentSubjectId;
    $subjects   = $categoryId ? \App\Models\Subject::where('category_id', $categoryId)->get() : collect();
@endphp

@if(!$categoryId)
    <div class="flex items-center gap-2 px-3 py-2 bg-amber-500/10 border border-amber-500/20 rounded-md mb-4">
        <svg class="w-3.5 h-3.5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <p class="text-[12px] text-amber-400">Select a category from the sidebar to see subjects.</p>
    </div>
@else
    <div class="flex flex-wrap gap-1.5 mb-6">
        @foreach($subjects as $subject)
            <a href="{{ route_with_context($routeName, ['subject_id' => $subject->id]) }}"
               class="px-3 py-1 rounded-full text-[12px] font-medium transition-colors
                      {{ $subject->id == $subjectId
                         ? 'bg-accent text-white'
                         : 'bg-surface-2 text-ink-muted hover:bg-surface-3 hover:text-ink border border-line' }}">
                {{ $subject->name }}
            </a>
        @endforeach
    </div>
@endif
