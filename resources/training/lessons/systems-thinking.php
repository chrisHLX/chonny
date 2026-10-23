<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'moving-the-problem',
        'title' => 'Solving the problem, or moving it?',
        'summary' => 'Improving a step that isn\'t the bottleneck moves the queue instead of removing it. Recurring traps, and where intervention actually works.',
        'minutes' => 20,
        'concepts' => ['bottlenecks', 'second-order'],
        'case' => [
            'domain' => 'Health',
            'scenario' => [
                'A hospital\'s emergency department has long waits. It hires more triage nurses so that patients are assessed faster.',
                'Assessment times fall. Total time in the department barely changes.',
            ],
            'prompt' => 'What do you think happened?',
            'reveal' => ['Patients who need admitting now wait longer for a ward bed. The wards are full, so assessed patients stay in the emergency department until a bed frees up.'],
            'question' => 'What is the best description?',
            'options' => [
                $o('The change sped up a step that wasn\'t the bottleneck, so the queue moved to the step that was.', true, 'Throughput is set by the slowest step. Speeding up any other step just moves the queue to the constraint: here, ward beds.'),
                $o('The new nurses aren\'t working hard enough.', false, 'Assessment did get faster, so the nurses did their part. The wait moved downstream.'),
                $o('More nurses were the wrong idea; nothing would have helped.', false, 'The lesson is to find and work on the constraint, not that nothing helps.'),
                $o('Patients are sicker than before.', false, 'Nothing suggests this, and it wouldn\'t explain the faster assessments.'),
            ],
        ],
        'principle' => [
            'In any chain of steps, the slowest one sets the pace of the whole. Improving a step that isn\'t the **bottleneck** doesn\'t increase what comes out of the end; it moves the queue to the constraint. Eliyahu Goldratt built his theory of constraints on this point.',
            ['list' => [
                'Find the constraint before improving anything: where does work pile up?',
                'Improvements elsewhere can make things worse, by flooding the constraint.',
                'When you fix the constraint, a new one appears. That\'s progress, and it tells you where to look next.',
            ]],
            ['h' => 'Patterns worth recognising'],
            ['table' => ['head' => ['Pattern', 'What happens', 'Where the leverage is'], 'rows' => [
                ['Fixes that fail', 'A quick fix has a delayed side effect that brings the problem back worse.', 'Ask what the fix does in three months, not three days.'],
                ['Shifting the burden', 'An easy symptomatic fix is used every time, and the ability to do the real fix fades.', 'Use the quick fix to buy time, and protect time for the real one.'],
                ['Limits to growth', 'Growth runs into a constraint it can\'t overcome.', 'Work on the constraint, not the growth.'],
                ['Eroding goals', 'When performance falls short, the goal is quietly lowered.', 'Anchor goals to something outside the system.'],
            ]]],
            ['note' => 'Donella Meadows ranked twelve places to intervene in a system. Numbers and parameters, where most effort goes, rank lowest. Delays, feedback structure, information flows, rules and goals rank higher. Before tuning a number, ask whether the problem is a delay, a missing feedback loop, or a goal nobody has questioned.'],
        ],
        'transfer' => [
            'domain' => 'Competitive games',
            'scenario' => 'A team keeps losing long games because their healer runs out of mana. To end games sooner, their two damage dealers drop all their damage-reduction talents for more damage. Games get shorter, but the team loses more often.',
            'question' => 'What is the most likely explanation?',
            'options' => [
                $o('The change moved the problem: the damage dealers now die faster, which strains the healer in a different way.', true, 'The fix aimed at one constraint (game length) and created another (fragile teammates). The problem moved instead of going away.'),
                $o('Short games are always worse for this team.', false, 'Too general. The issue is what was given up to shorten them.'),
                $o('The healer is the problem and should be replaced.', false, 'That skips over the change the team just made, which coincides with the extra losses.'),
                $o('They need even more damage to finish games before it matters.', false, 'That doubles down on the change that just made things worse, without checking why.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would you find the hospital\'s real constraint?',
            'model' => [
                'Measure how long patients wait at each stage, not just the total.',
                'Find where queues form: at assessment, tests, specialist review or bed allocation.',
                'Check bed occupancy and discharge times, since ward capacity often decides emergency waits.',
            ],
        ],
        'sources' => ['Eliyahu Goldratt, *The Goal* (1984)', 'Peter Senge, *The Fifth Discipline* (1990)', 'Donella Meadows, \'Leverage Points: Places to Intervene in a System\' (1999)'],
    ],
];
