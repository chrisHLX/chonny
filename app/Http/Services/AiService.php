<?php

namespace App\Http\Services;

use App\Models\AiRequest;
use App\Models\Concept;
use App\Models\Module;
use App\Models\Question;
use App\Models\ModulePage;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log; 

use App\Http\Services\HtmlFormatter;
use App\Http\Services\VersioningService;


use Illuminate\Support\Facades\Http;


class AiService
{
    protected HtmlFormatter $formatter;
    protected Client $client;
    protected string $apiKey;

    public function __construct(Client $client, HtmlFormatter $formatter)
    {
        $this->client = $client;
        $this->formatter = $formatter;
        $this->apiKey = env('OPENAI_API_KEY'); // or config('services.openai.key')
    }

    /* --------------------------------------------------------- OPENAI CALLS & HELPERS --------------------------------------------------------- */
    private function callOpenAi(string $prompt): array
    {
        Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);

        $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
            //'model' => 'gpt-3.5-turbo',
            'model' => 'gpt-4o-mini', // Use gpt-4 for better performance, its the same cost as gpt-3.5-turbo
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

    private function callOpenAiString(string $prompt): string
    {
        // Log the prompt for debugging
        \Log::debug('Our prompt sent to OpenAI', ['prompt' => $prompt]);

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

    // This was used in the question controller when creating new questions but we are not using it at the moment
    // Keeping it here in case we want to use it later
    public function tagUnits(string $questionText, string $answerText = ''): array
    {
        $prompt = <<<EOT
        You are a StarCraft 2 coach. Analyze the following question and answer. Identify 1–3 specific units mentioned or implied. Return in JSON: {"units": [...]}

        Question: {$questionText}
        Answer: {$answerText}
        EOT;

        return $this->callOpenAi($prompt)['units'] ?? [];
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

    public function generateContentForQuestion(Question $question, $moduleInfo): string
    {
        $correct = $question->answer['correct'] ?? '';
        $prompt = <<<EOT
        The user is has answered the following question wrong a consecutive number of times. Please provide a detailed explanation to help them understand the concept better.
        
        The question is related to the following module: {$moduleInfo}

        Question: {$question->question}
        Correct Answer: {$correct}
        
        
        Return only the explanation text without any additional formatting.
        EOT;

        \Log::info('Generating content for question with prompt', ['prompt' => $prompt]);

        
        $explanationString = $this->callOpenAiString($prompt);

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
                "\nProvide a short summary that will help them understand the concepts better (try word it differently).
                
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
            'current_difficulty' => 'beginner',
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
    public function generateQuestions(string $content, $questionList)
    {
        //Create a map of existing concepts for the module to avoid duplicates
        $conceptMap = Concept::all()->pluck('id', 'name');
        $questions = [];

        $prompt = "
        Generate 5 Multiple Choice Questions based on the following content: \n";
        $prompt .= $content;
        $prompt .= '\n Return the questions in JSON format like this ONLY:
        [
            {
                "question": "What upgrade is usually completed in time for a 7:00 Terran Stim timing push?",
                "type": "mcq",
                "answer": {
                "correct": "Stimpack",
                "options": ["Combat Shield", "Stimpack", "Concussive Shells", "Armory"]
                },
                "difficulty": "medium",
                "units": ["Marine", "Barracks Tech Lab"],
                "concepts": ["Build Orders", "Army"]
            }
        ]
        IMPORTANT: Ensure the JSON is properly formatted without markdown or extra text
        IMPORTANT: list of usable "concepts": ["Army", "Build Orders", "Economy", "Map Control", "Mechanics", "Other", "Scouting", "Strategy", "Tactics"]
        IMPORTANT: Use language suitable for a player who would struggle with the content provided'
        ;
        $prompt .= "\nThe questions have to be different to these\n" . $questionList;

        $questionsData = $this->callOpenAi($prompt); // decoded array from AI
        
        /* units currently being returned by the AI but we havent created the logic to attach them yet
        'units'      => $qData['units'] ?? null, // these dont exist but maybe we can attach them via the pivot table
        */
        // Loop through each question data and create a new question. then map concepts and attach concepts to the new questions
        foreach ($questionsData as $qData) {
            $question = Question::create([
                'question'   => $qData['question'],
                'answer'     => $qData['answer'],
                'type'       => $qData['type'] ?? 'mcq',
                'difficulty' => $qData['difficulty'] ?? 'medium',
                'created_by' => auth()->id(),
            ]); 
            // Example: ['Scouting' => 1, 'Economy' => 2, ...]
            // logic to attach questions here nned to get concept IDS
            $conceptIds = collect($qData['concepts'])
                ->map(fn($name) => $conceptMap[$name] ?? $conceptMap['Other'])
                ->unique()
                ->values();

            $question->concepts()->sync($conceptIds);

            $questions[] = $question;
        }

        return $questions; // array of Question models

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