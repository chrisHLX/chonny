<?php

namespace App\Http\Services;

use GuzzleHttp\Client;
use App\Models\AiRequest;
use App\Models\Module;
use App\Models\Question;
use Illuminate\Support\Facades\Log; 
use App\Models\ModulePage;
use App\Http\Services\HtmlFormatter;

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

    private function storeGeneratedPage($moduleId, $title, $content, $userId)
    {
        return ModulePage::create([
            'module_id'   => $moduleId,
            'title'       => $title,
            'content'     => $content,
            'page_number' => 1, // landing page
            'created_by'  => $userId,
            'updated_by'  => $userId,
        ]);
    }

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

    private function formatAnswer(array $q): string
    {
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
                // Typical shape: ['steps' => [...]]
                $steps = [];
                if (is_array($answer)) {
                    if (isset($answer['steps']) && is_array($answer['steps'])) {
                        $steps = $answer['steps'];
                    } else {
                        // fallback if someone stored just an array of steps
                        $steps = array_values($answer);
                    }
                }
                return $steps ? implode(' → ', $steps) : 'N/A';
            }

            case 'matching_pairs': {
                // Typical shape: ['correct' => ['Key' => 'Value', ...]]
                $pairs = [];
                if (is_array($answer)) {
                    $map = $answer['correct'] ?? $answer['pairs'] ?? null;
                    if (is_array($map)) {
                        foreach ($map as $k => $v) {
                            $pairs[] = "{$k} = {$v}";
                        }
                    }
                }
                return $pairs ? implode(', ', $pairs) : 'N/A';
            }

            case 'open': {
                // Typical shape: ['ideal_answer' => '...', 'correct_keywords' => [...]]
                if (is_array($answer) && !empty($answer['ideal_answer'])) {
                    return $answer['ideal_answer'];
                }
                if (is_array($answer) && !empty($answer['correct_keywords'])) {
                    return 'Keywords: ' . implode(', ', (array)$answer['correct_keywords']);
                }
                return 'N/A';
            }

            case 'true_false': {
                // Typical shape: ['correct' => true/false]
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
                // Typical shape: ['options' => [...], 'correct' => '...']
                if (is_array($answer) && isset($answer['correct'])) {
                    return (string) $answer['correct'];
                }
                return is_scalar($answer) ? (string)$answer : 'N/A';
            }

            default: {
                // Generic fallback: try to stringify scalars; encode arrays
                if (is_array($answer)) {
                    return json_encode($answer);
                }
                if ($answer === null || $answer === '') {
                    return 'N/A';
                }
                return (string)$answer;
            }
        }
    }

    

    public function followUpQuestions(array $questions)
    {
        // Filter out questions that were answered correctly
        $questionList = "";

        foreach ($questions as $index => $q) {
            $formattedAnswer = $this->formatAnswer($q); // We are fomatting the answer to handle different types like ordering, matching pairs (arrays not strings)
            \logger()->info('Formatted answer for question', ['formattedAnswer' => $formattedAnswer]);
            $questionList .= ($index + 1) . ". " . $q['question'] . " — " . $formattedAnswer . "\n";
        }


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

        AiRequest::create([
                'user_id' => auth()->id(),
                'purpose' => 'generate_landing_page',
                'prompt' => $prompt,
                'response' => $content,
                'metadata' => [
                    'module_id' => "N/A",
                    'model' => 'gpt-4o-mini',
                ],
            ]);

        return $content;
         
       // return $prompt;
    }


    //The content will be with html tage, we should strip them out before adding to the prompt
    public function generateQuestions(string $content)
    {
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
        IMPORTANT: Ensure the JSON is properly formatted without markdown or extra text';
        $questions = $this->callOpenAi($prompt); // returns decoded array of questions

        foreach ($questions as $q) {
            Question::create([
                'question'   => $q['question'],
                'answer'     => $q['answer'], // this is already an array, will be cast to JSON
                'type'       => $q['type'] ?? 'mcq',
                'difficulty' => $q['difficulty'] ?? 'medium',
                'units' => ($q['concepts'] ?? null), // helper method maybe?
                'concepts'    => ($q['units'] ?? null),
                'created_by' => auth()->id(), // current logged-in user
            ]);
        }

        return $questions;
    }

}