<?php

namespace App\Console\Commands;

use App\Http\Services\RoundAnalysisService;
use App\Models\ArenaRound;
use App\Support\PopulationGame;
use Illuminate\Console\Command;

/**
 * The website's pool (2026-10-10): every round players have uploaded, in the population's shape,
 * for wow:population on the developer's PC to build the norms from the archive and the uploads
 * together. Every stored round feeds it by default (Chriso, 2026-10-10).
 *
 *   php -d memory_limit=1G artisan wow:pool-export      # on the server
 *   -> storage/app/pool-export.jsonl.gz, one game per line
 *
 * Then, on the PC: fetch the file and `wow:population --aggregate --pool=FILE`, commit norms.json,
 * deploy (match-review.md, "The website's pool").
 *
 * Only what the aggregate needs leaves the server: players under the population's salted hash, no
 * names, no raw log (the server keeps none). A match uploaded by two players is one game: the first
 * upload of it is kept. Rounds measured at an older version are left out until their owner's app
 * sends them again, which it does when the version goes up.
 */
class ExportPopulationPool extends Command
{
    protected $signature = 'wow:pool-export {--out= : Where to write (default storage/app/pool-export.jsonl.gz)}';

    protected $description = "Write every uploaded round in the population's shape, for wow:population --pool";

    public function handle(): int
    {
        $out = $this->option('out') ?: storage_path('app/pool-export.jsonl.gz');
        $tmp = $out.'.tmp';
        $gz = gzopen($tmp, 'wb6');
        $seen = [];
        $written = 0;
        $older = 0;

        // Players who opted out on Your games (users.pool_opt_out_at) are left out entirely.
        $optedOut = \App\Models\User::whereNotNull('pool_opt_out_at')->pluck('id')->all();

        // The payload is read once per round: each `$r->payload[...] ?? x` decodes the whole JSON
        // twice (__isset, then __get), so the two reads this used to make cost four decodes of
        // ~180 KB a round. That took the export from 14s to 42s on 3,164 rounds (2026-11-26), and
        // RefreshPopulationNorms past its 85s limit whenever a page build shared the CPU.
        ArenaRound::query()->select(['id', 'match_id', 'payload'])->whereNotIn('user_id', $optedOut)->orderBy('id')->chunkById(25, function ($rounds) use ($gz, &$seen, &$written, &$older) {
            foreach ($rounds as $r) {
                $p = $r->payload ?: [];
                $a = $p['analysis'] ?? null;
                $meta = $p['metadata'] ?? null;
                if (! is_array($a) || ! is_array($meta) || ($a['version'] ?? 0) !== RoundAnalysisService::VERSION) {
                    $older++;

                    continue;
                }
                if (isset($seen[$r->match_id])) {
                    continue;
                }
                $seen[$r->match_id] = true;
                gzwrite($gz, json_encode(PopulationGame::compact($r->match_id, $a, $meta))."\n");
                $written++;
            }
        });

        gzclose($gz);
        rename($tmp, $out);
        $this->info("{$written} game(s) written to {$out}; {$older} round(s) measured at an older version left out.");

        return self::SUCCESS;
    }
}
