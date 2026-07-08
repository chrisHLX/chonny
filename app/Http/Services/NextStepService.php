<?php

namespace App\Http\Services;

use App\Enums\GeneratedReason;
use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Models\Concept;
use App\Models\UserConceptMastery;
use App\Models\UserNextStep;
use App\Models\UserNextStepReflection;
use App\Models\UserProfileInsight;
use Illuminate\Support\Facades\Log;

class NextStepService
{
    public function __construct(protected AiService $aiService)
    {
    }

    public function generateInitial(UserProfileInsight $insight): UserNextStep
    {
        $growthConcepts = $this->growthConceptsWithMastery($insight->user_id, $insight->growthAreaConcepts()->get());

        $prompt = $this->buildPrompt(
            summary: $insight->summary,
            profileTitle: $insight->profile_title,
            growthConcepts: $growthConcepts,
            context: null,
        );

        [$title, $instructions, $conceptId] = $this->callAndGround(
            $prompt,
            $insight->user_id,
            $insight->subject_id,
            'next_step_generation_initial'
        );

        return UserNextStep::create([
            'user_id'           => $insight->user_id,
            'module_id'         => null,
            'subject_id'        => $insight->subject_id,
            'insight_id'        => $insight->id,
            'concept_id'        => $conceptId,
            'previous_step_id'  => null,
            'step_type'         => StepType::Task,
            'status'            => NextStepStatus::Pending,
            'generated_reason'  => GeneratedReason::Initial,
            'title'             => $title,
            'instructions'      => $instructions,
        ]);
    }

    public function regenerateAfterReflection(UserNextStepReflection $reflection): UserNextStep
    {
        $previousStep = $reflection->nextStep;
        $insight = $previousStep->insight
            ?? UserProfileInsight::where('user_id', $previousStep->user_id)
                ->where('subject_id', $previousStep->subject_id)
                ->latest('generated_at')
                ->first();

        $growthConcepts = $this->growthConceptsWithMastery($previousStep->user_id, $insight?->growthAreaConcepts()->get() ?? collect());

        $evidenceContext = $reflection->evidence
            ->map(fn($e) => "  {$e->signal}: {$e->interpretation}")
            ->implode("\n");

        $reflectionContext = <<<CTX
PREVIOUS TASK: {$previousStep->title} — {$previousStep->instructions}
DID THEY TRY IT: {$reflection->did_try->value}
HOW IT WENT: {$reflection->how_it_went}
THEIR REASONING: {$reflection->why_reasoning}
INTERPRETED EVIDENCE (weak signal, low confidence — do not over-index on this):
{$evidenceContext}
CTX;

        $prompt = $this->buildPrompt(
            summary: $insight?->summary ?? '',
            profileTitle: $insight?->profile_title ?? '',
            growthConcepts: $growthConcepts,
            context: $reflectionContext,
        );

        [$title, $instructions, $conceptId] = $this->callAndGround(
            $prompt,
            $previousStep->user_id,
            $previousStep->subject_id,
            'next_step_generation_reflection'
        );

        return UserNextStep::create([
            'user_id'           => $previousStep->user_id,
            'module_id'         => null,
            'subject_id'        => $previousStep->subject_id,
            'insight_id'        => $insight?->id,
            'concept_id'        => $conceptId,
            'previous_step_id'  => $previousStep->id,
            'step_type'         => StepType::Task,
            'status'            => NextStepStatus::Pending,
            'generated_reason'  => GeneratedReason::Reflection,
            'title'             => $title,
            'instructions'      => $instructions,
        ]);
    }

    public function regenerateAfterExpiry(UserNextStep $expiredStep): UserNextStep
    {
        $insight = $expiredStep->insight
            ?? UserProfileInsight::where('user_id', $expiredStep->user_id)
                ->where('subject_id', $expiredStep->subject_id)
                ->latest('generated_at')
                ->first();

        $growthConcepts = $this->growthConceptsWithMastery($expiredStep->user_id, $insight?->growthAreaConcepts()->get() ?? collect());

        $prompt = $this->buildPrompt(
            summary: $insight?->summary ?? '',
            profileTitle: $insight?->profile_title ?? '',
            growthConcepts: $growthConcepts,
            context: "The previous task ({$expiredStep->title}) went unanswered and expired — pick a fresh, approachable task.",
        );

        [$title, $instructions, $conceptId] = $this->callAndGround(
            $prompt,
            $expiredStep->user_id,
            $expiredStep->subject_id,
            'next_step_generation_expired'
        );

        return UserNextStep::create([
            'user_id'           => $expiredStep->user_id,
            'module_id'         => null,
            'subject_id'        => $expiredStep->subject_id,
            'insight_id'        => $insight?->id,
            'concept_id'        => $conceptId,
            'previous_step_id'  => $expiredStep->id,
            'step_type'         => StepType::Task,
            'status'            => NextStepStatus::Pending,
            'generated_reason'  => GeneratedReason::Expired,
            'title'             => $title,
            'instructions'      => $instructions,
        ]);
    }

    public function supersedePendingStepsForNewInsight(UserProfileInsight $insight): void
    {
        UserNextStep::where('user_id', $insight->user_id)
            ->where('subject_id', $insight->subject_id)
            ->where('status', NextStepStatus::Pending->value)
            ->update(['status' => NextStepStatus::Superseded->value]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, string> lines like "Name: 42% mastery" or "Name: not yet assessed"
     */
    private function growthConceptsWithMastery(int $userId, $concepts)
    {
        $conceptIds = $concepts->pluck('id');

        $masteryByConceptId = UserConceptMastery::where('user_id', $userId)
            ->whereIn('concept_id', $conceptIds)
            ->pluck('mastery_percentage', 'concept_id');

        return $concepts->map(function ($concept) use ($masteryByConceptId) {
            $mastery = $masteryByConceptId->get($concept->id);
            return $mastery !== null
                ? "{$concept->name}: {$mastery}% mastery"
                : "{$concept->name}: not yet assessed";
        });
    }

    private function buildPrompt(string $summary, string $profileTitle, $growthConcepts, ?string $context): string
    {
        $conceptsBlock = $growthConcepts->isNotEmpty()
            ? $growthConcepts->map(fn($c) => "  {$c}")->implode("\n")
            : '  (no growth-area concepts recorded yet)';

        $contextBlock = $context ? "\n\nADDITIONAL CONTEXT\n{$context}" : '';

        return <<<PROMPT
You generate one concrete practice task for a learner, based on their current profile. Use ONLY the information below — do not assume any game, sport, or domain-specific detail not present here.

PROFILE SUMMARY
{$summary}

PROFILE TITLE: {$profileTitle}

GROWTH-AREA CONCEPTS (task should target one of these where possible — each line is "Name: mastery context", the mastery context is background only)
{$conceptsBlock}
{$contextBlock}

RULES
1. Judge the player's reasoning and habits, never diagnose or reference specific match/game state.
2. The task must be one concrete, actionable thing to try in their next session — not generic advice.
3. "concept" must be ONLY the exact name portion (before the colon) of one line from GROWTH-AREA CONCEPTS above, or null if none fit. Never invent a concept name, never include the mastery context in this field.
4. Return JSON ONLY — no markdown fences, no commentary.

OUTPUT SHAPE
{
  "title": "Short task label",
  "instructions": "One to two sentences describing the concrete task",
  "concept": "exact concept name or null"
}
PROMPT;
    }

    private function callAndGround(string $prompt, int $userId, int $subjectId, string $purpose): array
    {
        try {
            $response = $this->aiService->sendPromptToAi($prompt, 'gpt-4o-mini', $userId, $purpose);
        } catch (\Throwable $e) {
            Log::error("NextStepService: AI call failed for purpose {$purpose}", ['error' => $e->getMessage()]);
            $response = [];
        }

        $title        = $response['title'] ?? 'Keep practicing';
        $instructions = $response['instructions'] ?? 'Review your recent activity and try applying what you learned in your next session.';
        $conceptId    = $this->groundConceptName($response['concept'] ?? null, $subjectId);

        return [$title, $instructions, $conceptId];
    }

    private function groundConceptName(?string $name, int $subjectId): ?int
    {
        if (!$name) {
            return null;
        }

        $conceptId = Concept::where('subject_id', $subjectId)->where('name', $name)->value('id');

        if (!$conceptId) {
            Log::warning('NextStepService: could not ground concept name', [
                'name'       => $name,
                'subject_id' => $subjectId,
            ]);
            return null;
        }

        return $conceptId;
    }
}
