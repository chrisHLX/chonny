<x-app-layout>
    <div class="max-w-3xl mx-auto py-10 px-6">
        <h1 class="text-3xl font-bold text-white mb-6 text-center">
            📘 {{ $module->name ?? 'Module Content' }}
        </h1>

        @if($pages->isEmpty())
            <div class="bg-gray-100 border border-gray-200 rounded-xl p-6 text-center text-gray-900 shadow-sm">
                <p>There’s no content available for this module yet.</p>
                <p class="text-sm text-gray-900 mt-2">Please check back later or start the quiz to explore available modules.</p>
            </div>
        @else
            @foreach($pages as $page)
                <div class="bg-white border text-black border-gray-200 rounded-xl p-6 shadow-sm mb-6">
                    <div class="prose max-w-none">
                        {!! Str::markdown($page->content) !!}
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
