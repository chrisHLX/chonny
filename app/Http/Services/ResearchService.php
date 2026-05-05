<?php

namespace App\Http\Services;

use App\Models\AiRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResearchService
{
    public function fetchLatestMaterial(string $topic, string $subjectName, int $userID): array
    {
        $key = config('services.gemini.key');

        if (empty($key)) {
            return ['summary' => '', 'sources' => [], 'error' => 'GEMINI_API_KEY is not configured.'];
        }

        $promptText = "Research the following topic for an educational learning module. "
            . "Provide a comprehensive, accurate, and up-to-date summary suitable for creating quiz questions. "
            . "Include key facts, concepts, strategies, and any recent developments.\n\n"
            . "Topic: {$topic}\n"
            . "Subject area: {$subjectName}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $promptText],
                    ],
                ],
            ],
            'tools' => [
                ['google_search' => (object) []],
            ],
        ];

        $modelId = 'gemini-2.5-flash-lite';
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent";

        Log::debug('ResearchService sending to Gemini', ['topic' => $topic]);

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(30)
                ->post("{$endpoint}?key={$key}", $payload);
        } catch (\Throwable $e) {
            Log::error('ResearchService HTTP error', ['message' => $e->getMessage()]);
            return ['summary' => '', 'sources' => [], 'error' => 'Research request failed: ' . $e->getMessage()];
        }

        if ($response->failed()) {
            $status = $response->status();
            $body   = $response->body();
            Log::error('ResearchService Gemini API error', ['status' => $status, 'body' => $body]);
            return ['summary' => '', 'sources' => [], 'error' => "Gemini API returned status {$status}."];
        }

        $json = $response->json();

        $summary = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $rawChunks = $json['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
        $sources = collect($rawChunks)
            ->filter(fn ($chunk) => isset($chunk['web']['uri']))
            ->map(fn ($chunk) => [
                'uri'   => $chunk['web']['uri'],
                'title' => $chunk['web']['title'] ?? $chunk['web']['uri'],
            ])
            ->values()
            ->all();

        AiRequest::create([
            'user_id'  => $userID,
            'purpose'  => 'research',
            'prompt'   => $promptText,
            'response' => $summary,
            'metadata' => [
                'model'   => $modelId,
                'topic'   => $topic,
                'sources' => count($sources),
            ],
        ]);

        Log::info('ResearchService completed', ['topic' => $topic, 'sources' => count($sources)]);

        return ['summary' => $summary, 'sources' => $sources];
    }
}
