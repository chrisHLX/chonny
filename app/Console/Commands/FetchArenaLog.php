<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use Illuminate\Console\Command;

/**
 * Fetches one arena match's raw combat log + metadata from wowarenalogs.com and stores both
 * locally under data/arena-logs/ — same raw-data-first convention already used by
 * data/spelldata (SimC dumps) and data/talenttrees (Blizzard Game Data API): source material
 * lives in a plain, readable, version-controllable format, never fetched-and-discarded.
 *
 * Thin CLI wrapper — all the actual GraphQL/fetch/store logic lives in ArenaLogService (also
 * used by wow:pull-comp-log). See arena-log-api.md at the repo root for the full
 * reverse-engineered API reference (endpoint, query shapes, raw log event-type breakdown,
 * COMBATANT_INFO structure).
 *
 * This is a completely different, unrelated service from Warcraft Logs (warcraftlogs.com) —
 * the WARCRAFT_LOGS_CLIENT_ID/SECRET already in .env are for that separate, real Warcraft
 * Logs API and are NOT used anywhere in this command.
 *
 * Usage:
 *   php artisan wow:fetch-arena-log f3026e050f7c7833099b62bd33255ec5
 *   php artisan wow:fetch-arena-log "https://wowarenalogs.com/match?id=f3026e050f7c7833099b62bd33255ec5&viewerIsOwner=false&source=search"
 *
 * Output (both idempotent — re-running overwrites with the same content):
 *   data/arena-logs/raw/{matchId}.log.gz    — the raw combat log, gzipped (~92% smaller than
 *                                              the raw text, fully lossless — decompress with
 *                                              gzip_decode()/`gunzip`/`zcat` to get the exact
 *                                              original text back, byte for byte)
 *   data/arena-logs/metadata/{matchId}.json — match + per-unit metadata, human-readable JSON
 */
class FetchArenaLog extends Command
{
    protected $signature = 'wow:fetch-arena-log {match : A wowarenalogs.com match ID, or a full match URL containing one}';

    protected $description = "Fetch one arena match's raw combat log + metadata from wowarenalogs.com's public API and store both under data/arena-logs/";

    public function handle(ArenaLogService $service): int
    {
        $matchId = $this->resolveMatchId($this->argument('match'));

        if ($matchId === null) {
            $this->error('Could not extract a match ID from: '.$this->argument('match'));

            return self::FAILURE;
        }

        $this->info("Fetching match {$matchId}...");

        $match = $service->fetchMatch($matchId);

        if ($match === null) {
            $this->error('No match found for that ID (or the response shape was unexpected).');

            return self::FAILURE;
        }

        $unitCount = count($match['units'] ?? []);
        $bracket = $match['startInfo']['bracket'] ?? 'unknown';
        $this->info("Found: {$match['__typename']}, bracket {$bracket}, {$unitCount} units.");

        $stored = $service->storeMatch($matchId, $match);

        if (isset($stored['error'])) {
            $this->error($stored['error']);

            return self::FAILURE;
        }

        $this->info("Saved raw log to data/arena-logs/raw/{$matchId}.log.gz ({$stored['compressedBytes']} bytes, gzipped from {$stored['rawBytes']})");
        $this->info("Saved metadata to data/arena-logs/metadata/{$matchId}.json");

        return self::SUCCESS;
    }

    private function resolveMatchId(string $input): ?string
    {
        if (preg_match('/^[a-f0-9]{20,40}$/i', $input)) {
            return $input;
        }

        if (preg_match('/[?&]id=([a-f0-9]{20,40})/i', $input, $m)) {
            return $m[1];
        }

        return null;
    }
}
