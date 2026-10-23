<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'and-then-what',
        'title' => 'And then what?',
        'summary' => 'First-order effects are the ones you planned. Second-order effects are what those cause next, often delayed and accumulating.',
        'minutes' => 15,
        'concepts' => ['second-order'],
        'case' => [
            'domain' => 'Everyday life',
            'scenario' => ['Someone notices that a large dessert after dinner reliably makes them feel good. Each evening the choice feels obviously right: the benefit is immediate and certain, and one dessert makes no visible difference.'],
            'prompt' => 'Why might a choice that seems right every single evening still turn out badly?',
            'reveal' => ['A daily 400-calorie dessert adds up to about 146,000 calories over a year. Any effect on weight builds slowly, after a long delay, and never shows on a single evening.'],
            'question' => 'What thinking error does the evening-by-evening choice make?',
            'options' => [
                $o('It counts only first-order effects; the second-order effect is delayed and accumulates.', true, 'Each decision is judged on its immediate result, while the cost builds in a stock (body weight) that only shows after many decisions.'),
                $o('It overrates how good the dessert tastes.', false, 'The pleasure is real. The problem is what the evaluation leaves out.'),
                $o('Desserts are always a bad decision.', false, 'The point isn\'t that dessert is bad. A repeated choice should be judged as a policy, including what it adds up to.'),
                $o('There\'s no error; each choice is independent.', false, 'The choices aren\'t independent: they add to the same stock.'),
            ],
        ],
        'principle' => [
            '**First-order** effects are the immediate, intended results of a decision. **Second-order** effects are what those results cause next. They\'re often delayed, spread out, or land on someone else, which is why they get ignored.',
            ['list' => [
                'Ask *and then what?* at least twice.',
                'Judge repeated choices as policies. One dessert, one discount, one skipped review is negligible; a habit isn\'t.',
                'Look for stocks that build quietly: weight, debt, technical debt, resentment, what your opponents learn about you.',
            ]],
            ['note' => 'In games, a habit of using your strongest defensive at the first sign of pressure feels right every time, and every round it\'s gone before the attack that actually decides the round. The cost only shows across many rounds.'],
        ],
        'transfer' => [
            'domain' => 'Business',
            'scenario' => 'To hit a quarterly target, a sales team offers a 20% discount to customers who sign this month. The target is met.',
            'question' => 'Which second-order effect matters most?',
            'options' => [
                $o('Customers learn to wait for end-of-quarter discounts, pulling sales forward and lowering prices next time.', true, 'The discount changes customer behaviour, which changes future sales and margins. That is the effect the target doesn\'t see.'),
                $o('The sales team will be pleased.', false, 'True, but that\'s a first-order effect.'),
                $o('The discount lowers this month\'s margin.', false, 'Also first-order, and already known when the decision was made.'),
                $o('Competitors might notice.', false, 'Possible, but the more direct consequence is how the company\'s own customers respond.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would you check whether the end-of-quarter discount is causing harm over time?',
            'model' => [
                'Track what share of sales lands in the last weeks of each quarter, across several quarters.',
                'Compare the prices customers paid before and after discounting started.',
                'See whether customers who got a discount later buy again at full price.',
            ],
        ],
        'sources' => ['Howard Marks, *The Most Important Thing* (2011), on second-level thinking'],
    ],

    [
        'slug' => 'expected-value-and-reversibility',
        'title' => 'Expected value and reversibility',
        'summary' => 'Weigh outcomes by how likely they are, count what each choice does to your future options, and pay to keep decisions reversible when estimates are shaky.',
        'minutes' => 15,
        'concepts' => ['expected-value'],
        'case' => [
            'domain' => 'Competitive games',
            'scenario' => [
                'Late in a close round, a player can use their last defensive now to survive a probable kill attempt, or hold it in case the enemy\'s bigger cooldown comes next.',
                'If they hold it and this attack is the real one, they die. If they use it and the bigger attack follows, they die then instead.',
            ],
            'prompt' => 'How would you decide? What information would you want?',
            'reveal' => ['From past rounds against this team, their first attack in this situation was the real one about 70% of the time. And surviving this attack means the player\'s own team gets its cooldowns back in 10 seconds.'],
            'question' => 'What does an expected-value view suggest?',
            'options' => [
                $o('Use it now: it answers the more likely threat and buys time for the team\'s cooldowns to return.', true, 'Weigh outcomes by likelihood. Using it answers the 70% case and creates a future (cooldowns back) that improves the other case too.'),
                $o('Hold it, because the bigger attack is more dangerous.', false, 'The bigger attack only matters if it comes first, which happened about 30% of the time. Holding risks dying to the likely case.'),
                $o('It\'s a coin flip; either is fine.', false, '70/30 isn\'t a coin flip, and the cooldown timing tips it further.'),
                $o('Never use your last defensive.', false, 'A fixed rule ignores both the probabilities and the situation.'),
            ],
        ],
        'principle' => [
            '**Expected value** weighs each outcome by how likely it is. It doesn\'t tell you what will happen; it tells you which choice does best across the situations you can\'t tell apart.',
            ['list' => [
                'List the outcomes, estimate their chances, and weigh what each is worth.',
                'Count what each choice does to the future: options it opens or closes.',
                'When the estimates are shaky, prefer the choice that\'s easier to undo.',
            ]],
            ['h' => 'Reversibility'],
            'Some decisions can be undone cheaply, some can\'t. A reversible decision can be made quickly and corrected by feedback; an irreversible one deserves more care, because feedback arrives too late to help. Jeff Bezos called these two-way and one-way doors.',
            ['note' => 'Good decisions can have bad outcomes. Judge a decision by what was knowable when it was made, or you will learn the wrong lesson from bad luck. Annie Duke calls the opposite mistake *resulting*.'],
        ],
        'transfer' => [
            'domain' => 'Business',
            'scenario' => 'A small company must choose between signing a three-year lease on a bigger office now, or renting flexible space month to month, at a higher price, while it finds out whether its growth will last.',
            'question' => 'Which principle matters most here?',
            'options' => [
                $o('Weigh the cost of being wrong: the flexible option costs more but keeps the decision reversible while growth is uncertain.', true, 'When estimates are uncertain, paying something to keep a decision reversible lets feedback correct you.'),
                $o('Always choose the cheaper option per month.', false, 'The cheaper monthly cost comes with a commitment that\'s hard to undo.'),
                $o('Sign the lease, because growth is likely.', false, 'If "likely" is an estimate, the downside of being wrong for three years needs weighing too.'),
                $o('Wait until growth is certain.', false, 'Certainty rarely arrives. Reversible options let you act now and adjust.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would the player get better estimates for the next time this situation comes up?',
            'model' => [
                'Record, across many rounds, which attack came first against this team or composition.',
                'Track the outcome of each choice in similar situations, not just the memorable ones.',
                'When reviewing, separate decision quality from outcome: was the call right given what was known?',
            ],
        ],
        'sources' => ['Annie Duke, *Thinking in Bets* (2018)', 'Jeff Bezos, letter to Amazon shareholders (2015), on one-way and two-way doors'],
    ],
];
