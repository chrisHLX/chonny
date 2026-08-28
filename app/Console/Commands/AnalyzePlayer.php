<?php

namespace App\Console\Commands;

use App\Http\Services\PlayerMatchAnalysisService;
use Illuminate\Console\Command;

/**
 * Full single-match playstyle breakdown for one real player — build (talents + PvP talents
 * decoded from COMBATANT_INFO), what they actually pressed / procced / kept up, and every
 * selected talent linked to its in-match evidence with a verdict (used / UNUSED /
 * DEAD MODIFIER / NO PROC SEEN / passive).
 *
 * All logic is in PlayerMatchAnalysisService — this is a thin CLI wrapper.
 *
 * Usage:
 *   php artisan wow:analyze-player 9331e84694a7a5c91509cd033747ee22 Lonetotem
 *   php artisan wow:analyze-player 9331e84694a7a5c91509cd033747ee22 "Lonetotem-Area52-US" --json
 */
class AnalyzePlayer extends Command
{
    protected $signature = 'wow:analyze-player
        {matchId : A match already on file (raw/ + metadata/)}
        {player : Player name (with or without realm) or full Player-GUID}
        {--json : Emit the raw analysis JSON instead of the formatted report}';

    protected $description = 'Decode one player\'s build from a match and link each talent to what they actually did';

    public function handle(PlayerMatchAnalysisService $service): int
    {
        $a = $service->analyze($this->argument('matchId'), $this->argument('player'));

        if (isset($a['error'])) {
            $this->error($a['error']);

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $m = $a['match'];
        $this->newLine();
        $this->info("  {$m['player']}  —  {$m['spec']}");
        $this->line("  match {$m['id']}  ·  {$m['durationSec']}s");
        $this->line('  allies:  '.implode(', ', $m['roster']['allies']));
        $this->line('  enemies: '.implode(', ', $m['roster']['enemies']));

        $b = $a['build'];
        $this->newLine();
        $this->line("  <fg=yellow>BUILD</>  ({$b['nodesResolved']}/{$b['nodesInLog']} talent nodes resolved)");
        foreach ($b['pvpTalents'] as $p) {
            $this->line("    <fg=magenta>PvP</>  {$p['name']}");
        }

        $rows = collect($a['talentAnalysis']);
        $isFlag = fn ($r) => str_starts_with($r['verdict'], 'UNUSED') || str_starts_with($r['verdict'], 'DEAD') || str_starts_with($r['verdict'], 'NO PROC');
        [$flagged, $rest] = $rows->partition($isFlag);
        [$active, $passive] = $rest->partition(fn ($r) => ! in_array($r['linkType'], ['passive', 'unknown'], true));

        $this->newLine();
        $this->line(sprintf('  <fg=yellow>TALENT → USAGE</>   %d flagged · %d active · %d passive/other', $flagged->count(), $active->count(), $passive->count()));

        $render = function ($r, $colour) {
            $this->line(sprintf('    <fg=%s>%-11s</> %-24s %s', $colour, $r['linkType'], $this->trunc($r['talent'], 24), $r['verdict']));
            foreach ($r['evidence']['modifies'] ?? [] as $mod) {
                if (is_array($mod)) {
                    $this->line(sprintf('                  ↳ %s  %s', $mod['target'], $mod['seen'] ? '<fg=green>✓</>' : '·'));
                }
            }
            foreach ($r['evidence']['refs'] ?? [] as $ref) {
                $this->line(sprintf('                  ↳ %s  (%s)', $ref['name'], $ref['fired'] > 0 ? "seen {$ref['fired']}x" : 'not seen'));
            }
        };

        if ($flagged->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=red>⚑ FLAGGED — talent taken but no benefit observed this match</>');
            $flagged->sortBy('talent')->each(fn ($r) => $render($r, 'red'));
        }

        $this->newLine();
        $this->line('  <fg=green>✓ ACTIVE</>');
        $active->sortBy('talent')->each(fn ($r) => $render($r, 'green'));

        $this->newLine();
        $this->line('  <fg=gray>· PASSIVE / NOT USED THIS MATCH</>');
        $this->line('    '.$passive->sortBy('talent')->pluck('talent')->implode(', '));

        $this->newLine();
        $this->line('  <fg=yellow>CASTS</> (raw SPELL_CAST_SUCCESS — periodic/proc-ride not filtered)');
        foreach (array_slice($a['usage']['casts'], 0, 18) as $c) {
            $this->line(sprintf('    %3dx  %-26s  %.0f–%.0fs', $c['count'], $this->trunc($c['name'], 26), $c['firstT'], $c['lastT']));
        }

        if ($a['usage']['interrupts']) {
            $this->newLine();
            $this->line('  <fg=yellow>INTERRUPTS LANDED</>');
            foreach ($a['usage']['interrupts'] as $i) {
                $this->line(sprintf('    %dx  interrupted %s', $i['count'], $i['interruptedName']));
            }
        }

        $this->newLine();
        $this->line('  <fg=yellow>KEY BUFFS</> (self, by uptime)');
        foreach ($a['buffWeb'] as $bw) {
            $feeders = $bw['feedingTalents'] ? '  ← '.implode(', ', $bw['feedingTalents']) : '';
            $this->line(sprintf('    %3d%%  %-24s  x%d apply · max %d stk%s', $bw['uptimePct'], $this->trunc($bw['buff'], 24), $bw['applies'], $bw['maxStack'], $feeders));
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function trunc(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
    }
}
