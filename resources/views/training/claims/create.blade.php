<x-app-layout title="Examine a claim | Training | MindCollector">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-6">
    @include('training.partials.top')
    <header class="max-w-3xl space-y-2">
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl leading-[1.05]">Four questions, and a confidence before and after</h1>
    </header>
    @if ($errors->any())<div class="rounded-lg bg-rose-500/10 text-rose-300 px-4 py-2.5 text-[14px] max-w-3xl">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <form method="post" action="{{ route('training.claims.store') }}" class="linear-card !bg-surface-1 p-5 grid gap-4 sm:grid-cols-2 max-w-3xl">
        @csrf
        <label class="sm:col-span-2 space-y-1 text-[13px] text-ink-muted">The claim, in one or two sentences
            <textarea class="form-textarea !resize-y" name="claim" rows="2" required placeholder="For example: players who keep two defensives available win far more rounds.">{{ old('claim') }}</textarea></label>
        <label class="space-y-1 text-[13px] text-ink-muted">Where it came from
            <input class="form-input" name="source" value="{{ old('source') }}" placeholder="An article, a teammate, an AI review…"></label>
        <label class="space-y-1 text-[13px] text-ink-muted">How sure are you it's true, before thinking it through? <output id="t-cbo" class="text-ink font-semibold">{{ old('confidence_before', 50) }}%</output>
            <input type="range" name="confidence_before" min="0" max="100" step="5" value="{{ old('confidence_before', 50) }}" oninput="document.getElementById('t-cbo').value = this.value + '%'"></label>
        <label class="sm:col-span-2 space-y-1 text-[13px] text-ink-muted"><b class="text-ink">1. What do we actually know?</b> The observation, separated from the story told about it.
            <textarea class="form-textarea !resize-y" name="known" rows="3" required>{{ old('known') }}</textarea></label>
        <label class="sm:col-span-2 space-y-1 text-[13px] text-ink-muted"><b class="text-ink">2. What else could explain it?</b> Other causes, missing information, how the evidence was selected.
            <textarea class="form-textarea !resize-y" name="alternatives" rows="3" required>{{ old('alternatives') }}</textarea></label>
        <label class="sm:col-span-2 space-y-1 text-[13px] text-ink-muted"><b class="text-ink">3. What would change your confidence?</b> Evidence that would support or weaken it. Name it before you look.
            <textarea class="form-textarea !resize-y" name="would_change" rows="3" required>{{ old('would_change') }}</textarea></label>
        <label class="sm:col-span-2 space-y-1 text-[13px] text-ink-muted"><b class="text-ink">4. What happens if you act on it?</b> Immediate effects, delayed effects, and how you'd know if it worked. Optional.
            <textarea class="form-textarea !resize-y" name="if_acted" rows="3">{{ old('if_acted') }}</textarea></label>
        <label class="space-y-1 text-[13px] text-ink-muted">Confidence now <output id="t-cao" class="text-ink font-semibold">{{ old('confidence_after', 50) }}%</output>
            <input type="range" name="confidence_after" min="0" max="100" step="5" value="{{ old('confidence_after', 50) }}" oninput="document.getElementById('t-cao').value = this.value + '%'"></label>
        <label class="space-y-1 text-[13px] text-ink-muted">Come back to it in
            <select class="form-select" name="revisit_days">@foreach ([7 => '1 week', 14 => '2 weeks', 30 => '1 month', 90 => '3 months'] as $d => $l)<option value="{{ $d }}" @selected(old('revisit_days', 14) == $d)>{{ $l }}</option>@endforeach</select>
            <span class="block text-[12px] text-ink-subtle">When it comes back you record what turned out to be true. That's how calibration is measured.</span></label>
        <div class="sm:col-span-2"><button class="btn-primary" type="submit">Save claim</button></div>
    </form>
</div>
</x-app-layout>
