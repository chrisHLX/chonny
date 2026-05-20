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
        
        // Idempotency check
        $existing = Card::where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->first();

        if ($existing) {
            return $existing;
        }

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
        // 5) MINT NUMBER -- mint number is currently just adding all cards
        // ---------------------------
        $mint = $this->nextMintNumberForModule($module->id);

        // ---------------------------
        // 6) AI IMAGE GENERATION
        // ---------------------------
        /* moved to module creation
        $artPath = $this->aiService->generateCardArt([
            'module_id' => $module->id,
            'module_name' => $module->name,
            'description' => $module->description,
            'proficiency_name' => $proficiency->name ?? 'Basic',
        ]);
        */

        $imagePath = $module->art_path ?? 'cards/default.svg';

        $edition = match (true) {
            $mint === 1       => 'First Edition',
            $mint < 10        => 'Limited',
            default           => 'Common',
        };

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
            'edition' => $edition,
            'image_path' => $imagePath,
        ];

        return DB::transaction(fn() => Card::create($cardData));
    }

    protected function nextMintNumberForModule(int $moduleId): int
    {
        // Deliberately allow for race conditions here; a duplicate mint number will be rarer
        return (Card::where('module_id', $moduleId)->max('mint_number') ?? 0) + 1;
    }
    
}
