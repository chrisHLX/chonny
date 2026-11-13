<?php

namespace App\Http\Services;

use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Answers /ask questions from the WoW Forever addon with Gemini and Google Search (2026-11-13, Chriso:
 * "a quick optimised search where we minimise prose and return coordinates", so players without
 * Claude Code can use Ask). The desktop app sends the question and what the game knows of the player;
 * the answer goes back into the game the way Claude's does (AskBridge, Answers.lua).
 *
 * What it can't do: read Wowhead's map data. Claude reads every spawn point from the page in the
 * player's Chrome; Google Search only sees page text, and Wowhead blocks this server. So a point is
 * only kept when a source wrote its coordinates out, and Gemini is told never to estimate one: a pin
 * in the wrong place is worse than none (CLAUDE.md, flag don't guess). Claude stays the exact option.
 *
 * Answers are kept for three days by question and faction, so the same "where do I get X" from
 * another player costs nothing; each player gets DAILY_LIMIT fresh answers a day.
 */
class ForeverAskService
{
    public const MODEL = 'gemini-2.5-flash';

    public const DAILY_LIMIT = 30;

    public const KEEP_DAYS = 3;

    public const MAX_POINTS = 25;

    private const SYSTEM = <<<'TXT'
        You answer a question typed inside World of Warcraft: Forever, Blizzard's level-60 remake of the original world
        (launched November 2026, with new zones, recipes and items). The answer is shown in the game and any points are
        drawn on the in-game world map.

        Search Google. Prefer Wowhead's Forever pages (wowhead.com/forever/...) over Classic or retail pages, which can be wrong for Forever.

        Reply with only a JSON object, nothing before or after it:
        {"answer": "...", "points": [{"zone": "Ashenvale", "x": 44.2, "y": 63.1, "label": "Raene Wolfrunner"}]}

        answer: at most two short sentences in plain words, no markdown. Say who or what, and where (zone, town).
        If you couldn't find it, say so plainly.

        points: only coordinates written in a source you found (for example "/way 44.2 63.1" or "(44.2, 63.1)"), as percent
        of the zone's map from 0 to 100, with the zone named as the game shows it. Never estimate, work out or invent a
        coordinate. If no source gives coordinates, return "points": [] and name the zone in the answer. At most 25 points.

        When the question comes with facts "From the Forever game files", they are exact: use them over any website.
        TXT;

    public function __construct(private TokenService $tokens) {}

    /**
     * @return array{status: string, answer?: string, points?: array, sources?: array, cached?: bool, left?: int, reason?: string}
     */
    public function answer(User $user, string $question, string $context, ?string $faction): array
    {
        $question = trim(preg_replace('/\s+/', ' ', $question));
        $key = 'forever-ask:'.sha1(mb_strtolower($question).'|'.($faction ?? ''));
        if (is_array($kept = Cache::get($key))) {
            return $kept + ['cached' => true, 'left' => $this->left($user)];
        }

        $countKey = 'forever-ask-count:'.$user->id.':'.now()->toDateString();
        $used = (int) Cache::get($countKey, 0);
        if ($used >= self::DAILY_LIMIT) {
            return ['status' => 'limit', 'reason' => 'That\'s today\'s '.self::DAILY_LIMIT.' questions. More tomorrow.', 'left' => 0];
        }

        $result = $this->ask($user, $question, $context);
        if ($result === null) {
            return ['status' => 'failed', 'reason' => 'The search didn\'t answer. Try again in a minute.'];
        }
        Cache::put($countKey, $used + 1, now()->endOfDay());
        Cache::put($key, $result, now()->addDays(self::KEEP_DAYS));

        return $result + ['cached' => false, 'left' => self::DAILY_LIMIT - $used - 1];
    }

    private function left(User $user): int
    {
        return max(0, self::DAILY_LIMIT - (int) Cache::get('forever-ask-count:'.$user->id.':'.now()->toDateString(), 0));
    }

    /** One Gemini call with Google Search; null when it fails or returns nothing readable. */
    private function ask(User $user, string $question, string $context): ?array
    {
        $prompt = "Question: {$question}".($context !== '' ? "\n\n{$context}" : '');
        $start = microtime(true);
        $response = Http::timeout(45)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.':generateContent?key='.config('services.gemini.key'),
            [
                'systemInstruction' => ['parts' => [['text' => self::SYSTEM]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                // Search grounding can't be combined with a JSON response type, so the JSON is asked for in words.
                'tools' => [['google_search' => new \stdClass]],
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 1200],
            ]
        );
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($response->failed()) {
            Log::warning('Forever ask: Gemini failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            return null;
        }

        $json = $response->json();
        $candidate = $json['candidates'][0] ?? [];
        $text = collect($candidate['content']['parts'] ?? [])->pluck('text')->filter()->implode('');
        $parsed = self::parse($text);

        $in = (int) ($json['usageMetadata']['promptTokenCount'] ?? 0);
        $out = (int) ($json['usageMetadata']['candidatesTokenCount'] ?? 0) + (int) ($json['usageMetadata']['thoughtsTokenCount'] ?? 0);
        $usage = $this->tokens->calculateCreditCost(self::MODEL, $in, $out);
        AiRequest::create([
            'user_id' => $user->id,
            'purpose' => 'forever_ask',
            'prompt' => $prompt,
            'template_prompt' => '',
            'response' => $text,
            'duration_ms' => $ms,
            'metadata' => [
                'model' => self::MODEL,
                'input_tokens' => $in,
                'output_tokens' => $out,
                'cost_usd' => $usage['cost']['total_usd'] ?? null,
                'searches' => $candidate['groundingMetadata']['webSearchQueries'] ?? [],
            ],
        ]);

        if ($parsed === null) {
            Log::warning('Forever ask: no JSON in the answer', ['text' => mb_substr($text, 0, 500)]);

            return null;
        }

        $sources = collect($candidate['groundingMetadata']['groundingChunks'] ?? [])
            ->map(fn ($c) => $c['web']['title'] ?? null)->filter()->unique()->take(5)->values()->all();

        return ['status' => 'ok'] + $parsed + ['sources' => $sources];
    }

    /**
     * The answer and its points from the model's text: the JSON object in it, points kept only with a
     * zone and both coordinates inside 0-100. Null when there's no answer to give.
     *
     * @return array{answer: string, points: array}|null
     */
    public static function parse(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($data) || ! is_string($data['answer'] ?? null) || trim($data['answer']) === '') {
            return null;
        }

        $points = [];
        foreach (is_array($data['points'] ?? null) ? $data['points'] : [] as $p) {
            $zone = is_array($p) && is_string($p['zone'] ?? null) ? trim($p['zone']) : '';
            $x = $p['x'] ?? null;
            $y = $p['y'] ?? null;
            if ($zone === '' || mb_strlen($zone) > 48 || ! is_numeric($x) || ! is_numeric($y)) {
                continue;
            }
            if ($x < 0 || $x > 100 || $y < 0 || $y > 100) {
                continue;
            }
            $points[] = ['zone' => $zone, 'x' => round((float) $x, 1), 'y' => round((float) $y, 1),
                'label' => is_string($p['label'] ?? null) ? mb_substr(trim($p['label']), 0, 60) : ''];
        }

        return ['answer' => mb_substr(trim(strip_tags($data['answer'])), 0, 400), 'points' => array_slice($points, 0, self::MAX_POINTS)];
    }
}
