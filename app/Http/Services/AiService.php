<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiService
{
    public function tagConcepts(string $questionText, string $answerText = ''): array
    {
        $prompt = <<<EOT
        You are a StarCraft 2 coach. Analyze the following question and answer. Select 1–3 core gameplay concepts from the list that apply. Return in JSON: {"concepts": [...]}

        Concepts: ['economy', 'build orders', 'scouting', 'strategy', 'map control', 'tactics', 'mechanics']
        Question: {$questionText}
        Answer: {$answerText}
        EOT;

        return $this->callOpenAi($prompt)['concepts'] ?? [];
    }

    public function tagUnits(string $questionText, string $answerText = ''): array
    {
        $prompt = <<<EOT
        You are a StarCraft 2 coach. Analyze the following question and answer. Identify 1–3 specific units mentioned or implied. Return in JSON: {"units": [...]}

        Question: {$questionText}
        Answer: {$answerText}
        EOT;

        return $this->callOpenAi($prompt)['units'] ?? [];
    }

    private function callOpenAi(string $prompt): array
    {
        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        $json = $response->json();
        return json_decode($json['choices'][0]['message']['content'] ?? '{}', true);
    }
}
