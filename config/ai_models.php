<?php

return [
    'gpt-4o-mini' => [
        'label'       => 'Standard',
        'description' => 'Fast and cost-effective. Good for most modules.',
        'multiplier'  => 1.0,
        'provider'    => 'openai',
    ],
    'gpt-4.1-mini' => [
        'label'       => 'Enhanced',
        'description' => 'Better reasoning. Recommended for complex topics.',
        'multiplier'  => 1.8,
        'provider'    => 'openai',
    ],
    'gpt-4o' => [
        'label'       => 'Advanced',
        'description' => 'Highest quality. Best for expert-level content.',
        'multiplier'  => 4.0,
        'provider'    => 'openai',
    ],
];
