<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Http\Services\CombatLogIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Imports your own arena matches straight from WoW's combat log, with no third party involved.
 *
 * This is the replacement for `wow:fetch-arena-log`, which depended on wowarenalogs.com — a
 * service that turned match search off entirely in September 2026 and then put it behind a
 * Battle.net sign-in. Everything their metadata carried is already in the log WoW writes
 * (see CombatLogIngestService for the field-by-field derivation, verified against an archived
 * match we hold both halves of), so the dependency was never necessary.
 *
 * The files this writes are the same shape `ArenaLogService::storeMatch()` wrote, so
 * `wow:refresh-match-derived` and every analysis command downstream work unchanged.
 *
 * HOW TO GET A LOG. In WoW: System > Network > tick **Advanced Combat Logging**, then type
 * `/combatlog` to start writing. The file is
 * `<WoW>/_retail_/Logs/WoWCombatLog.txt`. Logging stays on until you type `/combatlog` again or
 * reload, so the simplest habit is to turn it on before a session and run this after.
 *
 *   php artisan wow:ingest-combatlog "C:/Program Files (x86)/World of Warcraft/_retail_/Logs/WoWCombatLog.txt"
 *   php artisan wow:ingest-combatlog path/to/logs/          # every .txt/.log/.gz in a folder
 *   php artisan wow:ingest-combatlog <file> --dry-run       # list what it would import
 *
 * A SOLO SHUFFLE LOBBY IMPORTS AS SIX MATCHES, one per round, each with its own roster teams and
 * its own result — because that is what a round is, and because the log re-states the teams every
 * round. They are listed with an `r1`..`r6` marker. Until 2026-09-25 only the sixth round of each
 * lobby survived the split, which threw away 83% of the shuffle games in a real log; see
 * CombatLogIngestService's docblock for the measurement.
 *
 * Re-running over a log that keeps growing is safe and cheap: a match's id is derived from its
 * start instant, arena and roster, so an already-imported match is skipped rather than
 * duplicated. That makes "point it at the same file after every session" the intended workflow.
 */
class IngestCombatLog extends Command
{
    protected $signature = 'wow:ingest-combatlog
        {path? : A WoWCombatLog.txt, or a directory of them. Defaults to config(arena_logs.combatlog_path).}
        {--dry-run : Parse and report, write nothing}
        {--overwrite : Re-import matches already in the archive}
        {--all-brackets : Keep skirmishes and anything else, not just the rated brackets}';

    protected $description = "Import your own arena matches from WoW's combat log into data/arena-logs/";

    public function handle(CombatLogIngestService $ingest, ArenaLogService $archive): int
    {
        $path = $this->argument('path') ?: config('arena_logs.combatlog_path');

        if (! $path) {
            $this->error('No path given and no arena_logs.combatlog_path configured.');
            $this->line('  Pass the file directly, e.g.');
            $this->line('  php artisan wow:ingest-combatlog "C:/Program Files (x86)/World of Warcraft/_retail_/Logs/WoWCombatLog.txt"');

            return self::FAILURE;
        }

        $files = $this->resolveFiles($path);

        if ($files === []) {
            $this->error("No combat logs found at {$path}");

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        $ignored = 0;
        $incomplete = 0;

        foreach ($files as $file) {
            $this->line('<fg=gray>'.basename($file).'</>');

            foreach ($ingest->splitMatches($file) as $match) {
                $metadata = $ingest->deriveMetadata(
                    $match['lines'], $match['start'], $match['end'], $match['sequence'], $match['lobbyFirstLine']
                );
                $bracket = $metadata['startInfo']['bracket'] ?: 'unknown';

                if (! $this->option('all-brackets') && ! in_array($bracket, CombatLogIngestService::WANTED_BRACKETS, true)) {
                    $ignored++;

                    continue;
                }

                // A match nobody's spec could be read from is not usable by anything downstream:
                // every analysis command keys off `units[].spec`.
                $withSpec = array_filter($metadata['units'], fn ($u) => ($u['spec'] ?? '0') !== '0');

                if ($withSpec === []) {
                    $this->line("  <fg=yellow>{$bracket} at ".$this->when($metadata).' — no COMBATANT_INFO, skipped</>');
                    $this->line('    <fg=gray>Turn on Advanced Combat Logging (System > Network); without it the log has no specs.</>');
                    $incomplete++;

                    continue;
                }

                // A shuffle lobby produces six of these, so the round number is the only thing
                // distinguishing six otherwise identical lines.
                $round = $metadata['sequenceNumber'] === null ? '' : ' r'.$metadata['sequenceNumber'];

                $summary = sprintf(
                    '  %-18s %s%-3s  %ds  %d players%s',
                    $bracket,
                    $this->when($metadata),
                    $round,
                    $metadata['durationInSeconds'],
                    count($withSpec),
                    $metadata['result'] === null ? '' : ($metadata['result'] === CombatLogIngestService::RESULT_WIN ? '  WON' : '  lost')
                );

                if ($this->option('dry-run')) {
                    $this->line($summary.'  <fg=gray>('.$metadata['id'].')</>');
                    $imported++;

                    continue;
                }

                $stored = $ingest->store($metadata, $match['lines'], $archive, (bool) $this->option('overwrite'));

                if ($stored === null) {
                    $skipped++;

                    continue;
                }

                $this->info($summary.'  <fg=gray>'.number_format($stored['compressedBytes'] / 1024).'KB</>');
                $imported++;
            }
        }

        $this->newLine();
        $this->info(($this->option('dry-run') ? 'Would import' : 'Imported')." {$imported} match(es).");

        if ($skipped) {
            $this->line("  {$skipped} already in the archive.");
        }
        if ($ignored) {
            $this->line("  {$ignored} skipped as not a rated bracket (--all-brackets keeps them).");
        }
        if ($incomplete) {
            $this->line("  {$incomplete} had no combatant info — Advanced Combat Logging was off.");
        }

        if ($imported > 0 && ! $this->option('dry-run')) {
            $this->newLine();
            $this->comment('Run `php artisan wow:refresh-match-derived` to fold these into CC chains, burst windows and the rest.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function resolveFiles(string $path): array
    {
        if (File::isDirectory($path)) {
            $found = [];
            foreach (File::files($path) as $file) {
                if (preg_match('/\.(txt|log|gz)$/i', $file->getFilename())) {
                    $found[] = $file->getPathname();
                }
            }
            sort($found);

            return $found;
        }

        return File::exists($path) ? [$path] : [];
    }

    private function when(array $metadata): string
    {
        return $metadata['startTime']
            ? date('Y-m-d H:i', (int) ($metadata['startTime'] / 1000))
            : 'unknown time';
    }
}
