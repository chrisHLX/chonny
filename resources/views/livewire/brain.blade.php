{{-- The MindCollector Brain — the model every Claude-drafted guide is written from.

     One always-rendered root element (see CLAUDE.md, Livewire rule 25): the wrapper below is
     unconditional, so the component never produces zero roots even if the source file is missing. --}}

<div class="max-w-3xl mx-auto px-4 sm:px-0 pb-16">

    <header class="pt-6 pb-4">
        <p class="text-[11px] uppercase tracking-[0.18em] text-gold mb-2">MindCollector</p>
        <h1 class="font-display italic text-3xl sm:text-4xl text-ink">{{ $meta['title'] ?? 'The MindCollector Brain' }}</h1>

        @if (! empty($meta['subtitle']))
            <p class="mt-2 text-ink-muted leading-relaxed">{{ $meta['subtitle'] }}</p>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-[12px] text-ink-subtle">
            @if (! empty($meta['updated']))
                <span>Updated {{ $meta['updated'] }}</span>
            @endif
            <a href="{{ route('guides.machine') }}" wire:navigate class="text-violet hover:text-violet-hover transition-colors">
                See the guides written from it &rarr;
            </a>
        </div>
    </header>

    @if (empty($sections))
        <div class="linear-card p-5 text-ink-muted text-sm">
            The document could not be read right now. Nothing has been lost &mdash; it lives in the
            repository at <code class="text-gold">data/brain/brain.md</code>.
        </div>
    @else

        {{-- Legend. The tiers are the point of the page, so they are explained before the reader
             meets the first badge rather than in a footnote. --}}
        <div class="linear-card p-4 mb-6">
            <p class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle mb-2.5">How sure we are</p>
            <dl class="space-y-1.5 text-[12.5px]">
                <div class="flex gap-2.5">
                    <dt class="shrink-0"><span class="badge-green">Observed</span></dt>
                    <dd class="text-ink-muted">A top player said it about real games, or our own player found it in his.</dd>
                </div>
                <div class="flex gap-2.5">
                    <dt class="shrink-0"><span class="badge-blue">Derived</span></dt>
                    <dd class="text-ink-muted">Follows from game mechanics or the data on this site. You can check it.</dd>
                </div>
                <div class="flex gap-2.5">
                    <dt class="shrink-0"><span class="badge-amber">Hypothesis</span></dt>
                    <dd class="text-ink-muted">Reasoned and plausible, but untested. We might be wrong.</dd>
                </div>
            </dl>
        </div>

        {{-- Contents. Plain anchors, not wire:navigate — these are in-page jumps. --}}
        <nav class="linear-card p-4 mb-8">
            <p class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle mb-2.5">Contents</p>
            <ol class="grid sm:grid-cols-2 gap-x-6 gap-y-1 text-[12.5px]">
                @foreach ($sections as $i => $section)
                    <li class="flex gap-2">
                        <span class="text-ink-subtle tabular-nums">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                        <a href="#{{ $section['id'] }}" class="text-ink-muted hover:text-gold transition-colors">
                            {{ $section['heading'] }}
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>

        @if ($error)
            <p class="mb-4 text-[12.5px] text-amber-400">{{ $error }}</p>
        @endif

        <div class="space-y-6">
            @foreach ($sections as $section)
                @php
                    $tier = \App\Support\BrainDocument::tierLabel($section['tier'] ?? null);
                    $comments = $commentsBySection[$section['id']] ?? collect();
                    $isOpen = $commentingOn === $section['id'];
                @endphp

                <section id="{{ $section['id'] }}" class="linear-card p-5 scroll-mt-6" wire:key="brain-{{ $section['id'] }}">

                    <div class="flex items-start justify-between gap-4 mb-3">
                        <h2 class="font-display italic text-xl text-ink leading-snug">{{ $section['heading'] }}</h2>

                        @if ($tier)
                            <span class="{{ $tier['class'] }} shrink-0 mt-1" title="{{ $tier['title'] }}">{{ $tier['label'] }}</span>
                        @endif
                    </div>

                    <div class="prose-guide text-[13.5px] text-ink-muted">
                        {!! $section['html'] !!}
                    </div>

                    {{-- Comment thread. Its own markup rather than <x-guides.note-thread> because
                         that component's copy is written for a guide's author and steps, and its
                         wire:click targets are Guides\Show's method names. --}}
                    <div class="mt-4 pt-3 border-t border-line">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle">
                                {{ $comments->isEmpty() ? 'Comments' : 'Comments ('.$comments->count().')' }}
                            </span>

                            @auth
                                <button type="button" wire:click="startComment('{{ $section['id'] }}')"
                                        class="text-[12px] text-violet hover:text-violet-hover transition-colors">
                                    {{ $isOpen ? 'Cancel' : '+ Push back on this' }}
                                </button>
                            @else
                                <a href="{{ route('login') }}" class="text-[12px] text-ink-subtle hover:text-gold">Sign in to comment</a>
                            @endauth
                        </div>

                        @if ($comments->isNotEmpty())
                            <div class="mt-2 space-y-2">
                                @foreach ($comments as $c)
                                    <div class="text-[12.5px] pl-3 border-l-2 border-violet/40" wire:key="bc-{{ $c->id }}">
                                        <span class="text-violet">&#64;{{ $c->user?->handle() ?? 'someone' }}</span>
                                        <span class="text-ink-muted">{{ $c->body }}</span>
                                        <span class="text-ink-subtle text-[11px]">&middot; {{ $c->created_at->diffForHumans() }}</span>
                                        @if (auth()->id() === $c->user_id)
                                            <button type="button" wire:click="deleteComment({{ $c->id }})"
                                                    class="text-[11px] text-ink-subtle hover:text-red-400 ml-1">Delete</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($isOpen)
                            <div class="mt-2">
                                <textarea wire:model="body" rows="3" maxlength="1000"
                                          placeholder="Where is this wrong, and what would you say instead?"
                                          class="form-textarea w-full text-[13px]"></textarea>
                                <div class="flex items-center gap-2 mt-1.5">
                                    <button type="button" wire:click="postComment" class="btn-secondary text-[12px]">Post comment</button>
                                    <span class="text-[11px] text-ink-subtle">
                                        Corrections here change every guide written from this model.
                                    </span>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>

        <p class="mt-8 text-[12.5px] text-ink-subtle leading-relaxed">
            This model is what the machine-drafted guides are written from, and it is wrong in
            places we cannot see from the inside. If you disagree with a guide, the most useful
            place to say so is the section above that produced it.
        </p>
    @endif
</div>
