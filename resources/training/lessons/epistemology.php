<?php

$o = fn (string $text, bool $correct, string $feedback) => compact('text', 'correct', 'feedback');

return [
    [
        'slug' => 'what-would-change-my-mind',
        'title' => 'What would change my mind?',
        'summary' => 'Separate the observation from the story, list what else could explain it, and name in advance the evidence that would prove you wrong.',
        'minutes' => 20,
        'concepts' => ['confirmation-bias'],
        'case' => [
            'domain' => 'News and politics',
            'scenario' => [
                'A local politician is struck by a car while crossing a road. Within an hour, posts are spreading that it was a deliberate, politically motivated attack. Others insist it was obviously an accident.',
                'Both sides point to the same short video clip as proof.',
            ],
            'prompt' => 'What do we actually know at this point, and what is each side assuming?',
            'reveal' => ['Later, police report that the driver didn\'t know who the pedestrian was and had a medical episode at the wheel. Some who had called it an attack say the police are covering it up.'],
            'question' => 'Before the police report, what was the most defensible position?',
            'options' => [
                $o('Unknown: the clip shows a collision, not a motive, so either explanation needed more evidence.', true, 'Separate the observation (a car hit a person who happens to be a politician) from the story (why). Then ask what evidence would tell the stories apart.'),
                $o('It was an attack; the target was too convenient to be chance.', false, 'The victim\'s job is a fact about the victim, not evidence about the driver\'s motive. That needed separate evidence.'),
                $o('It was an accident; politicians aren\'t targeted like that.', false, 'That\'s an assumption too. Some attacks are deliberate; the clip couldn\'t settle it.'),
                $o('Whichever side has more posts is probably right.', false, 'The number of posts measures attention, not evidence.'),
            ],
        ],
        'principle' => [
            '**Confirmation bias** is the tendency to notice, seek and remember evidence that fits what you already believe. Both sides saw the same clip and each found proof, because the clip didn\'t contain the answer. Each side supplied it.',
            ['h' => 'Four questions for any claim'],
            ['list' => [
                '**What do we actually know?** Separate the observation from the story told about it.',
                '**What else could explain it?** List at least one alternative, and what would follow if it were true.',
                '**What would change our confidence?** Name the evidence that would support or weaken each explanation, before you look.',
                '**What happens if we act on it?** Immediate consequences, delayed effects, and how we\'d know whether it worked.',
            ]],
            'Karl Popper argued that a claim worth holding is one that could be shown wrong. If no possible evidence could change your mind, the belief isn\'t resting on evidence. A useful habit: before deciding, write down what would convince you that you\'re wrong.',
            ['note' => 'The end of the case matters too. When evidence arrived, some people explained it away instead of updating. Committing in advance to what would change your mind makes that harder to do to yourself. The Claims page in this course is built for exactly that.'],
        ],
        'transfer' => [
            'domain' => 'Competitive games',
            'scenario' => 'A player is convinced their teammate "always" misses interrupts at important moments. In every lost round they review, they look for missed interrupts, and they keep finding some.',
            'question' => 'What is the best way to test the belief?',
            'options' => [
                $o('Count interrupts landed and missed in won and lost rounds alike, and compare with what similar players manage.', true, 'That looks at all the evidence, including rounds that could disconfirm the belief, and compares against a sensible base rate.'),
                $o('Keep reviewing lost rounds for missed interrupts.', false, 'That only looks where the belief predicts problems. It can\'t find evidence against it.'),
                $o('Ask the teammate whether they miss interrupts.', false, 'Their view is worth hearing, but it isn\'t a test.'),
                $o('Trust your experience; you\'ve seen it many times.', false, 'Seeing it many times is exactly what selective attention produces.'),
            ],
        ],
        'evidence' => [
            'prompt' => 'Before the police report, what evidence would have separated "deliberate attack" from "accident"?',
            'model' => [
                'Whether the driver knew who the pedestrian was, or had any connection to them.',
                'How the car moved before the collision: braking, swerving, speed.',
                'Witness accounts, and the driver\'s medical and phone records.',
                'Any motive found independently of the event itself.',
            ],
        ],
        'sources' => ['Karl Popper, *The Logic of Scientific Discovery* (1959)', 'Daniel Kahneman, *Thinking, Fast and Slow* (2011)'],
    ],
];
