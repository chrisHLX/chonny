<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'base-rates',
        'title' => 'Base rates',
        'summary' => 'A count without its base rate says nothing about risk. The question is always: out of how many?',
        'minutes' => 15,
        'concepts' => ['base-rates'],
        'case' => [
            'domain' => 'News and statistics',
            'scenario' => [
                'A headline: **"The Holden Commodore is Australia\'s most stolen car."**',
                'A reader concludes that owning a Commodore makes their car more likely to be stolen than owning another model.',
            ],
            'prompt' => 'What would you want to know before agreeing?',
            'reveal' => ['For many years the Commodore was among Australia\'s best-selling cars, so far more of them are on the road than most models. Many are also older, and older cars generally lack the engine immobilisers newer cars have.'],
            'question' => 'What does "most stolen" tell an individual owner about their risk?',
            'options' => [
                $o('Not much on its own: we need thefts per registered car, compared against cars of similar age.', true, 'The base rate (how many are on the road) and a confounder (age and security features) both drive the count. Risk per car, compared like for like, answers the owner\'s question.'),
                $o('Commodore owners face the highest risk.', false, '"Most stolen" counts thefts, not thefts per car. A very common car can top the count even if each one is at average risk.'),
                $o('Commodores must be easier to steal.', false, 'That might be true of older ones, but the count alone can\'t show it.'),
                $o('The statistic is false.', false, 'The count can be accurate. It\'s the reader\'s interpretation that isn\'t supported.'),
            ],
        ],
        'principle' => [
            'A **base rate** is how common something is to begin with. Without it, a count can\'t tell you about risk. The question to ask is always: *out of how many?*',
            ['list' => [
                '**Counts versus rates.** Most thefts, accidents and errors happen where there is the most of something.',
                '**The right denominator.** Thefts per registered car, accidents per kilometre driven, errors per thousand jobs.',
                '**Like for like.** Even a rate can mislead if the groups differ in other ways, such as vehicle age.',
            ]],
            ['note' => 'The same mistake turns up in game data. "Most deaths happen to the healer" may only mean the healer is targeted most. Deaths per time spent being targeted is the fairer comparison.'],
        ],
        'transfer' => [
            'domain' => 'Everyday life',
            'scenario' => 'A road-safety campaign says that most car accidents happen within 5 km of home, and concludes that driving near home is especially dangerous.',
            'question' => 'What is the most likely explanation?',
            'options' => [
                $o('Most driving happens within 5 km of home, so most accidents do too.', true, 'Without the share of kilometres driven near home, the accident count says nothing about the risk per kilometre.'),
                $o('Drivers are more careless near home.', false, 'Possible, but the claim needs a base rate first.'),
                $o('Local roads are more dangerous.', false, 'That\'s a hypothesis you could only test with accidents per kilometre.'),
                $o('The statistic is made up.', false, 'It may well be accurate. It\'s the conclusion that skips the base rate.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'What data would let you judge whether Commodores are at higher risk per car?',
            'model' => [
                'Thefts of each model divided by the number of that model registered.',
                'The same rate split by vehicle age, or limited to cars of similar age and security features.',
                'Where cars are parked or garaged, if available, since location also affects theft.',
            ],
        ],
        'sources' => ['Daniel Kahneman, *Thinking, Fast and Slow* (2011)'],
    ],

    [
        'slug' => 'when-the-mix-changes',
        'title' => 'When the mix changes',
        'summary' => 'An average is a mix of groups. Change the proportions and the average moves, even if nothing in any group did.',
        'minutes' => 15,
        'concepts' => ['simpsons-paradox'],
        'case' => [
            'domain' => 'Business operations',
            'scenario' => [
                'A service company\'s average response time to urgent jobs rose from 10.2 hours to 12.6 hours. The board asks why the team got slower.',
            ],
            'prompt' => 'What questions would you ask before accepting that the team got slower?',
            'reveal' => [
                'Urgent jobs come in two kinds: emergencies, reached in about 3 hours, and breakdowns, reached in about 15. Both times are unchanged between the two periods.',
                'What changed is the mix. Emergencies were 40% of urgent jobs in the first period and 20% in the second.',
            ],
            'question' => 'What best describes what happened?',
            'options' => [
                $o('The average rose because the mix shifted toward slower job types; the team is no slower at either kind of job.', true, 'With 40% emergencies the average is 0.4 × 3 + 0.6 × 15 = 10.2 hours. With 20% it is 0.2 × 3 + 0.8 × 15 = 12.6. The whole rise is mix.'),
                $o('The team got about 24% slower across the board.', false, 'Within each type of job, nothing changed. The average moved because the proportions did.'),
                $o('The data must be wrong.', false, 'Every figure is consistent. The average simply mixes two groups whose proportions changed.'),
                $o('Averages are useless.', false, 'Averages are fine when you know what\'s in them. Split by group before comparing.'),
            ],
        ],
        'principle' => [
            'An average is a mix. If the proportions in the mix change, the average changes even when nothing inside each group does. In extreme cases every group improves while the overall average gets worse. That reversal is **Simpson\'s paradox**.',
            ['table' => ['head' => ['', 'Period 1', 'Period 2'], 'rows' => [
                ['Emergencies (3 h each)', '40% of jobs', '20% of jobs'],
                ['Breakdowns (15 h each)', '60% of jobs', '80% of jobs'],
                ['Overall average', '10.2 h', '12.6 h'],
            ]]],
            ['list' => [
                'Split by group before comparing averages.',
                'Compare each group with itself, then weight the groups the same way in both periods. This is a *mix-adjusted* or like-for-like comparison.',
                'Ask what changed in the mix, and why. That may be the real story.',
            ]],
            ['note' => 'A well-known real case: in 1973, graduate admissions at the University of California, Berkeley looked biased against women overall, but most departments admitted women at similar or higher rates. Women had applied more often to departments that admitted fewer applicants of either sex.'],
        ],
        'transfer' => [
            'domain' => 'Health',
            'scenario' => 'Hospital A has a higher overall death rate than Hospital B. Hospital A treats far more severe cases. Within each severity group, Hospital A\'s death rate is lower.',
            'question' => 'Which hospital does better for a patient of a given severity?',
            'options' => [
                $o('Hospital A, because within each severity group its death rate is lower.', true, 'Comparing like with like reverses the overall picture. That is Simpson\'s paradox.'),
                $o('Hospital B, because its overall death rate is lower.', false, 'The overall rate mixes severities. B looks better only because it treats milder cases.'),
                $o('They\'re the same.', false, 'Within every group A does better; the data says so directly.'),
                $o('It can\'t be known without data from more hospitals.', false, 'The within-group comparison answers the question for these two hospitals.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'What would you need to show the board whether the team really got slower?',
            'model' => [
                'Response times for each job type in each period.',
                'The share of each job type in each period.',
                'A mix-adjusted comparison: each type compared with itself, weighted the same in both periods.',
                'Why the mix changed. Fewer emergencies might itself be good or bad news.',
            ],
        ],
        'sources' => ['P. J. Bickel, E. A. Hammel and J. W. O\'Connell, \'Sex Bias in Graduate Admissions: Data from Berkeley\', *Science* (1975)'],
    ],

    [
        'slug' => 'regression-to-the-mean',
        'title' => 'Regression to the mean',
        'summary' => 'Extreme results are partly luck, so the next result is usually less extreme, whatever you did in between.',
        'minutes' => 15,
        'concepts' => ['regression-to-mean'],
        'case' => [
            'domain' => 'Coaching',
            'scenario' => ['A coach picks the five players with the worst results in last month\'s tournament for extra training. Next month, all five do better. The coach concludes that the training works.'],
            'prompt' => 'What else could explain the improvement?',
            'reveal' => ['The coach also tracked the five players with the best results last month, who got no extra training. Next month, four of those five did worse.'],
            'question' => 'What explains both groups?',
            'options' => [
                $o('Regression to the mean: extreme results include luck, and the next result is usually less extreme.', true, 'Selecting players by an extreme result selects some who were unusually unlucky (or lucky). Their next results drift back toward their usual level, whatever you do.'),
                $o('The training works, and the top players got complacent.', false, 'That\'s two separate stories for one pattern. A single explanation fits both groups.'),
                $o('The tournament was rigged.', false, 'Nothing suggests that, and ordinary chance explains the pattern.'),
                $o('Both groups\' skill really changed.', false, 'Possible for some players, but when the bottom rises and the top falls as groups, chance is the simpler explanation.'),
            ],
        ],
        'principle' => [
            'Any result is part skill, part luck. Select people by an extreme result and you select people whose luck was extreme too. Next time their luck is likely to be ordinary, so their result moves back toward their average. This is **regression to the mean**, and it happens with no intervention at all.',
            ['list' => [
                'It makes any fix applied after a bad result look effective.',
                'It makes praise after a good result look harmful, because the next result is usually worse.',
                'The more luck in a result, the stronger the effect.',
            ]],
            ['note' => 'Daniel Kahneman tells of flight instructors who believed that shouting at trainees improved them and praise made them worse. Bad landings were followed by better ones and good landings by worse, whatever the instructor said.'],
            'The defence: compare with a group selected the same way that got no intervention, or judge results over many attempts rather than one.',
        ],
        'transfer' => [
            'domain' => 'Business',
            'scenario' => 'After the worst sales month in two years, a company replaces its sales manager. The following month, sales are much better.',
            'question' => 'What is the most careful reading?',
            'options' => [
                $o('Some recovery was likely regardless; judge the new manager over several months against the usual range.', true, 'The bad month was chosen for being extreme. The next one tends back toward normal. Several months tell you far more.'),
                $o('The new manager is much better.', false, 'Maybe, but one month after an unusually bad one would probably improve anyway.'),
                $o('The old manager caused the bad month.', false, 'One extreme month can come from luck, season or a couple of lost deals.'),
                $o('Sales are random and managers don\'t matter.', false, 'Managers can matter; one month just can\'t show how much.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'How would the coach find out whether the extra training actually helps?',
            'model' => [
                'Randomly choose half of the low scorers for training and compare them with the other half.',
                'Compare improvement in trained players against untrained players with similar starting results.',
                'Measure over several tournaments, not one.',
            ],
        ],
        'sources' => ['Daniel Kahneman, *Thinking, Fast and Slow* (2011)', 'Francis Galton, \'Regression Towards Mediocrity in Hereditary Stature\' (1886)'],
    ],
];
