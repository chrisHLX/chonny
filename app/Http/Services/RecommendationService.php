<?php

namespace App\Http\Services;

use App\Enums\GeneratedReason;
use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Models\Concept;
use App\Models\Module;
use App\Models\UserConceptMastery;
use App\Models\UserNextStep;
use App\Models\UserProfileInsight;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecommendationService
{
    public function __construct(protected AiService $aiService)
    {
    }

    /**
     * Picks one concept to explore next from a closed list of concepts that already have real,
     * available content — never a diagnosed "growth area" the AI has to force into a concept
     * schema — then deterministically resolves the module that covers it. Always produces a
     * step_type = Module UserNextStep (or null if the subject currently has no explorable
     * concept at all); never generates a free-text task.
     */
    public function generateRecommendation(
        UserProfileInsight $insight,
        ?UserNextStep $previousStep = null,
        GeneratedReason $reason = GeneratedReason::Initial
    ): ?UserNextStep {
        $candidates = $this->candidateConcepts($insight->user_id, $insight->subject_id);

        if ($candidates->isEmpty()) {
            Log::info('RecommendationService: no candidate concepts with available modules', [
                'user_id'    => $insight->user_id,
                'subject_id' => $insight->subject_id,
            ]);
            return null;
        }

        $masteryByConceptId = UserConceptMastery::where('user_id', $insight->user_id)
            ->whereIn('concept_id', $candidates->pluck('id'))
            ->pluck('mastery_percentage', 'concept_id');

        $conceptLines = $candidates->map(function (Concept $concept) use ($masteryByConceptId) {
            $mastery = $masteryByConceptId->get($concept->id);
            return $mastery !== null
                ? "  {$concept->name}: {$mastery}% mastery"
                : "  {$concept->name}: not yet assessed";
        })->implode("\n");

        $prompt = $this->buildPrompt($insight, $conceptLines);

        $response = [];
        try {
            $response = $this->aiService->sendPromptToAi($prompt, 'gpt-4.1-mini', $insight->user_id, 'recommendation_generation');
        } catch (\Throwable $e) {
            Log::error('RecommendationService: AI call failed', ['error' => $e->getMessage()]);
        }

        $chosenName = $response['concept'] ?? null;
        $chosenConcept = $candidates->firstWhere('name', $chosenName);

        if (!$chosenConcept) {
            // Closed list, so a deterministic fallback (lowest/absent mastery first) can never fail.
            $chosenConcept = $candidates->sortBy(fn (Concept $c) => $masteryByConceptId->get($c->id) ?? -1)->first();
        }

        $module = $this->findModuleForConcept($insight->user_id, $insight->subject_id, $chosenConcept->id);

        if (!$module) {
            Log::warning('RecommendationService: no module found for chosen concept despite candidate filter', [
                'user_id'    => $insight->user_id,
                'concept_id' => $chosenConcept->id,
            ]);
            return null;
        }

        $reasonText = $response['reason'] ?? "This module covers \"{$chosenConcept->name}\".";

        return UserNextStep::create([
            'user_id'          => $insight->user_id,
            'module_id'        => $module->id,
            'subject_id'       => $insight->subject_id,
            'insight_id'       => $insight->id,
            'concept_id'       => $chosenConcept->id,
            'previous_step_id' => $previousStep?->id,
            'step_type'        => StepType::Module,
            'status'           => NextStepStatus::Pending,
            'generated_reason' => $reason,
            'title'            => $module->name,
            'instructions'     => $reasonText,
        ]);
    }

    /**
     * Concepts belonging to the subject that already have at least one published, ready,
     * non-diagnostic, non-child module the user hasn't completed — the same filter criteria
     * NextStepService::findBestModuleForConcepts() uses, just concept-first. Guarantees every
     * candidate returned here can always be resolved to a real module.
     */
    private function candidateConcepts(int $userId, int $subjectId)
    {
        $completedModuleIds = DB::table('module_user')
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->pluck('module_id');

        return Concept::where('subject_id', $subjectId)
            ->whereHas('questions.modules', function ($q) use ($completedModuleIds) {
                $q->where('published', true)
                    ->where('status', 'ready')
                    ->where('type', '!=', 'diagnostic')
                    ->whereNull('parent_id')
                    ->whereNotIn('modules.id', $completedModuleIds);
            })
            ->get();
    }

    /**
     * Resolves a single already-chosen concept to a module. Deliberately simpler than
     * findBestModuleForConcepts() (no multi-concept coverage ranking needed — candidateConcepts()
     * already guarantees a match exists for this concept).
     */
    private function findModuleForConcept(int $userId, int $subjectId, int $conceptId): ?Module
    {
        $completedModuleIds = DB::table('module_user')
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->pluck('module_id');

        return Module::where('subject_id', $subjectId)
            ->where('published', true)
            ->where('status', 'ready')
            ->where('type', '!=', 'diagnostic')
            ->whereNull('parent_id')
            ->whereNotIn('id', $completedModuleIds)
            ->whereHas('questions.concepts', fn ($q) => $q->where('concepts.id', $conceptId))
            ->orderBy('id')
            ->first();
    }

    private function buildPrompt(UserProfileInsight $insight, string $conceptLines): string
    {
        return <<<PROMPT
You recommend which concept a learner should explore next, based on their profile. Use ONLY the information below — do not assume any game, sport, or domain-specific detail not present here.

PROFILE SUMMARY
{$insight->summary}

PROFILE TITLE: {$insight->profile_title}

AVAILABLE CONCEPTS (each line is "Name: mastery context" — the mastery context is background only)
{$conceptLines}

RULES
1. Choose exactly one concept from AVAILABLE CONCEPTS above — never invent a name, never return a concept not on this list.
2. Frame this as "worth exploring next", not a certain diagnosis or guaranteed weakness.
3. "concept" must be ONLY the exact name portion (before the colon) of one line above.
4. "reason" is one short sentence explaining why this concept is a good next step for this learner.
5. Return JSON ONLY — no markdown fences, no commentary.

OUTPUT SHAPE
{
  "concept": "exact concept name",
  "reason": "One sentence"
}
PROMPT;
    }
}
