<x-app-layout title="Claim | Training | MindCollector">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-5">
    @include('training.partials.top')
    <p class="text-[12px] text-ink-subtle"><a href="{{ route('training.claims.index') }}" class="hover:text-ink">Claims</a> / {{ $claim->created_at->format('j M Y') }}</p>
    <article class="max-w-3xl space-y-4">
        <h1 class="font-display font-extrabold tracking-tight text-ink text-2xl sm:text-3xl leading-tight">{{ $claim->claim }}</h1>
        <p class="text-[13px] text-ink-subtle">{{ $claim->source ? 'From '.$claim->source.' · ' : '' }}{{ $claim->confidence_before }}% sure before, {{ $claim->confidence_after }}% after thinking it through</p>
        @foreach (['known' => 'What we actually know', 'alternatives' => 'What else could explain it', 'would_change' => 'What would change confidence', 'if_acted' => 'If we act on it'] as $f => $h)
            @if ($claim->$f)
                <section class="linear-card !bg-surface-1 p-4"><p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">{{ $h }}</p><p class="whitespace-pre-wrap text-[14.5px] text-ink-muted mt-1.5">{{ $claim->$f }}</p></section>
            @endif
        @endforeach
        @if ($claim->outcome)
            <section class="rounded-lg bg-emerald-500/10 p-4"><p class="text-[11px] font-bold uppercase tracking-[.12em] text-emerald-300">What happened</p><p class="text-[14.5px] text-ink mt-1.5"><b>{{ \App\Models\Training\Claim::OUTCOMES[$claim->outcome] }}.</b> {{ $claim->outcome_note }}</p></section>
        @else
            <section class="linear-card !bg-surface-1 p-4 space-y-3">
                <p class="text-[11px] font-bold uppercase tracking-[.12em] text-ink-subtle">{{ $claim->isDue() ? 'Due now' : 'Comes back on '.$claim->revisit_on->format('j M Y') }}</p>
                <form method="post" action="{{ route('training.claims.update', $claim) }}" class="space-y-3">
                    @csrf @method('PATCH')
                    <p class="text-[14px] text-ink"><b>What turned out to be true?</b> You can record it now if you already know.</p>
                    <div class="outcomes">@foreach (\App\Models\Training\Claim::OUTCOMES as $v => $l)<label><input type="radio" name="outcome" value="{{ $v }}" required> {{ $l }}</label>@endforeach</div>
                    <textarea class="form-textarea !resize-y" name="outcome_note" rows="3" placeholder="What evidence settled it? What did you miss at the start?" aria-label="What settled it"></textarea>
                    <button class="btn-primary !px-5 !py-2" type="submit">Record outcome</button>
                </form>
            </section>
        @endif
    </article>
</div>
</x-app-layout>
