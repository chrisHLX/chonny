@props(['module', 'modulePages'])

<div
    x-data="{
        pages: {{ Js::from($modulePages->map(fn($p) => ['id' => $p->id, 'title' => $p->title, 'content' => $p->content])) }},
        pageId: null,
        pageTitle: '',
        get isEditing() { return this.pageId !== null; },
        loadPage(id) {
            const page = this.pages.find(p => p.id === id);
            if (!page) return;
            this.pageId = page.id;
            this.pageTitle = page.title;
            this.$refs.contentArea.value = page.content;
            this.$nextTick(() => {
                document.getElementById('content-editor-section').scrollIntoView({ behavior: 'smooth' });
            });
        },
        newPage() {
            this.pageId = null;
            this.pageTitle = '';
            this.$refs.contentArea.value = '';
        }
    }"
    class="space-y-4"
>

    {{-- Existing pages --}}
    @foreach($modulePages as $modulePage)
        <div class="linear-card overflow-hidden">
            <div class="px-6 pt-5 pb-4 border-b border-border flex items-start justify-between gap-4">
                <div>
                    <p class="page-section-title">{{ $modulePage->title }}</p>
                    <p class="page-section-desc mt-0.5">Page {{ $modulePage->page_number }}</p>
                </div>
                <div class="flex gap-2 shrink-0">
                    <button
                        type="button"
                        @click="loadPage({{ $modulePage->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[13px] font-medium text-ink-muted border border-border hover:border-accent/50 hover:text-ink rounded-md transition-colors"
                    >
                        Edit
                    </button>
                    <form action="{{ route('module-page.destroyPage', ['modulePage' => $modulePage->id]) }}"
                          method="POST" onsubmit="return confirm('Delete this page?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger">Delete Page</button>
                    </form>
                </div>
            </div>
            <div class="px-6 py-5 prose prose-invert max-w-none text-[13px]">
                {!! Str::markdown($modulePage->content) !!}
            </div>
        </div>
    @endforeach

    {{-- Content editor --}}
    <div id="content-editor-section" class="linear-card overflow-hidden">

        <div class="px-6 pt-5 pb-4 border-b border-border">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="page-section-title" x-text="isEditing ? 'Edit Page' : 'New Page'"></h3>
                    <p class="page-section-desc mt-0.5">
                        Write source material for <strong class="text-ink-muted">{{ $module->name }}</strong>.
                        This is what the AI reads to generate quiz questions.
                    </p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span
                        x-show="isEditing"
                        x-cloak
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20"
                    >
                        Editing existing page
                    </span>
                    <button
                        type="button"
                        x-show="isEditing"
                        x-cloak
                        @click="newPage()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[13px] font-medium text-ink-muted border border-border hover:border-accent/50 hover:text-ink rounded-md transition-colors"
                    >
                        + New Page
                    </button>
                </div>
            </div>
        </div>

        <form action="{{ route('module-pages.save', $module) }}" method="POST">
            @csrf

            <input type="hidden" name="page_id" :value="pageId">

            <div class="px-6 pt-4 space-y-4">

                {{-- Page title --}}
                <div>
                    <x-input-label for="pageTitle" value="Page Title" />
                    <input
                        type="text"
                        id="pageTitle"
                        name="page_title"
                        x-model="pageTitle"
                        :placeholder="'{{ addslashes($module->name) }}'"
                        class="form-input mt-1 w-full text-[13px]"
                        maxlength="255"
                    >
                </div>

                {{-- Content --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <x-input-label for="descriptionC" value="Content" />
                        <span class="text-[11px] text-ink-muted font-medium tracking-wide uppercase">Markdown supported</span>
                    </div>

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
                        name="content"
                        id="descriptionC"
                        x-ref="contentArea"
                        rows="16"
                        spellcheck="true"
                        class="form-textarea font-mono text-[13px] leading-relaxed w-full resize-y"
                        placeholder="# Introduction&#10;&#10;Write your module content here using Markdown...&#10;&#10;## Key Concepts&#10;&#10;- Concept one — explain it clearly&#10;- Concept two — include examples where helpful&#10;&#10;The more structured and detailed your content, the higher quality questions the AI will generate."
                    ></textarea>
                    <x-input-error :messages="$errors->get('content')" class="mt-1.5" />
                </div>

            </div>

            {{-- Footer --}}
            <div class="px-6 py-4 mt-2 bg-bg-subtle/40 border-t border-border flex items-center justify-between gap-4">
                <p class="text-[11px] text-ink-muted leading-relaxed max-w-sm">
                    Aim for at least 200 words. Include headings, bullet points, and concrete examples to get the best question variety.
                </p>
                <button
                    type="submit"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors"
                    x-text="isEditing ? 'Update Page' : 'Save New Page'"
                ></button>
            </div>

        </form>
    </div>

</div>
