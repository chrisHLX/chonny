<?php

namespace App\Http\Services;

/**
 * Whom each team went after, and how each go on them went (2026-11-01, the Summary's Kill targets box).
 *
 * The kill target is the first real decision a team makes when the gates open (Chriso). The log holds
 * no call, so the player who took the most from a team is that team's primary target; the games agree
 * (docs/plan-overlay.md: both DPS mostly on one player in 95% of rounds, the first death on a main
 * target in 36 of 37).
 *
 * Read when a page is drawn, from `taken` (RoundAnalysisService version 18) and FallsService, so the
 * box can change without a re-measure. A go here is a fall with something committed to it: the control
 * on the fallen player's healer, the drop, and the cooldowns. Plain damage with neither is left out.
 */
class KillTargetService
{
    /** Goes shown per team, the worst of them: a long game has more than a player reads. */
    public const MOST_GOES = 6;

    public function __construct(private FallsService $falls) {}

    /**
     * @param  array<string, mixed>  $analysis  a stored round's `analysis`
     * @return array{us: array, them: array}|null keyed by the team attacking; null before version 18
     */
    public function read(array $analysis): ?array
    {
        $taken = $analysis['taken'] ?? null;
        if (! is_array($taken) || $taken === []) {
            return null;
        }

        $side = array_column($analysis['players'] ?? [], 'side', 'guid');
        $died = [];
        foreach ($analysis['deaths'] ?? [] as $d) {
            $died[$d['who']] ??= (float) $d['t'];
        }
        $falls = $this->falls->read($analysis)['falls'];

        $out = [];
        foreach (['us' => 'them', 'them' => 'us'] as $attacker => $defender) {
            $targets = [];
            foreach ($taken as $guid => $damage) {
                if (($side[$guid] ?? null) === $defender) {
                    $targets[] = ['guid' => $guid, 'damage' => (int) $damage, 'died' => $died[$guid] ?? null];
                }
            }
            usort($targets, fn ($a, $b) => $b['damage'] <=> $a['damage']);

            $goes = array_values(array_filter($falls, fn ($f) => $f['side'] === $attacker && $f['kind'] !== 'damage'));
            $out[$attacker] = ['targets' => $targets, 'goes' => $this->worst($goes), 'allGoes' => count($goes)];
        }

        return $out;
    }

    /** The goes that mattered most, in the order they happened: deaths first, then the deepest drops. */
    private function worst(array $goes): array
    {
        usort($goes, fn ($a, $b) => [$b['died'], $b['high'] - $b['low']] <=> [$a['died'], $a['high'] - $a['low']]);
        $kept = array_slice($goes, 0, self::MOST_GOES);
        usort($kept, fn ($a, $b) => $a['from'] <=> $b['from']);

        return array_map(fn ($f) => [
            'from' => $f['from'],
            'on' => $f['on'],
            'high' => $f['high'],
            'low' => $f['low'],
            'died' => $f['died'],
            'healerCc' => array_values(array_map(fn ($l) => ['spell' => $l['spell'], 'seconds' => round($l['until'] - $l['t'], 1)],
                array_filter($f['links'], fn ($l) => $l['cat'] === 'control' && $l['role'] === 'healer'))),
            'cooldowns' => array_values(array_unique(array_map(fn ($l) => $l['spell'],
                array_filter($f['links'], fn ($l) => $l['cat'] === 'offensive')))),
        ], $kept);
    }
}
