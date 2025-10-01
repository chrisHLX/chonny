<?php
namespace App\Listeners;

use App\Events\ModuleAttemptSubmitted;
use App\Http\Services\VersioningService;
use App\Jobs\GenerateNewVersionJob;
use Illuminate\Support\Facades\DB;

class HandleModuleAttempt
{
    public function handle(ModuleAttemptSubmitted $event)
    {
        $attempt = $event->attempt;
        $module = $attempt->module;
        $wrongQuestions = $attempt->wrong_questions ?? []; // Normalize: sort for consistency
        sort($wrongQuestions);
        $wrongQuestionsJson = json_encode($wrongQuestions);
        $parentModuleId = $module->parent_module_id ?? $module->id;
        $baseVersion = $module->version; // e.g., 'V2'

        // Step 1: Check for existing module with matching wrong questions
        $existingModule = $this->findMatchingModule($parentModuleId, $wrongQuestionsJson);
        if ($existingModule) {
            // Optionally, assign as next task for user
            return;
        }

        // Step 2: Check attempt count or unique wrong set
        $attemptCount = $module->attempts()->where('user_id', $attempt->user_id)->count();
        $isUniqueWrongSet = $this->isUniqueWrongSet($parentModuleId, $wrongQuestionsJson);

        // Step 3: Infinite loop safeguard - check branch failures
        $branchFailures = $this->countBranchFailures($parentModuleId, $baseVersion);
        $shouldEscalate = $branchFailures >= 3; // e.g., V2A1, V2A2, V2A3 → escalate to V3

        if ($attemptCount >= 3 || $isUniqueWrongSet || $shouldEscalate) {
            $latestVersion = $module->latest_version;
            if ($shouldEscalate) {
                // Escalate to next major version
                $newVersion = VersioningService::next($baseVersion, null); // null triggers major increment
            } else {
                $newVersion = VersioningService::next($baseVersion, $latestVersion);
            }
            GenerateNewVersionJob::dispatch($module, $newVersion, $wrongQuestions);
        }
    }

    private function findMatchingModule($parentModuleId, $wrongQuestionsJson)
    {
        return Module::whereIn('id', function ($query) use ($parentModuleId, $wrongQuestionsJson) {
            $query->select('module_id')
                ->from('user_module_history')
                ->where('parent_module_id', $parentModuleId)
                ->where('wrong_questions', $wrongQuestionsJson);
        })->first();
    }

    private function isUniqueWrongSet($parentModuleId, $wrongQuestionsJson)
    {
        return !DB::table('user_module_history')
            ->where('parent_module_id', $parentModuleId)
            ->where('wrong_questions', $wrongQuestionsJson)
            ->exists();
    }

    private function countBranchFailures($parentModuleId, $baseVersion)
    {
        return DB::table('user_module_history')
            ->where('parent_module_id', $parentModuleId)
            ->whereRaw('module_id IN (SELECT id FROM user_modules WHERE version LIKE ?)', ["{$baseVersion}%"])
            ->count();
    }
}