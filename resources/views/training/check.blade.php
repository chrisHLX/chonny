{{-- The reasoning check (CheckController): six problems, one per concept; B tests the same concepts on different problems. --}}
<x-app-layout :title="'Reasoning check '.$form.' | Training | MindCollector'">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-6">
    @include('training.partials.top')
    <header class="max-w-3xl space-y-3">
        <p class="page-eyebrow">Reasoning check · form {{ $form }}</p>
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl leading-[1.05]">{{ $form === 'A' ? 'Baseline: before the lessons' : 'Second check: new problems, same ideas' }}</h1>
        @if (! $result['done'])
            <p class="text-[15px] text-ink-muted">Six short problems. Answer with your best judgment; you won't see the answers until you finish. {{ $form === 'A' ? 'Later, form B tests the same ideas on different problems, so you can see whether your reasoning changed.' : 'The problems differ from form A, so a better score reflects transfer, not memory.' }}</p>
        @endif
    </header>
    @if ($errors->any())<div class="rounded-lg bg-rose-500/10 text-rose-300 px-4 py-2.5 text-[14px] max-w-3xl">{{ $errors->first() }}</div>@endif

    @if (! $result['done'])
        <form method="post" action="{{ route('training.check.store', $form) }}" class="space-y-4 max-w-3xl">
            @csrf
            @foreach ($items as $id => $item)
                <fieldset class="q-box !mt-0">
                    <legend class="sr-only">Problem {{ $loop->iteration }}</legend>
                    <div class="q-head"><span class="!text-ink !font-bold !text-[13px]">Problem {{ $loop->iteration }}</span><span>{{ $item['domain'] }}</span></div>
                    <div class="q-body">
                        <p class="text-[14.5px] text-ink-muted">{{ $item['scenario'] }}</p>
                        <p class="q mt-2">{{ $item['question'] }}</p>
                        <div class="opts">
                            @foreach ($item['options'] as $i => $o)
                                <label class="opt"><span class="k"><input type="radio" name="answers[{{ $id }}]" value="{{ $i }}" @checked(old('answers.'.$id) === (string) $i) required></span><span>{{ $o }}</span></label>
                            @endforeach
                        </div>
                    </div>
                </fieldset>
            @endforeach
            <button class="btn-primary" type="submit">Submit answers</button>
        </form>
    @else
        <div class="flex items-baseline gap-3"><h2 class="text-ink font-bold text-2xl">Score: {{ $result['score'] }} / {{ $result['total'] }}</h2>@if ($other && $other['done'])<span class="text-[13px] text-ink-subtle">baseline was {{ $other['score'] }} / {{ $other['total'] }}</span>@endif</div>
        <div class="space-y-4 max-w-3xl">
            @foreach ($items as $id => $item)
                @php($a = $result['answers'][$id] ?? null)
                <div class="q-box !mt-0">
                    <div class="q-head"><span class="!text-ink !font-bold !text-[13px]">{{ $curriculum->concept($item['concept'])['name'] ?? $item['concept'] }}</span><span>{{ $a && $a->correct ? 'Right' : 'Wrong' }}</span></div>
                    <div class="q-body">
                        <p class="text-[14.5px] text-ink-muted">{{ $item['scenario'] }}</p>
                        <p class="q mt-2">{{ $item['question'] }}</p>
                        <div class="opts">
                            @foreach ($item['options'] as $i => $o)
                                <div @class(['opt', 'right' => $i === $item['answer'], 'wrong' => $a && $a->choice === $i && $i !== $item['answer']])><span class="k">{{ chr(65 + $i) }}</span><span>{{ $o }}</span></div>
                            @endforeach
                        </div>
                        <p class="t-fb">{{ $item['explanation'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
        <a class="btn-primary" href="{{ route('training') }}">Back to Training</a>
    @endif
</div>
</x-app-layout>
