{{-- The field manual (TrainingController::reading): the long read from the original Steering by
     Feedback page. Nine modules with diagrams, a self-check after each, and a worksheet that saves to
     the account. Every readable block has a data-block id (m{module}-{n}) so notes anchor to it. --}}
@php
    $manual = require resource_path('training/manual.php');
    $mods = [1 => 'Where the idea came from', 'Anatomy of a control loop', 'Balancing and reinforcing loops', 'Gain, delay and oscillation', 'Variety and models', 'Learning loops and the observer', 'When loops go wrong', 'Where to intervene', 'Map a loop of your own'];
    $manualClient = ['quiz' => $manual['quiz'], 'worksheet' => $manual['worksheet'], 'mods' => $mods];
@endphp
<x-app-layout title="Steering by Feedback | Training | MindCollector" :description="$reading['summary']">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10">
    @include('training.partials.top')

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_300px] items-start">
        <article id="lessonBody" class="min-w-0">
            <p class="text-[12px] text-ink-subtle"><a href="{{ route('training') }}" class="hover:text-ink">Training</a> / Field manual</p>
            <header class="mt-3 max-w-[68ch] space-y-4">
                <p class="page-eyebrow">A working course · 9 modules · about 2–3 hours</p>
                <h1 class="font-display font-extrabold tracking-tight text-ink text-4xl sm:text-5xl leading-[1.02]">Steering by Feedback</h1>
                <p class="text-[16px] text-ink-muted leading-relaxed" data-block="intro-0">Cybernetics is the study of how systems steer themselves: how a thermostat holds a room at 21°C, how a player adjusts after a lost round, how a business notices that something has drifted and corrects it. This course teaches the ideas with small models you can push until they break.</p>
                <div class="t-etym" data-block="intro-1"><span class="t-etym-g">κυβερνήτης</span><span><b>kybernētēs</b>, Greek for <i>steersman</i>. Norbert Wiener took the word in 1948 for the study of control and communication in the animal and the machine. A steersman never holds a fixed course. They watch the drift and correct it, again and again.</span></div>
                <details class="t-toc" open>
                    <summary>Contents <span data-toc-progress></span></summary>
                    <ol>
                        @foreach ($mods as $n => $m)
                            <li><a href="#m{{ $n }}" data-toc="{{ $n }}"><span class="n">{{ str_pad($n, 2, '0', STR_PAD_LEFT) }}</span><span>{{ $m }}</span><i class="tick" aria-hidden="true"></i></a></li>
                        @endforeach
                    </ol>
                </details>
            </header>

            {{-- 01 --}}
            <section class="t-mod" id="m1">
                <div class="t-mod-h"><span class="num">01</span><h2>Where the idea came from</h2><span class="st" data-mod-status="1"></span></div>
                <div class="t-read">
                    <p data-block="m1-0">Engineers built feedback devices long before anyone had a theory of them. The theory arrived when people noticed that the same pattern shows up in machines, bodies, minds and organisations: <b>a system senses the gap between where it is and where it should be, and acts to close it.</b></p>
                    <p data-block="m1-1">That observation is the whole field in one sentence. Everything else is detail about what goes wrong: delays, too much correction, the wrong measurement, a goal nobody questions.</p>
                    <ol class="t-tl" data-block="m1-2">
                        <li><span class="y">1788</span><span class="d"></span><span class="t"><b>James Watt</b> fits a centrifugal governor to his steam engine. Spinning weights rise as the engine speeds up and close the steam valve.</span></li>
                        <li><span class="y">1868</span><span class="d"></span><span class="t"><b>James Clerk Maxwell</b> publishes "On Governors", the first mathematical analysis of why some governors hunt and oscillate.</span></li>
                        <li><span class="y">1927</span><span class="d"></span><span class="t"><b>Harold Black</b> at Bell Labs invents the negative-feedback amplifier: give up some gain and get far more stability.</span></li>
                        <li><span class="y">1946</span><span class="d"></span><span class="t"><b>The Macy Conferences</b> (to 1953) put mathematicians, physiologists, anthropologists and engineers in one room: Wiener, von Neumann, McCulloch, Mead, Bateson and others.</span></li>
                        <li><span class="y">1948</span><span class="d"></span><span class="t"><b>Wiener</b> publishes <i>Cybernetics</i>. The same year <b>Claude Shannon</b> publishes "A Mathematical Theory of Communication".</span></li>
                        <li><span class="y">1956</span><span class="d"></span><span class="t"><b>W. Ross Ashby</b>, <i>An Introduction to Cybernetics</i>: the Law of Requisite Variety (module 5).</span></li>
                        <li><span class="y">1961</span><span class="d"></span><span class="t"><b>Jay Forrester</b>, <i>Industrial Dynamics</i>: system dynamics, modelling firms as stocks, flows and loops.</span></li>
                        <li><span class="y">1970</span><span class="d"></span><span class="t"><b>Conant and Ashby</b>: every good regulator of a system must be a model of that system.</span></li>
                        <li><span class="y">1971</span><span class="d"></span><span class="t"><b>Stafford Beer</b> leads Project Cybersyn in Chile (to 1973), an attempt to steer an economy with near-real-time feedback.</span></li>
                        <li><span class="y">1972</span><span class="d"></span><span class="t"><b>Meadows et al.</b>, <i>The Limits to Growth</i>: a system dynamics model of world population and resources.</span></li>
                        <li><span class="y">1974</span><span class="d"></span><span class="t"><b>Heinz von Foerster</b>, <i>Cybernetics of Cybernetics</i>: second-order cybernetics, where the observer is part of the system (module 6).</span></li>
                        <li><span class="y">1990</span><span class="d"></span><span class="t"><b>Peter Senge</b>, <i>The Fifth Discipline</i>: systems archetypes for managers (module 7).</span></li>
                        <li><span class="y">1999</span><span class="d"></span><span class="t"><b>Donella Meadows</b>, "Leverage Points: Places to Intervene in a System" (module 8).</span></li>
                    </ol>
                    <aside class="t-note" data-block="m1-3">Today the ideas are everywhere without the name. Control engineering, machine learning, product analytics, sports coaching and management by metrics are all feedback loops. A model trained by gradient descent is a loop: predict, measure the error, adjust the weights, repeat. So is MindCollector: read a game, compare it with a standard, change something, read the next game.</aside>
                </div>
                <div class="t-mq" data-mquiz="1"></div>
            </section>

            {{-- 02 --}}
            <section class="t-mod" id="m2">
                <div class="t-mod-h"><span class="num">02</span><h2>Anatomy of a control loop</h2><span class="st" data-mod-status="2"></span></div>
                <div class="t-read">
                    <p data-block="m2-0">Every goal-seeking loop has the same parts. Learn to name them and you can take apart any system you're trying to improve, and find the part that's missing or broken.</p>
                    <p data-block="m2-1">Click a part of the diagram, then switch between examples. The same seven roles appear in a thermostat, a car, your own play and a business.</p>
                    @include('training.sims.loop-diagram')
                    <h3 data-block="m2-2">Three things the diagram makes obvious</h3>
                    <ul class="t-pts" data-block="m2-3">
                        <li><span><b>The loop only sees what the sensor reports.</b> If the sensor is slow, biased or measuring the wrong thing, the whole loop faithfully steers toward the wrong place. Most broken business loops have a sensor problem.</span></li>
                        <li><span><b>Open loop versus closed loop.</b> A toaster on a timer is open loop: it acts without checking the result. Add a sensor and a comparison and it becomes closed loop. Feedforward, acting on a disturbance before it shows up in the output, complements feedback but can't replace it.</span></li>
                        <li><span><b>The set point usually comes from outside the loop.</b> Who sets it, and whether it's the right one, is a separate question. Module 6 is about loops that change their own set points.</span></li>
                    </ul>
                </div>
                <div class="t-mq" data-mquiz="2"></div>
            </section>

            {{-- 03 --}}
            <section class="t-mod" id="m3">
                <div class="t-mod-h"><span class="num">03</span><h2>Balancing and reinforcing loops</h2><span class="st" data-mod-status="3"></span></div>
                <div class="t-read">
                    <p data-block="m3-0">There are only two kinds of feedback loop. Every complicated behaviour you'll meet is some combination of them, plus delays.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="t-cmp t-cmp-b" data-block="m3-1"><b>Balancing loop (B)</b><span>Also called negative feedback. It pushes against change and seeks a goal. The further the system is from the goal, the harder it pushes back. Thermostats, hunger, prices meeting supply, a coach correcting a habit.</span></div>
                        <div class="t-cmp t-cmp-r" data-block="m3-2"><b>Reinforcing loop (R)</b><span>Also called positive feedback. It amplifies change in whichever direction it's already going. Compound interest, word of mouth, a confidence spiral, a team falling behind and tilting.</span></div>
                    </div>
                    <p data-block="m3-3"><i>Positive</i> and <i>negative</i> here describe direction, not value. A reinforcing loop can be a vicious circle, and a balancing loop can hold you stuck in a place you don't want to be.</p>
                    @include('training.sims.stock-flow')
                    <p data-block="m3-4">The S-curve in "limits to growth" is the shape to remember. Early on the reinforcing loop dominates and growth looks exponential. Then the balancing loop catches up and growth stalls. Pushing harder on the reinforcing loop at that point does almost nothing. The lever is whatever sets the limit.</p>
                    <h3 data-block="m3-5">Reading a loop's type from its links</h3>
                    <p data-block="m3-6">Mark each link <b>+</b> if the two things move in the same direction and <b>−</b> if they move in opposite directions. Then count the minus signs around the loop. <b>An even number (including zero) makes a reinforcing loop. An odd number makes a balancing loop.</b> Try it on these six.</p>
                    @include('training.sims.polarity')
                    <aside class="t-note" data-block="m3-7">The sixth one is the loop behind committing to a go in arena: fewer defensives left means more damage lands, which forces out more defensives. The lesson <a class="text-gold hover:text-gold-light" href="{{ route('training.lesson', 'commitment-and-pressure') }}">Does a full go beat half a go?</a> models it, and sets the model against what MindCollector's archive measured.</aside>
                </div>
                <div class="t-mq" data-mquiz="3"></div>
            </section>

            {{-- 04 --}}
            <section class="t-mod" id="m4">
                <div class="t-mod-h"><span class="num">04</span><h2>Gain, delay and oscillation</h2><span class="st" data-mod-status="4"></span></div>
                <div class="t-read">
                    <p data-block="m4-0">A balancing loop doesn't automatically settle smoothly. How hard it corrects (<b>gain</b>) and how late it finds out (<b>delay</b>) decide whether it creeps toward the goal, overshoots, or swings forever.</p>
                    <p data-block="m4-1">The simulation below heats a room to 21°C. The outside is 10°C and the room leaks heat continuously. Use the tasks under the chart as a guide.</p>
                    @include('training.sims.thermostat')
                    <h3 data-block="m4-2">What just happened</h3>
                    <ul class="t-pts" data-block="m4-3">
                        <li><span><b>Steady-state error.</b> A purely proportional controller only acts when there's a gap. To keep the heater on against the heat loss, some gap must remain. Higher gain shrinks it but can't remove it.</span></li>
                        <li><span><b>Integral action.</b> Adding up the error over time keeps pushing until the gap is gone. This is the "I" in a PID controller. Too much of it causes slow, overshooting swings.</span></li>
                        <li><span><b>Delay plus high gain means oscillation.</b> The controller is reacting to where the room was, not where it is. By the time it sees the overshoot, it has already overshot further. This is the shower that's too cold, then scalding, then too cold.</span></li>
                        <li><span><b>Derivative action</b>, the "D" in PID, reacts to how fast the error is changing, which damps swings. It's left out here to keep the controls readable.</span></li>
                    </ul>
                    <aside class="t-note t-note-r" data-block="m4-4"><b>In organisations,</b> delay is usually the measurement cycle. A monthly report on a weekly problem is a sensor with a four-week lag. Managers then correct hard because the problem looks big by the time they see it, and the business swings between too much and too little: hiring, stock, discounting. The beer game, developed in Jay Forrester's system dynamics group at MIT, shows this with players running a four-stage supply chain; most teams end up with large swings in stock.</aside>
                </div>
                <div class="t-mq" data-mquiz="4"></div>
            </section>

            {{-- 05 --}}
            <section class="t-mod" id="m5">
                <div class="t-mod-h"><span class="num">05</span><h2>Variety and models</h2><span class="st" data-mod-status="5"></span></div>
                <div class="t-read">
                    <p data-block="m5-0">Two results from W. Ross Ashby set hard limits on what any controller can do. They apply to a thermostat, a manager, a coach and an AI agent equally.</p>
                    <h3 data-block="m5-1">The Law of Requisite Variety</h3>
                    <p data-block="m5-2">Ashby (1956) showed that a regulator can only hold a system steady against as much <b>variety</b> (the number of distinguishable states or moves) as it has responses for. His short form: only variety can destroy variety. Stafford Beer later put it as "absorb". If the disturbances can do 20 different things and you can only respond in 5 ways, some disturbances will get through.</p>
                    <p data-block="m5-3">Stafford Beer turned this into practical advice for managers. When you're outmatched, you have two options:</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="t-cmp" data-block="m5-4"><b>Attenuate the variety coming in</b><span>Reduce what you have to deal with: standardise products, filter requests, narrow what customers can order, fight on terrain where the opponent has fewer options.</span></div>
                        <div class="t-cmp" data-block="m5-5"><b>Amplify your own variety</b><span>Increase what you can respond with: train people, delegate decisions to those closest to the problem, add tools, keep spare capacity.</span></div>
                    </div>
                    <aside class="t-note" data-block="m5-6">In a 3v3 arena game the enemy team has a large set of threats: burst windows, crowd-control chains, swaps. A team with one defensive plan loses to any comp it doesn't fit. Good teams attenuate (pillar play and line of sight remove threats) and amplify (more ways to answer each threat, spread across three players). Requisite variety explains why "just react better" stops working past a certain level.</aside>
                    <h3 data-block="m5-7">The Good Regulator Theorem</h3>
                    <p data-block="m5-8">Roger Conant and Ashby (1970) argued that every good regulator of a system must be a model of that system. You can't steer reliably what you don't model, at least implicitly. Two consequences matter in practice:</p>
                    <ul class="t-pts" data-block="m5-9">
                        <li><span>The quality of control is capped by the quality of the model. A dashboard is only as good as the picture of the business it encodes.</span></li>
                        <li><span>When control fails, check the model before blaming the controller. A player who keeps misreading a matchup needs a better model of the opponent, not faster hands.</span></li>
                    </ul>
                </div>
                <div class="t-mq" data-mquiz="5"></div>
            </section>

            {{-- 06 --}}
            <section class="t-mod" id="m6">
                <div class="t-mod-h"><span class="num">06</span><h2>Learning loops and the observer</h2><span class="st" data-mod-status="6"></span></div>
                <div class="t-read">
                    <p data-block="m6-0">So far the set point has been given. People and organisations also have to decide what the goal should be, and notice when their own way of measuring is part of the problem.</p>
                    <h3 data-block="m6-1">Single-loop and double-loop learning</h3>
                    <p data-block="m6-2">Chris Argyris and Donald Schön used the thermostat as their example. A thermostat that keeps the room at 21°C is learning in a single loop: it corrects action against a fixed goal. A thermostat that asked "why 21°C? Is that still right?" would be learning in a double loop: questioning the goal and the assumptions behind it.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="t-cmp t-cmp-b" data-block="m6-3"><b>Single loop</b><span>"We lost because I used my defensive late. Use it earlier."<br>"Repeat callouts are up. Remind technicians to check their work."</span></div>
                        <div class="t-cmp t-cmp-b" data-block="m6-4"><b>Double loop</b><span>"Is 'use it earlier' even the right rule against this comp, or is the real problem positioning?"<br>"Is the repeat-callout measure counting planned follow-ups as failures?"</span></div>
                    </div>
                    <h3 data-block="m6-5">Loops you'll meet under other names</h3>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="t-card" data-block="m6-6"><span class="lbl">John Boyd · fighter tactics</span><b>OODA</b><span>Observe, Orient, Decide, Act. Boyd's point was that the faster loop wins, and that orient, how you interpret what you see, is where most of the advantage lives.</span></div>
                        <div class="t-card" data-block="m6-7"><span class="lbl">Shewhart and Deming · quality</span><b>PDSA</b><span>Plan, Do, Study, Act. Deming preferred "study" to "check": you're trying to learn whether a change worked and why, not tick a box.</span></div>
                        <div class="t-card" data-block="m6-8"><span class="lbl">Machine learning</span><b>Training loop</b><span>Predict, measure loss, update weights, repeat. Gradient descent is a balancing loop on prediction error. The set point is the training data, which is why bad data steers it reliably wrong.</span></div>
                    </div>
                    <h3 data-block="m6-9">Second-order cybernetics</h3>
                    <p data-block="m6-10">Heinz von Foerster's move in the 1970s was to put the observer inside the system. Whoever designs the measurement, chooses the goal and reads the report is part of the loop, with their own biases and incentives. When you review your own games, you are both the system being steered and the steersman. That's why an outside reviewer, or a fixed method you commit to before looking, catches things you won't.</p>
                    <aside class="t-note" data-block="m6-11">Your notes here work the same way. A passage marked <i>Confusing</i> comes back after a day, and you record whether your understanding changed. Over time you can see which first readings held and which didn't.</aside>
                </div>
                <div class="t-mq" data-mquiz="6"></div>
            </section>

            {{-- 07 --}}
            <section class="t-mod" id="m7">
                <div class="t-mod-h"><span class="num">07</span><h2>When loops go wrong</h2><span class="st" data-mod-status="7"></span></div>
                <div class="t-read">
                    <h3 data-block="m7-0">Goodhart's law</h3>
                    <p data-block="m7-1">Economist Charles Goodhart observed in 1975 that a statistical relationship tends to break down once it's used as a target for control. The usual modern phrasing, from anthropologist Marilyn Strathern, is that when a measure becomes a target, it stops being a good measure. In loop terms, the sensor gets gamed: people start acting on the measurement instead of on the thing it was meant to measure.</p>
                    <p data-block="m7-2">Response-time targets lead to jobs being closed early. A rating target leads to dodging hard matchups. Defence against it: measure several things that are hard to game together, keep a person reading the cases behind the number, and change measures when they stop meaning anything.</p>
                    <h3 data-block="m7-3">Systems traps</h3>
                    <p data-block="m7-4">Peter Senge and colleagues catalogued loop structures that recur in organisations. Recognising one tells you where the leverage is.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($manual['archetypes'] as $i => $a)
                            <div class="t-card" data-block="m7-{{ 5 + $i }}">
                                <b>{{ $a[0] }}</b><span>{{ $a[1] }}</span>
                                <span class="lbl mt-2">Example</span><span>{{ $a[2] }}</span>
                                <span class="lbl mt-2">Where the leverage is</span><span>{{ $a[3] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="t-mq" data-mquiz="7"></div>
            </section>

            {{-- 08 --}}
            <section class="t-mod" id="m8">
                <div class="t-mod-h"><span class="num">08</span><h2>Where to intervene</h2><span class="st" data-mod-status="8"></span></div>
                <div class="t-read">
                    <p data-block="m8-0">Donella Meadows ranked twelve places to intervene in a system, from least to most effective. Her point was that people reach first for the numbers at the bottom of the list, which are easy to change and rarely change much, and overlook the structure and goals at the top.</p>
                    <ol class="t-lev" data-block="m8-1">
                        @foreach ($manual['leverage'] as $l)
                            <li><span class="n">{{ str_pad($l[0], 2, '0', STR_PAD_LEFT) }}</span><span><b>{{ $l[1] }}</b><br><span class="sub">{{ $l[2] }}</span></span><span class="s" aria-hidden="true"><i style="width:{{ round((13 - $l[0]) / 12 * 100) }}%"></i></span></li>
                        @endforeach
                    </ol>
                    <p class="text-[12.5px] text-ink-subtle" data-block="m8-2">Ranking from Meadows, "Leverage Points: Places to Intervene in a System" (1999). The bar shows relative leverage on her ordering, not a measured quantity.</p>
                    <p data-block="m8-3">A practical reading: before tuning a parameter, ask whether the problem is a delay (module 4), a missing or gamed sensor (modules 2 and 7), a loop that shouldn't exist, or a goal nobody has questioned (module 6). Those are higher on the list and usually cheaper than they look.</p>
                </div>
                <div class="t-mq" data-mquiz="8"></div>
            </section>

            {{-- 09 --}}
            <section class="t-mod" id="m9">
                <div class="t-mod-h"><span class="num">09</span><h2>Map a loop of your own</h2><span class="st" data-mod-status="9"></span></div>
                <div class="t-read">
                    <p data-block="m9-0">Pick a system you're trying to improve: your arena games, a business process, a habit. Fill in each part of its loop. The gaps you can't fill are usually the problem. Your answers save to your account as you type.</p>
                </div>
                <div class="t-ws" data-worksheet>
                    <div class="t-ws-h"><b>Loop worksheet</b><span data-ws-status></span></div>
                    <div class="t-ws-grid">
                        @foreach ($manual['worksheet']['fields'] as $k => [$label, $hint])
                            <label class="{{ $k === 'sys' ? 'full' : '' }}">{{ $label }}@if ($hint)<small> · {{ $hint }}</small>@endif
                                @if ($k === 'sys')<input class="t-field" data-ws="{{ $k }}" id="t-ws-{{ $k }}">@else<textarea class="t-field" data-ws="{{ $k }}" id="t-ws-{{ $k }}" rows="3"></textarea>@endif
                            </label>
                        @endforeach
                        <div class="full t-row">
                            <button class="t-btn" type="button" data-ws-copy>Copy as text</button>
                            <button class="t-link" type="button" data-ws-clear>Clear all fields</button>
                        </div>
                    </div>
                    <pre class="t-ws-out" data-ws-out hidden></pre>
                </div>
            </section>

            <section class="t-mod">
                <div class="t-read">
                    <p>Next: <a class="text-gold hover:text-gold-light" href="{{ route('training.reading', 'what-to-learn') }}">What to learn for the AI era</a>, or the problem-first <a class="text-gold hover:text-gold-light" href="{{ route('training.subject', 'cybernetics') }}">cybernetics lessons</a>, which test each idea on a situation you haven't seen.</p>
                </div>
            </section>
        </article>

        <aside class="lg:sticky lg:top-4 space-y-3" aria-label="Your notes on this page">
            <div class="flex items-baseline justify-between"><h2 class="text-[15px] font-bold text-ink">Your notes</h2><span class="text-[12px] text-ink-subtle" id="noteCount"></span></div>
            <p class="text-[13px] text-ink-muted leading-relaxed">Select any passage to highlight it or add a note. <i>Confusing</i> and <i>Question</i> come back tomorrow, <i>Disagree</i> in three days, notes in a week.</p>
            <div class="flex flex-wrap gap-1.5">@foreach ($kinds as $k)<span class="kind kind-{{ $k['value'] }}">{{ $k['label'] }}</span>@endforeach</div>
            <div class="space-y-2" id="notes"></div>
        </aside>
    </div>
</div>
<script>window.TRAINING = @json($client); window.TRAINING_MANUAL = @json($manualClient);</script>
<script src="/js/training/lesson.js?v={{ filemtime(public_path('js/training/lesson.js')) }}"></script>
<script src="/js/training/sims.js?v={{ filemtime(public_path('js/training/sims.js')) }}"></script>
<script src="/js/training/reading.js?v={{ filemtime(public_path('js/training/reading.js')) }}"></script>
</x-app-layout>
