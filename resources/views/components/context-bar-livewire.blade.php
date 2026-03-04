@props([
    'categoryId' => null,
    'currentSubjectId' => null,
    'routeName' => null,
])

@php
    $categoryId = $categoryId ?? request('category_id');
    $subjectId = request('subject_id') ?? $currentSubjectId;
    $subjects = $categoryId 
        ? \App\Models\Subject::where('category_id', $categoryId)->get()
        : collect();
@endphp

@if(!$categoryId)
    <div class="p-4 bg-yellow-100 text-yellow-800 rounded">
        Please select a category to see subjects.
    </div>
@else
    <div class="flex flex-wrap gap-2 mb-8">
        @foreach($subjects as $subject)
            <a href="{{ route_with_context($routeName, ['subject_id' => $subject->id]) }}"
               class="px-4 py-2 rounded-full text-sm font-medium transition
                      {{ $subject->id == $subjectId 
                          ? 'bg-blue-600 text-white' 
                          : 'bg-gray-700 text-gray-300 hover:bg-gray-600' }}">
                {{ $subject->name }}
            </a>
        @endforeach
    </div>
@endif