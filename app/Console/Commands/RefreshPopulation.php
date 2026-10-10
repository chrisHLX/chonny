<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The website builds its own norms (2026-10-11): every uploaded round (wow:pool-export) and the
 * archive's games, uploaded once as a bundle (wow:population --bundle / --archive), into the live
 * copy every page reads when it is the newer one (BuildPopulation::normsPath()).
 *
 *   php -d memory_limit=1G artisan wow:population-refresh
 *
 * Runs on its own after uploads (RefreshPopulationNorms, queued from /api/coach/done) and at the end
 * of every deploy. About 10 seconds and 150 MB for a thousand games: the export reads the stored
 * rounds, the aggregate reads the compact games.
 */
class RefreshPopulation extends Command
{
    protected $signature = 'wow:population-refresh';

    protected $description = 'Rebuild the live norms from the archive bundle and every uploaded round';

    public function handle(): int
    {
        $pool = storage_path('app/pool-export.jsonl.gz');
        if ($this->call('wow:pool-export', ['--out' => $pool]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('wow:population', ['--aggregate' => true, '--pool' => $pool, '--live' => true]);
    }
}
