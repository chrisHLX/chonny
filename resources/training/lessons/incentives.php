<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'measures-and-motives',
        'title' => 'Measures and motives',
        'summary' => 'People optimise what they\'re rewarded for. When a measure becomes the target, it stops measuring what it used to.',
        'minutes' => 15,
        'concepts' => ['goodhart'],
        'case' => [
            'domain' => 'Business',
            'scenario' => [
                'A call centre starts rewarding staff for keeping the average call under four minutes. Within two months, average call length falls by a third.',
                'Over the same period, the number of customers calling back about the same problem rises sharply.',
            ],
            'prompt' => 'What happened, and whose fault is it?',
            'reveal' => ['Staff say that long calls hurt their bonus, so they resolve what\'s quick and suggest customers call back about the rest. A few transfer difficult calls to other departments.'],
            'question' => 'What is the best explanation?',
            'options' => [
                $o('The measure became a target, and people optimised it instead of the goal it stood for: resolving problems.', true, 'Goodhart\'s law. Call length used to indicate efficiency; once it was rewarded, it was gamed and stopped measuring that.'),
                $o('Staff are dishonest.', false, 'They responded sensibly to what they were rewarded for. Blaming individuals misses the design problem.'),
                $o('Customers have harder problems this year.', false, 'Nothing suggests that, and it wouldn\'t explain shorter calls.'),
                $o('Four minutes is the wrong number.', false, 'Any target on that measure alone would invite the same behaviour.'),
            ],
        ],
        'principle' => [
            'Economist Charles Goodhart observed in 1975 that a statistical relationship tends to break down once it\'s used as a target for control. The usual modern wording, from anthropologist Marilyn Strathern: when a measure becomes a target, it stops being a good measure.',
            'This is a game-theory problem: the people being measured are players with their own goals. In a **principal-agent** relationship, the principal (the company) can\'t observe effort directly, so it rewards a measure, and the agent (the employee) optimises whatever is rewarded.',
            ['list' => [
                'Measure several things that are hard to game together: call length *and* repeat calls *and* resolution.',
                'Keep a person reading the cases behind the number.',
                'Change or retire measures that have stopped meaning anything.',
                'Before launching a measure, ask: how would a clever person improve this number without improving the real thing?',
            ]],
            ['note' => 'Ratings are measures too. A player who avoids hard matchups to protect their rating improves the number without improving their play.'],
        ],
        'transfer' => [
            'domain' => 'Software',
            'scenario' => 'A software team is told it will be judged on the number of bugs closed each week. Within a month, bugs closed has doubled.',
            'question' => 'What is most likely to have happened to software quality?',
            'options' => [
                $o('It may not have improved, or may be worse: the count can rise through split tickets, quick closes and skipped hard bugs.', true, 'Once the count is the target, it can rise in ways unconnected to quality. You\'d need to check reopened bugs and defects reported by customers.'),
                $o('It improved, since twice as many bugs were fixed.', false, 'Possible, but the measure invites splitting bugs into many small tickets, closing without proper fixes, and avoiding hard ones.'),
                $o('It\'s impossible to say anything.', false, 'We can predict how the incentive shapes behaviour, and what to check.'),
                $o('Quality depends only on the developers\' skill.', false, 'Skill matters, but incentives decide where it\'s spent.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'What would you measure alongside call length to see whether the call centre is actually serving customers better?',
            'model' => [
                'Repeat calls about the same problem within a week.',
                'Transfers between departments.',
                'Whether customers say their problem was resolved, asked independently of the agent.',
                'A review of a random sample of calls.',
            ],
        ],
        'sources' => ['Charles Goodhart, \'Problems of Monetary Management: The U.K. Experience\' (1975)', 'Marilyn Strathern, \'"Improving ratings": audit in the British University system\' (1997)', 'Robert Axelrod, *The Evolution of Cooperation* (1984)'],
    ],
];
