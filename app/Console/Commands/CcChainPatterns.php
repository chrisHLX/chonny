<?php

namespace App\Console\Commands;

use App\Http\Services\CcChainStatsService;
use Illuminate\Console\Command;

/**
 * Reports the two real-data signals CcChainStatsService computes over the CC chain corpus
 * (data/arena-logs/cc-chains/*.json) — opener frequency and step-transition frequency. See
 * CcChainStatsService's own docblock for the full reasoning and the collapsed-repeat-steps
 * methodology shared with wow:cc-formula, which consumes the same service to ground its
 * opener/sequencing rules in this data instead of assumption (added 2026-08-23 after duration-
 * based opener selection was shown to disagree with real play for Freezing Trap vs
 * Intimidation).
 *
 * Report-only, like wow:find-cc-duration — writes nothing.
 *
 * Usage: php artisan wow:cc-chain-patterns [--min-count=5]
 */
class CcChainPatterns extends Command
{
    protected $signature = 'wow:cc-chain-patterns {--min-count=5 : Minimum real occurrences before an ability/transition is reported}';

    protected $description = 'Report real opener-frequency and step-transition-frequency stats from the CC chain corpus';

    public function handle(CcChainStatsService $stats): int
    {
        $files = glob(base_path('data/arena-logs/cc-chains/*/*.json'));

        if ($files === []) {
            $this->error('No cc-chains JSON files found — run wow:find-cc-chains --json first.');

            return self::FAILURE;
        }

        $minCount = (int) $this->option('min-count');

        $this->reportOpenerFrequency($stats->openerRates($minCount), $stats);
        $this->reportTransitionFrequency($stats->transitionRates($minCount));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, float>  $rates
     */
    private function reportOpenerFrequency(array $rates, CcChainStatsService $stats): void
    {
        // Recompute raw counts alongside the rate purely for display context (total chains /
        // times opener) — the rate alone doesn't tell a reader whether it's backed by 5 or 500
        // real instances.
        $counts = [];

        foreach ($stats->collapsedChains() as $steps) {
            $seenInThisChain = [];

            foreach ($steps as $i => $step) {
                $name = $step['name'];

                if (!isset($seenInThisChain[$name])) {
                    $counts[$name]['total'] = ($counts[$name]['total'] ?? 0) + 1;
                    $seenInThisChain[$name] = true;
                }

                if ($i === 0) {
                    $counts[$name]['opener'] = ($counts[$name]['opener'] ?? 0) + 1;
                }
            }
        }

        $rows = [];

        foreach ($rates as $name => $rate) {
            $rows[] = [$name, $counts[$name]['total'] ?? 0, $counts[$name]['opener'] ?? 0, round($rate * 100, 1)];
        }

        usort($rows, fn ($a, $b) => $b[3] <=> $a[3]);

        $this->newLine();
        $this->info('=== Opener frequency ===');
        $this->table(
            ['Ability', 'Total chains', 'Times opener', 'Opener rate'],
            array_map(fn ($r) => [$r[0], $r[1], $r[2], $r[3].'%'], $rows)
        );
    }

    /**
     * @param  array<string, array<string, float>>  $rates
     */
    private function reportTransitionFrequency(array $rates): void
    {
        $this->newLine();
        $this->info('=== Transition frequency — what real chains do right after each ability ===');

        foreach ($rates as $from => $tos) {
            arsort($tos);
            $top = array_slice($tos, 0, 3, true);
            $parts = [];

            foreach ($top as $to => $rate) {
                $parts[] = "{$to} (".round($rate * 100, 1).'%)';
            }

            $this->line('  '.$from.' -> '.implode(', ', $parts));
        }
    }
}
