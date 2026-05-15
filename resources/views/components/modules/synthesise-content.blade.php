@props(['module', 'moduleResearch' => null])

<div class="linear-card overflow-hidden">

    <div class="px-6 pt-5 pb-4 border-b border-border">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="page-section-title">Synthesise Content</h3>
                <p class="page-section-desc mt-0.5">
                    Turn the fetched research into structured module content using AI.
                    The result overwrites page 1 of this module.
                </p>
            </div>
            <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-surface-1 text-ink-muted border border-border">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.347.745A3.002 3.002 0 0112 21a3.002 3.002 0 01-2.79-2.055l-.347-.745z"/>
                </svg>
                OpenAI
            </span>
        </div>
    </div>

    @if($moduleResearch === null)

        <div class="px-6 py-5 flex items-center gap-3">
            <svg class="w-4 h-4 text-ink-subtle shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <p class="text-[13px] text-ink-subtle">Run the Research tool first to fetch source material.</p>
        </div>

    @else

        <div
            x-data="{
                loading: false,
                result: null,
                error: null,
                userPrompt: '',
                async run() {
                    this.loading = true;
                    this.result = null;
                    this.error = null;
                    try {
                        const res = await fetch('{{ route('modules.synthesise', $module) }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ user_prompt: this.userPrompt }),
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.error = data.error ?? 'Synthesis failed. Please try again.';
                        } else {
                            this.result = true;
                            setTimeout(() => window.location.reload(), 1000);
                        }
                    } catch (e) {
                        this.error = 'Network error. Please check your connection and try again.';
                    } finally {
                        this.loading = false;
                    }
                },
            }"
            class="px-6 py-5 space-y-4"
        >
            {{-- Research source indicator --}}
            <div class="flex items-center gap-2 text-[12px] text-ink-subtle">
                <svg class="w-3.5 h-3.5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Source: <span class="text-ink-muted truncate">{{ $moduleResearch->title }}</span>
            </div>

            {{-- Custom prompt --}}
            <div class="space-y-1.5">
                <label class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide">
                    Custom Instructions (optional)
                </label>
                <textarea
                    x-model="userPrompt"
                    placeholder="e.g. Write a beginner PvP guide highlighting key differences. Focus on cooldown timers and when to use each."
                    rows="2"
                    class="w-full rounded-md border border-border bg-surface-1 px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-accent focus:outline-none resize-none"
                ></textarea>
                <p class="text-[11px] text-ink-subtle">Leave blank for a general structured guide.</p>
            </div>

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
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.347.745A3.002 3.002 0 0112 21a3.002 3.002 0 01-2.79-2.055l-.347-.745z"/>
                    </svg>
                </template>
                <template x-if="loading">
                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </template>
                <span x-text="loading ? 'Synthesising...' : 'Synthesise Content'"></span>
            </button>

            {{-- Error --}}
            <div x-show="error !== null" x-cloak class="flex items-start gap-2 p-3 rounded-lg bg-red-500/10 border border-red-500/20">
                <svg class="w-4 h-4 text-red-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-[13px] text-red-400" x-text="error"></p>
            </div>

            {{-- Success --}}
            <div x-show="result !== null" x-cloak class="flex items-center gap-2 p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
                <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <p class="text-[13px] text-emerald-400">Content saved. Reloading…</p>
            </div>

        </div>

    @endif

</div>
