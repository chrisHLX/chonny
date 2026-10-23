<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'knowing-is-not-doing',
        'title' => 'Knowing is not doing',
        'summary' => 'Correct, understood, accepted advice can still change nothing. Attention, load and the timing of feedback decide whether it reaches the moment of action.',
        'minutes' => 15,
        'concepts' => ['behaviour-change'],
        'case' => [
            'domain' => 'Competitive games',
            'scenario' => [
                'After every session a player reads a clear review: "Use your crowd control on the enemy healer when your team commits to a go." They agree with it every time.',
                'Weeks later, they still rarely do it in games.',
            ],
            'prompt' => 'Why might someone understand and agree with advice and still not act on it?',
            'reveal' => ['Asked about it, the player says that during a go they\'re focused on their own damage and target, and only remember the advice after the round. The review arrives the next day.'],
            'question' => 'What is the most useful change?',
            'options' => [
                $o('Move the cue to the moment of action and reduce the load there, such as one keybind and a sound when the team commits.', true, 'The barrier is attention and timing, not knowledge. Feedback a day later can\'t shape a split-second habit; a cue in the moment, with less to remember, can.'),
                $o('Repeat the advice more forcefully.', false, 'Understanding isn\'t the problem. More emphasis doesn\'t reach the moment where the action is needed.'),
                $o('The player doesn\'t really agree with the advice.', false, 'They agree; they don\'t act. That gap is what this lesson is about.'),
                $o('Accept that some players can\'t do it.', false, 'That treats a design problem as a fixed trait.'),
            ],
        ],
        'principle' => [
            'Information changes what people know. Behaviour also depends on attention, habit, effort, timing and motivation. A recommendation can be correct, understood and accepted, and still change nothing.',
            ['list' => [
                '**Feedback timing.** Feedback shapes behaviour best close to the action. A review the next day informs; it rarely forms a habit.',
                '**Cognitive load.** Under pressure, people fall back on practised routines. Adding a new step to an already full moment fails.',
                '**Cues and friction.** Make the right action easier and more obvious at the moment it\'s needed.',
                '**Deliberate practice.** Isolate the skill and repeat it with immediate feedback, as Anders Ericsson\'s research describes.',
            ]],
            ['note' => 'For anyone building a coaching or analytics tool: the job isn\'t finished when the insight is correct. It\'s finished when the person\'s behaviour changes, and you\'ve measured that it did.'],
        ],
        'transfer' => [
            'domain' => 'Business operations',
            'scenario' => 'Technicians are given a laminated checklist for avoiding return visits. In a survey, they rate it highly. Return-visit rates don\'t change.',
            'question' => 'What is the most likely reason?',
            'options' => [
                $o('The checklist isn\'t part of the moment of work: it isn\'t required, isn\'t to hand, or adds steps to a rushed job.', true, 'Rating something highly and using it are different things. Build the check into the job itself (the job can\'t be closed without it), then measure whether it\'s used.'),
                $o('The checklist is wrong.', false, 'Possibly, but the more basic question is whether it\'s used at the moment of work.'),
                $o('The technicians are lazy.', false, 'That explains nothing and suggests no fix.'),
                $o('Return visits can\'t be reduced.', false, 'There\'s no evidence for that. The intervention may never have reached the work.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would you tell whether the player\'s problem is knowledge, attention or skill?',
            'model' => [
                'Ask them to explain when to use the crowd control. If they can, knowledge isn\'t the gap.',
                'Test it in a low-pressure setting. If they do it there but not in games, attention under load is the gap.',
                'Measure how often it happens in games before and after a change to cues or keybinds.',
            ],
        ],
        'sources' => ['Anders Ericsson and Robert Pool, *Peak* (2016)', 'B. J. Fogg, *Tiny Habits* (2019)'],
    ],
];
