<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'testing-a-recommendation',
        'title' => 'Testing whether it works',
        'summary' => 'An experiment builds the comparison you can\'t observe. Random assignment, a control group and a measure chosen in advance.',
        'minutes' => 20,
        'concepts' => ['experiment-design', 'counterfactual'],
        'case' => [
            'domain' => 'Coaching software',
            'scenario' => [
                'A team building a match-review tool wants to know: **does using it actually make players better?**',
                'Their first plan: compare the ratings of players who use the tool with the ratings of players who don\'t.',
            ],
            'prompt' => 'What\'s wrong with that plan, and what would you do instead?',
            'reveal' => ['Players who sign up for a match-review tool are likely to be more motivated, to play more, and to be improving already, compared with players who don\'t.'],
            'question' => 'Which design gives the most trustworthy answer?',
            'options' => [
                $o('Randomly give half of a group of new sign-ups the full tool and half a basic version, and compare improvement on a measure chosen in advance.', true, 'Random assignment makes the two groups alike, on average, in motivation and everything else, so a difference can be put down to the tool.'),
                $o('Compare users with non-users, as planned.', false, 'Users choose themselves, and differ in motivation and practice. Any difference could come from that.'),
                $o('Compare users\' ratings before and after they start.', false, 'Players often start using a tool when they\'re already on an upswing, and seasons and patches move ratings too.'),
                $o('Ask users whether the tool helped them.', false, 'Worth hearing, but people who chose a tool tend to believe it helped. It isn\'t a measure of improvement.'),
            ],
        ],
        'principle' => [
            'An experiment is a way of creating the counterfactual you can\'t observe. **Random assignment** decides by chance who gets the change, so that differences in motivation, skill and circumstances spread evenly across both groups.',
            ['list' => [
                '**Decide the measure in advance.** Choosing it afterwards lets you find a win somewhere by chance.',
                '**Use a control group** that gets the same attention without the thing being tested.',
                '**Make the sample big enough** to see the effect you care about above the noise.',
                '**Check the mechanism.** Did people actually do what the advice said? An effect without the behaviour change is suspicious.',
                '**Look for transfer.** Does improvement show up in situations the tool didn\'t coach directly?',
            ]],
            ['note' => 'For a coaching tool, the hard part is the measure. Rating moves slowly and noisily. Measures closer to the advice (how often the player does the recommended thing, and what happens in those moments) show change sooner, but still have to connect to winning.'],
        ],
        'transfer' => [
            'domain' => 'Business',
            'scenario' => 'A company wants to know whether a new welcome email increases the share of trial users who become paying customers.',
            'question' => 'What is the best test?',
            'options' => [
                $o('Randomly send the new email to half of new trial users and the old one to the rest, and compare conversion.', true, 'Random assignment at the same time controls for season and everything else. This is a standard A/B test.'),
                $o('Send it to everyone this month and compare with last month.', false, 'Last month differs in season, marketing and more. The comparison can\'t isolate the email.'),
                $o('Send it to the most engaged users first.', false, 'They would convert more anyway, so the result would flatter the email.'),
                $o('Ask the marketing team which email is better.', false, 'Opinion is where a hypothesis starts, not a test of it.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'List the decisions you\'d need to make before running the coaching-tool experiment.',
            'model' => [
                'The main measure, and when it\'s taken (for example, rating change after 100 games).',
                'How players are assigned, and how many each group needs.',
                'What the control group gets, so attention and novelty don\'t explain the difference.',
                'How you\'ll check that players actually followed the advice.',
            ],
        ],
        'sources' => ['Ronald Fisher, *The Design of Experiments* (1935)', 'Ron Kohavi, Diane Tang and Ya Xu, *Trustworthy Online Controlled Experiments* (2020)'],
    ],
];
