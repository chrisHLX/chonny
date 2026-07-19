<?php

namespace App\Http\Services;

use App\Models\Module;
use App\Models\SubjectContextDimension;
use App\Models\SubjectContextOption;
use App\Models\UserSubjectContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Player-declared context (class, race, role, ...) — see the "context, never inference" design
 * principle in CLAUDE.md. This service is the only place that writes to user_subject_context;
 * always go through declare() rather than creating/updating rows directly, so the
 * cascade-clear-children rule below is never bypassed.
 */
class SubjectContextService
{
    /**
     * Declares (or replaces) a user's option for a dimension. Validates the option actually
     * belongs to the given dimension — the DB's unique(user_id, dimension_id) constraint alone
     * can't catch a mismatched option/dimension pair, only a duplicate dimension declaration.
     *
     * Replacing a parent dimension's declaration clears every descendant dimension's declaration
     * (changing Class clears Spec) — a stale Spec left over from a different Class would be
     * meaningless at best, actively wrong at worst.
     */
    public function declare(int $userId, int $dimensionId, int $optionId): UserSubjectContext
    {
        $option = SubjectContextOption::findOrFail($optionId);

        if ($option->dimension_id !== $dimensionId) {
            throw new InvalidArgumentException("Option {$optionId} does not belong to dimension {$dimensionId}.");
        }

        $declaration = UserSubjectContext::updateOrCreate(
            ['user_id' => $userId, 'dimension_id' => $dimensionId],
            ['subject_context_option_id' => $optionId]
        );

        $this->clearDescendantDeclarations($userId, $dimensionId);

        return $declaration;
    }

    /**
     * True when the user has declared every dimension marked required=true for this subject —
     * used by Collection::getLearningPathProperty() to decide when the context milestone is
     * complete. A subject with zero dimensions always returns false here; that's fine, the
     * milestone is omitted entirely for such subjects (see RoadmapService::resolveStages()), so
     * this method never gets asked the question in that case.
     */
    public function hasDeclaredAllRequiredDimensions(int $userId, int $subjectId): bool
    {
        $requiredDimensionIds = SubjectContextDimension::where('subject_id', $subjectId)
            ->where('required', true)
            ->pluck('id');

        if ($requiredDimensionIds->isEmpty()) {
            return false;
        }

        $declaredCount = UserSubjectContext::where('user_id', $userId)
            ->whereIn('dimension_id', $requiredDimensionIds)
            ->count();

        return $declaredCount === $requiredDimensionIds->count();
    }

    /**
     * @return Collection<int, UserSubjectContext> the user's current declarations for a subject, keyed by dimension_id
     */
    public function declarationsForSubject(int $userId, int $subjectId): Collection
    {
        $dimensionIds = SubjectContextDimension::where('subject_id', $subjectId)->pluck('id');

        return UserSubjectContext::where('user_id', $userId)
            ->whereIn('dimension_id', $dimensionIds)
            ->with('option')
            ->get()
            ->keyBy('dimension_id');
    }

    /**
     * @return \Illuminate\Support\Collection<int, int> option IDs the user has declared across every dimension of this subject
     */
    public function declaredOptionIds(int $userId, int $subjectId): Collection
    {
        return $this->declarationsForSubject($userId, $subjectId)
            ->pluck('subject_context_option_id')
            ->values();
    }

    /**
     * The routing preference — shared by NextStepService::findBestModuleForConcepts() and
     * RecommendationService::findModuleForConcept() so a module is filtered/ranked by declared
     * context identically regardless of which path generated the recommendation. $module must
     * have contextOptions eager-loaded; $declaredOptionIds should be fetched once per call site
     * via declaredOptionIds() and passed in, not re-fetched per module (this runs inside sort
     * comparators, which get called many times).
     *
     * A module with zero context tags is context-free — always eligible, for every user,
     * declared or not. A module with at least one context tag is eligible only if the user
     * declared at least one of the options it's tagged with (a Mage module must never reach a
     * declared Rogue; an undeclared user only ever receives context-free modules, since an empty
     * declared set can never intersect a non-empty tag set).
     */
    public function isContextEligible(Module $module, Collection $declaredOptionIds): bool
    {
        $moduleOptionIds = $module->contextOptions->pluck('id');

        if ($moduleOptionIds->isEmpty()) {
            return true;
        }

        return $moduleOptionIds->intersect($declaredOptionIds)->isNotEmpty();
    }

    /**
     * Specificity = depth of the deepest declared option this module is tagged with (e.g. a
     * declared Assassination Rogue: an Assassination-tagged module scores 1, a Rogue-tagged
     * module scores 0, a context-free module scores -1 — deeper/more specific wins). Only
     * meaningful for modules that already passed isContextEligible(); call after filtering, not
     * instead of it.
     */
    public function contextSpecificity(Module $module, Collection $declaredOptionIds): int
    {
        $matchedOptions = $module->contextOptions->filter(fn (SubjectContextOption $option) => $declaredOptionIds->contains($option->id));

        if ($matchedOptions->isEmpty()) {
            return -1; // context-free (or, post-filter, this shouldn't happen)
        }

        return $matchedOptions->max(fn (SubjectContextOption $option) => $option->depth());
    }

    /**
     * Walks the full parent_dimension_id chain (children, grandchildren, ...) rather than
     * hardcoding "one level" — the plan's hierarchy is only 2 levels deep today (Class → Spec),
     * but this stays correct if a deeper one is ever seeded.
     */
    private function clearDescendantDeclarations(int $userId, int $parentDimensionId): void
    {
        $childDimensionIds = SubjectContextDimension::where('parent_dimension_id', $parentDimensionId)->pluck('id');

        if ($childDimensionIds->isEmpty()) {
            return;
        }

        UserSubjectContext::where('user_id', $userId)->whereIn('dimension_id', $childDimensionIds)->delete();

        foreach ($childDimensionIds as $childId) {
            $this->clearDescendantDeclarations($userId, $childId);
        }
    }
}
