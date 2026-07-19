<?php

namespace App\Console\Commands;

use App\Http\Services\RoadmapService;
use App\Models\Module;
use App\Models\UserProfileInsight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time backfill for users who completed a diagnostic before the Learning Path feature
 * shipped (2026-07-19) — RoadmapService::persistStagesForUser() is only ever called from the
 * two live completion hooks (DiagnosticQuizRunner::recordProfileInsight(),
 * RegisteredUserController::claimGuestQuizResults()), so it never runs retroactively on its own.
 *
 * Sourced from module_user.diagnostic_profile directly (not UserProfileInsight) because that
 * column has existed on every diagnostic completion since the diagnostic system itself, whereas
 * UserProfileInsight is a later addition (2026-07-08) — some older completions may predate it
 * and have no insight row at all. insight_id is attached on a best-effort basis where a matching
 * UserProfileInsight does exist, purely for the FK reference; RoadmapService doesn't require it.
 *
 * Safe to re-run: persistStagesForUser() replaces (not appends) a user's stages per subject.
 */
class BackfillLearningPaths extends Command
{
    protected $signature = 'roadmap:backfill';

    protected $description = 'Persists Learning Path stages for users who completed a diagnostic before this feature existed.';

    public function handle(RoadmapService $roadmapService): int
    {
        $rows = DB::table('module_user')
            ->join('modules', 'modules.id', '=', 'module_user.module_id')
            ->where('modules.type', 'diagnostic')
            ->whereNotNull('module_user.diagnostic_profile')
            ->select('module_user.user_id', 'module_user.module_id', 'module_user.diagnostic_profile')
            ->get();

        $processed = 0;
        $skipped   = 0;

        foreach ($rows as $row) {
            $profile = json_decode($row->diagnostic_profile, true);
            $module  = Module::with('subject')->find($row->module_id);

            if (!$profile || !$module || !$module->subject_id) {
                $skipped++;
                continue;
            }

            $insightId = UserProfileInsight::where('user_id', $row->user_id)
                ->where('module_id', $row->module_id)
                ->value('id');

            try {
                $roadmapService->persistStagesForUser($row->user_id, $module, $profile, [], $insightId);
                $processed++;
            } catch (\Throwable $e) {
                Log::error("BackfillLearningPaths: failed for user #{$row->user_id}, module #{$row->module_id} — {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info("Backfilled {$processed} learning path(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
