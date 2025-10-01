<?php

// Costs related to AI requests in credits
// e.g., text prompts, video generation, etc.
// These costs are deducted from the user's credits balance

// Calculated by the cost of the API call divided by 10 to give some margin for profit

return [
    'costs' => [
        'text_prompt' => 1,      // 1 credit per text prompt
        'video_prompt' => 50,    // 50 credits per 30s video
    ],
];