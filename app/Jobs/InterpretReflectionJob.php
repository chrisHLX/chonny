<?php

namespace App\Jobs;

use App\Enums\NextStepStatus;
use App\Http\Services\AiService;
use App\Http\Services\NextStepService;
use App\Models\Concept;
use App\Models\UserNextStepReflection;
use App\Models\UserReflectionEvidence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InterpretReflectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reflection-derived evidence is self-reported and weak — never let it swing scores hard. */
    private const MAX_CONFIDENCE = 0.4;

    public function __construct(protected int $reflectionId) {}

    public function handle(AiService $aiService, NextStepService $nextStepService): void
    {
        $reflection = UserNextStepReflection::with('nextStep')->find($this->reflectionId);

        if (!$reflection) {
            Log::warning("⚠️ InterpretReflectionJob: reflection #{$this->reflectionId} not found.");
            return;
        }

        $step = $reflection->nextStep;

        Log::info("🚀 InterpretReflectionJob: interpreting reflection #{$this->reflectionId}");

        try {
            $prompt   = $this->buildPrompt($reflection);
            $response = $aiService->sendPromptToAi($prompt, 'gpt-4o-mini', $step->user_id, 'reflection_interpretation');

            foreach ($response['evidence'] ?? [] as $item) {
                $confidence = min((float) ($item['confidence'] ?? 0), self::MAX_CONFIDENCE);
                $confidence = max($confidence, 0);

                $conceptId = $this->groundConceptName($item['concept'] ?? null, $step->subject_id);

                UserReflectionEvidence::create([
                    'reflection_id'  => $reflection->id,
                    'concept_id'     => $conceptId,
                    'signal'         => $item['signal'] ?? '',
                    'interpretation' => $item['interpretation'] ?? '',
                    'confidence'     => $confidence,
                ]);
            }

            $step->update(['status' => NextStepStatus::Completed, 'completed_at' => now()]);

            $nextStepService->regenerateAfterReflection($reflection);

            Log::info("✅ InterpretReflectionJob: reflection #{$this->reflectionId} completed.");
        } catch (\Throwable $e) {
            Log::error("❌ InterpretReflectionJob: reflection #{$this->reflectionId} failed — {$e->getMessage()}");
            // Deliberately left in 'attempted' status (not advanced) — the 14-day expiry sweep
            // is the safety net that will eventually regenerate a fresh step for this user.
        }
    }

    private function buildPrompt(UserNextStepReflection $reflection): string
    {
        $step = $reflection->nextStep;

        return <<<PROMPT
A learner was given a practice task and reported back on it. Interpret their reflection into structured evidence about their reasoning — not about whether they won or lost.

TASK GIVEN: {$step->title} — {$step->instructions}

DID THEY TRY IT: {$reflection->did_try->value}
HOW IT WENT: {$reflection->how_it_went}
THEIR REASONING (why they think it went that way): {$reflection->why_reasoning}

RULES
1. Judge the QUALITY OF REASONING described, never the match/game outcome — sound reasoning with a bad outcome is not a negative signal, and a good outcome from flawed reasoning is not a positive signal.
2. Never diagnose or reference specific match/game state — only the player's own described thinking and habits.
3. This is self-reported reflection — weak evidence. Every "confidence" value must be between 0 and 0.4, never higher.
4. "concept" must be an exact concept name only if the task above named one, or null. Never invent a concept name.
5. If there is not enough information to say anything meaningful, return an empty evidence array — do not guess.
6. Return JSON ONLY — no markdown fences, no commentary.

OUTPUT SHAPE
{
  "evidence": [
    {
      "signal": "Plain English title, e.g. 'Reflective About Overextension'",
      "interpretation": "One sentence — what this reflection suggests about their reasoning",
      "concept": "exact concept name or null",
      "confidence": 0.3
    }
  ]
}
PROMPT;
    }

    private function groundConceptName(?string $name, int $subjectId): ?int
    {
        if (!$name) {
            return null;
        }

        $conceptId = Concept::where('subject_id', $subjectId)->where('name', $name)->value('id');

        if (!$conceptId) {
            Log::warning('InterpretReflectionJob: could not ground concept name', [
                'name'       => $name,
                'subject_id' => $subjectId,
            ]);
            return null;
        }

        return $conceptId;
    }
}
