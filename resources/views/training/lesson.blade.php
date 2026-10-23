{{-- A Training lesson (TrainingController::lesson): the problem first, then evidence, the principle,
     a new situation in another domain, what would settle it, and a look back. Notes on any passage
     come back for a revisit (public/js/training/lesson.js). --}}
<x-app-layout :title="$lesson['title'].' | Training | MindCollector'" :description="$lesson['summary']">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10">
    @include('training.partials.top')

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_300px] items-start">
        <article id="lessonBody" class="min-w-0">
            <p class="text-[12px] text-ink-subtle flex flex-wrap gap-2">
                <a href="{{ route('training') }}" class="hover:text-ink">Training</a><span>/</span>
                <a href="{{ route('training.subject', $subject['slug']) }}" class="hover:text-ink">{{ $subject['title'] }}</a><span>/</span>
                <span>Lesson {{ $lesson['number'] }}</span>
            </p>
            <header class="mt-3 max-w-[68ch] space-y-3">
                <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl sm:text-4xl leading-[1.05]">{{ $lesson['title'] }}</h1>
                <p class="text-[15.5px] text-ink-muted">{{ $lesson['summary'] }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <span class="badge-gray">{{ $lesson['minutes'] }} min</span>
                    @foreach ($concepts as $c)<span class="badge-blue" title="{{ $c['definition'] }}">{{ $c['name'] }}</span>@endforeach
                </div>
            </header>

            {{-- 1. The problem, before any teaching --}}
            <section class="t-step" data-step="case">
                <div class="t-step-h"><span class="n">01</span><h2>The problem</h2><span class="t-domain">{{ $lesson['case']['domain'] }}</span></div>
                <div class="t-read"><div class="t-scenario">@include('training.partials.blocks', ['blocks' => $lesson['case']['scenario'], 'prefix' => 'case'])</div></div>
                <div class="t-read mt-4" data-written="interpretation">
                    <p class="text-ink font-semibold">{{ $lesson['case']['prompt'] }}</p>
                    <p class="text-[13px] text-ink-subtle">Write it before reading on. You'll compare it with what you think at the end.</p>
                    <textarea class="form-textarea !resize-y min-h-[96px]" id="t-interpretation" rows="4" aria-label="Your interpretation"></textarea>
                    <div class="flex flex-wrap items-center gap-3">
                        <button class="btn-primary !px-5 !py-2" type="button" data-save>Save and see more evidence</button>
                        <button class="btn-ghost" type="button" data-skip>Skip</button>
                        <span class="t-saved" data-saved hidden>Saved</span>
                    </div>
                </div>
            </section>

            {{-- 2. Evidence that may change the first reading, and the in-lesson question --}}
            <section class="t-step" data-step="reveal" hidden>
                <div class="t-step-h"><span class="n">02</span><h2>More evidence</h2></div>
                <div class="t-read">@include('training.partials.blocks', ['blocks' => $lesson['case']['reveal'], 'prefix' => 'reveal'])</div>
                <div class="max-w-[68ch]">@include('training.partials.question', ['exercise' => 'case', 'q' => $lesson['case'], 'heading' => 'What fits the evidence?'])</div>
            </section>

            {{-- 3. The principle --}}
            <section class="t-step" data-step="principle" hidden>
                <div class="t-step-h"><span class="n">03</span><h2>The principle</h2></div>
                <div class="t-read">@include('training.partials.blocks', ['blocks' => $lesson['principle'], 'prefix' => 'principle'])</div>
            </section>

            {{-- 4. A new situation in a different domain: what mastery counts --}}
            <section class="t-step" data-step="transfer" hidden>
                <div class="t-step-h"><span class="n">04</span><h2>A new situation</h2><span class="t-domain">{{ $lesson['transfer']['domain'] }}</span></div>
                <div class="t-read"><div class="t-scenario">@include('training.partials.blocks', ['blocks' => (array) $lesson['transfer']['scenario'], 'prefix' => 'transfer'])</div></div>
                <div class="max-w-[68ch]">@include('training.partials.question', ['exercise' => 'transfer', 'q' => $lesson['transfer'], 'heading' => 'Apply it'])</div>
                <p class="text-[13px] text-ink-subtle mt-2 max-w-[68ch]">Your first answer here counts toward mastery of {{ collect($concepts)->pluck('name')->join(', ', ' and ') }}.</p>
            </section>

            {{-- 5. What evidence would settle it --}}
            <section class="t-step" data-step="evidence" hidden>
                <div class="t-step-h"><span class="n">05</span><h2>What would settle it?</h2></div>
                <div class="t-read" data-written="evidence">
                    <p class="text-ink font-semibold">{{ $lesson['evidence']['prompt'] }}</p>
                    <textarea class="form-textarea !resize-y min-h-[96px]" id="t-evidence" rows="4" aria-label="Evidence you would need"></textarea>
                    <div class="flex flex-wrap items-center gap-3">
                        <button class="btn-primary !px-5 !py-2" type="button" data-save>Save and compare</button>
                        <span class="t-saved" data-saved hidden>Saved</span>
                    </div>
                </div>
                <div class="t-read mt-5" data-model hidden>
                    <p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">Evidence a careful analyst would look for</p>
                    @include('training.partials.blocks', ['blocks' => [['list' => $lesson['evidence']['model']]], 'prefix' => 'evidence'])
                    <p class="text-[13px] text-ink-subtle">Compare with yours. Anything you missed is worth a note.</p>
                </div>
            </section>

            {{-- 6. Look back: the double loop --}}
            <section class="t-step" data-step="reflection" hidden>
                <div class="t-step-h"><span class="n">06</span><h2>Look back</h2></div>
                <div class="t-read">
                    <p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">What you wrote first</p>
                    <blockquote class="t-scenario whitespace-pre-wrap" data-first-reading></blockquote>
                    <div data-written="reflection" class="space-y-3">
                        <p class="text-ink font-semibold">Reading it again after the whole lesson: what would you change, and what made you change it?</p>
                        <textarea class="form-textarea !resize-y min-h-[80px]" id="t-reflection" rows="3" aria-label="Your reflection"></textarea>
                        <div class="flex flex-wrap items-center gap-3">
                            <button class="btn-secondary !px-5 !py-2" type="button" data-save>Save reflection</button>
                            <span class="t-saved" data-saved hidden>Saved</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="t-step" data-step="done" hidden>
                <div class="t-step-h"><span class="n">✓</span><h2>Lesson complete</h2></div>
                <div class="t-read">
                    <p>Notes you marked <i>Confusing</i> or <i>Question</i> come back in your notebook tomorrow. When they do, record whether your understanding changed.</p>
                    <div class="flex flex-wrap gap-3">
                        @if ($next)<a class="btn-primary" href="{{ route('training.lesson', $next['slug']) }}">Next: {{ $next['title'] }}</a>@endif
                        <a class="btn-secondary" href="{{ route('training.notebook') }}">Open notebook</a>
                    </div>
                </div>
            </section>

            @if (! empty($lesson['sources']))
                <section class="t-step">
                    <p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">Sources and further reading</p>
                    <ul class="mt-2 list-disc pl-5 text-[13px] text-ink-subtle space-y-1">@foreach ($lesson['sources'] as $s)<li>{!! \App\Training\Prose::inline($s) !!}</li>@endforeach</ul>
                </section>
            @endif
        </article>

        <aside class="lg:sticky lg:top-4 space-y-3" aria-label="Your notes on this lesson">
            <div class="flex items-baseline justify-between"><h2 class="text-[15px] font-bold text-ink">Your notes</h2><span class="text-[12px] text-ink-subtle" id="noteCount"></span></div>
            <p class="text-[13px] text-ink-muted leading-relaxed">{{ request()->userAgent() && preg_match('/Mobi|Android/i', request()->userAgent()) ? 'Press and hold' : 'Select' }} any passage to highlight it or add a note. <i>Confusing</i> and <i>Question</i> come back tomorrow, <i>Disagree</i> in three days, notes in a week.</p>
            <div class="flex flex-wrap gap-1.5">@foreach ($kinds as $k)<span class="kind kind-{{ $k['value'] }}">{{ $k['label'] }}</span>@endforeach</div>
            <div class="space-y-2" id="notes"></div>
        </aside>
    </div>
</div>
<script>window.TRAINING = @json($client);</script>
<script src="/js/training/lesson.js?v={{ filemtime(public_path('js/training/lesson.js')) }}"></script>
<script src="/js/training/sims.js?v={{ filemtime(public_path('js/training/sims.js')) }}"></script>
</x-app-layout>
