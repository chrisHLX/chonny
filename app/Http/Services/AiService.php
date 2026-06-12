<?php

namespace App\Http\Services;

use App\Models\AiRequest;
use App\Models\Concept;
use App\Models\Module;
use App\Models\Question;
use App\Models\ModulePage;
use App\Models\Subject;
use App\Models\Proficiency;
use App\Models\User;
use App\Models\Tag;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log; 

use App\Http\Services\HtmlFormatter;
use App\Http\Services\VersioningService;
use App\Http\Services\CreditService;

use App\Jobs\GenerateQuestions;
use Illuminate\Support\Facades\Http;


class AiService
{
    protected HtmlFormatter $formatter;
    protected CreditService $creditService;
    protected Client $client;
    protected string $apiKey;
    protected TokenService $tokenService;

    public function __construct(Client $client, HtmlFormatter $formatter, CreditService $creditService, TokenService $tokenService)
    {
        $this->client = $client;
        $this->formatter = $formatter;
        $this->apiKey = config('services.openai.key');
        $this->creditService = $creditService;
        $this->tokenService = $tokenService;
    }


    // Could be Unlock Module
    public function createModule()
    {
        // Placeholder for module creation logic

        
        $module = Module::create([
            'name' => 'Basic Medical Module',
            'description' => 'A module covering basic medical concepts.',
            'created_by' => auth()->id(),
            'subject_id' => 3,
        ]);
        $subjectID = $module->subject_id;
        \Log::info('Subject ID {$subjectID}');
        $proficiencyId = Proficiency::where('subject_id', $subjectID)->orderBy('id', 'asc')->first();
        \Log::info('Proficiency ID {$proficiencyId}');
        $module->proficiencies()->attach($proficiencyId);
    
        
        $this->testContent($module);

    }

    public function sendPromptToAi(string $prompt, string $model, int $userID, string $description = 'undefined'): array
    {
        $response = $this->callOpenAi($prompt, $model, $userID, $description);
        return $response;
    }

    public function answerQuestion(string $question, string $context, int $userId): string
    {
        $prompt = "Using ONLY the following content as context, answer the user's question concisely and clearly. If the answer cannot be determined from the provided content, say so.\n\nCONTENT:\n{$context}\n\nQUESTION:\n{$question}";
        return $this->callOpenAiString($prompt, $userId, 'content_qa');
    }

    private function estimateTokens(string $text): int
    {
        // Rough estimate: ~4 chars per token (English text)
        return (int) ceil(strlen($text) / 4);
    }


    /* --------------------------------------------------------- OPENAI CALLS & HELPERS --------------------------------------------------------- */
    private function callOpenAi(string $prompt, $model = 'gpt-4o-mini', int $userID, string $description = 'undefined'): array
    {
        Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);
        \Log::info("Using Model {$model}");

        $inputTokens = $this->estimateTokens($prompt);

        $start = microtime(true);
        $response = Http::withToken(config('services.openai.key'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $json = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? '{}';

        $content = trim($content);
        $content = preg_replace('/^```json|```$/i', '', $content);
        $content = trim($content);

        Log::debug('Cleaned OpenAI content', ['content' => $content]);

        $outputTokens = $this->estimateTokens($content);

        $usage = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);

        $this->creditService->spendAiCredits($userID, $usage['credits']['charged'], $description);

        \Log::info('AI token usage', [
            'model'           => $usage['model'],
            'input_tokens'    => $usage['input_tokens'],
            'output_tokens'   => $usage['output_tokens'],
            'cost_usd'        => $usage['cost']['total_usd'],
            'input_usd'       => $usage['cost']['input_usd'],
            'output_usd'      => $usage['cost']['output_usd'],
            'credits_charged' => $usage['credits']['charged'],
            'credits_raw'     => $usage['credits']['raw'],
        ]);

        AiRequest::create([
            'user_id'         => $userID,
            'purpose'         => $description,
            'prompt'          => $prompt,
            'template_prompt' => '',
            'response'        => $content,
            'duration_ms'     => $durationMs,
            'metadata'        => [
                'model'           => $model,
                'input_tokens'    => $inputTokens,
                'output_tokens'   => $outputTokens,
                'cost_usd'        => $usage['cost']['total_usd'],
                'input_usd'       => $usage['cost']['input_usd'],
                'output_usd'      => $usage['cost']['output_usd'],
                'credits_charged' => $usage['credits']['charged'],
            ],
        ]);

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function callOpenAiString(string $prompt, $userID, string $description = 'undefined'): string
    {
        \Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);

        $model       = 'gpt-4o-mini';
        $inputTokens = $this->estimateTokens($prompt);

        $start = microtime(true);
        $response = Http::withToken(config('services.openai.key'))->post('https://api.openai.com/v1/chat/completions', [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $json    = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? '';

        $content = trim(preg_replace('/^```json|```$/i', '', $content));

        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['content'])) {
            $content = $decoded['content'];
        }

        $outputTokens = $this->estimateTokens($content);

        $usage = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);

        $this->creditService->spendAiCredits($userID, $usage['credits']['charged'], $description);

        \Log::info('AI token usage', [
            'model'           => $usage['model'],
            'input_tokens'    => $usage['input_tokens'],
            'output_tokens'   => $usage['output_tokens'],
            'cost_usd'        => $usage['cost']['total_usd'],
            'input_usd'       => $usage['cost']['input_usd'],
            'output_usd'      => $usage['cost']['output_usd'],
            'credits_charged' => $usage['credits']['charged'],
            'credits_raw'     => $usage['credits']['raw'],
        ]);

        $content = trim($content);

        \Log::debug('Final OpenAI explanation', ['content' => $content]);

        AiRequest::create([
            'user_id'         => $userID,
            'purpose'         => $description,
            'prompt'          => $prompt,
            'template_prompt' => '',
            'response'        => $content,
            'duration_ms'     => $durationMs,
            'metadata'        => [
                'model'           => $model,
                'input_tokens'    => $inputTokens,
                'output_tokens'   => $outputTokens,
                'cost_usd'        => $usage['cost']['total_usd'],
                'input_usd'       => $usage['cost']['input_usd'],
                'output_usd'      => $usage['cost']['output_usd'],
                'credits_charged' => $usage['credits']['charged'],
            ],
        ]);

        return $content;
    }

    private function callOpenAiHTML(string $prompt, int $userID = 0, string $description = 'generate_html'): string
    {
        Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);

        $model       = 'gpt-4.1-nano';
        $inputTokens = $this->estimateTokens($prompt);

        $start = microtime(true);
        $response = Http::withToken(config('services.openai.key'))->post('https://api.openai.com/v1/chat/completions', [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $json    = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? '';

        $content = trim($content);
        $content = preg_replace('/^```html|```$/i', '', $content);
        $content = trim($content);

        Log::debug('Cleaned OpenAI content', ['content' => $content]);

        $outputTokens = $this->estimateTokens($content);
        $usage        = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);

        $resolvedUserId = $userID ?: (auth()->id() ?? 0);
        $this->creditService->spendAiCredits($resolvedUserId, $usage['credits']['charged'], $description);

        AiRequest::create([
            'user_id'         => $resolvedUserId,
            'purpose'         => $description,
            'prompt'          => $prompt,
            'template_prompt' => '',
            'response'        => $content,
            'duration_ms'     => $durationMs,
            'metadata'        => [
                'model'           => $model,
                'input_tokens'    => $inputTokens,
                'output_tokens'   => $outputTokens,
                'cost_usd'        => $usage['cost']['total_usd'],
                'input_usd'       => $usage['cost']['input_usd'],
                'output_usd'      => $usage['cost']['output_usd'],
                'credits_charged' => $usage['credits']['charged'],
            ],
        ]);

        return $content;
    }

    private function callOpenAiRaw(
        string $prompt,
        int    $userID,
        string $description   = 'undefined',
        string $model         = 'gpt-4o-mini',
        string $systemPrompt  = 'You are a helpful assistant.',
        float  $temperature   = 0.2
    ): string {
        $inputTokens = $this->estimateTokens($prompt);

        $start = microtime(true);
        $response = Http::withToken(config('services.openai.key'))->post('https://api.openai.com/v1/chat/completions', [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
        ]);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $json    = $response->json();
        $content = trim($json['choices'][0]['message']['content'] ?? '');

        $outputTokens = $this->estimateTokens($content);
        $usage        = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);

        $this->creditService->spendAiCredits($userID, $usage['credits']['charged'], $description);

        AiRequest::create([
            'user_id'         => $userID,
            'purpose'         => $description,
            'prompt'          => $prompt,
            'template_prompt' => '',
            'response'        => $content,
            'duration_ms'     => $durationMs,
            'metadata'        => [
                'model'           => $model,
                'input_tokens'    => $inputTokens,
                'output_tokens'   => $outputTokens,
                'cost_usd'        => $usage['cost']['total_usd'],
                'input_usd'       => $usage['cost']['input_usd'],
                'output_usd'      => $usage['cost']['output_usd'],
                'credits_charged' => $usage['credits']['charged'],
            ],
        ]);

        return $content;
    }

    /* --------------------------------------------------------- GEMINI CALLS & HELPERS --------------------------------------------------------- */
    private function callGemini(string $prompt, string $model = 'gemini-2.5-flash', int $userID = 0, string $description = 'undefined'): array
    {
        Log::debug('Prompt sent to Gemini', ['prompt' => $prompt]);
        Log::info("Using Gemini model {$model}");

        $key      = config('services.gemini.key');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $inputTokens = $this->estimateTokens($prompt);

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => 'You are a helpful assistant.']],
            ],
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
            ],
        ];

        $start = microtime(true);
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->timeout(30)
            ->post("{$endpoint}?key={$key}", $payload);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($response->failed()) {
            Log::error('Gemini API error in callGemini', ['status' => $response->status(), 'body' => $response->body()]);
            return [];
        }

        $json    = $response->json();
        $content = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '{}');

        Log::debug('Gemini JSON response', ['content' => $content]);

        $outputTokens = $this->estimateTokens($content);
        $usage        = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);

        $this->creditService->spendAiCredits($userID, $usage['credits']['charged'], $description);

        Log::info('Gemini token usage', [
            'model'           => $usage['model'],
            'input_tokens'    => $usage['input_tokens'],
            'output_tokens'   => $usage['output_tokens'],
            'cost_usd'        => $usage['cost']['total_usd'],
            'input_usd'       => $usage['cost']['input_usd'],
            'output_usd'      => $usage['cost']['output_usd'],
            'credits_charged' => $usage['credits']['charged'],
            'credits_raw'     => $usage['credits']['raw'],
        ]);

        AiRequest::create([
            'user_id'         => $userID,
            'purpose'         => $description,
            'prompt'          => $prompt,
            'template_prompt' => '',
            'response'        => $content,
            'duration_ms'     => $durationMs,
            'metadata'        => [
                'model'           => $model,
                'input_tokens'    => $inputTokens,
                'output_tokens'   => $outputTokens,
                'cost_usd'        => $usage['cost']['total_usd'],
                'input_usd'       => $usage['cost']['input_usd'],
                'output_usd'      => $usage['cost']['output_usd'],
                'credits_charged' => $usage['credits']['charged'],
            ],
        ]);

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function callGeminiString(string $prompt, int $userID = 0, string $description = 'undefined', string $model = 'gemini-2.5-flash'): string
    {
        Log::debug('Prompt sent to Gemini (string)', ['prompt' => $prompt]);
        Log::info("Using Gemini model {$model}");

        $key      = config('services.gemini.key');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $inputTokens = $this->estimateTokens($prompt);

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => 'You are a helpful assistant.']],
            ],
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];

        $start = microtime(true);
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->timeout(30)
            ->post("{$endpoint}?key={$key}", $payload);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($response->failed()) {
            Log::error('Gemini API error in callGeminiString', ['status' => $response->status(), 'body' => $response->body()]);
            return '';
        }

        $json    = $response->json();
        $content = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');

        Log::debug('Gemini string response', ['content' => $content]);

        $outputTokens = $this->estimateTokens($content);
        $usage        = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);

        $this->creditService->spendAiCredits($userID, $usage['credits']['charged'], $description);

        Log::info('Gemini token usage', [
            'model'           => $usage['model'],
            'input_tokens'    => $usage['input_tokens'],
            'output_tokens'   => $usage['output_tokens'],
            'cost_usd'        => $usage['cost']['total_usd'],
            'input_usd'       => $usage['cost']['input_usd'],
            'output_usd'      => $usage['cost']['output_usd'],
            'credits_charged' => $usage['credits']['charged'],
            'credits_raw'     => $usage['credits']['raw'],
        ]);

        AiRequest::create([
            'user_id'         => $userID,
            'purpose'         => $description,
            'prompt'          => $prompt,
            'template_prompt' => '',
            'response'        => $content,
            'duration_ms'     => $durationMs,
            'metadata'        => [
                'model'           => $model,
                'input_tokens'    => $inputTokens,
                'output_tokens'   => $outputTokens,
                'cost_usd'        => $usage['cost']['total_usd'],
                'input_usd'       => $usage['cost']['input_usd'],
                'output_usd'      => $usage['cost']['output_usd'],
                'credits_charged' => $usage['credits']['charged'],
            ],
        ]);

        return $content;
    }

    // Format the answer based on its type for better readability in prompts important for generating questions we send the correct answer to ai
    // Must return a string?
    private function formatAnswer($q): string
    {
        // Normalize: if we got a model, turn it into array shape
        if ($q instanceof \App\Models\Question) {
            $q = [
                'question' => $q->question,
                'type'     => $q->type,
                'answer'   => $q->answer,
            ];
        }

        $type   = $q['type']   ?? null;
        $answer = $q['answer'] ?? null;

        // If $answer is a JSON string, try to decode it
        if (is_string($answer)) {
            $decoded = json_decode($answer, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $answer = $decoded;
            }
        }

        switch ($type) {
            case 'ordering': {
                $steps = $answer['steps'] ?? array_values((array) $answer);
                return $steps ? implode(' → ', $steps) : 'N/A';
            }

            case 'matching_pairs': {
                $pairs = [];
                $map = $answer['correct'] ?? $answer['pairs'] ?? null;
                if (is_array($map)) {
                    foreach ($map as $k => $v) {
                        $pairs[] = "{$k} = {$v}";
                    }
                }
                return $pairs ? implode(', ', $pairs) : 'N/A';
            }

            case 'open': {
                if (!empty($answer['ideal_answer'])) {
                    return $answer['ideal_answer'];
                }
                if (!empty($answer['correct_keywords'])) {
                    return 'Keywords: ' . implode(', ', (array)$answer['correct_keywords']);
                }
                return 'N/A';
            }

            case 'true_false': {
                if (is_array($answer) && array_key_exists('correct', $answer)) {
                    return $answer['correct'] ? 'True' : 'False';
                }
                if (is_bool($answer)) {
                    return $answer ? 'True' : 'False';
                }
                return 'N/A';
            }

            case 'mcq':
            case 'multiple_choice': {
                return $answer['correct'] ?? 'N/A';
            }

            default: {
                if (is_array($answer)) {
                    return json_encode($answer);
                }
                if ($answer === null || $answer === '') {
                    return 'N/A';
                }
                return (string) $answer;
            }
        }
    }


    /* --------------------------------------------------------- Question Controller --------------------------------------------------------- */

    // This is used in the question controller when creating new questions
    public function tagConcepts(string $questionText, string $answerText = '', $module_id, $conceptMap): array
    {
        $conceptMap = json_encode($conceptMap);

        $prompt = <<<EOT
        You are an expert educator. Analyze the following question and answer. Select 1–3 concepts from the list that best apply.

        Return only raw JSON. Do not include markdown or formatting. Just return: {"concepts": [...]}

        Concepts: {$conceptMap}
        Question: {$questionText}
        Answer: {$answerText}
        EOT;


        $response = $this->callOpenAi($prompt);
        $concepts = $response['concepts'] ?? [];

        \Log::info('This is the custom LOG', ['response' => $concepts]);

        return $concepts;

    }

    public function generateTags(int $moduleId): array
    {
        $module = Module::findOrFail($moduleId);

        // Get existing tags for the subject
        $existingTags = Tag::where('subject_id', $module->subject->id)->get();
        $existingTagsArray = $existingTags->map(fn($tag) => "{$tag->name} ({$tag->type})")->toArray();
        $existingTagsString = implode(', ', $existingTagsArray);

        // Build the prompt
        $prompt = <<<EOT
    I need you to pick 2–5 descriptive tags for a learning module based on the content provided. 
    The tags will be used for filtering and discovery.

    Module to have tags attached
    Subject: {$module->subject->name}
    Module title: {$module->name}
    Description: {$module->description}

    Return 2–5 tags only from the list of available tags below.

    Tags: name (type): {$existingTagsString}

    IMPORTANT Format: [{"name":"example","type":"identity"}]

    NOTE: IMPORTANT Return only in the above JSON array format. Do not include any explanatory text or markdown formatting.
    EOT;
                
        // Send to AI
        $response = $this->callOpenAi($prompt, 'gpt-4.1-mini', 2, 'generate_tags');

        return $response ?? [];
    }

    // Used in the question controller in the store function for saving questions, automatically generating keywords for open questions
    public function getKeywords($input)
    {
        $prompt = "Extract the most important keywords from the following text that I can then compare against a users answer.
            Respond ONLY with a JSON array of strings.
            Text: \"$input\"";

        $response = $this->callOpenAi($prompt, 'gpt-4o-mini', auth()->id() ?? 0, 'get_keywords');

        return is_array($response) ? $response : [];
    }

    public function generateContentForQuestion(Question $question, $moduleInfo, $userID, string $skillType = ''): string
    {
        $correct = $this->formatAnswer($question);

        $skillTypeInstruction = $skillType
            ? "\nThinking mode: {$skillType}.\nrecall = reinforce the fact clearly; analysis = explain how to interpret the situation; application = demonstrate the decision and why it is correct."
            : '';

        $prompt = <<<EOT
        The user has answered this question incorrectly multiple times.

        Write a short explanation (max 120 words) that:

        - Corrects the likely misconception.
        - Explains why the correct answer fits the concept.
        - If contrasting with other options, avoid making specific factual claims unless they are directly implied by the Question or Correct Answer.
        - Do not introduce additional domain facts that are not explicitly given.
        - End naturally with the correct answer.

        Be concise. No fluff. No formatting.
        Use simple language appropriate for the given proficiency level.
        Include one short memorable mental hook.
        Return only plain text.{$skillTypeInstruction}

        Context:
        - Module: $moduleInfo
        - Question: $question->question
        - Correct Answer: $correct

        EOT;
        
        \Log::info('Generating content for question with prompt', ['prompt' => $prompt]);

        $aiDescription = 'AI explanation generation';

        $explanationString = $this->callGeminiString($prompt, $userID, $aiDescription);

        \Log::info('Generated explanation $explanationString["content"]');

        return $explanationString;
    }
    

    /* --------------------------------------------------------- Module Controller --------------------------------------------------------- */

    // Used in the module controller when creating landing pages
    public function createLandingPage(Module $module)
    {

    $moduleName = $module->name;
    $moduleCat = $module->subject->name;
    $proficiency = $module->proficiencies()->first()->description;
    $profName = $module->proficiencies()->first()->name;
    $prompt = <<<EOT
    Create a beginner learning module page for the module $moduleName
    Under the Category: $moduleCat

    Audience:

    * Proficiency: $profName
    * $proficiency

    Purpose:

    * Explain the essential foundational ideas clearly
    * Build conceptual understanding before advanced strategy

    Requirements:

    * Use clear headings
    * Explain concepts in simple language
    * Focus only on beginner fundamentals
    * Avoid advanced competitive nuance unless briefly mentioned
    * Include practical examples where useful
    * Keep the content readable and instructional

    Output format:
    Return JSON with:
    {
    "title": "",
    "summary": "",
    "sections": [
    {
    "heading": "",
    "content": ""
    }
    ]
    }

    EOT;

        $response = $this->callOpenAiHTML($prompt, auth()->id() ?? 0, 'generate_landing_page');

        $formattedResponse = $this->formatter->format($response);

        ModulePage::create([
            'module_id'   => $module->id,
            'title'       => $module->name,
            'content'     => $formattedResponse,
            'page_number' => 1, // landing page
            'created_by'  => auth()->id(),
            'updated_by'  => auth()->id(),
        ]);

        return $response;
    }

    public function generateModuleContent(Module $module, int $userID, string $focusContext = ''): string
    {
        $prof     = $module->proficiencies()->first();
        $subject  = $module->subject;
        $category = $subject->category;

        $axes = $category->axes()->with('concepts')->get();
        $axisBlock = $axes->map(fn ($axis) =>
            "- {$axis->name}: " . $axis->concepts->where('subject_id', $subject->id)->pluck('name')->implode(', ')
        )->implode("\n");

        $conceptList = $subject->concepts()->pluck('name')->implode(', ');

        $profIndex = $prof?->index ?? 0;
        if ($profIndex < 2) {
            $depthGuide = "beginner — introduce terms plainly, use analogies, avoid jargon. Target 300–450 words.";
        } elseif ($profIndex < 4) {
            $depthGuide = "intermediate — assume basic familiarity, explain how concepts interact and why decisions matter. Target 400–500 words.";
        } else {
            $depthGuide = "advanced — assume solid fundamentals, explore nuance, trade-offs, and expert decision-making. Target 450–500 words.";
        }

        $profName        = $prof?->name ?? 'General';
        $profDescription = $prof?->description ?? '';

        $focusBlock = $focusContext
            ? "\nLEARNER CONTEXT (why this module was recommended — weight your content toward these gaps):\n{$focusContext}\n"
            : '';

        $prompt = <<<EOT
        You are writing educational module content for a structured learning platform.

        MODULE: {$module->name}
        DESCRIPTION: {$module->description}
        SUBJECT: {$subject->name} (Category: {$category->name})
        PROFICIENCY: {$profName} — {$profDescription}
        {$focusBlock}
        SKILL DIMENSIONS (axes that define mastery in this category — write content that develops reasoning across these):
        {$axisBlock}

        CONCEPTS IN THIS SUBJECT (questions will test these — cover the ones most relevant to this module):
        {$conceptList}

        WRITING INSTRUCTIONS:
        - Depth: {$depthGuide}
        - Use Markdown: ## for main sections, ### for sub-sections, bullet points for lists
        - Open with one short paragraph framing why this topic matters
        - Each section should develop understanding across at least one skill dimension above
        - Include at least one concrete example or scenario that shows the concept applied, not just defined
        - Close with a ## Key Takeaways section containing 3–5 bullet points
        - Maximum 500 words total. Be concise and prioritise the most important information.
        - Return only the Markdown content. No JSON wrapper, no preamble, no meta-commentary.
        EOT;

        $content = $this->callGeminiString($prompt, $userID, 'generate_module_content');

        return $content;
    }

    public function synthesiseContent(Module $module, string $researchContent, string $userPrompt, int $userID): string
    {
        $prof            = $module->proficiencies()->first();
        $profName        = $prof?->name ?? 'General';
        $profDescription = $prof?->description ?? '';
        $subject         = $module->subject;

        $effectivePrompt = trim($userPrompt) !== ''
            ? $userPrompt
            : 'Create a clear, structured educational guide suitable for quiz question generation.';

        $prompt = <<<EOT
        You are creating educational content for a learning module.

        MODULE: {$module->name}
        SUBJECT: {$subject->name}
        PROFICIENCY: {$profName} — {$profDescription}

        SOURCE RESEARCH (use this as your factual basis):
        {$researchContent}

        USER REQUEST:
        {$effectivePrompt}

        Write structured Markdown content. Maximum 500 words.
        Use ## for sections, bullet points for lists.
        Close with a ## Key Takeaways section with 3–5 points.
        Return only the Markdown. No preamble.
        EOT;

        return $this->callOpenAiString($prompt, $userID, 'synthesise_content');
    }

    /* --------------------------------------------------------- MODULE GENERATION --------------------------------------------------------- */

    // TODO: dead code — review before removing
    public function generateNewModule($moduleID, array $IDs)
    {
        \Log::info("new mod generated");
        $module = Module::find($moduleID);
        $module = $this->followUpQuestions($module, $IDs);
        return $module;
    }

    // TODO: dead code — review before removing
    public function generateHarderModule($moduleID, array $IDs)
    {
        \Log::info("harder mod generated");
    }

    // TODO: dead code — review before removing (hardcoded StarCraft 2 system prompt, HTTP call marked cancelled)
    private function followUpQuestions(Module $module, array $questionIds)
    {
        // Moved the variables up the top.
        $user = auth()->user();
        // Filter out questions that were answered correctly
        $questionList = "";
        $ogModule = $module;
        // Get the latest version number of this parent’s children
        $parentVersion = $ogModule->version;
        $latestChildVersion = $ogModule->version;
        // Lets change the name of the module

        // If no children exist yet, start at 2 (since v1 is the parent itself) need a latest child version
        $newVersion = VersioningService::next($parentVersion, $latestChildVersion);

        $questions = Question::whereIn('id', $questionIds)->get()->map(fn($q) => [
            'question' => $q->question,
            'type'     => $q->type,
            'answer'   => $q->answer,
        ])->toArray();

        foreach ($questions as $index => $q) {
            $formattedAnswer = $this->formatAnswer($q); // We are fomatting the answer to handle different types like ordering, matching pairs (arrays not strings)
            \logger()->info('Formatted answer for question', ['formattedAnswer' => $formattedAnswer]);
            $questionList .= ($index + 1) . ". " . $q['question'] . " — " . $formattedAnswer . "\n";
        }

        \logger()->info('Compiled question list for AI prompt', ['questionList' => $questionList]);

        // Create the prompt
        $prompt = "The user struggles to answer the following questions correctly:\n" .
                $questionList .
                "\nProvide a short summary that will help them understand the concepts better (try word it differently and).
                
                Provide the response in this format:
                Summary: [Your summary here]
                
                ";

        // Call OpenAI API (CANCELLED AT THE MOMENT)
        
        $response = Http::withToken(config('services.openai.key'))->post(
            'https://api.openai.com/v1/chat/completions',
            [
                'model' => 'gpt-4.1-nano',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are helpful starcraft 2 coach.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
            ]
        );

        // Parse and return
        $data = $response->json();

        $content = $data['choices'][0]['message']['content'] ?? '';

        // Now send the newly created content back to ai to generate questions
        $questions = $this->generateQuestions($content, $questionList); 

        AiRequest::create([
                'user_id' => auth()->id(),
                'purpose' => 'follow_up_questions',
                'prompt' => $prompt,
                'response' => $content,
                'metadata' => [
                    'module_id' => "N/A",
                    'model' => 'gpt-4o-mini',
                ],
            ]);

        $module = Module::create([
            'name'          => $ogModule->name . now()->toDateTimeString(),
            'description'   => "AI generated module to help user with problematic questions:" . $questionList,
            'version'       => $newVersion,
            'parent_id' => $ogModule->id,
            'created_by'    => auth()->id(),
        ]);

        $formattedContent = $this->formatter->format($content);

        $module->modulePages()->create([
            'title'       => $module->name,
            'content'     => $formattedContent,
            'page_number' => 1,
            'created_by'  => auth()->id(),
            'updated_by'  => auth()->id(),
        ]);

        $user->modules()->attach($module->id, [
            'status' => 'in_progress',
            'score' => 0,
            'last_activity_at' => now(),
            'completed_at' => null
        ]);

        foreach ($questions as $q) {    
            $q->modules()->attach($module->id); // attach to the new module
        }

        \logger()->info('Generated follow-up questions', ['questions' => $questions]);

        return $module;
    }

    //The content will be with html tag, we should strip them out before adding to the prompt
    public function generateQuestions(string $type, string $content, $newModule, int $userID, ?string $difficultyFocus = null, bool $enforceAllSkillTypes = false, ?string $modelOverride = null)
    {
        // Fetch all concepts as [ 'name' => id ]
        $conceptMap = Concept::where('subject_id', $newModule->subject_id)->pluck('id', 'name');
        $questionsList = $newModule->questions()->pluck('question')->toArray();
        $questionsList = $newModule->questions()->pluck('question')->implode("\n- ");
        $prof = $newModule->proficiencies()->first();

        // Build axis context string for the prompt
        $axes = $newModule->subject->category->axes()->with('concepts')->get();
        $axisString = $axes->map(fn($axis) =>
            $axis->name . ': ' . $axis->concepts->where('subject_id', $newModule->subject_id)->pluck('name')->implode(', ')
        )->implode(' | ');

        // Complex-type guard: gpt-4o-mini cannot handle ordering/matching_pairs reliably
        $complexType = ($type === 'ordering' || $type === 'matching_pairs');

        if ($modelOverride !== null) {
            $model = ($modelOverride === 'gpt-4o-mini' && $complexType)
                ? 'gpt-4.1-mini'  // silent upgrade — guard always wins
                : $modelOverride;
        } else {
            $model = $complexType ? 'gpt-4.1-mini' : 'gpt-4o-mini';
        }
        
        
        // Define example JSON structures for each type
        $examples = [
            'mcq' => '[
                {
                    "question": "Which organ in the human body is primarily responsible for filtering blood?",
                    "type": "mcq",
                    "skill_type": "recall",
                    "answer": {
                        "correct": "Kidney",
                        "options": ["Kidney", "Liver", "Spleen", "Pancreas"]
                    },
                    "difficulty": "easy",
                    "concepts": ["Human Biology"]
                }
            ]',
            'true_false' => '[
                {
                    "question": "True or False: The mitochondria is responsible for producing ATP through cellular respiration.",
                    "type": "true_false",
                    "skill_type": "recall",
                    "answer": { "correct": true },
                    "difficulty": "easy",
                    "concepts": ["Cell Biology"]
                }
            ]',
            'matching_pairs' => '[
                {
                    "question": "Match each country to its capital city.",
                    "type": "matching_pairs",
                    "skill_type": "recall",
                    "answer": {
                        "correct": {
                            "France": "Paris",
                            "Japan": "Tokyo",
                            "Brazil": "Brasília",
                            "Egypt": "Cairo"
                        },
                        "pairs": {
                            "keys": ["France", "Japan", "Brazil", "Egypt"],
                            "values": ["Cairo", "Brasília", "Paris", "Tokyo"]
                        }
                    },
                    "difficulty": "easy",
                    "concepts": ["World Geography"]
                }
            ]',
            'ordering' => '[
                {
                    "question": "Put the steps of the scientific method in the correct order.",
                    "type": "ordering",
                    "skill_type": "application",
                    "answer": {
                        "steps": ["Observe a phenomenon", "Form a hypothesis", "Design an experiment", "Collect data", "Draw conclusions"]
                    },
                    "difficulty": "easy",
                    "concepts": ["Research Methods"]
                }
            ]',
        ];

        // Ensure valid type
        if (!isset($examples[$type])) {
            \Log::warning("Invalid question type provided: {$type}");
            return [];
        }

        // Build the AI prompt (use HEREDOC for clarity)
        $usableConcepts = $conceptMap->keys()->implode(', ');
        $exampleJson = $examples[$type];
        
        $questionAmount = 5;
        $requirements = " 2 easy, 2 medium, and 1 hard question.";

        if ($prof->index < 2 && ($type == 'ordering' || $type == 'matching_pairs')) {
            $questionAmount = 2;
            $requirements = " 1 medium, 1 hard";
        } else if ($prof->index >= 2 && $prof->index < 4) {
            $questionAmount = 5;
            $requirements = " 2 easy, 2 medium, and 1 hard question.";
        } else if ($prof->index >= 4) {
            $questionAmount = 7;
            $requirements = " 2 easy, 2 medium, and 3 hard question.";
        }

        // Override difficulty mix when explicitly requested by the content creator
        if ($difficultyFocus) {
            $requirements = " all {$questionAmount} at {$difficultyFocus} difficulty.";
        }

        $skillTypeCoverageBlock = $enforceAllSkillTypes
            ? "\n    - MANDATORY: Include at least 1 recall, 1 analysis, and 1 application question across the set."
            : '';


    $axisBlock = $axisString
    ? "SKILL MEASUREMENT DIMENSIONS (do NOT use these as concept tags — they are measurement dimensions only, not content labels):\n    {$axisString}\n    Aim to spread questions across these dimensions where the content allows, but always tag questions using CONCEPTS from the list below, never axis names.\n"
    : '';

    $typeConstraints = match ($type) {
        'matching_pairs' => <<<'BLOCK'

    MATCHING PAIRS DESIGN RULES:
    - CONCRETE TRIGGER PRINCIPLE: Every Premise must reference a specific, actionable game state, scenario, data point, or error condition — never a high-level concept name.
    - EXCLUSIVE 1-TO-1 MAPPING: Each Premise must have exactly ONE logically correct Match. A knowledgeable user should be able to match them with confidence, not guess between two plausible options.
    - NO ABSTRACT PLATITUDES: Do NOT pair a high-level concept with a high-level goal (e.g., "Good positioning" -> "Helps you win"). Instead pair a specific trigger with its direct tactical consequence.
    - Example of BAD pair: "Target switching" -> "Kills enemies efficiently"
    - Example of GOOD pair: "Enemy healer at 30% HP with no defensive cooldowns" -> "Immediately switch all damage to the healer"
    BLOCK,

        'ordering' => <<<'BLOCK'

    ORDERING DESIGN RULES:
    - TEMPORAL DEPENDENCY: Only generate an ordering question if the items represent a strict, unarguable chronological or priority sequence. If the order could reasonably be debated, pick a different scenario.
    - CONCRETE STEPS: Each step must describe a specific, observable action — not a vague phase label (e.g., NOT "Preparation phase" — YES "Activate defensive cooldown before the enemy burst window").
    - NO ABSTRACT PLATITUDES: Steps must be distinct enough that swapping any two would produce a clearly wrong outcome.
    BLOCK,

        default => '',
    };

    $prompt = <<<PROMPT
    You are an elite, performance-driven diagnostic coach, NOT a high school textbook author. Your goal is to test active recall and operational mastery of the material — not vocabulary definitions.

    CRITICAL DESIGN RULES (apply to every question you generate):
    1. NO ABSTRACT PLATITUDES: Never create questions where both the prompt and answer are high-level concept labels. Ground every question in a specific, observable state or action.
    2. CONCRETE TRIGGER PRINCIPLE: Question stems should reference specific scenarios, data points, error conditions, or decision moments drawn directly from the content below.
    3. EXCLUSIVE ANSWERS: Every correct answer must be the only defensible correct answer. Distractors must be plausible but clearly wrong to someone who has mastered the material.
    4. TEST UNDERSTANDING, NOT GLOSSARY LOOKUP: A question that can be answered correctly by someone who has never studied the content is a bad question.
    {$typeConstraints}
    Generate {$questionAmount} {$type} questions based on the following content.

    CONTENT:
    {$content}

    PROFICIENCY LEVEL: {$prof->name}.
    {$prof->name} Level Description: {$prof->description}.

    IMPORTANT NOTE: Proficiency represents the user's reading level (vocabulary) and prior knowledge. Use this to tailor question complexity and readability.

    {$axisBlock}
    Existing Questions for this module (try not to repeat the same ones):
    - {$questionsList}

    REQUIREMENTS:
    - {$requirements}
    - IMPORTANT Return JSON ONLY in this format Explicitly:
    {$exampleJson}
    - IMPORTANT: Tag each question using ONLY concepts from this list. Do not invent new concept names. Do not use axis names as concepts. Only use: {$usableConcepts}
    - Each question must include a skill_type field. Assign the most appropriate:
      recall = tests memory and recognition of facts
      analysis = tests interpretation of a situation or information
      application = tests decision making and use of knowledge in context{$skillTypeCoverageBlock}
    - Ensure JSON is valid, without markdown or commentary.
    PROMPT;
        
    $aiDescription = "Generate {$type} questions for module {$newModule->id}";
        
        // Call the AI safely
        try {
            $questionsData = $this->callOpenAi($prompt, $model, $userID, $aiDescription);
        } catch (\Throwable $e) {
            \Log::error("OpenAI request failed for {$type}: " . $e->getMessage());
            return [];
        }

        // Validate response
        if (!is_array($questionsData) || empty($questionsData)) {
            \Log::error("OpenAI returned invalid data for {$type}.", ['response' => $questionsData]);
            return [];
        }

        $createdQuestions = [];

        foreach ($questionsData as $qData) {
            // Skip malformed entries
            if (!isset($qData['question'], $qData['answer'])) {
                \Log::warning("Malformed question data skipped", ['data' => $qData]);
                continue;
            }

            // Normalize ordering answer if AI returned a flat array instead of {"steps": [...]}
            $qAnswer = $qData['answer'];
            $qType   = $qData['type'] ?? $type;
            if ($qType === 'ordering' && is_array($qAnswer) && !isset($qAnswer['steps'])) {
                $qAnswer = ['steps' => array_values($qAnswer)];
            }

            // Create the question
            $question = Question::create([
                'question'   => $qData['question'],
                'answer'     => $qAnswer,
                'type'       => $qData['type'] ?? $type,
                'difficulty' => $qData['difficulty'] ?? 'medium',
                'skill_type' => $qData['skill_type'] ?? 'recall',
                'created_by' => auth()->id() ?? 1,
            ]);

            // Map and attach concept IDs
            $conceptIds = collect($qData['concepts'] ?? [])
                ->map(fn($name) => $conceptMap[$name] ?? null)
                ->filter()
                ->unique()
                ->values();

            if ($conceptIds->isEmpty()) {
                \Log::info("No valid concept IDs found for question: {$question->id}");
            } else {
                $question->concepts()->sync($conceptIds);
            }
            $newModule->questions()->syncWithoutDetaching($question->id);

            $createdQuestions[] = $question;
        }

        // sync selected questions
        

        return $createdQuestions;
    }


    
    public function inferProficiencyLevel(string $prompt, int $userId): string
    {
        return $this->callOpenAiRaw(
            $prompt,
            $userId,
            'infer_proficiency_level',
            'gpt-4o-mini',
            'You are a proficiency assessment assistant. Reply only with a single integer.',
            0.0
        );
    }

    public function generateExploreContent(string $userIntent, \App\Models\Subject $subject, \App\Models\Proficiency $proficiency, int $userID, string $priorKnowledge = '', string $researchContext = ''): array
    {
        $subject->loadMissing(['category.axes.concepts', 'concepts']);

        $axes        = $subject->category->axes;
        $axisString  = $axes->map(fn ($axis) =>
            $axis->name . ': ' . $axis->concepts->where('subject_id', $subject->id)->pluck('name')->implode(', ')
        )->implode(' | ');

        $conceptsList = $subject->concepts->pluck('name')->implode(', ');

        $outcomesRaw = $proficiency->outcomes;
        $outcomes = is_array($outcomesRaw) && ! empty($outcomesRaw)
            ? implode("\n- ", $outcomesRaw)
            : 'No outcomes specified.';

        $axisBlock = $axisString
            ? "SKILL MEASUREMENT DIMENSIONS (for context only — do NOT use axis names as content section titles):\n    {$axisString}\n"
            : '';

        $priorKnowledgeLine = $priorKnowledge !== ''
            ? "\nUser's current knowledge level: \"{$priorKnowledge}\""
            : '';

        $researchBlock = $researchContext !== ''
            ? "LATEST RESEARCH — YOU MUST USE THIS AS YOUR ONLY FACTUAL SOURCE:\nThe following contains current, patch-specific information.\nYou MUST base all content exclusively on what is written below.\nDo NOT use prior training knowledge about this topic.\nEvery specific ability name, talent name, cooldown value, or strategic detail\nyou include MUST appear in the research below.\nIf the research mentions specific talents, compositions, or mechanics — include them by name.\nDo not generalise or simplify patch-specific details.\n\n{$researchContext}\n"
            : '';

        $prompt = <<<PROMPT
You are an expert educator creating a structured learning module.

The user wants to learn: "{$userIntent}"{$priorKnowledgeLine}

SUBJECT: {$subject->name}
PROFICIENCY LEVEL: {$proficiency->name}
LEVEL DESCRIPTION: {$proficiency->description}
LEARNING OUTCOMES AT THIS LEVEL:
- {$outcomes}

{$researchBlock}
{$axisBlock}
AVAILABLE CONCEPTS (write content that naturally covers relevant ones — these will be used to tag questions):
{$conceptsList}

YOUR TASK:
Generate a structured learning module with 2–3 content pages that:
1. Directly addresses the user's stated learning intent
2. Uses the vocabulary and specific terminology found in the LATEST RESEARCH above —
   do not simplify named concepts, domain-specific terms, or precise details.
   A learner at {$proficiency->name} level wants specifics not generalities.
3. Covers enough factual content to support Recall questions (memory and recognition of facts)
4. Includes situations or examples to support Analysis questions (interpretation of information)
5. Includes decisions or scenarios to support Application questions (contextual use of knowledge)
6. Write only as much as the research context permits. Prioritise absolute accuracy and
   brevity over length. If the research does not mention a specific detail, omit it entirely
   rather than inferring or generalising. A shorter page with verified specifics is always
   preferable to a longer page that invents or softens source-specific information.

Return JSON ONLY in this exact format, no markdown fences or commentary:
{
  "title": "A concise, specific module title",
  "description": "One clear sentence describing what this module covers",
  "pages": [
    {
      "title": "Page title",
      "content": "Rich markdown content"
    }
  ]
}
PROMPT;

        $raw = $this->callGemini($prompt, 'gemini-2.5-flash', $userID, "Explore module content: {$userIntent}");

        if (! isset($raw['title'], $raw['pages'])) {
            Log::error('generateExploreContent returned unexpected structure', ['raw' => $raw]);
            throw new \RuntimeException('AI returned unexpected structure for explore content.');
        }

        return [
            'title'       => $raw['title'],
            'description' => $raw['description'] ?? '',
            'pages'       => $raw['pages'],
        ];
    }

}