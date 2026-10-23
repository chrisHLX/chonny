{{-- A choice question. Correctness and feedback are not in the page: the server returns them per answer. --}}
<div class="q-box" data-question="{{ $exercise }}">
    <div class="q-head"><span class="!text-ink !font-bold !text-[13px]">{{ $heading }}</span><span data-q-status></span></div>
    <div class="q-body">
        <p class="q" data-block="{{ $exercise }}q-0">{!! \App\Training\Prose::inline($q['question']) !!}</p>
        <div class="opts" role="group" aria-label="Answers">
            @foreach ($q['options'] as $i => $o)
                <button type="button" class="opt" data-choice="{{ $i }}"><span class="k">{{ chr(65 + $i) }}</span><span>{!! \App\Training\Prose::inline($o['text']) !!}</span></button>
            @endforeach
        </div>
        <div class="t-fb" data-fb hidden aria-live="polite"></div>
    </div>
</div>
