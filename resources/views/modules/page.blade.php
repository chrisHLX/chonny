<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-3xl mx-auto space-y-4">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">{{ $module->name ?? 'Module Content' }}</h1>
            </div>

            @if($pages->isEmpty())
                <div class="linear-card px-5 py-12 text-center">
                    <p class="text-[13px] text-ink-subtle">No content available for this module yet.</p>
                    <p class="text-[12px] text-ink-subtle mt-1">Check back later or start the quiz.</p>
                </div>
            @else
                @foreach($pages as $page)
                    <div class="linear-card p-6">
                        <div class="prose prose-invert prose-sm max-w-none">
                            {!! Str::markdown($page->content) !!}
                        </div>
                    </div>
                @endforeach
            @endif

        </div>
    </div>
</x-app-layout>
