<?php

namespace App\Console\Commands;

use App\Http\Services\GuestPlanService;
use Illuminate\Console\Command;

/**
 * Delete guest plans nobody has opened in GuestPlanService::KEEP_DAYS. The same cleanup already
 * runs whenever a visitor starts a plan (production has no scheduler); this is the manual lever.
 */
class PruneGuestPlans extends Command
{
    protected $signature = 'guides:prune-guest-plans';

    protected $description = 'Delete guest plans (made without an account) that nobody has opened in a week';

    public function handle(GuestPlanService $plans): int
    {
        $total = 0;

        do {
            $removed = $plans->pruneStale();
            $total += $removed;
        } while ($removed > 0);

        $this->info("Removed {$total} guest plan(s).");

        return self::SUCCESS;
    }
}
