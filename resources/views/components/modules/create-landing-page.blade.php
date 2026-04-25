@props(['modulePages', 'module', 'allQuestions'])

@php $hasPage = $modulePages->isNotEmpty(); @endphp

<div class="linear-card overflow-hidden">

    {{-- Header --}}
    <div class="px-6 pt-5 pb-4 border-b border-border">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="page-section-title">
                    {{ $hasPage ? 'Edit Module Content' : 'Add Module Content' }}
                </h3>
                <p class="page-section-desc mt-0.5">
                    Write the source material for <strong class="text-ink-muted">{{ $module->name }}</strong>.
                    This content is what the AI reads to generate quiz questions — the richer the detail, the better the questions.
                </p>
            </div>
            @if($hasPage)
                <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Content added
                </span>
            @endif
        </div>
    </div>

    <form action="{{ route('modules-pagex.createLandingPage', $module->id) }}" method="POST">
        @csrf

        {{-- Markdown toolbar hint --}}
        <div class="px-6 pt-4">
            <div class="flex items-center justify-between mb-2">
                <x-input-label for="descriptionC" value="Content" />
                <span class="text-[11px] text-ink-muted font-medium tracking-wide uppercase">Markdown supported</span>
            </div>

            {{-- Markdown cheatsheet bar --}}
            <div class="flex flex-wrap gap-x-4 gap-y-1 mb-2 text-[11px] text-ink-muted font-mono">
                <span><span class="text-ink-subtle"># </span>Heading</span>
                <span><span class="text-ink-subtle">## </span>Subheading</span>
                <span><span class="text-ink-subtle">**</span>bold<span class="text-ink-subtle">**</span></span>
                <span><span class="text-ink-subtle">*</span>italic<span class="text-ink-subtle">*</span></span>
                <span><span class="text-ink-subtle">- </span>bullet list</span>
                <span><span class="text-ink-subtle">1. </span>numbered list</span>
                <span><span class="text-ink-subtle">`</span>inline code<span class="text-ink-subtle">`</span></span>
                <span><span class="text-ink-subtle">> </span>blockquote</span>
            </div>

            <textarea
                name="description"
                id="descriptionC"
                rows="16"
                spellcheck="true"
                class="form-textarea font-mono text-[13px] leading-relaxed w-full resize-y"
                placeholder="# Introduction&#10;&#10;Write your module content here using Markdown...&#10;&#10;## Key Concepts&#10;&#10;- Concept one — explain it clearly&#10;- Concept two — include examples where helpful&#10;&#10;The more structured and detailed your content, the higher quality questions the AI will generate."
            >{{ old('description', $modulePages->first()?->content) }}</textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
        </div>

        {{-- Footer: tips + submit --}}
        <div class="px-6 py-4 mt-2 bg-bg-subtle/40 border-t border-border flex items-center justify-between gap-4">
            <p class="text-[11px] text-ink-muted leading-relaxed max-w-sm">
                Aim for at least 200 words. Include headings, bullet points, and concrete examples to get the best question variety.
            </p>
            <x-primary-button>
                {{ $hasPage ? 'Update Content' : 'Save Content' }}
            </x-primary-button>
        </div>

    </form>
</div>
