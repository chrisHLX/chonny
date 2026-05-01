<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-7xl mx-auto space-y-8">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">Collection</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Your learning cards and question history.</p>
            </div>

            <div class="linear-card p-12 text-center">
                <p class="text-[14px] font-semibold text-ink mb-2">No cards yet</p>
                <p class="text-[13px] text-ink-muted mb-6">Complete a module to view your progress and earn your first card.</p>
                <a href="{{ route('modules.index') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                    Browse modules
                </a>
            </div>

        </div>
    </div>
</x-app-layout>
