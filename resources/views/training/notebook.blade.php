{{-- The notebook (TrainingController::notebook): notes due for a revisit, where the learner records
     what they think now, and every note with its history. That record is the learning loop. --}}
<x-app-layout title="Notebook | Training | MindCollector">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-8">
    @include('training.partials.top')
    <header class="max-w-3xl space-y-3">
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl sm:text-4xl leading-[1.05]">What you marked, and what changed</h1>
        <p class="text-[15px] text-ink-muted">Notes come back on a schedule. Each time, record what you think now. Over time this shows which first readings held and which didn't.</p>
    </header>

    @php($total = $outcomes->sum())
    @if ($total)
        <section class="grid gap-3 grid-cols-3 max-w-2xl">
            <div class="linear-card p-4"><p class="text-2xl font-extrabold text-ink tabular-nums">{{ $total }}</p><p class="text-[11.5px] text-ink-subtle">revisits recorded</p></div>
            <div class="linear-card p-4"><p class="text-2xl font-extrabold text-ink tabular-nums">{{ ($outcomes['changed_mind'] ?? 0) + ($outcomes['now_understand'] ?? 0) }}</p><p class="text-[11.5px] text-ink-subtle">moved your understanding</p></div>
            <div class="linear-card p-4"><p class="text-2xl font-extrabold text-ink tabular-nums">{{ $outcomes['still_confused'] ?? 0 }}</p><p class="text-[11.5px] text-ink-subtle">still unclear after a revisit</p></div>
        </section>
    @endif

    <section class="space-y-3">
        <div class="flex items-baseline justify-between gap-3"><h2 class="text-ink font-bold text-xl">Due for a revisit</h2><span class="text-[12px] text-ink-subtle">{{ $due->count() }} waiting</span></div>
        @forelse ($due as $a)
            @php($lesson = $curriculum->lesson($a->lesson_slug))
            <article class="linear-card !bg-surface-1 p-4 grid gap-4 md:grid-cols-2">
                <div class="space-y-3 min-w-0">
                    <div class="flex flex-wrap items-center gap-2"><span class="kind kind-{{ $a->kind->value }}">{{ $a->kind->label() }}</span><span class="text-[12px] text-ink-subtle">{{ $lesson['title'] ?? $a->lesson_slug }} · marked {{ $a->created_at->format('j M') }}</span></div>
                    <p class="text-[14.5px] text-ink-muted border-l-2 border-line-strong pl-3">“{{ $a->quote }}”</p>
                    @if ($a->body)<p class="text-[14px] text-ink"><b>You wrote:</b> {{ $a->body }}</p>@endif
                    @if ($a->revisits->isNotEmpty())
                        <ul class="space-y-1 text-[13px] text-ink-muted">
                            @foreach ($a->revisits as $r)<li><span class="text-ink-subtle tabular-nums">{{ $r->created_at->format('j M') }}</span> · <b class="text-ink">{{ $r->outcome->label() }}.</b> {{ $r->reflection }}</li>@endforeach
                        </ul>
                    @endif
                    @if ($lesson)<a class="text-[13px] text-gold hover:text-gold-light" href="{{ route('training.lesson', $a->lesson_slug) }}">Reread it in the lesson</a>@endif
                </div>
                <form method="post" action="{{ route('training.notes.revisit', $a) }}" class="space-y-3">
                    @csrf
                    <p class="text-[14px] text-ink font-semibold">Reading it again, what do you think now?</p>
                    <div class="outcomes" role="radiogroup" aria-label="Outcome">
                        @foreach ($outcomeOptions as $o)<label><input type="radio" name="outcome" value="{{ $o->value }}" required> {{ $o->label() }}</label>@endforeach
                    </div>
                    <textarea class="form-textarea !resize-y" name="reflection" rows="3" aria-label="What changed, or what is still unclear" placeholder="What changed, or what's still unclear? If you changed your mind, what did it?"></textarea>
                    <button class="btn-primary !px-5 !py-2" type="submit">Record revisit</button>
                </form>
            </article>
        @empty
            <p class="text-[14px] text-ink-muted">Nothing due. Notes marked <i>Confusing</i> or <i>Question</i> come back the next day; others after three or seven days.</p>
        @endforelse
    </section>

    <section class="space-y-3">
        <h2 class="text-ink font-bold text-xl">All notes</h2>
        <form method="get" class="flex flex-wrap items-end gap-3">
            <label class="space-y-1 text-[12px] text-ink-subtle w-40">Kind<select class="form-select" name="kind"><option value="">Any</option>@foreach ($kinds as $v => $label)<option value="{{ $v }}" @selected(($filters['kind'] ?? '') === $v)>{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-[12px] text-ink-subtle w-40">Status<select class="form-select" name="status"><option value="">Any</option>@foreach (['open' => 'Open', 'revisited' => 'Revisited', 'resolved' => 'Resolved'] as $v => $label)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-[12px] text-ink-subtle w-56">Subject<select class="form-select" name="subject"><option value="">Any</option>@foreach ($curriculum->subjects() as $s)<option value="{{ $s['slug'] }}" @selected(($filters['subject'] ?? '') === $s['slug'])>{{ $s['title'] }}</option>@endforeach</select></label>
            <button class="btn-secondary !py-2" type="submit">Filter</button>
        </form>
        <div class="grid gap-3 md:grid-cols-2">
            @forelse ($notes as $a)
                @php($lesson = $curriculum->lesson($a->lesson_slug))
                <div class="n-item">
                    <div class="meta">
                        <span class="kind kind-{{ $a->kind->value }}">{{ $a->kind->label() }}</span><span>{{ ucfirst($a->status) }}</span>
                        @if ($a->revisit_on && $a->status !== 'resolved')<span>back {{ $a->revisit_on->format('j M') }}</span>@endif
                        @if ($a->revisits->count())<span>{{ $a->revisits->count() }} {{ Str::plural('revisit', $a->revisits->count()) }}</span>@endif
                    </div>
                    <div class="quote">“{{ $a->quote }}”</div>
                    @if ($a->body)<div class="body">{{ $a->body }}</div>@endif
                    @if ($a->revisits->isNotEmpty())<div class="text-[12.5px] text-ink-subtle">Latest: <b class="text-ink-muted">{{ $a->revisits->last()->outcome->label() }}</b>{{ $a->revisits->last()->reflection ? ' · '.Str::limit($a->revisits->last()->reflection, 120) : '' }}</div>@endif
                    @if ($lesson)<a class="text-[12.5px] text-gold hover:text-gold-light" href="{{ route('training.lesson', $a->lesson_slug) }}">{{ $lesson['title'] }}</a>@endif
                </div>
            @empty
                <p class="text-[14px] text-ink-muted">No notes match. Select text in any lesson to add one.</p>
            @endforelse
        </div>
        <div>{{ $notes->links() }}</div>
    </section>
</div>
</x-app-layout>
