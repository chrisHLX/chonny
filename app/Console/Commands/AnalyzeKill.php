<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use Illuminate\Console\Command;

/**
 * Prints the full causal reconstruction around one match's deciding kill — every CC landing
 * (real source + real target), every watched defensive/offensive/trinket cast from either
 * team, the killed player's HP curve, and the top damage sources against them in the closing
 * seconds. Backed by ArenaLogService::analyzeKillCausally() — see that method's docblock for
 * the full story of what this replaces (a chain of five separate one-off scratchpad scripts
 * written by hand, same session, to answer "who was CC'ing the healer, when did their
 * defensives actually cover the real burst, what killed them").
 *
 * Deliberately does NOT try to explain WHY a player pressed something, and deliberately does
 * NOT try to auto-detect "wasted" cooldowns by comparing timing against the outcome — a real
 * investigation this session found that framing to be backwards (a defensive that was pressed
 * and the target didn't die from THAT burst is often evidence the press worked, not evidence
 * it wasn't needed). This command surfaces the real, cross-referenced facts; interpreting them
 * needs a human (or a human+AI conversation) bringing real game knowledge to the output, the
 * same way the original investigation actually worked.
 *
 * Usage: php artisan wow:analyze-kill {matchId} [--window=60]
 */
class AnalyzeKill extends Command
{
    protected $signature = 'wow:analyze-kill {matchId} {--window=60 : Seconds before the kill to reconstruct}';

    protected $description = "Reconstruct the full causal picture (real-target CC, defensives/offensives, HP curve, damage sources) around one match's deciding kill";

    public function handle(ArenaLogService $service): int
    {
        $matchId = $this->argument('matchId');
        $window = (int) $this->option('window');

        $result = $service->analyzeKillCausally($matchId, $window);

        if ($result === null) {
            $this->error("No usable death event found for match {$matchId} (or it isn't on file).");

            return self::FAILURE;
        }

        $this->info("Match: {$result['matchId']}  (duration {$result['durationSeconds']}s)");
        $this->info("Killed: {$result['killedName']} ({$result['killedSpec']})");
        $this->newLine();

        $this->info('Roster:');
        foreach ($result['roster'] as $info) {
            $healerTag = $info['isHealer'] ? ' [HEALER]' : '';
            $killedTag = $info['name'] === $result['killedName'] ? ' <== KILLED' : '';
            $this->line("  team{$info['reaction']}  {$info['name']} — {$info['spec']}{$healerTag}{$killedTag}");
        }
        $this->newLine();

        $this->info("=== Causal timeline (CC + watched defensives/offensives/trinkets), last {$window}s ===");
        foreach ($result['timeline'] as $e) {
            $this->line(sprintf('[-%6.2fs] %s', $e['t'], $e['text']));
        }
        $this->newLine();

        $this->info("=== {$result['killedName']}'s HP curve, last 25s ===");
        $this->line('    (known caveat: a few entries can reflect a companion pet\'s health instead of the player\'s own — read as directional, not exact; verified 2026-08-15 that this can also make the final entry not show 0 at the actual kill)');
        foreach ($result['hpCurve'] as $h) {
            if ($h['t'] > 25) {
                continue;
            }
            $this->line(sprintf('[-%6.2fs] %s / %s  (%s%%)', $h['t'], number_format($h['currentHp'], 0), number_format($h['maxHp'], 0), $h['pct']));
        }
        $this->newLine();

        $this->info("=== Top damage taken by {$result['killedName']}, last 20s ===");
        $shown = collect($result['damageTaken'])->filter(fn ($d) => $d['t'] <= 20)->sortByDesc('amount')->take(20);
        foreach ($shown as $d) {
            $this->line(sprintf('[-%6.2fs] %s hits for %s via %s', $d['t'], $d['source'], number_format($d['amount']), $d['ability']));
        }

        return self::SUCCESS;
    }
}
