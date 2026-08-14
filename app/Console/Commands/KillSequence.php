<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Console\Command;

/**
 * Shows the "closing sequence" for one match — every ability the winning team cast in the
 * seconds before the deciding kill, via ArenaLogService::findPreKillWindow(). Built 2026-08-14
 * per direct user request: arena logs stop shortly after the deciding kill (confirmed by
 * inspection, not assumed — see findPreKillWindow()'s docblock), so "what happened right
 * before someone's health hit zero" is a real, already-anchored signal, not something that
 * needs a fuzzy combo-detection algorithm to find.
 *
 * Each cast's name prefers our own `spells` row (canonical English) and falls back to the raw
 * combat log's own embedded spellName field only when the spell isn't in our data yet — a pure
 * DB lookup previously produced "spell_id N" placeholders for real casts (trinkets/racials/
 * anything not yet imported); a pure raw-log lookup turned out to have its own bug, confirmed
 * 2026-08-14 — the log's spellName field is written in whatever locale the recording client was
 * running, so some matches show Chinese names instead of English. See ArenaLogService::
 * recordKillSequence()'s docblock for the full trace.
 *
 * Command-line only, one match at a time — validates the signal before deciding whether/how
 * to aggregate "closing sequences" across many matches into a real combo library.
 *
 * Usage: php artisan wow:kill-sequence {matchId} [--window=20]
 */
class KillSequence extends Command
{
    protected $signature = 'wow:kill-sequence {matchId} {--window=20 : Seconds before the kill to look back}';

    protected $description = 'Show what the winning team cast in the seconds before a match\'s deciding kill';

    public function handle(ArenaLogService $service): int
    {
        $matchId = $this->argument('matchId');
        $window = (int) $this->option('window');

        $result = $service->findPreKillWindow($matchId, $window);

        if ($result === null) {
            $this->error("No death events found for match {$matchId} (or the match isn't on file).");

            return self::FAILURE;
        }

        if ($result['players'] === []) {
            $this->warn('No casts found from the winning side in the window before the kill.');

            return self::SUCCESS;
        }

        $patch = Patch::where('is_current', true)->first();

        $this->info("Killed: {$result['killedPlayer']}  —  showing the {$window}s before the kill");
        $this->newLine();

        foreach ($result['players'] as $player) {
            $this->info("=== {$player['name']} ===");

            foreach ($player['casts'] as $cast) {
                $spell = $patch ? Spell::where('patch_id', $patch->id)->where('spell_id', $cast['spellId'])->first() : null;
                $name = $spell?->name ?? $cast['name'] ?? "spell_id {$cast['spellId']}";
                $this->line(sprintf('  [+%5.1fs] %s', $cast['time'], $name));
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
