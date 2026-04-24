<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-5xl mx-auto space-y-6">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">Concepts</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">{{ $user->name }}'s concept mastery overview.</p>
            </div>

            <x-context-bar :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" />

            <div class="linear-card overflow-hidden">
                @forelse($concepts as $concept)
                    <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-4 hover:bg-surface-2 transition-colors">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-[14px] font-medium text-ink">{{ $concept->name }}</p>
                                @if($concept->description)
                                    <p class="text-[12px] text-ink-muted mt-0.5 line-clamp-2">{{ $concept->description }}</p>
                                @endif
                            </div>
                            @if($concept->questions->isNotEmpty())
                                <span class="badge-blue shrink-0">{{ $concept->questions->count() }} points</span>
                            @else
                                <span class="badge-gray shrink-0">No questions</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-12 text-center">
                        <p class="text-[13px] text-ink-subtle">No concepts found for this subject.</p>
                    </div>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
