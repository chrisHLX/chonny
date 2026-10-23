<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'trusting-a-model',
        'title' => 'Should you trust this model?',
        'summary' => 'What a model was tested on, whether its inputs leak the answer, and how it compares with a trivial guess. And why fluent isn\'t the same as right.',
        'minutes' => 20,
        'concepts' => ['model-evaluation', 'base-rates'],
        'case' => [
            'domain' => 'AI',
            'scenario' => ['A team builds a model to predict which customers will cancel their subscription. On historical data it is **98% accurate**. They plan to use it to decide who gets a retention offer.'],
            'prompt' => 'Should they trust the 98%? What would you check?',
            'reveal' => [
                'One of the model\'s inputs is "support tickets in the last 30 days". In the historical data, that window includes the cancellation request itself, which arrives as a ticket.',
                'Also, only 3% of customers cancel in a given month.',
            ],
            'question' => 'What is the most serious problem?',
            'options' => [
                $o('Leakage: the model sees information that only exists because the customer cancelled; and with 3% cancelling, guessing "nobody cancels" already scores 97%.', true, 'Two problems. The leaked input won\'t exist before a cancellation in real use, and accuracy against a 3% base rate barely beats always guessing "stays". It needs testing on genuinely later data, with measures that look at the cancellers.'),
                $o('98% isn\'t high enough.', false, 'The number looks impressive. The question is whether it means anything.'),
                $o('The model needs more data.', false, 'More leaked data would make the same mistake look even better.'),
                $o('Machine learning can\'t predict cancellations.', false, 'It often can, usefully. This model\'s evaluation is the problem.'),
            ],
        ],
        'principle' => [
            ['h' => 'Questions to ask of any model'],
            ['list' => [
                '**What was it tested on?** Data it hadn\'t seen, from a later period, or the data it learned from?',
                '**Leakage:** could an input contain the answer, or exist only after the outcome?',
                '**Base rate:** how well would a trivial rule, always guessing the common outcome, do?',
                '**Drift:** has the world changed since the training data was collected?',
                '**Who checks it?** A process independent of the one that produced it?',
            ]],
            ['h' => 'Fluent is not the same as right'],
            'Language models produce fluent, confident explanations whether or not they\'re right, and sometimes cite sources or facts that don\'t exist. A well-written answer can be fully understood and still be wrong.',
            'Asking the same system to grade its own explanation is weak evidence, because it shares the same blind spots. Prefer checks that come from outside: real outcomes, independent data, a test designed in advance.',
            ['note' => 'Use AI to generate hypotheses, alternatives and drafts. Tie the judgment of which ones are true to evidence you can check.'],
        ],
        'transfer' => [
            'domain' => 'Competitive games',
            'scenario' => 'An AI assistant reviews your match and says: "You lost because your crowd control on the enemy healer was mistimed in the second minute. Fix this and you will win far more often."',
            'question' => 'What is the most useful response?',
            'options' => [
                $o('Check the claim against the log, then ask what evidence links that moment to the loss.', true, 'Verify the observation first, then ask how strongly that moment connects to the outcome, and whether something else explains the loss better.'),
                $o('Accept it; the assistant analysed the whole log.', false, '"You lost because" is a causal claim and "win far more often" is a prediction. Neither has been shown.'),
                $o('Ignore it; AI can\'t understand games.', false, 'It can find real patterns. Ignoring it throws away a possibly useful hypothesis.'),
                $o('Ask the assistant whether it\'s sure.', false, 'Its confidence isn\'t independent evidence.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would you evaluate the cancellation model fairly before using it?',
            'model' => [
                'Remove any input recorded at or after the cancellation.',
                'Train on older data and test on a later period the model never saw.',
                'Report how many actual cancellers it catches, and how many of its warnings are right, and compare with simple baselines.',
                'Run it on new customers for a while and check its predictions against what happens.',
            ],
        ],
        'sources' => ['Sayash Kapoor and Arvind Narayanan, \'Leakage and the reproducibility crisis in machine-learning-based science\', *Patterns* (2023)'],
    ],
];
