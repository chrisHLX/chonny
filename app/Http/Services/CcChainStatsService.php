<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\File;

/**
 * Shared real-data lookups over the CC chain corpus (data/arena-logs/cc-chains/*.json,
 * produced by wow:find-cc-chains --json), used by both wow:cc-chain-patterns (reporting) and
 * wow:cc-formula (chain building) so the two never drift on how "opener rate" or "transition
 * rate" is computed. See CcChainPatterns's own docblock for the full reasoning — summary: real
 * data beats assumption-based tie-breaks (curated duration for openers, category-freshness
 * alone for sequencing), confirmed 2026-08-23 when duration-based opener selection was shown to
 * disagree with real play for Freezing Trap vs Intimidation.
 *
 * Consecutive same-spellId steps within one real chain are collapsed before either stat is
 * computed — a repeated cast of the same ability isn't a sequencing decision, and leaving them
 * uncollapsed would make "X -> X" dominate transition counts for any frequently-recast ability.
 */
class CcChainStatsService
{
    /**
     * @return array<int, array<int, array<string, mixed>>> one array of collapsed steps per chain
     */
    public function collapsedChains(): array
    {
        $files = glob(base_path('data/arena-logs/cc-chains/*/*.json'));
        $chains = [];

        foreach ($files as $file) {
            foreach (json_decode(File::get($file), true) as $chain) {
                $chains[] = $chain;
            }
        }

        $collapsed = array_map(fn ($chain) => $this->collapseRepeats($chain['steps']), $chains);

        return array_values(array_filter($collapsed, fn ($steps) => $steps !== []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function collapseRepeats(array $steps): array
    {
        $collapsed = [];

        foreach ($steps as $step) {
            $last = end($collapsed);

            if ($last !== false && $last['spellId'] === $step['spellId']) {
                continue;
            }

            $collapsed[] = $step;
        }

        return $collapsed;
    }

    /**
     * For each ability name, the fraction of real chains containing it where it's the first
     * step. Abilities with fewer than $minCount total real occurrences are omitted entirely —
     * callers should treat a missing key as "no confident real data," not "0% opener rate."
     *
     * @return array<string, float> name => rate (0.0-1.0)
     */
    public function openerRates(int $minCount = 5): array
    {
        $totalCount = [];
        $openerCount = [];

        foreach ($this->collapsedChains() as $steps) {
            $seenInThisChain = [];

            foreach ($steps as $i => $step) {
                $name = $step['name'];

                if (!isset($seenInThisChain[$name])) {
                    $totalCount[$name] = ($totalCount[$name] ?? 0) + 1;
                    $seenInThisChain[$name] = true;
                }

                if ($i === 0) {
                    $openerCount[$name] = ($openerCount[$name] ?? 0) + 1;
                }
            }
        }

        $rates = [];

        foreach ($totalCount as $name => $total) {
            if ($total >= $minCount) {
                $rates[$name] = ($openerCount[$name] ?? 0) / $total;
            }
        }

        return $rates;
    }

    /**
     * For each "from" ability, what fraction of real transitions away from it land on each "to"
     * ability. Only "from" abilities with at least $minCount total outgoing transitions are
     * included — same "missing means no confident data" contract as openerRates().
     *
     * @return array<string, array<string, float>> from => [to => rate (0.0-1.0)]
     */
    public function transitionRates(int $minCount = 5): array
    {
        $transitions = [];

        foreach ($this->collapsedChains() as $steps) {
            for ($i = 0; $i < count($steps) - 1; $i++) {
                $from = $steps[$i]['name'];
                $to = $steps[$i + 1]['name'];
                $transitions[$from][$to] = ($transitions[$from][$to] ?? 0) + 1;
            }
        }

        $rates = [];

        foreach ($transitions as $from => $tos) {
            $totalFrom = array_sum($tos);

            if ($totalFrom < $minCount) {
                continue;
            }

            foreach ($tos as $to => $count) {
                $rates[$from][$to] = $count / $totalFrom;
            }
        }

        return $rates;
    }
}
