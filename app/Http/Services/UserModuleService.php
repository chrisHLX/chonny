<?php
namespace App\Http\Services;

use App\Models\User;
use App\Models\Module;
use App\Models\Proficiency;
use App\Http\Services\AiService;
use App\Http\Services\SuggestionsService;

class UserModuleService
{
    /**
     * Build detailed stats for a user's performance in a specific module 
     *
     * @param User $user
     * @param Module $module
     * @return array
     */
    protected AiService $aiService;
    protected SuggestionsService $suggestionsService;

    public function __construct(AiService $aiService, SuggestionsService $suggestionsService)
    {
        $this->aiService = $aiService;
        $this->suggestionsService = $suggestionsService;
    }

    public function nextModuleResponse(User $user, Module $module)
    {
        // 1. Build full user-module stats
        $moduleStats = $this->buildModuleUserStats($user, $module);

        // 2. Reduce to only relevant info for AI
        $aiData = $this->prepareModuleStatsForAI($moduleStats);
        
        // Use this later to see if we have a response in the system if not save one
        $hashkey = collect($aiData["struggled_questions"])->pluck('id')->toArray();
        \Log::info('Hashkey questions: '.json_encode($hashkey));
        $statsHash = hash('sha256', json_encode($hashkey));

        $usableData = json_encode($aiData, JSON_PRETTY_PRINT);
        // 3. Build the prompt JSON for AI
        
        
        $availableProficiencies = Proficiency::where('subject_id', $module->subject_id)->get()->pluck('name')->toArray();
        // turn array into a comma-separated list
        $availableProficiencies = implode(", ", $availableProficiencies);
        
        
        $prompt = <<<EOT
    You are an adaptive learning engine that recommends the next learning modules
    for a user based on their performance. Use the following user data as context:

    {$usableData}

    Rules:
    - Recommend exactly 3 next modules.
    - Prioritize modules that address the user's struggled_concepts.
    - Consider the user's proficiency level and suggest modules at the same or slightly higher difficulty. 
        (Available Proficiencies for this module: {$availableProficiencies})

    - Avoid modules that are too advanced unless the user scored above 85% with few struggles.
    - If the user struggled with certain question types, include modules that reinforce those types.
    - Output JSON ONLY with this exact structure:
    
    \"recommendations\": [
        {
        \"name\": \"\",
        \"subject\": \"\",
        \"proficiency\": \"\",
        \"reason\": \"\"
        }
    ]
EOT
;
        
        $existing = $this->suggestionsService->getSuggestions($module, $statsHash);

        if ($existing) {
            $response = $existing->getRecommendations();
        } else {
            $response = $this->aiService->sendPromptToAi($prompt);
            $this->suggestionsService->storeSuggestions($module, $statsHash, $response);
        }

        return $response['recommendations'];
    }

    public function buildModuleUserStats(User $user, Module $module)
    {
        // Load module with questions and concepts
        // This is loaded from the pivot table user_module_question which means it only works if the user is actually linked to the module!!! IMPORTANT
        $module = $user->modules()
            ->with(['questions.concepts', 'questions'])
            ->findOrFail($module->id);

        $questions = $module->questions;
        $questionIds = $questions->pluck('id')->toArray();

        // Get pivot stats for ONLY this module's questions
        $answered = $user->answeredQuestions()
            ->whereIn('questions.id', $questionIds)
            ->withPivot([
                'attempts',
                'correct_count',
                'last_answered_at',
                'total_time_spent',
                'last_time_spent',
                'last_answer',
                'last_answer_correct',
                'consecutive_fails'
            ])
            ->get();

        $totalQuestions = $questions->count();

        // QUESTION-LEVEL BREAKDOWN (with struggle detection)
        $questionStats = $answered->map(function ($q) {
            $attempts = (int) ($q->pivot->attempts ?? 0);
            $correctCount = (int) ($q->pivot->correct_count ?? 0);
            $consecutiveFails = (int) ($q->pivot->consecutive_fails ?? 0);

            // how many times they answered incorrectly overall
            $wrongTimes = max(0, $attempts - $correctCount);

            // did they ever get it correct at least once?
            $everCorrect = $correctCount > 0;

            // did they get it correct on the first try?
            $firstTryCorrect = ($attempts > 0 && $correctCount > 0 && $attempts === 1 && $correctCount === 1);

            // struggled = had one or more incorrect attempts OR had consecutive fails
            $struggled = $wrongTimes > 0 || $consecutiveFails > 0;

            return [
                'id' => $q->id,
                'text' => $q->question,
                'type' => $q->type ?? null,
                'difficulty' => $q->difficulty ?? null,
                'concepts' => $q->concepts?->pluck('name')->toArray() ?? [],
                'attempts' => $attempts,
                'correct_count' => $correctCount,
                'wrong_times' => $wrongTimes,
                'consecutive_fails' => $consecutiveFails,
                'total_time_spent' => $q->pivot->total_time_spent ?? 0,
                'last_time_spent' => $q->pivot->last_time_spent ?? 0,
                // final_correct may be true due to retries; keep it but don't use it for struggle detection
                'final_correct' => (bool) ($q->pivot->last_answer_correct ?? false),
                'ever_correct' => $everCorrect,
                'first_try_correct' => $firstTryCorrect,
                'struggled' => (bool) $struggled
            ];
        })->values()->toArray();

        // MODULE SUMMARY using struggle-aware metrics
        $answeredCollection = collect($questionStats);

        $numEverCorrect = $answeredCollection->where('ever_correct', true)->count();
        $numStruggled = $answeredCollection->where('struggled', true)->count();

        // total incorrect attempts across all questions (signal of effort/struggle)
        $totalWrongAttempts = $answeredCollection->sum('wrong_times');

        // average time per answered question (skip unanswered)
        $avgTime = $answeredCollection->avg('total_time_spent') ?? 0;
        $maxFails = $answeredCollection->max('consecutive_fails') ?? 0;

        // Decide how you want to compute score:
        // Option A: % of questions the user eventually got correct
        $scorePercent = $totalQuestions > 0 ? round(($numEverCorrect / $totalQuestions) * 100, 1) : 0;

        // Option B (alternative): score that penalises many wrong attempts:
        // $scorePercent = $totalQuestions > 0 ? round((($numEverCorrect / $totalQuestions) * 100) - min(30, $totalWrongAttempts), 1) : 0;

        $moduleSummary = [
            'id' => $module->id,
            'name' => $module->name,
            'subject' => $module->subject->name ?? null,
            'proficiency' => $module->proficiencies()->first()->name ?? null,
            'score_percent' => $scorePercent,
            'num_questions' => $totalQuestions,
            'num_ever_correct' => $numEverCorrect,
            'num_unanswered_or_never_correct' => $totalQuestions - $numEverCorrect,
            'num_struggled' => $numStruggled,
            'total_wrong_attempts' => $totalWrongAttempts,
            'average_time' => round($avgTime, 2),
            'max_consecutive_fails' => $maxFails
        ];

        // PATTERN ANALYSIS (struggle-focused)
        $struggledTypes = $answeredCollection
            ->where('struggled', true)
            ->groupBy('type')
            ->keys()
            ->values()
            ->toArray();

        $struggledConcepts = $answeredCollection
            ->where('struggled', true)
            ->flatMap(fn($q) => $q['concepts'] ?? [])
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(5)
            ->values()
            ->toArray();

        $longestTimeTopics = $answeredCollection
            ->sortByDesc('total_time_spent')
            ->take(3)
            ->flatMap(fn($q) => $q['concepts'] ?? [])
            ->unique()
            ->values()
            ->toArray();

        $overallMastery = $totalQuestions > 0
            ? round($numEverCorrect / max(1, $totalQuestions + ($maxFails * 0.5)), 2)
            : 0;

        $patterns = [
            'struggled_types' => $struggledTypes,
            'struggled_concepts' => $struggledConcepts,
            'longest_time_topics' => $longestTimeTopics,
            'total_wrong_attempts' => $totalWrongAttempts,
            'overall_mastery_score' => $overallMastery
        ];

        
        return [
            'module' => $moduleSummary,
            'question_stats' => $questionStats,
            'patterns' => $patterns
        ];
    }



    public function prepareModuleStatsForAI(array $moduleStats)
    {
        // Pick module summary fields
        $moduleSummary = [
            //'id' => $moduleStats['module']['id'],
            'name' => $moduleStats['module']['name'],
            'subject' => $moduleStats['module']['subject'],
            'proficiency' => $moduleStats['module']['proficiency'],
            //'score_percent' => $moduleStats['module']['score_percent'],
            //'num_questions' => $moduleStats['module']['num_questions'],
            //'num_struggled' => $moduleStats['module']['num_struggled'],
            //'total_wrong_attempts' => $moduleStats['module']['total_wrong_attempts'],
        ];

        // Pick patterns
        $patterns = [
            'struggled_concepts' => array_slice($moduleStats['patterns']['struggled_concepts'] ?? [], 0, 5),
            'struggled_types' => array_slice($moduleStats['patterns']['struggled_types'] ?? [], 0, 3),
            // 'longest_time_topics' => array_slice($moduleStats['patterns']['longest_time_topics'] ?? [], 0, 3),
        ];

        // Optional: include only questions that the user struggled with
        $struggledQuestions = collect($moduleStats['question_stats'] ?? [])
            ->filter(fn($q) => $q['struggled'] ?? false)
            ->map(fn($q) => [
                'id' => $q['id'],
                'question' => $q['text'],
                'type' => $q['type'],
                'concepts' => $q['concepts']
            ])
            ->sortBy('id')    // ← THE IMPORTANT PART
            ->values()
            ->toArray();

           
        return [
            'module' => $moduleSummary,
            'patterns' => $patterns,
            'struggled_questions' => $struggledQuestions
        ];
    }

    public function getHash($user, $module)
    {
        
        // 1. Build full user-module stats
        $moduleStats = $this->buildModuleUserStats($user, $module);

        // 2. Reduce to only relevant info for AI
        $aiData = $this->prepareModuleStatsForAI($moduleStats);
        
        // Use this later to see if we have a response in the system if not save one
        $hashkey = collect($aiData["struggled_questions"])->pluck('id')->toArray();
        \Log::info('Hashkey questions: '.json_encode($hashkey));
        $statsHash = hash('sha256', json_encode($hashkey));
        return $statsHash;
    }

}