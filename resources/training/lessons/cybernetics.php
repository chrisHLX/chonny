<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'the-control-loop',
        'title' => 'Anatomy of a control loop',
        'summary' => 'Every goal-seeking system has the same seven parts. Naming them shows where a broken loop is broken.',
        'minutes' => 15,
        'concepts' => ['feedback'],
        'case' => [
            'domain' => 'Business operations',
            'scenario' => [
                'A field-service company sets a goal: 90% of jobs fixed on the first visit. Every month the operations manager reads a report and reminds the technicians to be thorough.',
                'After six months the first-time fix rate hasn\'t moved. The manager concludes that the technicians aren\'t trying hard enough.',
            ],
            'prompt' => 'What do you think is going on? Where might this loop be broken?',
            'reveal' => [
                'Two more facts come out. The monthly report arrives three weeks after month end, so each reminder is about work done four to seven weeks earlier.',
                'And the main reason for return visits is a part not being on the van. Van stock is decided by the stores team, not the technicians.',
            ],
            'question' => 'Which part of the loop is the most likely problem?',
            'options' => [
                $o('The sensor and the actuator: the report arrives too late to steer by, and reminders can\'t change the cause.', true, 'The comparison happens, but the information is weeks old and the only action available (reminding technicians) doesn\'t reach the cause (van stock). Neither more effort nor a different target fixes that.'),
                $o('The set point: 90% is too ambitious.', false, 'The target might be high, but nothing in the evidence says it\'s out of reach. A loop with a late sensor and no working actuator would fail against any target.'),
                $o('The technicians\' effort, as the manager concluded.', false, 'That is the story the manager told, and it\'s the one part the evidence doesn\'t support. Effort isn\'t what this loop is missing.'),
                $o('Nothing is wrong; six months is too short to see change.', false, 'Patience helps when a loop works but is slow. Here the action can\'t reach the cause, so waiting would show the same result.'),
            ],
        ],
        'principle' => [
            ['h' => 'Seven parts of every goal-seeking loop'],
            'A balancing feedback loop has the same parts whether it\'s a thermostat, a car\'s cruise control, a player reviewing their games, or a company chasing a target. Naming them lets you check each one separately.',
            ['sim' => 'loop-diagram'],
            ['list' => [
                '**Set point:** the goal. It usually comes from outside the loop.',
                '**Sensor:** how the loop finds out what happened. The loop can never be better informed than its sensor.',
                '**Comparator:** subtracts what happened from the goal. The result, the error, is the only thing the loop acts on.',
                '**Controller:** decides how much to correct and how quickly.',
                '**Actuator:** the part that changes the world. A controller without one can only watch.',
                '**Process:** the thing being steered, with its own delays.',
                '**Disturbances:** everything outside the loop that pushes it off course.',
            ]],
            ['h' => 'Open and closed loops'],
            'A toaster on a timer is open loop: it acts without checking the result. Add a sensor and a comparison and it becomes closed loop. *Feedforward*, acting on a predicted disturbance before it arrives, can help, but can\'t replace feedback, because predictions are sometimes wrong.',
            ['note' => 'When a loop isn\'t working, the person in it often gets the blame. Check the sensor and the actuator first. Most broken loops in organisations are missing one of them.'],
        ],
        'transfer' => [
            'domain' => 'Health',
            'scenario' => 'Someone wants to lower their blood pressure. They check it on a pharmacy machine every few months and, when it\'s high, resolve to eat less salt. A year later it\'s unchanged.',
            'question' => 'Using the parts of the loop, what is the most useful first change?',
            'options' => [
                $o('Measure more often with a home monitor, and turn the resolution into a specific daily action they can check.', true, 'This fixes the sensor (frequent readings shorten the delay) and the actuator (a vague resolution becomes a checkable action). Now the loop can steer.'),
                $o('Set a stricter target.', false, 'The target isn\'t what\'s failing. With readings months apart, they can\'t tell whether any change in diet does anything.'),
                $o('Conclude that diet can\'t change blood pressure.', false, 'That conclusion comes from a loop that couldn\'t have detected a change either way.'),
                $o('Read more about salt.', false, 'More knowledge helps the controller, but the sensor is still months apart and the action still vague.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'In the field-service case, what evidence would show that van stock, not technician effort, drives return visits?',
            'model' => [
                'Return visits broken down by recorded cause: how many were waiting for a part?',
                'Return-visit rates compared across technicians on the same kinds of job. If they\'re similar, individual effort is an unlikely driver.',
                'A test: stock one area\'s vans with the most-needed parts for two months and compare its return-visit rate with a similar area left unchanged.',
            ],
        ],
        'sources' => ['Norbert Wiener, *Cybernetics* (1948)', 'Karl Åström and Richard Murray, *Feedback Systems* (free online)'],
    ],

    [
        'slug' => 'balancing-and-reinforcing',
        'title' => 'Balancing and reinforcing loops',
        'summary' => 'There are only two kinds of feedback loop. Growth that stalls, plateaus and vicious circles are combinations of them.',
        'minutes' => 20,
        'concepts' => ['loop-polarity', 'feedback'],
        'case' => [
            'domain' => 'Products',
            'scenario' => [
                'A small app grew quickly for eight months, almost entirely through people recommending it to friends. Sign-ups doubled roughly every six weeks.',
                'Then growth flattened. The team doubled the referral reward. Sign-ups barely moved.',
            ],
            'prompt' => 'What do you think happened, and why didn\'t the bigger reward help?',
            'reveal' => [
                'Support tickets had tripled over the same period, and replies went from hours to days. App-store reviews from the last two months mention slow support far more often than features.',
            ],
            'question' => 'Which explanation fits the evidence best?',
            'options' => [
                $o('A balancing loop has caught up: growth created a support backlog that now damages recommendations.', true, 'Growth (reinforcing) produced support load; slow support produced worse reviews and fewer recommendations (balancing). Pushing harder on the reinforcing side does little while the limit holds.'),
                $o('The market is saturated: everyone who wants the app already has it.', false, 'Possible, but the evidence points at something closer: a constraint created by the growth itself. Saturation wouldn\'t explain the change in reviews.'),
                $o('The referral reward was still too small.', false, 'If the limit is the experience new users get, more reward sends more people into the same bottleneck.'),
                $o('Growth always slows; nothing in particular caused it.', false, 'Growth does usually slow, but because some balancing loop takes over. Naming which one is the useful step.'),
            ],
        ],
        'principle' => [
            ['h' => 'Only two kinds of loop'],
            'A **balancing loop** pushes against change and seeks a goal: the further from the goal, the harder it pushes back. A **reinforcing loop** amplifies change in whichever direction it\'s already going. Every complicated behaviour you\'ll meet is a combination of the two, plus delays.',
            '*Positive* and *negative* feedback describe direction, not value. A reinforcing loop can be a vicious circle, and a balancing loop can hold you stuck somewhere you don\'t want to be.',
            ['sim' => 'stock-flow'],
            'The S-shaped curve of *limits to growth* is the one to remember. Early on, the reinforcing loop dominates and growth looks exponential. Then a balancing loop catches up and growth stalls. At that point the lever is whatever sets the limit.',
            ['h' => 'Reading a loop\'s type from its links'],
            'Mark each link **+** if the two things move in the same direction and **−** if they move in opposite directions. Count the minus signs around the loop: an even number (including zero) makes it reinforcing, an odd number makes it balancing.',
            ['sim' => 'polarity'],
        ],
        'transfer' => [
            'domain' => 'Competitive games',
            'scenario' => 'An arena player climbs quickly from 1,600 to 2,100 rating, then stalls for weeks at around 2,150, despite playing more games than ever.',
            'question' => 'Which loop structure best explains the stall?',
            'options' => [
                $o('A balancing loop: higher rating brings stronger opponents, which lowers the win rate until gains and losses even out.', true, 'Rating → opponent strength (+), opponent strength → win rate (−), win rate → rating (+). One minus sign, so balancing. The plateau is where the loop settles; playing more games doesn\'t move the level, only playing better does.'),
                $o('A reinforcing loop: losing causes tilt, which causes more losing.', false, 'Tilt would produce a slide, not a stable plateau. A plateau is the signature of a balancing loop finding its level.'),
                $o('No loop: matchmaking is random.', false, 'Matchmaking pairs players of similar rating, which is exactly what creates the balancing loop.'),
                $o('Two reinforcing loops cancelling out.', false, 'Possible in general, but a single balancing loop explains the plateau with less.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would you test whether the app\'s slowdown comes from support load rather than market saturation?',
            'model' => [
                'Compare how often users recommend the app after a fast support reply versus a slow one.',
                'Check whether sign-ups from channels where nobody has heard of the app are also flat. Saturation would hit those too; a support problem mostly hits word of mouth.',
                'Clear the support backlog for a month and see whether referral growth resumes.',
            ],
        ],
        'sources' => ['Donella Meadows, *Thinking in Systems* (2008)', 'John Sterman, *Business Dynamics* (2000)'],
    ],

    [
        'slug' => 'gain-delay-oscillation',
        'title' => 'Gain, delay and oscillation',
        'summary' => 'How hard a loop corrects and how late it finds out decide whether it settles, overshoots or swings forever.',
        'minutes' => 20,
        'concepts' => ['delay'],
        'case' => [
            'domain' => 'Business operations',
            'scenario' => [
                'A shop reviews its stock once a month. When an item looks low, it orders a large batch; when it looks high, it orders nothing.',
                'Over a year, the shop swings between running out and having storerooms full of unsold stock. The owner blames unpredictable customers.',
            ],
            'prompt' => 'What do you think is causing the swings?',
            'reveal' => ['Sales records show demand was steady all year, within about ten percent month to month. Orders take three weeks to arrive.'],
            'question' => 'What is the most likely cause of the swings?',
            'options' => [
                $o('Monthly checks and a three-week lead time, combined with large corrections.', true, 'Long delays plus strong correction produce oscillation: the shop reacts to old information, overcorrects, and sees the result too late. Steady demand turned into swinging stock.'),
                $o('Unpredictable customers, as the owner says.', false, 'The sales records contradict this: demand was steady. The swings came from inside the loop.'),
                $o('The orders are too small.', false, 'Larger corrections would make the swings worse when the information is delayed.'),
                $o('The supplier is unreliable.', false, 'Nothing suggests the lead time varies. A reliable delay can still cause oscillation if the loop doesn\'t allow for it.'),
            ],
        ],
        'principle' => [
            'A balancing loop doesn\'t automatically settle smoothly. How hard it corrects (its *gain*) and how late it finds out (its *delay*) decide whether it creeps toward the goal, overshoots, or swings indefinitely.',
            ['sim' => 'thermostat'],
            ['list' => [
                '**Steady-state error.** A controller that acts only in proportion to the gap has to leave some gap to keep acting against a constant loss.',
                '**Integral action** keeps adding up the error until it\'s gone. This is the *I* in PID control. Too much of it causes slow, overshooting swings.',
                '**Delay plus high gain means oscillation.** The controller reacts to where the system was. By the time it sees the overshoot, it has already overshot further.',
            ]],
            ['note' => 'In organisations, delay is usually the reporting cycle. A monthly report on a weekly problem is a sensor with a four-week lag. The beer game, developed in Jay Forrester\'s system dynamics group at MIT, has players run a four-stage supply chain; most teams end up with large swings in stock even though customer demand barely changes.'],
        ],
        'transfer' => [
            'domain' => 'Everyday life',
            'scenario' => 'In an unfamiliar shower, you turn the tap toward hot. Nothing happens for a few seconds, so you turn it further. It becomes scalding, so you turn it hard toward cold, and soon it\'s freezing.',
            'question' => 'What is the best strategy, using the same principle?',
            'options' => [
                $o('Make a small adjustment, then wait long enough for the water to reflect it before adjusting again.', true, 'Matching the size and pace of corrections to the delay stops the overshoot. That is what lower gain and patience do in the simulator.'),
                $o('Turn the tap further each time to reach the right temperature faster.', false, 'That raises the gain, which with a delay makes the swings bigger.'),
                $o('Pick a position at random and accept whatever comes out.', false, 'That gives up control rather than tuning it.'),
                $o('Turn it fully hot, then fully cold, to learn the range.', false, 'That is the swinging behaviour itself, at maximum gain.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'What would you measure to confirm that the shop\'s swings come from its ordering rather than from demand?',
            'model' => [
                'Weekly sales against weekly stock. If sales are flat while stock swings, the swing is generated internally.',
                'When orders were placed relative to shortages: orders placed after a shortfall that arrive after the next review are the delay at work.',
                'A trial of smaller, more frequent orders, checking whether the swings shrink.',
            ],
        ],
        'sources' => ['Karl Åström and Richard Murray, *Feedback Systems* (free online)', 'John Sterman, \'Modeling Managerial Behavior: Misperceptions of Feedback in a Dynamic Decision Making Experiment\', *Management Science* (1989)'],
    ],

    [
        'slug' => 'commitment-and-pressure',
        'title' => 'Does a full go beat half a go?',
        'summary' => 'Burst as a stock-and-flow problem: why committing fully can change a fight in a way that half-committing never does, and how you would test it in your own games.',
        'minutes' => 25,
        'concepts' => ['stocks-flows', 'loop-polarity', 'confounding'],
        'case' => [
            'domain' => 'Competitive games',
            'scenario' => [
                'In 3v3 arena, a team\'s big damage cooldowns come back roughly every minute. In each of those windows, a *go*, they can commit everything (all damage cooldowns plus crowd control on the enemy healer) or commit partly and keep something back.',
                'One player argues: "Half-committing is safer. You still do damage and you keep options open." Their teammate argues: "If we\'re going, we go fully."',
            ],
            'prompt' => 'What do you think? Is a full go just more damage, or does it change the game in a different way?',
            'reveal' => [
                'Think about what the enemy target has: a small number of defensive cooldowns, each taking a minute or more to come back. These are a stock. Each of your goes can drain it; time refills it.',
                'A go that doesn\'t force a defensive drains nothing. The healer heals the damage back, and your own cooldowns are now on cooldown. A go that forces two defensives drains the stock faster than it refills.',
            ],
            'question' => 'Viewed as stocks and flows, what is the strongest argument for committing fully?',
            'options' => [
                $o('A full go can push the enemy\'s defensive stock down faster than it recovers, so each later go meets fewer answers.', true, 'That\'s the structure. Once the drain on their defensives exceeds the refill, each go leaves them weaker for the next one: a reinforcing decline. A half go that never forces a defensive leaves their stock untouched while emptying yours.'),
                $o('Full goes always do more total damage.', false, 'True but incomplete. Damage that gets healed back changes nothing. What matters is what the go does to the stocks that decide later goes.'),
                $o('Half goes are never useful.', false, 'Too strong. A partial go can force a trade, bait a cooldown or set up the next go. What matters is whether it changes a stock.'),
                $o('Full goes win because they\'re more aggressive.', false, 'Aggression describes the go; it isn\'t a mechanism. The stock view points at one you can check: defensives forced per go against defensives recovered between goes.'),
            ],
        ],
        'principle' => [
            'This is a stock-and-flow problem. Each team holds stocks: health, defensive cooldowns, offensive cooldowns, crowd-control availability (diminishing returns), mana. Goes are flows that drain them; time and healing refill them.',
            'The model below is deliberately simple and its numbers are invented. One enemy target has two defensive cooldowns on a 90-second recharge. Your team has a go every 45 seconds. *Commitment* sets how much burst you add and, above half, how much crowd control lands on their healer.',
            ['sim' => 'burst'],
            'Move the commitment slider and three regimes appear:',
            ['list' => [
                '**Low commitment:** health dips and is healed back. No defensive is forced. You spent your cooldowns and changed nothing.',
                '**Middle:** each go forces one defensive, and their stock refills as fast as you drain it. Stalemate.',
                '**High:** a go forces two defensives at once. The stock empties faster than it refills, and the next go meets no answers.',
            ]],
            'The response is **nonlinear**. Across a wide range of commitment nothing changes; across a threshold, everything does. That is why half a go can be worth much less than half of a full one. The sweep chart shows it directly: kills don\'t start gradually, they start at a line.',
            ['note' => 'Now the caution. In real games, teams tend to commit fully when the enemy is already low or out of cooldowns. So "full goes win more often" in your logs is confounded: the full go and the win may share a cause, the enemy already being weak. The model shows a mechanism worth testing. It doesn\'t prove that mechanism decides your games.'],
            ['h' => 'What MindCollector\'s archive says'],
            'A model is a hypothesis. Here is what the measured games say about it (795 archived games, October 2026; full tables in the population findings of 7 October):',
            ['table' => ['head' => ['Question', 'What the games show'], 'rows' => [
                ['Does the **number** of defensives a go draws predict whether it kills?', 'No. Goes that drew none killed 30% of the time, three drew 21%, five or more 30%. The count says nothing on its own.'],
                ['Does the enemy\'s **state at the start** of a go matter?', 'Yes. With two or more of their big answers already down when a go started, it killed 35–43% of the time; with one down, 17–22% (from our own games).'],
                ['Does putting the **healer** under control inside the go matter?', 'Yes. Goes with a kick on their healer during the go killed 45% of the time, against 27% without (93 goes with one).'],
                ['Which side wins the **resource race**?', 'At one minute in, the team with more of its own defensives up than the other team wins 59% against 45%, and the team that has spent more of its offensive cooldowns wins 58% against 50%.'],
            ]]],
            'Read together, this supports the stock view and corrects the simple model. What matters is not how many answers a go draws, but **which** answers are down when the next go lands, and whether you still have your own. Offence is spent to take their defensives while keeping yours: resource conversion, not maximum damage. And the healer is part of the stock.',
            'Each row is still a correlation with the usual confound: a team that is already winning gets the chances to press. That is why the next section matters.',
            ['h' => 'How you would test it in your own games'],
            ['list' => [
                'For each go, record what the enemy had available at the start (defensives, trinket, the healer\'s crowd-control diminishing returns), how much you committed, and how many defensives the go forced.',
                'Compare goes that started from similar enemy states. A full go against a target with everything available is the fair test; a full go into an empty target isn\'t.',
                'Look for the threshold: plot kills against commitment within the same starting state. A flat line followed by a jump supports the model; a straight line doesn\'t.',
                'Better still, decide in advance to commit fully, with control on their healer, on openers for a set number of sessions, whatever the situation, and compare with your usual play.',
            ]],
        ],
        'transfer' => [
            'domain' => 'Business',
            'scenario' => 'A small company is launching into a new market. Option one: spread the launch budget evenly over twelve months. Option two: spend most of it in the first two months to reach the point where customers start recommending it to each other.',
            'question' => 'Using the same reasoning, when does concentrating the effort make most sense?',
            'options' => [
                $o('When there is a threshold, such as enough users for word of mouth to sustain itself, that spread-out spending would never cross.', true, 'Same structure as the full go: if a reinforcing loop only starts above a threshold, effort below it is mostly wasted and effort that crosses it compounds.'),
                $o('Always. Concentrated effort beats spread effort.', false, 'Only if there\'s a threshold or a stock that the concentrated effort pushes past. Without one, spreading may do just as well with less risk.'),
                $o('Never. Spreading risk is always safer.', false, 'Spreading avoids wasting everything at once, but if it never crosses a threshold it can waste everything slowly instead.'),
                $o('It depends only on the size of the budget.', false, 'Budget matters, but the deciding question is the shape of the response: steady, or with a threshold.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'Suppose your logs show rounds with a full opener won 70% of the time and rounds with a half opener 45%. What else would you need to know before concluding that full openers cause wins?',
            'model' => [
                'What the enemy had available at the start of each opener. If full openers mostly happened when the enemy was caught out of position or without trinket, the comparison is confounded.',
                'Whether the two kinds of opener happened in similar matchups and compositions.',
                'How many rounds are in each group. A difference built on twenty rounds can be noise.',
                'Ideally, a deliberate change: commit fully on openers regardless of the situation for a set period, and compare.',
            ],
        ],
        'sources' => ['Donella Meadows, *Thinking in Systems* (2008)', 'The simulation is a teaching model with invented numbers, not a model of the live game.', 'MindCollector population findings, 7 October 2026: defensive trading and the relative resource state (docs/learning/population-findings-2026-10-07-trading.md)'],
    ],

    [
        'slug' => 'variety-and-models',
        'title' => 'Variety and models',
        'summary' => 'Two hard limits on any controller: it can only absorb as much variety as it has, and it can only steer what it models.',
        'minutes' => 15,
        'concepts' => ['requisite-variety'],
        'case' => [
            'domain' => 'Customer service',
            'scenario' => [
                'A support team of four answers every kind of customer request by email: billing, bugs, how-to questions, complaints, refunds, data requests.',
                'Replies are getting slower. Management\'s plan: stricter scripts, so every agent answers the same way, faster.',
            ],
            'prompt' => 'Will stricter scripts help? Why or why not?',
            'reveal' => ['Of last month\'s requests, 60% were one of eight common how-to questions. The rest were spread across more than a hundred distinct problems, most of which no script covers.'],
            'question' => 'Which response follows from the Law of Requisite Variety?',
            'options' => [
                $o('Reduce the variety coming in (self-service answers for the eight common questions) and increase the team\'s (authority and tools for unusual cases).', true, 'A regulator can only absorb as much variety as it has. Attenuate the incoming variety where requests repeat, and amplify the team\'s where they don\'t.'),
                $o('Stricter scripts, as planned.', false, 'Scripts reduce the team\'s range of responses. Against a hundred distinct problems, fewer responses means more requests the team can\'t handle.'),
                $o('Hire twice as many agents to follow the scripts.', false, 'More agents with the same scripts add capacity, not variety. The uncovered problems stay uncovered.'),
                $o('Tell customers to ask only common questions.', false, 'That tries to reduce variety by decree. Customers\' problems don\'t change because you\'ve asked them to.'),
            ],
        ],
        'principle' => [
            ['h' => 'The Law of Requisite Variety'],
            'W. Ross Ashby (1956) showed that a regulator can only hold a system steady against as much *variety* (the number of distinguishable states or moves) as it has responses for. His short form: only variety can destroy variety. Stafford Beer later put it as *absorb*.',
            'When you\'re outmatched you have two options. **Attenuate** the variety coming in: standardise, filter, choose ground where the other side has fewer options. **Amplify** your own: train people, delegate decisions to those closest to the problem, add tools.',
            ['note' => 'In 3v3 arena the enemy team has many threats: burst windows, crowd-control chains, swaps between targets. A team with one defensive plan loses to any composition it doesn\'t fit. Good teams attenuate (line of sight and pillars remove threats) and amplify (more ways to answer each threat, spread across three players).'],
            ['h' => 'The Good Regulator Theorem'],
            'Roger Conant and Ashby (1970) argued that every good regulator of a system must be a model of that system. You can\'t reliably steer what you don\'t model, at least implicitly.',
            ['list' => [
                'The quality of control is capped by the quality of the model. A dashboard is only as good as its picture of the business.',
                'When control fails, check the model before blaming the controller. A player who keeps misreading a matchup needs a better model of the opponent, not faster hands.',
            ]],
        ],
        'transfer' => [
            'domain' => 'Competitive games',
            'scenario' => 'A player has one opener they always use. It works well against two common compositions and badly against everything else. Their rating is stuck.',
            'question' => 'What does requisite variety suggest?',
            'options' => [
                $o('Build a small set of openers matched to the compositions they meet most, and learn to recognise which situation they\'re in.', true, 'That amplifies their variety where it matters, and builds the model (recognising the situation) that the Good Regulator Theorem says they need to pick the right response.'),
                $o('Perfect the one opener until it works against everything.', false, 'One response can\'t absorb many different threats, however well it\'s executed.'),
                $o('Only queue at times when the two good matchups are common.', false, 'An attempt to attenuate, but the player doesn\'t control who they meet.'),
                $o('Variety doesn\'t matter in games; execution does.', false, 'Execution matters, but it\'s applied to a choice of plan. With one plan, execution can\'t fix a mismatch.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would you check whether the support team\'s problem is variety rather than speed?',
            'model' => [
                'Classify a month of requests by type and count the distinct types. A long tail of rare types points at variety.',
                'Measure resolution time by type. If common types are quick and rare ones slow, speed isn\'t the bottleneck.',
                'Trial self-service for the top questions and see whether the remaining requests are handled faster.',
            ],
        ],
        'sources' => ['W. Ross Ashby, *An Introduction to Cybernetics* (1956)', 'Roger Conant and W. Ross Ashby, \'Every Good Regulator of a System Must Be a Model of That System\' (1970)', 'Stafford Beer, *Brain of the Firm* (1972)'],
    ],

    [
        'slug' => 'learning-loops',
        'title' => 'Learning loops and the observer',
        'summary' => 'Getting better at a goal versus questioning the goal, and why whoever reads the evidence is part of the loop.',
        'minutes' => 15,
        'concepts' => ['double-loop'],
        'case' => [
            'domain' => 'Competitive games',
            'scenario' => [
                'A player reviews every lost round with one rule: "Was my defensive used before the enemy\'s big damage window?" When the answer is no, they note it and promise to press earlier.',
                'After two months, they press defensives earlier in almost every round. Their win rate hasn\'t changed.',
            ],
            'prompt' => 'What might the player be missing?',
            'reveal' => ['Looking at the rounds again with fresh eyes: in many of the losses, the player\'s position let the enemy reach them freely, so the defensive went early and nothing was left for the second attack.'],
            'question' => 'What kind of learning has the player been doing, and what\'s missing?',
            'options' => [
                $o('Single-loop learning against a fixed rule. What\'s missing is asking whether the rule addresses the real problem.', true, 'The player got better at following the rule while the rule measured the wrong thing. Double-loop learning asks whether the goal and its assumptions are right.'),
                $o('Double-loop learning; they just need more time.', false, 'They\'ve been correcting actions against a fixed rule without questioning it. That is single-loop learning.'),
                $o('No learning at all.', false, 'They did learn: they press earlier. They learned the wrong lesson well, which is a specific failure.'),
                $o('Their reviews are too slow.', false, 'Delay isn\'t the issue. The reviews were frequent; the question they asked was the problem.'),
            ],
        ],
        'principle' => [
            ['h' => 'Single-loop and double-loop learning'],
            'Chris Argyris and Donald Schön used the thermostat as their example. A thermostat holding 21°C learns in a single loop: it corrects actions against a fixed goal. Asking *why 21°C, and is it still right?* would be a double loop, questioning the goal and the assumptions behind it.',
            'Single-loop learning is efficient and usually enough. It fails when the goal or the measure is wrong, because then the loop gets steadily better at the wrong thing.',
            ['h' => 'Loops under other names'],
            ['list' => [
                '**OODA** (John Boyd): observe, orient, decide, act. Boyd argued that *orient*, how you interpret what you see, is where most of the advantage lives.',
                '**PDSA** (Shewhart and Deming): plan, do, study, act. Deming preferred *study* to *check*: the point is to learn whether a change worked and why.',
                '**Training a model:** predict, measure the error, adjust, repeat. A balancing loop on prediction error, steered by whatever the training data treats as right.',
            ]],
            ['h' => 'The observer is part of the loop'],
            'Heinz von Foerster\'s second-order cybernetics puts the observer inside the system. Whoever designs the measure, sets the goal and reads the report brings their own biases. When you review your own games you\'re both the system being steered and the steersman. A method fixed in advance, or an outside reviewer, catches things you won\'t.',
            ['note' => 'Your notes in this course work the same way. A passage marked *Confusing* comes back after a day, and you record whether your understanding changed. Over time you can see which first readings held and which didn\'t.'],
        ],
        'transfer' => [
            'domain' => 'Business',
            'scenario' => 'A company measures customer satisfaction with a survey score. Each quarter, teams with low scores get politeness training. Scores rise; the number of customers leaving doesn\'t fall.',
            'question' => 'Which question would start double-loop learning?',
            'options' => [
                $o('Is the survey score measuring what makes customers stay?', true, 'It questions the measure and the goal it stands for. Rising scores alongside flat losses suggest the two have come apart.'),
                $o('How do we raise survey scores faster?', false, 'Still single-loop: better performance against the same measure.'),
                $o('Which teams need more training?', false, 'That improves the existing correction without examining it.'),
                $o('Should the survey be sent more often?', false, 'More frequent sensing of the same thing tightens the loop but doesn\'t question it.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'What would tell the player that their rule about defensives was the wrong rule?',
            'model' => [
                'Losses where the rule was followed perfectly. If they\'re common, the rule doesn\'t decide the outcome.',
                'A comparison of won and lost rounds on other factors: position, damage taken before the window, whether a teammate peeled.',
                'Someone else reviewing the same rounds without being told the rule, and seeing what they notice first.',
            ],
        ],
        'sources' => ['Chris Argyris, \'Double Loop Learning in Organizations\', *Harvard Business Review* (1977)', 'Heinz von Foerster, *Cybernetics of Cybernetics* (1974)'],
    ],
];
