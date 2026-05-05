@props(['module'])

<div class="linear-card overflow-hidden">

    <div class="px-6 pt-5 pb-4 border-b border-border">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="page-section-title">Research Latest Material</h3>
                <p class="page-section-desc mt-0.5">
                    Search the web for up-to-date information on this topic using Gemini.
                    Review the result and add it to your module content before generating questions.
                </p>
            </div>
            <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-surface-1 text-ink-muted border border-border">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Gemini
            </span>
        </div>
    </div>

    @if(empty(config('services.gemini.key')))

        <div class="px-6 py-5 flex items-center gap-3">
            <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <p class="text-[13px] text-amber-400">
                Research unavailable — <span class="font-mono">GEMINI_API_KEY</span> not configured.
            </p>
        </div>

    @else

        <div
            x-data="{
                loading: false,
                result: null,
                error: null,
                appended: false,
                async run() {
                    this.loading = true;
                    this.result = null;
                    this.error = null;
                    this.appended = false;
                    try {
                        const res = await fetch('{{ route('modules.research', $module) }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                            },
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.error = data.error ?? 'Research failed. Please try again.';
                        } else {
                            this.result = data;
                        }
                    } catch (e) {
                        this.error = 'Network error. Please check your connection and try again.';
                    } finally {
                        this.loading = false;
                    }
                },
                append() {
                    const textarea = document.getElementById('descriptionC');
                    if (textarea && this.result?.summary) {
                        const sep = textarea.value.trim() ? '\n\n' : '';
                        textarea.value += sep + this.result.summary;
                        textarea.scrollTop = textarea.scrollHeight;
                    }
                    this.appended = true;
                    setTimeout(() => { this.appended = false; }, 2500);
                },
            }"
            class="px-6 py-5 space-y-4"
        >
            {{-- Trigger button --}}
            <button
                type="button"
                @click="run()"
                :disabled="loading"
                class="inline-flex items-center gap-2 px-4 py-2 text-[13px] font-medium border rounded-md transition-colors
                       border-border text-ink-muted hover:border-accent/50 hover:text-ink
                       disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <template x-if="!loading">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </template>
                <template x-if="loading">
                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </template>
                <span x-text="loading ? 'Searching...' : 'Research Latest Material'"></span>
            </button>

            {{-- Error --}}
            <div x-show="error !== null" x-cloak class="flex items-start gap-2 p-3 rounded-lg bg-red-500/10 border border-red-500/20">
                <svg class="w-4 h-4 text-red-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-[13px] text-red-400" x-text="error"></p>
            </div>

            {{-- Result --}}
            <div x-show="result !== null" x-cloak class="space-y-3">

                {{-- Summary --}}
                <div class="rounded-lg border border-border bg-surface-1 overflow-hidden">
                    <div class="px-4 py-2.5 border-b border-border flex items-center justify-between">
                        <span class="text-[11px] font-medium text-ink-muted uppercase tracking-wide">Research Summary</span>
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-500/10 text-blue-400 border border-blue-500/20">
                            Gemini · Web Search
                        </span>
                    </div>
                    <div
                        class="px-4 py-3 max-h-52 overflow-y-auto text-[13px] text-ink leading-relaxed whitespace-pre-wrap"
                        x-text="result?.summary"
                    ></div>
                </div>

                {{-- Sources --}}
                <template x-if="result?.sources?.length">
                    <div class="space-y-1.5">
                        <p class="text-[11px] text-ink-muted font-medium uppercase tracking-wide">Sources</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="source in result.sources" :key="source.uri">
                                <a
                                    :href="source.uri"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded border border-border bg-surface-1 text-[11px] text-ink-muted hover:text-ink hover:border-accent/40 transition-colors truncate max-w-[260px]"
                                >
                                    <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                    <span x-text="source.title" class="truncate"></span>
                                </a>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Add to content --}}
                <div class="flex items-center gap-3 pt-1">
                    <button
                        type="button"
                        @click="append()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium rounded-md border transition-colors
                               border-accent/40 text-accent hover:bg-accent/5"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add to module content
                    </button>
                    <span
                        x-show="appended"
                        x-cloak
                        class="text-[12px] text-emerald-400 font-medium"
                    >
                        Added to content editor
                    </span>
                </div>

            </div>

        </div>

    @endif

</div>
