<?php

namespace App\Http\Services;

use App\Models\Card;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Http\Services\AiService;

class CardGenerationService
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Build and persist a card for a user/module
     */
    public function generateFor($userId, $moduleId): Card
    {
        $user = User::findOrFail($userId);
        $module = Module::findOrFail($moduleId);

        // ---------------------------
        // 1) FETCH USER QUESTION DATA
        // ---------------------------
        $questions = $user->answeredQuestions()
            ->whereHas('modules', fn($q) => $q->where('modules.id', $moduleId))
            ->with(['concepts', 'modules'])
            ->get();

        // ---------------------------
        // 2) METRICS
        // ---------------------------
        $totalAttempts = $questions->sum(fn($q) => $q->pivot->attempts);
        $totalCorrect  = $questions->sum(fn($q) => $q->pivot->correct_count);
        $totalTime     = $questions->sum(fn($q) => $q->pivot->total_time_spent);

        $accuracy = $totalAttempts > 0
            ? round(($totalCorrect / $totalAttempts) * 100, 1)
            : 0.0;

        // ---------------------------
        // 3) STAT DISTRIBUTION
        // ---------------------------
        $conceptCounts = [];
        foreach ($questions as $question) {
            $correct = $question->pivot->correct_count ?? 0;

            foreach ($question->concepts as $concept) {
                $conceptCounts[$concept->name] =
                    ($conceptCounts[$concept->name] ?? 0) + $correct;
            }
        }

        // ---------------------------
        // 4) PICK PROFICIENCY (TEMP)
        // ---------------------------
        $proficiency = $module->proficiencies()->first();

        // ---------------------------
        // 5) MINT NUMBER
        // ---------------------------
        $mint = $this->nextMintNumber();

        // ---------------------------
        // 6) AI IMAGE GENERATION
        // ---------------------------
        $artPath = $this->aiService->generateCardArt([
            'module_id' => $module->id,
            'module_name' => $module->name,
            'description' => $module->description,
            'proficiency_name' => $proficiency->name ?? 'Basic',
        ]);

        // ---------------------------
        // 7) PERSIST CARD
        // ---------------------------
        $cardData = [
            'user_id' => $user->id,
            'module_id' => $module->id,
            'proficiency_id' => $proficiency?->id,
            'stats' => $conceptCounts,
            'accuracy' => $accuracy,
            'attempts' => $totalAttempts,
            'mint_number' => $mint,
            'edition' => 'First Edition',
            'image_path' => $artPath,
        ];

        return DB::transaction(fn() => Card::create($cardData));
    }

    protected function nextMintNumber(): int
    {
        return (Card::max('mint_number') ?? 0) + 1;
    }
}
