<?php

namespace App\Http\Services;

class TokenService
{
    // Pricing per 1,000,000 tokens in USD (as per OpenAI, Oct 2025)
    protected array $pricing = [
        'gpt-4.1' => [
            'input' => 2.00,
            'cached_input' => 0.50,
            'output' => 8.00,
        ],
        'gpt-4.1-mini' => [
            'input' => 0.40,
            'cached_input' => 0.10,
            'output' => 1.60,
        ],
        'gpt-4.1-nano' => [
            'input' => 0.10,
            'cached_input' => 0.025,
            'output' => 0.40,
        ],
        'gpt-4o' => [
            'input' => 2.50,
            'cached_input' => 1.25,
            'output' => 10.00,
        ],
        'gpt-4o-2024-05-13' => [
            'input' => 5.00,
            'output' => 15.00,
        ],
        'gpt-4o-mini' => [
            'input' => 0.15,
            'cached_input' => 0.075,
            'output' => 0.60,
        ],
    ];

    /**
     * Calculate cost (USD) based on token usage.
     */
    public function calculateCost(string $model, int $inputTokens, int $outputTokens, bool $cached = false): array
    {
        if (!isset($this->pricing[$model])) {
            throw new \Exception("Model pricing not defined for: {$model}");
        }

        $priceData = $this->pricing[$model];
        $inputRate = $cached && isset($priceData['cached_input'])
            ? $priceData['cached_input']
            : $priceData['input'];

        // Convert token counts to millions USD
        $inputUsd = ($inputTokens / 1_000_000) * $inputRate;
        $outputUsd = ($outputTokens / 1_000_000) * $priceData['output'];

        return [
            'input_usd' => round($inputUsd, 6),
            'output_usd' => round($outputUsd, 6),
            'total_usd' => round($inputUsd + $outputUsd, 6),
        ];
    }

    /**
     * Convert a USD cost into in-app credits.
     */
    public function convertToCredits(float $usdCost): array
    {
        $usdPerCredit = 0.01; // e.g. 1 credit = $0.01
        $rawCredits = $usdCost / $usdPerCredit;

        return [
            'raw' => $rawCredits,
            'charged' => (int) ceil($rawCredits),
            'usd_per_credit' => $usdPerCredit,
        ];
    }

    /**
     * Combined helper: compute total credit cost.
     */
    public function calculateCreditCost(string $model, int $inputTokens, int $outputTokens, bool $cached = false): array
    {
        $cost = $this->calculateCost(
            $model,
            $inputTokens,
            $outputTokens,
            $cached
        );

        $credits = $this->convertToCredits($cost['total_usd']);

        return [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'credits' => $credits,
        ];
    }
}
