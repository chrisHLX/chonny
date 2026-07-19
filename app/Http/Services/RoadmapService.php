<?php

namespace App\Http\Services;

use App\Models\Concept;
use App\Models\Module;
use App\Models\SubjectContextDimension;
use App\Models\UserLearningPathStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the "learning path" roadmap shown after a diagnostic completes. Deliberately AI-free:
 * milestone definitions are static, subject-scoped copy from config/roadmap.php describing the
 * product's training methodology, not a per-user AI diagnosis. The one dynamic piece — which
 * real module comes right after the diagnostic — is resolved with the same deterministic, no-AI
 * concept-to-module matching NextStepService::findBestModuleForConcepts() uses, so the roadmap
 * can never point at content that doesn't exist or contradict what the profile actually found.
 *
 * Two consumers share resolveStages():
 * - buildGuestRoadmap() — ephemeral, gated behind a click, guest-only (GuestRoadmap component).
 *   Status is assigned purely positionally (0=complete, 1=next, rest=future) since nothing is
 *   persisted to check real progress against.
 * - persistStagesForUser() — the authenticated counterpart. Writes one UserLearningPathStage row
 *   per milestone; live status (complete/next/future) is computed separately, at render time, by
 *   Collection::getLearningPathProperty() — never stored, so a stage's completion always reflects
 *   current module_user state instead of going stale.
 *
 * No AiService, no credit deduction, no HTTP calls anywhere in this class.
 */
class RoadmapService
{
    /**
     * @param array $profile        the guest's diagnostic_profile JSON (session('guest_quiz_results.{id}.diagnostic_profile'))
     * @param array $surveyAnswers  session('guest_quiz_results.{id}.survey_answers') — keyed by question_key, each ['text' => ..., 'value' => ...]
     */
    public function buildGuestRoadmap(array $profile, array $surveyAnswers, Module $module): array
    {
        $stages     = $this->resolveStages($module, $profile);
        $milestones = $this->assignPositionalStatus($stages);

        $growthArea         = $profile['primary_growth_area'] ?? null;
        $growthAreaName     = is_array($growthArea) ? ($growthArea['name'] ?? '') : ($profile['growth_area'] ?? '');
        $currentRatingText  = $surveyAnswers['current_rating']['text'] ?? null;
        $goalText           = $surveyAnswers['primary_goal']['text'] ?? null;

        return [
            'title'      => $goalText ? "Your Road to {$goalText}" : 'Your Learning Path',
            'summary'    => $this->buildSummary($growthAreaName, $currentRatingText, $goalText, $milestones[1]['title'] ?? null),
            'milestones' => $milestones,
        ];
    }

    /**
     * Persists the same stage list buildGuestRoadmap() would show, for an authenticated user —
     * called from both DiagnosticQuizRunner::recordProfileInsight() (already-logged-in users)
     * and RegisteredUserController::claimGuestQuizResults() (guest converting on signup), the
     * same two call sites NextStepService::recordInsightAndGenerateInitialStep() is already
     * called from, for the same reason: guest and auth users end up with identical behavior.
     *
     * Replaces any existing stages for this (user, subject) rather than appending — a retake or
     * a fresh diagnostic completion should show the new plan, not accumulate old ones. Never
     * throws: a failure here must not break diagnostic completion or account registration, same
     * contract as recordInsightAndGenerateInitialStep().
     */
    public function persistStagesForUser(int $userId, Module $module, array $profile, array $surveyAnswers, ?int $insightId): void
    {
        if (!$module->subject_id) {
            return;
        }

        try {
            $stages = $this->resolveStages($module, $profile);

            DB::transaction(function () use ($userId, $module, $stages, $insightId) {
                UserLearningPathStage::where('user_id', $userId)
                    ->where('subject_id', $module->subject_id)
                    ->delete();

                foreach (array_values($stages) as $index => $stage) {
                    UserLearningPathStage::create([
                        'user_id'     => $userId,
                        'subject_id'  => $module->subject_id,
                        'insight_id'  => $insightId,
                        'order_index' => $index,
                        'stage_key'   => $stage['key'],
                        'concept_id'  => $stage['concept_id'],
                        'module_id'   => $stage['module_id'],
                        'title'       => $stage['title'],
                        'detail'      => $stage['detail'],
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('RoadmapService: failed to persist learning path stages', [
                'user_id'   => $userId,
                'module_id' => $module->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolves config/roadmap.php's static stage list into concrete milestone data — no status,
     * that's assigned separately by each of the two callers above. The only stage with dynamic
     * content is 'first_module', resolved via the same deterministic, no-AI matching
     * findFirstModule() below performs.
     *
     * @return array<int, array{key: string, title: string, detail: ?string, concept_id: ?int, module_id: ?int}>
     */
    private function resolveStages(Module $module, array $profile): array
    {
        $subjectName = $module->subject?->name;
        $stageDefs   = config("roadmap.{$subjectName}") ?? config('roadmap.default');

        $growthArea         = $profile['primary_growth_area'] ?? null;
        $growthAreaName     = is_array($growthArea) ? ($growthArea['name'] ?? '') : ($profile['growth_area'] ?? '');
        $growthConceptNames = is_array($growthArea) ? ($growthArea['concepts'] ?? []) : [];

        $firstModule = $module->subject_id
            ? $this->findFirstModule($module->subject_id, $growthConceptNames)
            : null;

        $firstConceptId = null;
        if ($firstModule && !empty($growthConceptNames)) {
            $firstConceptId = Concept::where('subject_id', $module->subject_id)
                ->where('name', $growthConceptNames[0])
                ->value('id');
        }

        $stages = [];

        foreach (array_values($stageDefs) as $stage) {
            if ($stage['key'] === 'first_module') {
                $stages[] = [
                    'key'        => 'first_module',
                    'title'      => $firstModule?->name ?? 'Your First Training Module',
                    'detail'     => $firstModule
                        ? 'Covers "' . ($growthConceptNames[0] ?? $growthAreaName) . '" — one of your current growth areas.'
                        : ($growthAreaName ? "A starting point tailored to your {$growthAreaName} growth area." : 'A starting point tailored to your profile.'),
                    'concept_id' => $firstConceptId,
                    'module_id'  => $firstModule?->id,
                ];
                continue;
            }

            if ($stage['key'] === 'context_dimensions') {
                $contextStage = $this->buildContextDimensionsStage($module->subject_id);

                // A subject with zero seeded dimensions (Poker, deliberately) omits this
                // milestone from the path entirely, rather than showing an empty/meaningless step.
                if ($contextStage) {
                    $stages[] = $contextStage;
                }

                continue;
            }

            $stages[] = [
                'key'        => $stage['key'],
                'title'      => $stage['title'],
                'detail'     => $stage['detail'],
                'concept_id' => null,
                'module_id'  => null,
            ];
        }

        return $stages;
    }

    /**
     * Builds the context-declaration milestone from real SubjectContextDimension rows — never
     * the hardcoded per-subject copy in config/roadmap.php, which exists only as a defensive
     * fallback. Title rule: a single dimension reads "{Name} Breakdown" (e.g. "Race Breakdown"
     * for SC2); two or more join with " & " (e.g. "Class & Spec" for WoW). Detail copy
     * deliberately describes declaration, not inference — the user tells us this, the system
     * never guesses it (see the context-vs-behaviour-vs-evidence design principle in CLAUDE.md).
     */
    private function buildContextDimensionsStage(?int $subjectId): ?array
    {
        if (!$subjectId) {
            return null;
        }

        $dimensions = SubjectContextDimension::where('subject_id', $subjectId)
            ->orderBy('order')
            ->get();

        if ($dimensions->isEmpty()) {
            return null;
        }

        $names = $dimensions->pluck('name');

        $title = $names->count() === 1
            ? "{$names->first()} Breakdown"
            : $names->implode(' & ');

        $detail = 'Tell us which ' . $this->naturalJoin($names->map(fn ($n) => strtolower($n))) . ' you play so every future recommendation is personalised to it.';

        return [
            'key'        => 'context_dimensions',
            'title'      => $title,
            'detail'     => $detail,
            'concept_id' => null,
            'module_id'  => null,
        ];
    }

    /**
     * "race" / "class and spec" / "a, b and c" — no Oxford comma, matches the plain, unadorned
     * copy style used throughout this feature's guest-facing summary text.
     */
    private function naturalJoin($items): string
    {
        $items = $items->values();

        if ($items->count() <= 1) {
            return $items->first() ?? '';
        }

        if ($items->count() === 2) {
            return "{$items[0]} and {$items[1]}";
        }

        $last = $items->pop();

        return $items->implode(', ') . " and {$last}";
    }

    /**
     * Guest-only status assignment: purely positional (0=complete, 1=next, rest=future), since
     * an ephemeral, unsaved preview has nothing real to check progress against. The authenticated
     * path (Collection::getLearningPathProperty()) computes status from real module_user state
     * instead — do not reuse this method there.
     */
    private function assignPositionalStatus(array $stages): array
    {
        return collect($stages)->values()->map(function ($stage, $index) {
            $status = match (true) {
                $index === 0 => 'complete',
                $index === 1 => 'next',
                default      => 'future',
            };

            return [
                'title'  => $stage['title'],
                'detail' => $stage['detail'],
                'status' => $status,
            ];
        })->all();
    }

    /**
     * Same deterministic, coverage-ranked matching NextStepService::findBestModuleForConcepts()
     * uses for authenticated users — deliberately not reused directly since that method excludes
     * modules the calling user already completed, which has no meaning for a guest with no
     * user_id yet (and, for the authenticated path, the roadmap's first-module slot is meant to
     * always show what "module 1" was, not silently swap to a different module once completed).
     * No AI call.
     */
    private function findFirstModule(int $subjectId, array $conceptNames): ?Module
    {
        if (empty($conceptNames)) {
            return null;
        }

        $conceptIds = Concept::where('subject_id', $subjectId)
            ->whereIn('name', $conceptNames)
            ->pluck('id');

        if ($conceptIds->isEmpty()) {
            return null;
        }

        $candidates = Module::where('subject_id', $subjectId)
            ->where('published', true)
            ->where('status', 'ready')
            ->where('type', '!=', 'diagnostic')
            ->whereNull('parent_id')
            ->whereHas('questions.concepts', fn ($q) => $q->whereIn('concepts.id', $conceptIds))
            ->with('questions.concepts:id')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortByDesc(fn (Module $m) => $m->questions
                ->flatMap(fn ($q) => $q->concepts->pluck('id'))
                ->intersect($conceptIds)
                ->unique()
                ->count())
            ->first();
    }

    /**
     * Plain text, no markdown — matches how the rest of the diagnostic results screen renders
     * summary copy (see diagnostic-quiz-runner.blade.php's "Summary" card: a single interpolated
     * {{ $summary }} paragraph, no embedded emphasis markup). Every slot degrades gracefully;
     * never renders an empty clause or "null".
     */
    private function buildSummary(string $growthAreaName, ?string $currentRating, ?string $goal, ?string $firstMilestoneTitle): string
    {
        $opening = $growthAreaName
            ? "Your assessment identified {$growthAreaName} as your biggest opportunity."
            : 'Your assessment is in — now it\'s time to put it to work.';

        $progress = match (true) {
            $currentRating && $goal => " As you work from {$currentRating} toward your goal to {$goal},",
            (bool) $goal            => " As you work toward your goal to {$goal},",
            default                 => '',
        };

        $closing = $firstMilestoneTitle
            ? " your training will be weighted toward closing that gap — starting with {$firstMilestoneTitle}."
            : ' your training will be weighted toward closing that gap.';

        return $opening . $progress . $closing;
    }
}
