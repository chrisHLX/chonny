<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Http\Services\RoundAnalysisService;
use App\Models\ArenaRound;
use Illuminate\Console\Command;

/**
 * Each player's buttons in the stored rounds (`players[].kit`, the Watch page's arena frames and the
 * Moments' windows), worked out again from the talents the round kept (`combatants`) (2026-10-28).
 * No raw log and no re-send: run it after a change to how talents resolve.
 *
 *   php artisan wow:refresh-kits            # every round that has a kit
 *   php artisan wow:refresh-kits --dry-run  # count what would change
 *
 * Written for the choice-node fix of 28 Oct: a Frost Mage with Glacial Bulwark was read as Cold Snap,
 * so the frames showed one Ice Block where there were two.
 */
class RefreshKits extends Command
{
    protected $signature = 'wow:refresh-kits {--user= : Only this user\'s rounds} {--dry-run : Count the changes, write nothing}';

    protected $description = 'Work out each player\'s buttons in stored rounds again from the talents they kept';

    public function handle(RoundAnalysisService $analysis, ArenaLogService $arena): int
    {
        [$seen, $changed] = [0, 0];
        ArenaRound::query()
            ->when($this->option('user'), fn ($q, $user) => $q->where('user_id', (int) $user))
            ->select(['id', 'payload'])
            ->chunkById(25, function ($rounds) use ($analysis, $arena, &$seen, &$changed) {
                foreach ($rounds as $round) {
                    $p = is_string($round->payload) ? json_decode($round->payload, true) : (array) $round->payload;
                    $dirty = false;
                    foreach ($p['analysis']['players'] ?? [] as $i => $pl) {
                        $combatant = $p['combatants'][$pl['guid']] ?? null;
                        $spec = $arena->specForExternalId((string) ($pl['specExternalId'] ?? ''));
                        if (! is_array($pl['kit'] ?? null) || ! $combatant || ! $spec) {
                            continue;
                        }
                        $seen++;
                        $kit = $analysis->kitFor($combatant, $spec);
                        if ($kit != $pl['kit']) {   // loose: a stored 240 is a fresh 240.0
                            $p['analysis']['players'][$i]['kit'] = $kit;
                            $dirty = true;
                            $changed++;
                        }
                    }
                    if ($dirty && ! $this->option('dry-run')) {
                        $round->payload = $p;
                        $round->save();
                    }
                }
            });

        $this->info("{$seen} players' buttons read again, {$changed} changed".($this->option('dry-run') ? ' (dry run: nothing written)' : '').'.');

        return self::SUCCESS;
    }
}
