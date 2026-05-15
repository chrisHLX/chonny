<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-3xl mx-auto space-y-4">

            <div class="flex items-center gap-1.5 text-[12px] text-ink-subtle">
                <a href="{{ route('modules.show', $module) }}" class="hover:text-ink transition-colors">{{ $module->name }}</a>
                <span class="opacity-40">/</span>
                <span class="text-ink">Content</span>
            </div>

            <h1 class="text-[17px] font-semibold text-ink">{{ $module->name }}</h1>

            @if($pages->isEmpty())
                <div class="linear-card px-5 py-12 text-center">
                    <p class="text-[13px] text-ink-subtle">No content available for this module yet.</p>
                    <p class="text-[12px] text-ink-subtle mt-1">Check back later or start the quiz.</p>
                </div>
            @else
                @foreach($pages as $page)
                    <div class="linear-card p-6">
                        @if($page->title)
                            <h2 class="text-[14px] font-semibold text-ink mb-4">{{ $page->title }}</h2>
                        @endif
                        <div class="prose prose-invert prose-sm max-w-none
                                    prose-p:text-ink-muted prose-headings:text-ink prose-strong:text-ink
                                    prose-li:text-ink-muted prose-code:text-ink-muted">
                            {!! Str::markdown($page->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    </div>
                @endforeach
            @endif

            @if($research->isNotEmpty())
                <div class="linear-card overflow-hidden">
                    <div class="px-6 pt-6 pb-4 border-b border-line flex items-center justify-between">
                        <h2 class="page-section-title">Research</h2>
                        <span class="text-[11px] text-ink-subtle">{{ $research->count() }} {{ Str::plural('entry', $research->count()) }}</span>
                    </div>

                    <div class="divide-y divide-line">
                        @foreach($research as $item)
                            @php
                                $isPrimaryDiscoveredFormat = isset($item->source_urls['discovered']);
                                $primaryUrl = $isPrimaryDiscoveredFormat ? ($item->source_urls['primary'] ?? null) : null;
                                $discovered = $isPrimaryDiscoveredFormat
                                    ? $item->source_urls['discovered']
                                    : collect($item->source_urls ?? [])->map(fn($url) => ['uri' => $url, 'title' => $url])->toArray();
                            @endphp
                            <div x-data="{ open: false }">
                                <button
                                    x-on:click="open = !open"
                                    class="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-surface-2 transition-colors">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[13px] text-ink font-medium truncate">{{ $item->title }}</p>
                                        <p class="text-[11px] text-ink-subtle mt-0.5">{{ $item->created_at->diffForHumans() }}</p>
                                    </div>
                                    <svg x-bind:class="open ? 'rotate-180' : ''"
                                         class="w-4 h-4 text-ink-subtle shrink-0 ml-4 transition-transform duration-200"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>

                                <div x-show="open" x-cloak class="px-6 pb-5">
                                    <div class="prose prose-sm prose-invert max-w-none
                                                prose-p:text-ink-muted prose-headings:text-ink prose-strong:text-ink
                                                prose-li:text-ink-muted prose-code:text-ink-muted">
                                        {!! Str::markdown(e($item->content), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                                    </div>

                                    @if(!empty($primaryUrl) || !empty($discovered))
                                        <div class="mt-4 pt-4 border-t border-line">
                                            <p class="text-[11px] text-ink-subtle mb-2 uppercase tracking-wide">Sources</p>
                                            <div class="flex flex-col gap-1">
                                                @if(!empty($primaryUrl))
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-[10px] font-semibold text-ink-subtle uppercase tracking-wide shrink-0">Primary</span>
                                                        <a href="{{ $primaryUrl }}" target="_blank" rel="noopener noreferrer"
                                                           class="text-[12px] text-accent hover:text-accent-hover truncate transition-colors">
                                                            {{ $primaryUrl }}
                                                        </a>
                                                    </div>
                                                @endif
                                                @foreach($discovered as $source)
                                                    <a href="{{ $source['uri'] }}" target="_blank" rel="noopener noreferrer"
                                                       class="text-[12px] text-accent hover:text-accent-hover truncate transition-colors">
                                                        {{ $source['title'] ?? $source['uri'] }}
                                                    </a>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
