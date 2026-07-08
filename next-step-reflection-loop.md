# Next Step + Reflection Loop — How It Works

Second, faster cadence on top of the diagnostic profile. The diagnostic produces a profile roughly once per subject (slow cadence); this system gives the user one concrete practice task at a time and reinterprets it after they report back (fast cadence, re-triggered by reflection or by expiry).

Built on another machine, code pulled and verified here on 2026-07-08. See `## Next Step + Reflection Loop ✓ COMPLETE` in `CLAUDE.md` for the canonical reference — this file is the build/verification narrative behind that entry.

## What was built

5 tables, 4 models, 4 enums, 1 service, 1 job, 1 command, dashboard + `DiagnosticQuizRunner` integration:

- `user_profile_insights` (`UserProfileInsight`) / `user_profile_insight_concepts` (pivot)
- `user_next_steps` (`UserNextStep`)
- `user_next_step_reflections` (`UserNextStepReflection`)
- `user_reflection_evidence` (`UserReflectionEvidence`)
- `StepType`, `NextStepStatus`, `GeneratedReason`, `DidTry` enums
- `NextStepService`, `InterpretReflectionJob`, `ExpireNextSteps` command
- Integration points: `DiagnosticQuizRunner::completeModule()`, `DashboardController::index()`, `current-focus.blade.php`, `next-experiment.blade.php`, new `NextStepReflection` Livewire component

`QuizRunner.php` and `AiService.php` were **not** touched.

## Flow

```
DiagnosticQuizRunner::completeModule()
  → creates UserProfileInsight (subject_id = completed module's subject_id)
  → NextStepService::supersedePendingStepsForNewInsight()   [same tx]
      flips any still-pending UserNextStep for (user_id, subject_id) → superseded
  → NextStepService::generateInitial()                       [outside tx, AI call]
      writes first UserNextStep (status=pending, generated_reason=initial)

Dashboard → next-experiment.blade.php
  ← DashboardController's $activeNextStep (subject-scoped, step_type=task, status in pending|attempted)

User submits reflection (NextStepReflection Livewire)
  → UserNextStepReflection created
  → step flipped to `attempted`
  → InterpretReflectionJob dispatched

InterpretReflectionJob
  → AI call (purpose: reflection_interpretation)
  → writes UserReflectionEvidence rows (confidence clamped to [0, 0.4])
  → step flipped to `completed`
  → NextStepService::regenerateAfterReflection() → next UserNextStep
      (generated_reason=reflection, previous_step_id chains back)

next-steps:expire (scheduled daily via routes/console.php)
  → any pending/attempted step older than 14 days → expired
  → NextStepService::regenerateAfterExpiry() → fresh UserNextStep
      (generated_reason=expired, previous_step_id chains back)
```

## Verification checklist (run 2026-07-08, code read directly — not trusted from summary)

Four questions were used to stress-test the areas most likely to silently regress:

1. **Subject scoping** — does the "supersede stale pending steps" query scope to the *same* `subject_id` as the new insight, or repeat the "most recent regardless of subject" bug class documented elsewhere in `CLAUDE.md`?
   **Verified correct.** `supersedePendingStepsForNewInsight()` filters by `user_id` AND `subject_id`. Every `UserNextStep::create()` call site propagates `subject_id` from the originating insight/step rather than re-deriving it. `subject_id` is a NOT-NULL FK on both new tables.

2. **Confidence cap** — is the 0.4 cap on reflection-derived confidence a real code clamp, or just a prompt instruction the model could ignore?
   **Verified real clamp.** `InterpretReflectionJob::MAX_CONFIDENCE = 0.4`; every evidence item passes through `min(...)` then `max($confidence, 0)` before being written, independent of what the model returns.

3. **Expiry sweep** — does `ExpireNextSteps` generate a replacement task, or just flip status and leave the dashboard empty?
   **Verified it regenerates.** Calls `NextStepService::regenerateAfterExpiry()` per expired step, wrapped in try/catch so one AI failure doesn't block the rest of the batch. Also confirmed `Schedule::command('next-steps:expire')->daily()` is registered in `routes/console.php` (requires the Laravel scheduler cron to be live on the server).

4. **Title binding fix** — the original bug had `current-focus.blade.php`'s title bound to `archetype_key` (identity) while the body pulled `likely_in_game_pattern` (also identity-flavored), creating an identity/weakness mismatch. Does the new `growth_area_pattern` field fix the *title's* data source, or just add a field alongside a still-broken title?
   **Verified actually fixed.** Title (`$growthAreaName`) now binds to `primary_growth_area.name`; body (`$displayPattern`) prefers `growth_area_pattern` over `likely_in_game_pattern`, falling back to the latter only for pre-migration profiles. `DiagnosticProfileService`'s prompt explicitly forbids reusing `likely_in_game_pattern` text for `growth_area_pattern`.

**Bad-data check:** since migrations were already live before this verification, ran a direct `SELECT COUNT(*)` against all 4 new tables to rule out rows written before the fixes landed. All four were empty (0 rows) — nothing to clean up.

## Known gaps (not blocking, not yet built)

- `step_type` values `module` and `knowledge_check` exist in the enum but have no generation or rendering path — only `task` is implemented.
- `GeneratedReason::InsightChange` exists in the enum but nothing currently sets it — a new insight supersedes old steps, but the replacement step generated afterward uses `initial`/`reflection`, not `insight_change`.
