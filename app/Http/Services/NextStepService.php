<?php

namespace App\Http\Services;

use App\Enums\DidTry;
use App\Enums\GeneratedReason;
use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Models\Archetype;
use App\Models\Concept;
use App\Models\Module;
use App\Models\UserConceptMastery;
use App\Models\UserNextStep;
use App\Models\UserNextStepReflection;
use App\Models\UserProfileInsight;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NextStepService
{
    public function __construct(
        protected AiService $aiService,
        protected RecommendationService $recommendationService,
        protected SubjectContextService $subjectContextService
    ) {
    }

    public function generateInitial(UserProfileInsight $insight): UserNextStep
    {
        $growthConceptModels = $insight->growthAreaConcepts()->get();

        $matchedModule = $this->findBestModuleForConcepts($insight->user_id, $insight->subject_id, $growthConceptModels->pluck('id')->all());
        if ($matchedModule) {
            return $this->createModuleStep($matchedModule, $insight->user_id, $insight->subject_id, $insight->id, $growthConceptModels, null, GeneratedReason::Initial);
        }

        $growthConcepts = $this->growthConceptsWithMastery($insight->user_id, $growthConceptModels);

        $prompt = $this->buildPrompt(
            summary: $insight->summary,
            profileTitle: $insight->profile_title,
            growthConcepts: $growthConcepts,
            context: null,
            subjectName: $insight->subject?->name,
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

    /**
     * Writes the queryable UserProfileInsight snapshot for a completed diagnostic and generates
     * the first next-step task from it. Shared by both completion paths — a user completing the
     * diagnostic while logged in (DiagnosticQuizRunner) and a guest's diagnostic result being
     * claimed on registration (RegisteredUserController) — so both get the same next-step loop.
     * Never throws: failures here must not break diagnostic completion or account registration.
     */
    public function recordInsightAndGenerateInitialStep(int $userId, ?Module $moduleModel, array $diagnosticProfile): ?UserProfileInsight
    {
        if (!$moduleModel || !$moduleModel->subject_id) {
            return null;
        }

        try {
            $insight = DB::transaction(function () use ($userId, $moduleModel, $diagnosticProfile) {
                $archetypeId = !empty($diagnosticProfile['archetype_key'])
                    ? Archetype::where('key', $diagnosticProfile['archetype_key'])->value('id')
                    : null;

                $insight = UserProfileInsight::create([
                    'user_id'                => $userId,
                    'module_id'              => $moduleModel->id,
                    'subject_id'             => $moduleModel->subject_id,
                    'archetype_id'           => $archetypeId,
                    'profile_title'          => $diagnosticProfile['profile_title'] ?? ($diagnosticProfile['player_type'] ?? 'Unclassified'),
                    'confidence_level'       => $diagnosticProfile['confidence_level'] ?? 'medium',
                    'summary'                => $diagnosticProfile['summary'] ?? ($diagnosticProfile['narrative'] ?? ''),
                    'evidence'               => $diagnosticProfile['evidence'] ?? [],
                    'self_report_check'      => $diagnosticProfile['self_report_check'] ?? null,
                    'likely_in_game_pattern' => $diagnosticProfile['likely_in_game_pattern'] ?? '',
                    'growth_area_pattern'    => $diagnosticProfile['growth_area_pattern'] ?? '',
                    'generated_at'           => now(),
                ]);

                $conceptMap = Concept::where('subject_id', $moduleModel->subject_id)->pluck('id', 'name');

                foreach (($diagnosticProfile['primary_strength']['concepts'] ?? []) as $name) {
                    if ($conceptMap->has($name)) {
                        $insight->concepts()->attach($conceptMap[$name], ['role' => 'strength']);
                    }
                }

                $growthConceptsAttached = 0;
                foreach (($diagnosticProfile['primary_growth_area']['concepts'] ?? []) as $name) {
                    if ($conceptMap->has($name)) {
                        $insight->concepts()->attach($conceptMap[$name], ['role' => 'growth_area']);
                        $growthConceptsAttached++;
                    }
                }

                // Distinct from DiagnosticProfileService's per-field "filtered invalid concept
                // names" warning (which fires on the raw AI response, before grounding). This one
                // fires on the post-grounding outcome that actually matters downstream: when it's
                // zero, findBestModuleForConcepts() returns null before querying, and this insight
                // falls back to free-text tasks for its entire lifetime. Greppable so the rate can
                // be queried without re-deriving it from raw AI-response logs.
                if ($growthConceptsAttached === 0) {
                    Log::warning('NextStepService: insight created with zero growth-area concepts — module routing will be skipped for this insight', [
                        'insight_id' => $insight->id,
                        'user_id'    => $userId,
                        'subject_id' => $moduleModel->subject_id,
                    ]);
                }

                $this->supersedePendingStepsForNewInsight($insight);

                return $insight;
            });

            // Runs outside the transaction — it makes an AI HTTP call and shouldn't hold a DB lock open.
            // Delegates to RecommendationService rather than generateInitial(): the recommendation
            // is picked from a closed, availability-filtered concept list, not the diagnosis's own
            // (sometimes ungrounded) growth-area concepts. generateInitial() and the free-text task
            // path stay in this file, untouched, for future use — just no longer called from here.
            $this->recommendationService->generateRecommendation($insight);

            return $insight;
        } catch (\Throwable $e) {
            Log::error('Failed to record profile insight / next step', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function regenerateAfterReflection(UserNextStepReflection $reflection): UserNextStep
    {
        $previousStep = $reflection->nextStep;
        $insight = $previousStep->insight
            ?? UserProfileInsight::where('user_id', $previousStep->user_id)
                ->where('subject_id', $previousStep->subject_id)
                ->latest('generated_at')
                ->first();

        $growthConceptModels = $insight?->growthAreaConcepts()->get() ?? collect();

        $matchedModule = $this->findBestModuleForConcepts($previousStep->user_id, $previousStep->subject_id, $growthConceptModels->pluck('id')->all());
        if ($matchedModule) {
            return $this->createModuleStep($matchedModule, $previousStep->user_id, $previousStep->subject_id, $insight?->id, $growthConceptModels, $previousStep->id, GeneratedReason::Reflection);
        }

        if ($this->investigationChainExhausted($previousStep)) {
            $previousStep->update(['status' => NextStepStatus::Concluded]);
            return $previousStep;
        }

        $growthConcepts = $this->growthConceptsWithMastery($previousStep->user_id, $growthConceptModels);

        $evidenceContext = $reflection->evidence
            ->map(fn($e) => "  {$e->signal}: {$e->interpretation}")
            ->implode("\n");

        $historyContext = $this->buildHistoryContext($previousStep);
        $historyBlock = $historyContext ? "\n\n{$historyContext}" : '';

        $reflectionContext = <<<CTX
MOST RECENT TASK: {$previousStep->title} — {$previousStep->instructions}
DID THEY TRY IT: {$reflection->did_try->value}
HOW IT WENT: {$reflection->how_it_went}
THEIR REASONING: {$reflection->why_reasoning}
INTERPRETED EVIDENCE (weak signal, low confidence — do not over-index on this):
{$evidenceContext}{$historyBlock}
CTX;

        $prompt = $this->buildPrompt(
            summary: $insight?->summary ?? '',
            profileTitle: $insight?->profile_title ?? '',
            growthConcepts: $growthConcepts,
            context: $reflectionContext,
            subjectName: $previousStep->subject?->name,
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

    /**
     * A task's success condition is information gain, not skill gain — three genuine attempts on
     * the same growth-area concept, reflected on, is a concluded investigation either way, not a
     * failure to keep retrying. Scoped to insight_id+concept_id so a new insight (which attaches
     * its own fresh growth-area concept rows) always starts a new chain, never inherits a count
     * from a prior insight. Module-type steps never reach this — findBestModuleForConcepts() is
     * checked first in every caller and always wins when a match exists, regardless of task count.
     */
    private function investigationChainExhausted(UserNextStep $completedTaskStep, int $threshold = 3): bool
    {
        if (!$completedTaskStep->insight_id || !$completedTaskStep->concept_id) {
            return false;
        }

        $completedTaskCount = UserNextStep::where('insight_id', $completedTaskStep->insight_id)
            ->where('concept_id', $completedTaskStep->concept_id)
            ->where('step_type', StepType::Task->value)
            ->where('status', NextStepStatus::Completed->value)
            ->count();

        return $completedTaskCount >= $threshold;
    }

    public function regenerateAfterExpiry(UserNextStep $expiredStep): UserNextStep
    {
        $insight = $expiredStep->insight
            ?? UserProfileInsight::where('user_id', $expiredStep->user_id)
                ->where('subject_id', $expiredStep->subject_id)
                ->latest('generated_at')
                ->first();

        $growthConceptModels = $insight?->growthAreaConcepts()->get() ?? collect();

        $matchedModule = $this->findBestModuleForConcepts($expiredStep->user_id, $expiredStep->subject_id, $growthConceptModels->pluck('id')->all());
        if ($matchedModule) {
            return $this->createModuleStep($matchedModule, $expiredStep->user_id, $expiredStep->subject_id, $insight?->id, $growthConceptModels, $expiredStep->id, GeneratedReason::Expired);
        }

        $growthConcepts = $this->growthConceptsWithMastery($expiredStep->user_id, $growthConceptModels);

        $historyContext = $this->buildHistoryContext($expiredStep);
        $historyBlock = $historyContext ? "\n\n{$historyContext}" : '';

        $prompt = $this->buildPrompt(
            summary: $insight?->summary ?? '',
            profileTitle: $insight?->profile_title ?? '',
            growthConcepts: $growthConcepts,
            context: "The previous task ({$expiredStep->title}) went unanswered and expired — pick a fresh, approachable task.{$historyBlock}",
            subjectName: $expiredStep->subject?->name,
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

    /**
     * Shared with the dashboard's conclusion-card copy selection and the Progress page's "Your
     * Path" timeline, so both surfaces read the same chain the same way — a second, drifted copy
     * of this rule is exactly the kind of duplication the growth-area-concept-grounding note in
     * CLAUDE.md flags as worth avoiding.
     *
     * Dumb, explicit rule (deliberately no AI call): did_try is already a clean discrete field on
     * every reflection in the chain, so a majority-yes (2 of the 3 completed tasks) is enough to
     * read as a "positive/attempted" trend — no need to parse how_it_went free text.
     */
    public function isPositiveReflectionTrend(UserNextStep $concludedStep): bool
    {
        if (!$concludedStep->insight_id || !$concludedStep->concept_id) {
            return false;
        }

        $chainTaskStepIds = UserNextStep::where('insight_id', $concludedStep->insight_id)
            ->where('concept_id', $concludedStep->concept_id)
            ->where('step_type', StepType::Task->value)
            ->pluck('id');

        $triedCount = UserNextStepReflection::whereIn('next_step_id', $chainTaskStepIds)
            ->where('did_try', DidTry::Yes->value)
            ->count();

        return $triedCount >= 2;
    }

    /**
     * True if a UserNextStep has ever been generated for this (user, subject) — used to
     * distinguish "content genuinely ran out" (some step exists, none currently pending/attempted,
     * because RecommendationService::generateRecommendation() returned null on the last regen
     * attempt) from "nothing was ever generated" (e.g. insight recording failed silently). Both
     * DashboardController's Next Experiment card and Collection::getLearningPathProperty() need
     * this same distinction — a stale fallback (frozen roadmap guess / generic next_practice_goal
     * text) is actively misleading once real progress has happened, vs. being a reasonable
     * last-resort when no next-step system ever ran at all.
     */
    public function hasAnyStepEverExisted(int $userId, int $subjectId): bool
    {
        return UserNextStep::where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->exists();
    }

    public function supersedePendingStepsForNewInsight(UserProfileInsight $insight): void
    {
        UserNextStep::where('user_id', $insight->user_id)
            ->where('subject_id', $insight->subject_id)
            ->where('status', NextStepStatus::Pending->value)
            ->update(['status' => NextStepStatus::Superseded->value]);
    }

    /**
     * Finds the best already-existing, already-published module that covers one of the given
     * growth-area concepts, so a next-step can route a user to real content instead of always
     * generating a free-text task. Excludes modules the user already completed, diagnostic
     * modules, and AI-pipeline child/suggested modules (parent_id set) — those aren't meant to
     * be surfaced as generic library recommendations. Ranked in PHP (same style as
     * QuizSelection.php's concept-coverage counting) rather than a raw SQL aggregate — trivial
     * at this app's content-bank scale.
     *
     * Routing preference (Subject Context Dimensions): declared context is the primary sort
     * signal, concept coverage is the secondary tie-breaker — see
     * SubjectContextService::isContextEligible()/contextSpecificity() for the actual rule (a
     * module tagged with an option the user did NOT declare is excluded entirely; among eligible
     * candidates, the more specific match wins, e.g. a declared Assassination Rogue prefers an
     * Assassination-tagged module over a Rogue-tagged one over a context-free one). Deterministic,
     * no AI involvement.
     */
    public function findBestModuleForConcepts(int $userId, int $subjectId, array $conceptIds): ?Module
    {
        if (empty($conceptIds)) {
            return null;
        }

        $completedModuleIds = DB::table('module_user')
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->pluck('module_id');

        $candidates = Module::where('subject_id', $subjectId)
            ->where('published', true)
            ->where('status', 'ready')
            ->where('type', '!=', 'diagnostic')
            ->whereNull('parent_id')
            ->whereNotIn('id', $completedModuleIds)
            ->whereHas('questions.concepts', fn($q) => $q->whereIn('concepts.id', $conceptIds))
            ->with(['questions.concepts:id', 'contextOptions'])
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $declaredOptionIds = $this->subjectContextService->declaredOptionIds($userId, $subjectId);

        $candidates = $candidates->filter(
            fn (Module $module) => $this->subjectContextService->isContextEligible($module, $declaredOptionIds)
        );

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sort(function (Module $a, Module $b) use ($conceptIds, $declaredOptionIds) {
                $specificityA = $this->subjectContextService->contextSpecificity($a, $declaredOptionIds);
                $specificityB = $this->subjectContextService->contextSpecificity($b, $declaredOptionIds);

                if ($specificityA !== $specificityB) {
                    return $specificityB <=> $specificityA;
                }

                $coverage = fn (Module $module) => $module->questions
                    ->flatMap(fn ($q) => $q->concepts->pluck('id'))
                    ->intersect($conceptIds)
                    ->unique()
                    ->count();

                return $coverage($b) <=> $coverage($a);
            })
            ->first();
    }

    /**
     * Whether a user has a completed diagnostic for a subject — used to gate manual next-step
     * assignment. Without a completed diagnostic, $diagnosticProfile is null and the entire
     * "Next Experiment" card (dashboard.blade.php's @if($diagnosticProfile) branch) never renders
     * at all, regardless of whether a UserNextStep row exists — so assigning one anyway would be
     * a silent no-op today (worse: if the user later completes the diagnostic,
     * supersedePendingStepsForNewInsight() would flip the never-seen manual assignment straight
     * to Superseded without it ever having been shown).
     */
    public function hasCompletedDiagnosticForSubject(int $userId, int $subjectId): bool
    {
        return DB::table('module_user')
            ->join('modules', 'modules.id', '=', 'module_user.module_id')
            ->where('module_user.user_id', $userId)
            ->where('modules.subject_id', $subjectId)
            ->where('modules.type', 'diagnostic')
            ->where('module_user.status', 'completed')
            ->exists();
    }

    /**
     * Admin-driven override: directly assigns a specific module as a specific user's active next
     * step, bypassing concept-matching entirely (unlike createModuleStep(), which is only ever
     * reached via findBestModuleForConcepts()). Used by the module-edit dashboard's "Assign as
     * next step" action. Supersedes both pending AND attempted steps for that user+subject — a
     * deliberate override is meant to unambiguously replace whatever was active, unlike the
     * automatic insight-driven supersede (supersedePendingStepsForNewInsight()) which only ever
     * touches pending steps and deliberately leaves an in-progress attempt alone.
     *
     * Callers must check hasCompletedDiagnosticForSubject() first — this method does not enforce
     * it itself, since NextStepService methods elsewhere in this file are called from contexts
     * (reflection/expiry/module-completion regeneration) that legitimately have no diagnostic
     * gate of their own; the gate belongs to this specific admin-facing entry point only.
     *
     * Sends NextStepModuleAssigned to the target user on success — wrapped in try/catch so a mail
     * failure (bad SMTP config, queue down) never breaks the assignment itself, matching the
     * "never let this side effect break the flow it's observing" contract already used by
     * FunnelEvent::log() and RoadmapService::persistStagesForUser() elsewhere in this app.
     */
    public function assignModuleAsNextStep(Module $module, int $targetUserId, ?string $why = null): UserNextStep
    {
        $subjectId = $module->subject_id;

        UserNextStep::where('user_id', $targetUserId)
            ->where('subject_id', $subjectId)
            ->whereIn('status', [NextStepStatus::Pending->value, NextStepStatus::Attempted->value])
            ->update(['status' => NextStepStatus::Superseded->value]);

        $insightId = UserProfileInsight::where('user_id', $targetUserId)
            ->where('subject_id', $subjectId)
            ->latest('generated_at')
            ->value('id');

        $step = UserNextStep::create([
            'user_id'          => $targetUserId,
            'module_id'        => $module->id,
            'subject_id'       => $subjectId,
            'insight_id'       => $insightId,
            'concept_id'       => null,
            'previous_step_id' => null,
            'step_type'        => StepType::Module,
            'status'           => NextStepStatus::Pending,
            'generated_reason' => GeneratedReason::ManualAssignment,
            'title'            => $module->name,
            'instructions'     => $why ?: 'Recommended for you — take a look when you have a chance.',
        ]);

        try {
            $targetUser = $step->user;
            if ($targetUser?->email) {
                \Illuminate\Support\Facades\Mail::to($targetUser->email)
                    ->queue(new \App\Mail\NextStepModuleAssigned($step));
            }
        } catch (\Throwable $e) {
            Log::error('NextStepService: failed to send NextStepModuleAssigned email', [
                'step_id' => $step->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $step;
    }

    /**
     * Builds the UserNextStep for a matched module — no AI call, unlike the free-text task path.
     * The title/instructions are deterministic since we already know exactly which real content
     * closes the gap; the AI is only needed when no real content exists yet.
     */
    private function createModuleStep(Module $module, int $userId, int $subjectId, ?int $insightId, $growthConceptModels, ?int $previousStepId, GeneratedReason $reason): UserNextStep
    {
        $coveredConceptIds = $module->questions->flatMap(fn($q) => $q->concepts->pluck('id'))->unique();
        $matchedConcept = $growthConceptModels->first(fn($c) => $coveredConceptIds->contains($c->id));

        $instructions = $matchedConcept
            ? "This module covers \"{$matchedConcept->name}\" — one of your current growth areas. Complete it to work on this directly."
            : 'This module covers one of your current growth areas.';

        return UserNextStep::create([
            'user_id'           => $userId,
            'module_id'         => $module->id,
            'subject_id'        => $subjectId,
            'insight_id'        => $insightId,
            'concept_id'        => $matchedConcept?->id,
            'previous_step_id'  => $previousStepId,
            'step_type'         => StepType::Module,
            'status'            => NextStepStatus::Pending,
            'generated_reason'  => $reason,
            'title'             => $module->name,
            'instructions'      => $instructions,
        ]);
    }

    /**
     * Checks whether a Module-type step's target module has since been completed, and if so
     * marks it complete and generates the next step. No-op for Task-type steps or steps that
     * aren't currently pending/attempted — cheap enough to call on every dashboard load.
     *
     * Uses an atomic conditional UPDATE rather than read-then-write to avoid a double-regeneration
     * race when this fires from two concurrent requests (multi-tab, prefetch) against the same
     * just-completed step — only the request that wins the UPDATE proceeds to regenerate.
     */
    public function checkAndCompleteModuleStep(UserNextStep $step): UserNextStep
    {
        if ($step->step_type !== StepType::Module || !$step->module_id) {
            return $step;
        }

        if (!in_array($step->status, [NextStepStatus::Pending, NextStepStatus::Attempted], true)) {
            return $step;
        }

        $isCompleted = DB::table('module_user')
            ->where('user_id', $step->user_id)
            ->where('module_id', $step->module_id)
            ->where('status', 'completed')
            ->exists();

        if (!$isCompleted) {
            return $step;
        }

        $claimed = UserNextStep::where('id', $step->id)
            ->whereIn('status', [NextStepStatus::Pending->value, NextStepStatus::Attempted->value])
            ->update(['status' => NextStepStatus::Completed->value, 'completed_at' => now()]);

        if ($claimed === 0) {
            // Another concurrent request already claimed and handled this transition.
            return $step->fresh();
        }

        $step = $step->fresh();

        try {
            // Return the freshly generated replacement, not the now-completed step, so the
            // dashboard immediately shows the next thing rather than a dead "Start Module" link.
            return $this->generateRecommendationAfterModuleCompletion($step);
        } catch (\Throwable $e) {
            Log::error('NextStepService: failed to regenerate after module completion', [
                'step_id' => $step->id,
                'error'   => $e->getMessage(),
            ]);

            return $step;
        }
    }

    /**
     * RecommendationService-backed counterpart to regenerateAfterModuleCompletion() below — used
     * by checkAndCompleteModuleStep() instead of the old method so completing a recommended module
     * always leads to another concept-then-module recommendation, never a free-text task fallback.
     * regenerateAfterModuleCompletion() itself is untouched and still available for future use.
     */
    public function generateRecommendationAfterModuleCompletion(UserNextStep $completedStep): UserNextStep
    {
        $insight = $this->resolveInsightForStep($completedStep);
        if (!$insight) {
            return $completedStep;
        }

        return $this->recommendationService->generateRecommendation($insight, $completedStep, GeneratedReason::ModuleCompleted)
            ?? $completedStep;
    }

    /**
     * RecommendationService-backed counterpart to regenerateAfterExpiry() below — used by the
     * next-steps:expire command instead of the old method for the same reason as its
     * module-completion counterpart above. regenerateAfterExpiry() itself is untouched.
     */
    public function generateRecommendationAfterExpiry(UserNextStep $expiredStep): UserNextStep
    {
        $insight = $this->resolveInsightForStep($expiredStep);
        if (!$insight) {
            return $expiredStep;
        }

        return $this->recommendationService->generateRecommendation($insight, $expiredStep, GeneratedReason::Expired)
            ?? $expiredStep;
    }

    /**
     * Shared insight-resolution fallback for the two RecommendationService-backed methods above —
     * mirrors the same `$step->insight ?? latest insight for user+subject` pattern already inlined
     * 3x elsewhere in this file (regenerateAfterReflection/regenerateAfterExpiry/
     * regenerateAfterModuleCompletion), which are left with their own copies untouched.
     */
    private function resolveInsightForStep(UserNextStep $step): ?UserProfileInsight
    {
        return $step->insight
            ?? UserProfileInsight::where('user_id', $step->user_id)
                ->where('subject_id', $step->subject_id)
                ->latest('generated_at')
                ->first();
    }

    private function regenerateAfterModuleCompletion(UserNextStep $completedStep): UserNextStep
    {
        $insight = $completedStep->insight
            ?? UserProfileInsight::where('user_id', $completedStep->user_id)
                ->where('subject_id', $completedStep->subject_id)
                ->latest('generated_at')
                ->first();

        $growthConceptModels = $insight?->growthAreaConcepts()->get() ?? collect();

        $matchedModule = $this->findBestModuleForConcepts($completedStep->user_id, $completedStep->subject_id, $growthConceptModels->pluck('id')->all());
        if ($matchedModule) {
            return $this->createModuleStep($matchedModule, $completedStep->user_id, $completedStep->subject_id, $insight?->id, $growthConceptModels, $completedStep->id, GeneratedReason::ModuleCompleted);
        }

        $growthConcepts = $this->growthConceptsWithMastery($completedStep->user_id, $growthConceptModels);

        $historyContext = $this->buildHistoryContext($completedStep);
        $historyBlock = $historyContext ? "\n\n{$historyContext}" : '';

        $prompt = $this->buildPrompt(
            summary: $insight?->summary ?? '',
            profileTitle: $insight?->profile_title ?? '',
            growthConcepts: $growthConcepts,
            context: "The user just completed the module \"{$completedStep->title}\" — pick a fresh task or the next best thing to focus on.{$historyBlock}",
            subjectName: $completedStep->subject?->name,
        );

        [$title, $instructions, $conceptId] = $this->callAndGround(
            $prompt,
            $completedStep->user_id,
            $completedStep->subject_id,
            'next_step_generation_module_completed'
        );

        return UserNextStep::create([
            'user_id'           => $completedStep->user_id,
            'module_id'         => null,
            'subject_id'        => $completedStep->subject_id,
            'insight_id'        => $insight?->id,
            'concept_id'        => $conceptId,
            'previous_step_id'  => $completedStep->id,
            'step_type'         => StepType::Task,
            'status'            => NextStepStatus::Pending,
            'generated_reason'  => GeneratedReason::ModuleCompleted,
            'title'             => $title,
            'instructions'      => $instructions,
        ]);
    }

    /**
     * Walks back the previous_step_id chain so the AI sees more than just the single
     * immediately-previous reflection in isolation — without this, a repeating failure pattern
     * across two or more tasks (e.g. "narrowing focus keeps causing me to miss everything else")
     * is invisible to each new generation call, and the AI keeps proposing cosmetically different
     * versions of the same approach the user already reported doesn't work.
     */
    private function buildHistoryContext(UserNextStep $step, int $maxSteps = 4): string
    {
        $lines = [];
        $current = $step->previousStep;
        $depth = 0;

        while ($current && $depth < $maxSteps) {
            if ($current->step_type === StepType::Task && $current->reflection) {
                $r = $current->reflection;
                $lines[] = "- Task \"{$current->title}\" — tried: {$r->did_try->value}. How it went: \"{$r->how_it_went}\". Their reasoning: \"{$r->why_reasoning}\".";
            } elseif ($current->step_type === StepType::Module && $current->status === NextStepStatus::Completed) {
                $lines[] = "- Completed module \"{$current->title}\".";
            }

            $current = $current->previousStep;
            $depth++;
        }

        if (empty($lines)) {
            return '';
        }

        // Oldest first so the AI reads it as a timeline, not a stack.
        $timeline = implode("\n", array_reverse($lines));

        return <<<HIST
RECENT HISTORY (oldest to newest — look for a repeating pattern, e.g. the same kind of task
failing or producing the same complaint more than once; if you see one, do not propose another
task in that same style)
{$timeline}
HIST;
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

    private function buildPrompt(string $summary, string $profileTitle, $growthConcepts, ?string $context, ?string $subjectName = null): string
    {
        $conceptsBlock = $growthConcepts->isNotEmpty()
            ? $growthConcepts->map(fn($c) => "  {$c}")->implode("\n")
            : '  (no growth-area concepts recorded yet)';

        $contextBlock = $context ? "\n\nADDITIONAL CONTEXT\n{$context}" : '';
        $subjectLine  = $subjectName ? "\nSUBJECT: {$subjectName}" : '';

        return <<<PROMPT
You generate one concrete practice task for a learner, based on their current profile. Use ONLY the information below — do not assume any game, sport, or domain-specific detail not present here.
{$subjectLine}

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
5. If ADDITIONAL CONTEXT includes a RECENT HISTORY section, read it for repeating patterns before writing the task. If the same kind of task has already failed, or the learner reported the same complaint more than once, the new task must take a meaningfully different approach — not a reworded or narrower version of what already didn't work.

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
            $response = $this->aiService->sendPromptToAi($prompt, 'gpt-4.1-mini', $userId, $purpose);
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
