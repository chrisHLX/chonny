<?php

namespace App\Console\Commands;

use App\Enums\NextStepStatus;
use App\Http\Services\NextStepService;
use App\Models\UserNextStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireNextSteps extends Command
{
    protected $signature = 'next-steps:expire';

    protected $description = 'Flags stale pending/attempted next-steps as expired and triggers lightweight regeneration.';

    public function handle(NextStepService $nextStepService): int
    {
        $cutoff = now()->subDays(14);

        $stale = UserNextStep::whereIn('status', [NextStepStatus::Pending->value, NextStepStatus::Attempted->value])
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $step) {
            $step->update(['status' => NextStepStatus::Expired]);

            try {
                $nextStepService->regenerateAfterExpiry($step);
            } catch (\Throwable $e) {
                Log::error("ExpireNextSteps: regeneration failed for step #{$step->id} — {$e->getMessage()}");
            }
        }

        $this->info("Expired {$stale->count()} next-step(s).");

        return self::SUCCESS;
    }
}
