<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'prediction-is-not-cause',
        'title' => 'A predictor is not a cause',
        'summary' => 'A variable can predict an outcome perfectly well and still be the wrong thing to change.',
        'minutes' => 20,
        'concepts' => ['confounding', 'reverse-causality'],
        'case' => [
            'domain' => 'Competitive games',
            'scenario' => [
                'Suppose an analysis of 2,000 arena rounds finds: **teams lose 80% of rounds in which they have fewer than two major defensive cooldowns available** when the decisive attack starts. (A worked example; the figure is illustrative.)',
                'A coaching tool turns this into advice: "Always keep at least two defensives available."',
            ],
            'prompt' => 'Is that good advice? What do you think the statistic actually shows?',
            'reveal' => [
                'Look at how a team ends up with fewer than two defensives. Often it\'s because they were forced to use them earlier, by poor positioning or by an opponent who traded cooldowns better. The same earlier events that drained the defensives also put the team in a weak position.',
                'And sometimes spending them was right: the team survived several dangerous attacks that way and lost to a better opponent anyway.',
            ],
            'question' => 'What can we reasonably conclude from the 80% figure?',
            'options' => [
                $o('Low availability predicts losing, but it may be a symptom of earlier problems, so the advice may treat the symptom.', true, 'A variable can predict an outcome without being the thing to change. If the cause is several decisions earlier, "keep two defensives" could even make things worse by having players hold cooldowns they need.'),
                $o('Low defensive availability causes losses, so the advice is sound.', false, 'The figure fits that, but it also fits earlier events causing both the low availability and the loss. The data alone can\'t separate them.'),
                $o('The statistic is meaningless.', false, 'It\'s a real and useful pattern: it tells you when a round is in danger. It just doesn\'t tell you what to do about it.'),
                $o('Teams should use defensives less.', false, 'That\'s the same causal leap in a different direction. Nothing in the figure says less use would help.'),
            ],
        ],
        'principle' => [
            ['h' => 'Prediction and cause are different questions'],
            'A **predictive** variable tells you what is likely to happen. A **causal** explanation tells you what would happen if you changed something. AI models are very good at the first and, on their own, say nothing about the second.',
            ['h' => 'Two ways a predictor misleads'],
            ['list' => [
                '**Confounding.** A third factor causes both the predictor and the outcome. Poor positioning drains defensives *and* loses rounds.',
                '**Reverse causality.** The outcome, or the process leading to it, produces the predictor. A round that is already being lost forces defensives out.',
            ]],
            'In both cases, acting on the predictor (holding defensives) doesn\'t touch the cause, and can do harm.',
            ['h' => 'Look several steps back'],
            'The important cause is often several decisions earlier than the moment being measured. Ask how the system got into this state. Draw the sequence, not just the snapshot.',
            ['note' => 'A measurement can describe a losing position without identifying the mistake that produced it. Treat it as a sign that something went wrong, then go and find what.'],
        ],
        'transfer' => [
            'domain' => 'Research and AI',
            'scenario' => 'A study reports that people who use an AI productivity assistant complete 35% more tasks than people who don\'t.',
            'question' => 'What can we reasonably conclude?',
            'options' => [
                $o('Users completed more tasks, but we don\'t yet know whether the assistant caused it.', true, 'The observation is real; the cause isn\'t established. We\'d want to know how people came to use it, and whether their tasks were comparable.'),
                $o('The assistant increased productivity by 35%.', false, 'That is the causal reading. If people chose whether to use it, users may differ: more motivated, more technical, or in roles with more small tasks.'),
                $o('The study proves the assistant doesn\'t work.', false, 'Nothing points that way. Uncertainty about the cause isn\'t evidence against it.'),
                $o('The result is meaningless because statistics can\'t prove anything.', false, 'Statistics can support causal claims with the right design, such as random assignment. This study just doesn\'t say which design it used.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'What evidence would let you tell "low defensives cause losses" apart from "earlier mistakes cause both"?',
            'model' => [
                'The sequence of each round: what happened before the defensives were spent (damage taken, position, crowd control).',
                'Rounds where teams had few defensives but were not under pressure. If those are still mostly lost, availability matters in its own right.',
                'Rounds where defensives went early but the earlier play was strong. Do those teams still lose?',
                'A deliberate test: practise holding a defensive in a controlled setting and see whether outcomes change.',
            ],
        ],
        'sources' => ['Judea Pearl and Dana Mackenzie, *The Book of Why* (2018)'],
    ],

    [
        'slug' => 'did-it-work',
        'title' => 'Did the change cause the improvement?',
        'summary' => 'Every causal claim compares what happened with something that didn\'t. Without a fair version of that comparison, credit goes to whatever changed last.',
        'minutes' => 20,
        'concepts' => ['counterfactual', 'regression-to-mean'],
        'case' => [
            'domain' => 'Coaching software',
            'scenario' => [
                'A match-review app tells a player that their crowd control on the enemy healer is too rare, and recommends using it before every team go.',
                'Over the next month the player follows the advice and their win rate rises from 48% to 55%. The app\'s dashboard says: "Following this recommendation improved your win rate by 7 points."',
            ],
            'prompt' => 'Do you believe the dashboard? What else might explain the rise?',
            'reveal' => ['Over the same month, the player switched to a new teammate, a balance patch weakened a composition they often lost to, and they had started the month straight after an unusually bad losing streak.'],
            'question' => 'What is the strongest conclusion the evidence supports?',
            'options' => [
                $o('The rise happened, but without knowing what would have happened otherwise, the recommendation\'s share of it is unknown.', true, 'The counterfactual, the same month without the advice, is missing. A new teammate, the patch and recovery from a bad streak could each explain some or all of it.'),
                $o('The recommendation caused the 7-point rise.', false, 'Possible, but three other things changed at the same time, and a rise after an unusually bad stretch is expected anyway.'),
                $o('The recommendation had no effect.', false, 'The evidence doesn\'t show that either. The effect is unknown, not zero.'),
                $o('Seven points is too small to matter.', false, 'Seven points could matter a lot. The issue is attribution, not size.'),
            ],
        ],
        'principle' => [
            ['h' => 'Every causal claim compares with something that didn\'t happen'],
            '"The advice improved your win rate" means: your win rate is higher than it *would have been* without the advice. That second world, the **counterfactual**, can\'t be observed. Good evidence finds a fair stand-in for it.',
            ['table' => ['head' => ['Comparison', 'Weakness'], 'rows' => [
                ['Before and after', 'Anything else that changed in between gets counted too, and something usually does.'],
                ['People who took the advice versus people who didn\'t', 'The two groups may differ in motivation, skill or circumstance.'],
                ['Random assignment', 'Strong: chance decides who gets the change, so other differences even out.'],
            ]]],
            ['h' => 'Regression to the mean'],
            'Unusually bad results tend to be followed by more ordinary ones, with or without any change. Starting the month after a losing streak almost guarantees some improvement. Any fix applied at a low point will look effective.',
            ['note' => 'This is exactly the problem a coaching tool faces in proving itself. "This recommendation improved your play" is only as good as the comparison behind it.'],
        ],
        'transfer' => [
            'domain' => 'Business',
            'scenario' => 'A company installs new scheduling software in March. Average response time falls from 14 hours to 11 hours between February and May. The vendor publishes a case study crediting the software.',
            'question' => 'What is the most important thing to check first?',
            'options' => [
                $o('How response times moved over the same months in previous years, and what else changed.', true, 'If response times always fall after a summer peak, or a new hire started in March, the drop may have happened anyway. You need a fair comparison before giving credit.'),
                $o('Whether staff like the software.', false, 'Useful to know, but it doesn\'t address whether the drop was caused by it.'),
                $o('Whether three hours is a big improvement.', false, 'Size matters only once you know the cause.'),
                $o('Whether the vendor is reputable.', false, 'Reputation doesn\'t make the inference valid. The comparison does.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'Design a fairer test of whether the app\'s recommendation helps players.',
            'model' => [
                'Randomly choose which players (or which weeks) get the recommendation, so other changes affect both groups equally.',
                'Decide the measure in advance, for example win rate over a fixed number of games, so the result can\'t be picked afterwards.',
                'Track the specific behaviour the advice targets, to check whether players actually followed it.',
                'Compare against the same players\' earlier seasons to see their normal ups and downs.',
            ],
        ],
        'sources' => ['Judea Pearl and Dana Mackenzie, *The Book of Why* (2018)', 'Daniel Kahneman, *Thinking, Fast and Slow* (2011), on regression to the mean'],
    ],
];
