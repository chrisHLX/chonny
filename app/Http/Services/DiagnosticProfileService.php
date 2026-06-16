<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiagnosticProfileService
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function generateProfile(array $traitScores, array $axisScores, array $conceptScores, int $userId, bool $isGuest = false): array
    {
        $traitBlock = collect($traitScores)
            ->map(fn($score, $key) => "{$key}: {$score}")
            ->implode(', ') ?: 'No trait signals recorded.';

        $axisBlock = collect($axisScores)
            ->map(fn($score, $name) => "{$name}: {$score}%")
            ->implode(', ') ?: 'No axis mastery data available.';

        $conceptBlock = collect($conceptScores)
            ->map(fn($score, $name) => "{$name}: {$score}%")
            ->implode(', ') ?: 'No concept mastery data available.';

        $prompt = <<<PROMPT
        You are analysing a player's diagnostic quiz results to build a personality-style learning profile.

        TRAIT SCORES (accumulated signal strength per trait — higher means the trait is more strongly expressed, negative means the opposite tendency):
        {$traitBlock}

        AXIS MASTERY (percentage mastery per skill dimension):
        {$axisBlock}

        TOP STRUGGLED CONCEPTS (percentage mastery, weakest first):
        {$conceptBlock}

        Based on this data, return JSON ONLY in this exact format, no markdown fences or commentary:
        {
          "player_type": "Strategic Controller",
          "narrative": "One paragraph starting with 'Your answers suggest...'",
          "top_traits": ["control_orientation", "patience", "structure_preference"],
          "growth_area": "Short sentence about weakest axis/concept",
          "next_module_suggestion": "Module name or topic to study next"
        }
        PROMPT;

        // Guests have no user_credits row and the diagnostic profile is the conversion
        // moment for them — generate it for free via a direct Gemini call instead of
        // routing through AiService/CreditService, which requires a real user_id.
        $response = $isGuest
            ? $this->generateForGuest($prompt)
            : $this->aiService->sendPromptToAi($prompt, 'gpt-4o-mini', $userId, 'diagnostic_profile_generation');

        return [
            'player_type'            => $response['player_type'] ?? 'Unclassified',
            'narrative'              => $response['narrative'] ?? '',
            'top_traits'             => $response['top_traits'] ?? [],
            'growth_area'            => $response['growth_area'] ?? '',
            'next_module_suggestion' => $response['next_module_suggestion'] ?? '',
        ];
    }

    private function generateForGuest(string $prompt): array
    {
        $key = config('services.gemini.key');
        if (empty($key)) {
            Log::error('Guest diagnostic profile generation skipped: GEMINI_API_KEY is not configured.');
            return [];
        }

        $modelId  = 'gemini-2.5-flash-lite';
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent";

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(30)
                ->post("{$endpoint}?key={$key}", [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                ]);
        } catch (\Throwable $e) {
            Log::error('Guest diagnostic profile Gemini call failed', ['error' => $e->getMessage()]);
            return [];
        }

        if ($response->failed()) {
            Log::error('Guest diagnostic profile Gemini API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }

        $content = $response->json('candidates.0.content.parts.0.text', '');
        $content = trim(preg_replace('/^```json|```$/i', '', trim($content)));

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
