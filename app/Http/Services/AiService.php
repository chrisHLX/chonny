<?php

namespace App\Http\Services;

use GuzzleHttp\Client;
use App\Models\AiRequest;
use Illuminate\Support\Facades\Log; 

use Illuminate\Support\Facades\Http;


class AiService
{
    protected Client $client;
    protected string $apiKey;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->apiKey = env('OPENAI_API_KEY'); // Or inject via config if you prefer
    }

    public function tagConcepts(string $questionText, string $answerText = '', $module_id): array
    {
        $prompt = <<<EOT
        You are a StarCraft 2 coach. Analyze the following question and answer. Select 1–3 core gameplay concepts from the list that apply.

        Return only raw JSON. Do not include markdown or formatting. Just return: {"concepts": [...]}

        Concepts: ['economy', 'build orders', 'scouting', 'strategy', 'map control', 'tactics', 'mechanics', 'other']
        Question: {$questionText}
        Answer: {$answerText}
        EOT;


        $response = $this->callOpenAi($prompt);
        $concepts = $response['concepts'] ?? [];

        \Log::info('This is the custom LOG', ['response' => $concepts]);

        // add the response to the database
        AiRequest::create([
            'user_id' => auth()->id(),
            'purpose' => 'tag_concepts',
            'prompt' => $prompt,
            'response' => $concepts,
            'metadata' => [
                'question_text' => $questionText,
                'answer_text' => $answerText,
                'module_id' => $module_id, 
                'model' => 'gpt-4.1-mini',
            ],
        ]);

        return $concepts;

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
        Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);

        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            //'model' => 'gpt-3.5-turbo',
            'model' => 'gpt-4.1-mini', // Use gpt-4 for better performance
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        $json = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? '{}';

        // 🔥 Strip Markdown code block (if present)
        $content = trim($content);
        $content = preg_replace('/^```json|```$/i', '', $content); // Remove ```json and ```
        $content = trim($content);

        Log::debug('Cleaned OpenAI content', ['content' => $content]);

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    

}
