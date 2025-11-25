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
        $this->apiKey = env('OPENAI_API_KEY'); // or config('services.openai.key')
        $this->creditService = $creditService;
        $this->tokenService = $tokenService;
    }


    public function test()
    {
        $userID = auth()->id();
        $modules = auth()->user()->modules()->wherePivot('status', 'completed')->get();
        dd($modules);
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
    

    public function addCredits()
    {
        $this->creditService->addAiCredits(auth()->user()->id, 1000, "Test credit addition");
    }

    public function testContent($module)
    { 
        $newModule = $module;
        
        // dont forget to add parent module field instead of version potentially
        
        $types = ['mcq', 'true_false', 'matching_pairs', 'ordering'];
        foreach ($types as $selectedType) {
            GenerateQuestions::dispatch($selectedType, $newModule);
        }
        
    }

    public function sendPromptToAi(string $prompt): array
    {
        $response = $this->callOpenAi($prompt);
        return $response;
    }

    private function estimateTokens(string $text): int
    {
        // Rough estimate: ~4 chars per token (English text)
        return (int) ceil(strlen($text) / 4);
    }


    /* --------------------------------------------------------- OPENAI CALLS & HELPERS --------------------------------------------------------- */
    private function callOpenAi(string $prompt, $model = 'gpt-4o-mini'): array
    {
        Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);
        \Log::info("Using Model {$model}");

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

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function callOpenAiString(string $prompt, $userID): string
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
        $creditCost = $this->tokenService->calculateCreditCost($model, $inputTokens, $outputTokens);

        // 5. Deduct credits from user
        $this->creditService->spendAiCredits($userID, $creditCost);

        // 6. Log debug info
        \Log::info('AI token usage', [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'credit_cost' => $creditCost,
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

    // Format the answer based on its type for better readability in prompts important for generating questions
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

    public function generateContentForQuestion(Question $question, $moduleInfo, $userID): string
    {
        $correct = $this->formatAnswer($question);
        $prompt = <<<EOT
        The user has repeatedly answered the following question incorrectly. 
        Please write a clear, structured explanation to help them understand the underlying concept.

        - Start by explaining the context or reasoning behind the concept.
        - Include an example or analogy where appropriate.
        - End with the correct answer as a natural conclusion, not at the beginning.
        - Avoid listing or emphasizing the answer early (e.g. don’t say 'the correct order is' or 'the answer is').
        
        Focus on:
        - Explaining *why* the correct answer is right.
        - Clarifying *why* common wrong answers might be confusing. 
        - Giving one short, memorable tip or analogy.

        Context:
        - Module: $moduleInfo
        - Question: $question->question
        - Correct Answer: $correct

        Return only the plain text explanation — no lists, titles, or formatting.

        EOT;
        
        \Log::info('Generating content for question with prompt', ['prompt' => $prompt]);

        
        $explanationString = $this->callOpenAiString($prompt, $userID);

        \Log::info('Generated explanation $explanationString["content"]');

        return $explanationString;
    }
    

    /* --------------------------------------------------------- Module Controller --------------------------------------------------------- */

    // Used in the module controller when creating landing pages
    public function createLandingPage(Module $module, string $userPrompt = '')
    {
    $prompt = <<<EOT
    Please take the following user-provided content and format it into raw, well-structured HTML with no classes. 
    Focus on a logical hierarchy using <h2> for main sections, <h3> for sub-sections, 
    and use <p>, <ul>, <ol>, and <li> for content details.

    Do not include <html>, <head>, or <body> tags.

    Module Name: {$module->name}
    Module Description: {$module->description}

    User Content:
    {$userPrompt}
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

    public function generateIdeas($content, $userData)
    {
        $prompt = "the user just completed this module and would like a list of new modules to do";
        $prompt .= "provide your list in the following JSON format ONLY";
        $prompt .= '
            [
                {
                    "module_name": "name here",
                    "module_description": "description here"
                },
            ]
        ';
        $prompt .= "the user is interested " . $userModuleList . "consider their completed modules when creating 3 options";

        $options = $this->callOpenAiString($prompt);
        return $options;
        // send these options so we can show them in the view like foreach options as option option->name option->description. 
        // then the user can select the difficulty they want and then that gets sent to the below function
    }

    // This is an example of the generate next module no this is all wrong the user will select the module they want instead create a name and description
    // of the module let the user select the difficulty level from a dropdown list. let the user choose the primary concepts?
    // User will pass these variables $name $description $difficulty.
    public function generateModule($name, $description, $difficulty, $subject)
    {
        $prompt = <<<EOT
        Generate the following Module in JSON format ONLY:
        [    
            {
                "module_name": "A concise name for the module",
                "module_description": "A brief description of what the module covers",
                "subject": {$subject}
            }
        ]
        The module should focus on the subject of {$subject} and be suitable for a {$difficulty} level learner.
EOT;
        dd($prompt);
        $newModule = $this->callOpenAi($prompt);

        // Create newModule then below create and attach questions to module
        
    }

    //The content will be with html tag, we should strip them out before adding to the prompt
    public function generateQuestions(string $type, string $content, $newModule)
    {
        // Fetch all concepts as [ 'name' => id ]
        $conceptMap = Concept::where('subject_id', $newModule->subject_id)->pluck('id', 'name');
        $questionsList = $newModule->questions()->pluck('question')->toArray();
        $questionsList = $newModule->questions()->pluck('question')->implode("\n- ");
        $prof = $newModule->proficiencies()->first()->name;
        $pDesc = $newModule->proficiencies()->first()->description;

        if ($type == 'ordering' || $type == 'matching_pairs') {
            // Simplify content for complex question types
            $model= 'gpt-4.1-mini';
        } else {
            $model= 'gpt-4o-mini';
        }
        
        
        // Define example JSON structures for each type
        $examples = [
            'mcq' => '[
                {
                    "question": "Which unit can create Creep Tumors to expand vision and map control?",
                    "type": "mcq",
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
                    "answer": { "correct": true },
                    "difficulty": "medium",
                    "concepts": ["Economy", "Mechanics"]
                }
            ]',
            'matching_pairs' => '[
                {
                    "question": "Match the Zerg unit to its primary role.",
                    "type": "matching_pairs",
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

        $prompt = <<<PROMPT
    Generate 5 {$type} questions for our learning app based on the following content.

    CONTENT:
    {$content}

    PROFICIENCY LEVEL: {$prof}.
    {$prof} Level Description: {$pDesc}.

    IMPORTANT NOTE: Proficiency represents the user's reading level (vocabulary) and prior knowledge. Use this to tailor question complexity and readability.
    

    Existing Questions for this module:
    {$questionsList}

    REQUIREMENTS:
    - 2 easy, 2 medium, and 1 hard question.
    - Return JSON ONLY in this format:
    {$exampleJson}
    - Concepts must be chosen from this list (you can tag one or more): {$usableConcepts}
    - Ensure JSON is valid, without markdown or commentary.
    PROMPT;

    
        // Call the AI safely
        try {
            $questionsData = $this->callOpenAi($prompt, $model);
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


    
    /* --------------------------------------------------------- Unused Functions --------------------------------------------------------- */

    // currently not being used, was used for automatic landing page generation
    public function generateLandingPage(Module $module, string $userPrompt = '') 
    {
        $questions = $module->questions()->with('concepts')->get();
        $prompt = <<<EOT
        Could you create a guide for the following user-created module. The aim is to help people understand key concepts in sc2.

        Module Name: {$module->name}
        Module Description: {$module->description}
        Additional Context: {$userPrompt}
        EOT;

            $prompt .= <<<EOT

        Please format the guide using raw well structured HTML with no classes. 
        Focus on a logical hierarchy using <h2> for main sections, <h3> for sub-sections, 
        and use <p>, <ul>, <ol>, and <li> for content details.

        Do not include <html>, <head>, or <body> tags.

        EOT;

            \Log::debug('Generating landing page with prompt', ['prompt' => $prompt]);

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

}