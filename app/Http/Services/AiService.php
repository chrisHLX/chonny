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

        // Estimate Input & Output Tokens
        $inputTokens = $this->estimateTokens($prompt);
        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,     // use chosen model or default
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

        // Estimate Output Tokens
        $outputTokens = $this->estimateTokens($content);

        // Calculate Cost
        // 4. Calculate cost in credits
        $usage = $this->tokenService->calculateCreditCost(
            $model,
            $inputTokens,
            $outputTokens
        );

        // Deduct credits from user
        $this->creditService->spendAiCredits(
            $userID,
            $usage['credits']['charged'],
            $description
        );


        \Log::info('AI token usage', [
            'model' => $usage['model'],
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],

            // Your actual OpenAI cost
            'cost_usd' => $usage['cost']['total_usd'],
            'input_usd' => $usage['cost']['input_usd'],
            'output_usd' => $usage['cost']['output_usd'],

            // What the user paid
            'credits_charged' => $usage['credits']['charged'],
            'credits_raw' => $usage['credits']['raw'],
        ]);


        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function callOpenAiString(string $prompt, $userID, string $description = 'undefined'): string
    {
        $userID = $userID; // Need to pass the user ID for jobs
        
        // Log the prompt for debugging
        \Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);

        /* --------- Token estimation logic ---------- */
        $model = 'gpt-4o-mini';

        // 1. Estimate input tokens
        $inputTokens = $this->estimateTokens($prompt);

        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini', // or gpt-3.5-turbo
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        $json = $response->json();
        
        // Grab the content from the first choice
        $content = $json['choices'][0]['message']['content'] ?? '';

        // Strip any Markdown code blocks (like ```json ... ```)
        $content = trim(preg_replace('/^```json|```$/i', '', $content));

        // If OpenAI returned a JSON string, decode and try to extract 'content'
        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['content'])) {
            $content = $decoded['content'];
        }

        // 3. Estimate output tokens
        $outputTokens = $this->estimateTokens($content);

        // 4. Calculate cost in credits
        $usage = $this->tokenService->calculateCreditCost(
            $model,
            $inputTokens,
            $outputTokens
        );

        // Deduct credits from user
        $this->creditService->spendAiCredits(
            $userID,
            $usage['credits']['charged'],
            $description
        );


        \Log::info('AI token usage', [
            'model' => $usage['model'],
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],

            // Your actual OpenAI cost
            'cost_usd' => $usage['cost']['total_usd'],
            'input_usd' => $usage['cost']['input_usd'],
            'output_usd' => $usage['cost']['output_usd'],

            // What the user paid
            'credits_charged' => $usage['credits']['charged'],
            'credits_raw' => $usage['credits']['raw'],
        ]);


        // Final trim to clean up any leftover whitespace
        $content = trim($content);

        \Log::debug('Final OpenAI explanation', ['content' => $content]);

        return $content;
    }

    private function callOpenAiHTML(string $prompt): string
    {
        Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);

        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4.1-nano',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        $json = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? '';

        // 🔥 Strip Markdown code block (e.g. ```html)
        $content = trim($content);
        $content = preg_replace('/^```html|```$/i', '', $content);
        $content = trim($content);

        Log::debug('Cleaned OpenAI content', ['content' => $content]);

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
        You are a StarCraft 2 coach. Analyze the following question and answer. Select 1–3 core gameplay concepts from the list that apply.

        Return only raw JSON. Do not include markdown or formatting. Just return: {"concepts": [...]}

        Concepts: {$conceptMap}
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
        // Example prompt for OpenAI
        $prompt = "
            Extract the most important keywords from the following text that I can then compare against a users answer.
            Respond ONLY with a JSON array of strings. 
            Text: \"$input\"
        ";

        // Call OpenAI API (replace with your own client implementation)
        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        // Get the text output from OpenAI
        $output = $response['choices'][0]['message']['content'] ?? '[]';

        // Decode into an array
        $keywords = json_decode($output, true);

        // Make sure it's always an array
        if (!is_array($keywords)) {
            $keywords = [];
        }

        return $keywords;
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

        $explanationString = $this->callOpenAiString($prompt, $userID, $aiDescription);

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

        $response = $this->callOpenAiHTML($prompt);

        $formattedResponse = $this->formatter->format($response);

        AiRequest::create([
            'user_id' => auth()->id(),
            'purpose' => 'generate_landing_page',
            'prompt' => $prompt,
            'response' => $response,
            'metadata' => [
                'module_id' => $module->id,
                'model' => 'gpt-4o-mini',
            ],
        ]);

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

    public function generateModuleContent(Module $module, int $userID): string
    {
        $prof     = $module->proficiencies()->first();
        $subject  = $module->subject;
        $category = $subject->category;

        $axes = $category->axes()->with('concepts')->get();
        $axisBlock = $axes->map(fn ($axis) =>
            "- {$axis->name}: " . $axis->concepts->pluck('name')->implode(', ')
        )->implode("\n");

        $conceptList = $subject->concepts()->pluck('name')->implode(', ');

        $profIndex = $prof?->index ?? 0;
        if ($profIndex < 2) {
            $depthGuide = "beginner — introduce terms plainly, use analogies, avoid jargon. Target 300–450 words.";
        } elseif ($profIndex < 4) {
            $depthGuide = "intermediate — assume basic familiarity, explain how concepts interact and why decisions matter. Target 450–650 words.";
        } else {
            $depthGuide = "advanced — assume solid fundamentals, explore nuance, trade-offs, and expert decision-making. Target 650–900 words.";
        }

        $profName        = $prof?->name ?? 'General';
        $profDescription = $prof?->description ?? '';

        $prompt = <<<EOT
        You are writing educational module content for a structured learning platform.

        MODULE: {$module->name}
        DESCRIPTION: {$module->description}
        SUBJECT: {$subject->name} (Category: {$category->name})
        PROFICIENCY: {$profName} — {$profDescription}

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
        - Return only the Markdown content. No JSON wrapper, no preamble, no meta-commentary.
        EOT;

        $content = $this->callOpenAiString($prompt, $userID, 'Module Content Generation');

        AiRequest::create([
            'user_id'  => $userID,
            'purpose'  => 'generate_module_content',
            'prompt'   => $prompt,
            'response' => $content,
            'metadata' => [
                'module_id' => $module->id,
                'model'     => 'gpt-4o-mini',
            ],
        ]);

        return $content;
    }

    /* --------------------------------------------------------- MODULE GENERATION --------------------------------------------------------- */

    // Questions the user gets wrong should be sufficient to generate a new module content.
    public function generateNewModule($moduleID, array $IDs)
    {
        \Log::info("new mod generated");
        $module = Module::find($moduleID); 
        $module = $this->followUpQuestions($module, $IDs);
        return $module;
    }

    // Questions the user gets wrong should be sufficient to generate a new module content.
    public function generateHarderModule($moduleID, array $IDs)
    {
        \Log::info("harder mod generated");
    }

    // This is the functionality for helping users with problematic questions
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
        
        $response = Http::withToken(env('OPENAI_API_KEY'))->post(
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
            'parent_module' => $ogModule->id,
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
            $axis->name . ': ' . $axis->concepts->pluck('name')->implode(', ')
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
                    "question": "Which unit can create Creep Tumors to expand vision and map control?",
                    "type": "mcq",
                    "skill_type": "recall",
                    "answer": {
                    "correct": "Queen",
                    "options": ["Queen", "Overlord", "Drone", "Infestor"]
                    },
                    "difficulty": "easy",
                    "concepts": ["Map Control"]
                }
            ]',
            'true_false' => '[
                {
                    "question": "True or False: A Drone can be used to cancel a building and regain resources.",
                    "type": "true_false",
                    "skill_type": "recall",
                    "answer": { "correct": true },
                    "difficulty": "medium",
                    "concepts": ["Economy", "Mechanics"]
                }
            ]',
            'matching_pairs' => '[
                {
                    "question": "Match the Zerg unit to its primary role.",
                    "type": "matching_pairs",
                    "skill_type": "analysis",
                    "answer": {
                        "correct": {
                            "Zergling": "Basic attacker",
                            "Overlord": "Scouting",
                            "Hydralisk": "Anti-air",
                            "Drone": "Worker"
                        },
                        "pairs": {
                            "keys": ["Zergling", "Overlord", "Hydralisk", "Drone"],
                            "values": ["Worker", "Anti-air", "Scouting", "Basic attacker"]
                        }
                    },
                    "difficulty": "easy",
                    "concepts": ["Army"]
                }
            ]',
            'ordering' => '[
                {
                    "question": "Put the following build steps in the correct order.",
                    "type": "ordering",
                    "skill_type": "application",
                    "answer": {
                        "steps": ["Train Drone", "Build Overlord", "Build Spawning Pool", "Build Hatchery"]
                    },
                    "difficulty": "easy",
                    "concepts": ["Build Orders"]
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

    $prompt = <<<PROMPT
    Generate {$questionAmount} {$type} questions for our learning app based on the following content.

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

            // Create the question
            $question = Question::create([
                'question'   => $qData['question'],
                'answer'     => $qData['answer'],
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
        $model = 'gpt-4o-mini';
        $inputTokens = $this->estimateTokens($prompt);

        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => 'You are a proficiency assessment assistant. Reply only with a single integer.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.0,
        ]);

        $json    = $response->json();
        $content = trim($json['choices'][0]['message']['content'] ?? '0');

        $outputTokens = $this->estimateTokens($content);
        $usage = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);
        $this->creditService->spendAiCredits($userId, $usage['credits']['charged'], 'infer_proficiency_level');

        return $content;
    }

    public function generateExploreContent(string $userIntent, \App\Models\Subject $subject, \App\Models\Proficiency $proficiency, int $userID, string $priorKnowledge = '', string $researchContext = ''): array
    {
        $subject->loadMissing(['category.axes.concepts', 'concepts']);

        $axes        = $subject->category->axes;
        $axisString  = $axes->map(fn ($axis) =>
            $axis->name . ': ' . $axis->concepts->pluck('name')->implode(', ')
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
            ? "LATEST RESEARCH (treat this as your primary factual source — it reflects current, up-to-date information):\n{$researchContext}\n"
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
2. Is written at the {$proficiency->name} reading level and vocabulary
3. Covers enough factual content to support Recall questions (memory and recognition of facts)
4. Includes situations or examples to support Analysis questions (interpretation of information)
5. Includes decisions or scenarios to support Application questions (contextual use of knowledge)

Return JSON ONLY in this exact format, no markdown fences or commentary:
{
  "title": "A concise, specific module title",
  "description": "One clear sentence describing what this module covers",
  "pages": [
    {
      "title": "Page title",
      "content": "Rich markdown content, 200–400 words"
    }
  ]
}
PROMPT;

        $raw = $this->callOpenAi($prompt, 'gpt-4o-mini', $userID, "Explore module content: {$userIntent}");

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