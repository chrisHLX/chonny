<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiService
{
    public static function tagConcepts(string $questionText, string $answerText = ''): array
    {
        $prompt = <<<EOT
You are a StarCraft 2 coach. Analyze the following question and answer. Select 1–3 core gameplay concepts from the list that apply. Return in JSON: {"concepts": [...]}

Concepts: ["strategy", "tactic", "economic", "composition", "micro", "map control", "timing", "transition", "defensive", "harassment", "offensive", "scouting", "unit control", "resource management", "positioning", "build order", "macro", "adaptation", "psychological", "meta", "other"]

Question: {$questionText}

Answer: {$answerText}
EOT;

        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        $json = $response->json();

        return json_decode($json['choices'][0]['message']['content'] ?? '{}', true)['concepts'] ?? [];
    }
}
