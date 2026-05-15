<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-4xl mx-auto space-y-6">

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-[17px] font-semibold text-ink">{{ $module->name }}</h1>
                    @if($module->proficiencies()->first())
                        <p class="text-[13px] text-ink-muted mt-0.5">{{ $module->proficiencies()->first()->name }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('modules.export', $module) }}" target="_blank"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[13px] font-medium text-ink-muted border border-border hover:border-accent/50 hover:text-ink rounded-md transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Export Diagnostic
                    </a>
                    <form action="{{ route('modules.destroy', $module) }}" method="POST"
                          onsubmit="return confirm('Delete this module?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger">Delete Module</button>
                    </form>
                </div>
            </div>

            {{-- Research panel --}}
            <x-modules.research-panel
                :module="$module"
                :subject-research-count="$subjectResearchCount"
                :module-page-count="$modulePageCount"
                :module-research="$moduleResearch"
            />

            {{-- Research history --}}
            @if($subjectResearch->isNotEmpty())
                <div class="linear-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-line">
                        <h3 class="page-section-title">Research History</h3>
                        <p class="page-section-desc mt-0.5">Previously fetched Gemini research for this subject. Click any entry to read it.</p>
                    </div>
                    <div>
                        @foreach($subjectResearch as $research)
                            @php
                                $isPrimaryDiscoveredFormat = isset($research->source_urls['discovered']);
                                $primaryUrl = $isPrimaryDiscoveredFormat ? ($research->source_urls['primary'] ?? null) : null;
                                $discovered = $isPrimaryDiscoveredFormat
                                    ? $research->source_urls['discovered']
                                    : collect($research->source_urls ?? [])->map(fn($url) => ['uri' => $url, 'title' => $url])->toArray();
                            @endphp
                            <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }"
                                 class="{{ !$loop->last ? 'border-b border-line' : '' }}">

                                {{-- Row header --}}
                                <button type="button" @click="open = !open"
                                        class="w-full flex items-start justify-between gap-4 px-5 py-3 text-left hover:bg-surface-2 transition-colors">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[13px] font-medium text-ink truncate">{{ $research->title }}</p>
                                        <p class="text-[11px] text-ink-subtle mt-0.5">
                                            {{ $research->created_at->diffForHumans() }}
                                            @if($research->module_id === $module->id)
                                                · <span class="text-accent">This module</span>
                                            @endif
                                            @if(!empty($discovered))
                                                · {{ count($discovered) }} sources
                                            @endif
                                        </p>
                                    </div>
                                    <svg class="w-3.5 h-3.5 shrink-0 text-ink-subtle mt-0.5 transition-transform"
                                         :class="open ? 'rotate-180' : ''"
                                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>

                                {{-- Expanded content --}}
                                <div x-show="open" x-cloak class="px-5 pb-4 space-y-3">
                                    <div class="rounded-lg border border-border bg-surface-1 overflow-hidden">
                                        <div class="px-4 py-2 border-b border-border flex items-center justify-between">
                                            <span class="text-[11px] font-medium text-ink-subtle uppercase tracking-wide">Research Content</span>
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-500/10 text-blue-400 border border-blue-500/20">
                                                Gemini · Web Search
                                            </span>
                                        </div>
                                        <div class="px-4 py-3 max-h-72 overflow-y-auto text-[13px] text-ink leading-relaxed whitespace-pre-wrap">{{ $research->content }}</div>
                                    </div>

                                    {{-- Primary source if user provided one --}}
                                    @if(!empty($primaryUrl))
                                        <div class="mb-2">
                                            <span class="text-[10px] font-semibold text-ink-subtle uppercase tracking-wide mr-2">Primary Source</span>
                                            <a href="{{ $primaryUrl }}"
                                               target="_blank" rel="noopener noreferrer"
                                               class="inline-flex items-center gap-1 px-2 py-1 rounded border border-accent/40 bg-accent/5 text-[11px] text-accent hover:text-accent-hover transition-colors truncate max-w-[260px]">
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                                </svg>
                                                <span class="truncate">{{ $primaryUrl }}</span>
                                            </a>
                                        </div>
                                    @endif

                                    {{-- Gemini discovered sources --}}
                                    @if(!empty($discovered))
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($discovered as $source)
                                                <a href="{{ $source['uri'] }}" target="_blank" rel="noopener noreferrer"
                                                   class="inline-flex items-center gap-1 px-2 py-1 rounded border border-border bg-surface-1 text-[11px] text-ink-muted hover:text-ink hover:border-accent/40 transition-colors truncate max-w-[260px]">
                                                    <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                                    </svg>
                                                    <span class="truncate">{{ $source['title'] ?? $source['uri'] }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif

                                    <button
                                        onclick="document.getElementById('descriptionC').value += '\n\n' + {{ Js::from($research->content) }}"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium rounded-md border transition-colors border-accent/40 text-accent hover:bg-accent/5">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                        </svg>
                                        Add to module content
                                    </button>
                                </div>

                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Synthesise content --}}
            <x-modules.synthesise-content
                :module="$module"
                :module-research="$moduleResearch"
            />

            {{-- Edit module --}}
            <x-modules.update-form :module="$module" :all-questions="$allQuestions" />

            {{-- Generate questions --}}
            <x-modules.generate-questions-form :module="$module" :modulePages="$modulePages" />

            {{-- Generation progress --}}
            @if($pipeline)
                <livewire:generation-progress :pipeline-id="$pipeline->id" />
            @endif

            {{-- Content manager --}}
            <x-modules.content-manager :module="$module" :modulePages="$modulePages" />

        </div>
    </div>
</x-app-layout>
