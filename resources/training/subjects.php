<?php

// The subjects, in course order. Each has a lessons file at lessons/{slug}.php.
return [
    [
        'slug' => 'cybernetics',
        'title' => 'Cybernetics and feedback loops',
        'summary' => 'How systems use information about their own state to steer: feedback, delay, stability, reinforcing and balancing loops, and learning that questions its own goals.',
        'question' => 'Why does a solution sometimes make the original problem worse?',
    ],
    [
        'slug' => 'causal-reasoning',
        'title' => 'Causal reasoning',
        'summary' => 'Telling what happened from why it happened: confounding, reverse causality, counterfactuals, and why a good predictor is not necessarily something to change.',
        'question' => 'Did our intervention actually cause the improvement?',
    ],
    [
        'slug' => 'statistics',
        'title' => 'Statistics and probability',
        'summary' => 'Reading numbers without being misled by them: base rates, changes in mix, and results that drift back toward average on their own.',
        'question' => 'Does a statistic tell us what we think it tells us?',
    ],
    [
        'slug' => 'systems-thinking',
        'title' => 'Systems thinking and system dynamics',
        'summary' => 'How parts interact to produce outcomes none of them would on their own: bottlenecks, recurring traps, and where intervention actually works.',
        'question' => 'Are we solving the underlying problem, or moving it somewhere else?',
    ],
    [
        'slug' => 'decisions',
        'title' => 'Decision theory and second-order thinking',
        'summary' => 'Choosing under uncertainty and following consequences past the first step: expected value, reversibility, and effects that build up slowly.',
        'question' => 'What happens next, and what happens after that?',
    ],
    [
        'slug' => 'epistemology',
        'title' => 'Cognitive psychology and epistemology',
        'summary' => 'How minds build explanations and decide what counts as knowing: confirmation bias, separating observation from story, and naming what would change your mind.',
        'question' => 'What evidence would convince me that I\'m wrong?',
    ],
    [
        'slug' => 'experiments',
        'title' => 'Scientific method and experimentation',
        'summary' => 'Testing an explanation instead of defending it: controls, random assignment, measures chosen in advance.',
        'question' => 'How would we test whether this actually works?',
    ],
    [
        'slug' => 'incentives',
        'title' => 'Game theory and incentives',
        'summary' => 'Outcomes that emerge from people with different goals: principal and agent, gaming a measure, and Goodhart\'s law.',
        'question' => 'Why do people act against the goals they\'re measured on?',
    ],
    [
        'slug' => 'ai-literacy',
        'title' => 'AI literacy and model evaluation',
        'summary' => 'What models do, how they fail, and how to judge their output: leakage, base rates, drift, and why fluent is not the same as right.',
        'question' => 'How do we know whether an AI recommendation is trustworthy?',
    ],
    [
        'slug' => 'human-factors',
        'title' => 'Human factors and behaviour change',
        'summary' => 'Why correct information doesn\'t guarantee correct action: attention, load, feedback timing and habit.',
        'question' => 'Why might someone understand advice and still not act on it?',
    ],
];
