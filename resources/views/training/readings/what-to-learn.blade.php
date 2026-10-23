{{-- What to learn for the AI era (TrainingController::reading): fifteen subjects from the original
     Steering by Feedback page, each marked want / learning / have it; the marks save to the account. --}}
@php
    $subjects = require resource_path('training/ai-era.php');
    $readingClient = ['subjects' => count($subjects)];
@endphp
<x-app-layout title="What to learn for the AI era | Training | MindCollector" :description="$reading['summary']">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10">
    @include('training.partials.top')

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_300px] items-start">
        <article id="lessonBody" class="min-w-0 space-y-8">
            <p class="text-[12px] text-ink-subtle"><a href="{{ route('training') }}" class="hover:text-ink">Training</a> / What to learn</p>
            <header class="max-w-[68ch] space-y-4">
                <h1 class="font-display font-extrabold tracking-tight text-ink text-4xl sm:text-5xl leading-[1.02]">What to learn for the AI era</h1>
                <div class="t-read">
                    <p data-block="w-0">Nobody knows exactly how the next ten years go. This list rests on one trend that is already visible: producing code, text and analysis is getting much cheaper. When producing is cheap, the value moves to the parts of the loop around it.</p>
                    <ul class="t-pts" data-block="w-1">
                        <li><span><b>Setting the goal:</b> knowing what to ask for, and specifying it precisely.</span></li>
                        <li><span><b>Sensing and comparing:</b> measuring whether the output is right, and spotting when it isn't.</span></li>
                        <li><span><b>Modelling the system:</b> understanding the domain the output acts on (the Good Regulator Theorem again).</span></li>
                        <li><span><b>Owning the consequences:</b> judgment, security, ethics and the trust of the people affected.</span></li>
                    </ul>
                    <p data-block="w-2">Each subject says why it gains value as AI improves, where to start, and one well-regarded resource. Mark each one to plan your own route; the marks save to your account.</p>
                </div>
            </header>

            <div class="flex flex-wrap gap-2" role="group" aria-label="Filter subjects" data-subj-filters>
                @foreach (['all' => 'All', 'Thinking' => 'Thinking', 'Building' => 'Building', 'People' => 'People', 'mine' => 'My marked subjects'] as $g => $label)
                    <button type="button" class="seg-btn {{ $g === 'all' ? 'on' : '' }}" data-g="{{ $g }}">{{ $label }}</button>
                @endforeach
            </div>

            <div class="grid gap-3 md:grid-cols-2" data-subjects>
                @foreach ($subjects as $i => $s)
                    <article class="t-subj" data-subj="{{ $i }}" data-group="{{ $s['g'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div><span class="lbl">{{ $s['g'] }}</span><h3 class="text-ink font-bold text-[16.5px] leading-snug" data-block="s{{ $i }}-0">{{ $s['t'] }}</h3></div>
                            <div class="t-status" role="group" aria-label="My status for {{ $s['t'] }}">
                                @foreach (['want' => 'Want', 'learning' => 'Learning', 'have' => 'Have it'] as $v => $l)<button type="button" data-mark="{{ $v }}">{{ $l }}</button>@endforeach
                            </div>
                        </div>
                        <p class="text-[14.5px] text-ink" data-block="s{{ $i }}-1">{{ $s['why'] }}</p>
                        <dl class="space-y-2 text-[13.5px]">
                            <div><dt class="lbl">Start with</dt><dd class="text-ink-muted" data-block="s{{ $i }}-2">{{ $s['start'] }}</dd></div>
                            <div><dt class="lbl">One resource</dt><dd class="text-ink-muted" data-block="s{{ $i }}-3">{{ $s['res'] }}</dd></div>
                        </dl>
                        <p class="text-[13px] border-t border-dashed border-line-strong pt-2" style="color: var(--t-b)">Loop role: {{ $s['loop'] }}</p>
                    </article>
                @endforeach
            </div>
            <p class="text-[14px] text-ink-muted hidden" data-subj-empty>You haven't marked any subjects yet. Use Want, Learning or Have it on any card.</p>

            <section class="max-w-[68ch] space-y-3">
                <h2 class="text-ink font-bold text-xl">A suggested order</h2>
                <div class="t-read">
                    <p data-block="w-3">If you want a single route through the list, this order builds each subject on the ones before it.</p>
                    <ol class="list-decimal pl-5 space-y-2" data-block="w-4">
                        <li><b>Systems thinking</b> (the <a class="text-gold hover:text-gold-light" href="{{ route('training.reading', 'steering-by-feedback') }}">field manual</a>, then Meadows' <i>Thinking in Systems</i>). It's the frame the rest hang on.</li>
                        <li><b>Probability and causal inference</b>, so you can tell whether a pattern is real and whether a change caused anything.</li>
                        <li><b>Measurement and metric design</b>, to build sensors that don't lie and can't easily be gamed.</li>
                        <li><b>How machine learning works</b>, then <b>specifying and evaluating AI systems</b>: enough to know what the tools can and can't do, and to check their work.</li>
                        <li><b>Data modelling</b> and <b>clear writing</b>, in parallel. One turns messy reality into structure; the other turns intent into instructions.</li>
                        <li>Then whichever of the rest matches what you're building. For a data business that sells to other businesses, customer discovery and security come next.</li>
                    </ol>
                    <p class="text-[12.5px] text-ink-subtle">A reasoned view, not a forecast.</p>
                </div>
            </section>
        </article>

        <aside class="lg:sticky lg:top-4 space-y-3" aria-label="Your notes on this page">
            <div class="flex items-baseline justify-between"><h2 class="text-[15px] font-bold text-ink">Your notes</h2><span class="text-[12px] text-ink-subtle" id="noteCount"></span></div>
            <p class="text-[13px] text-ink-muted leading-relaxed">Select any passage to highlight it or add a note. Notes come back for a revisit like those on lessons.</p>
            <div class="flex flex-wrap gap-1.5">@foreach ($kinds as $k)<span class="kind kind-{{ $k['value'] }}">{{ $k['label'] }}</span>@endforeach</div>
            <div class="space-y-2" id="notes"></div>
        </aside>
    </div>
</div>
<script>window.TRAINING = @json($client); window.TRAINING_SUBJECTS = @json($readingClient);</script>
<script src="/js/training/lesson.js?v={{ filemtime(public_path('js/training/lesson.js')) }}"></script>
<script src="/js/training/reading.js?v={{ filemtime(public_path('js/training/reading.js')) }}"></script>
</x-app-layout>
