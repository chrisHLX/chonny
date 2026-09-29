<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaReviewIngestService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Feeds arena round logs through EXACTLY the path the browser upload uses
 * (ArenaReviewIngestService::ingestRound, then assembleLobby), for one player's account.
 *
 * For the archive's own per-match files (`raw/{id}.log.gz`) and anything else that is one round's
 * slice of a combat log, the same thing the browser sends. It exists so a set of games can be put
 * on a player's account without a browser, which is how "Your analysis" was first tested against
 * the 26 Sep 2026 games. It stores only what the upload stores: the raw text is derived and dropped.
 */
class UploadRounds extends Command
{
    protected $signature = 'wow:upload-rounds
        {user : the account, by id or email}
        {paths* : round logs (.log or .log.gz), one round each}';

    protected $description = 'Ingest round logs for a player through the browser-upload path';

    public function handle(ArenaReviewIngestService $ingest): int
    {
        $ref = $this->argument('user');
        $user = is_numeric($ref) ? User::find($ref) : User::where('email', $ref)->first();

        if (! $user) {
            $this->error("No user '{$ref}'.");

            return self::FAILURE;
        }

        $lobbies = [];
        foreach ($this->argument('paths') as $path) {
            $raw = str_ends_with($path, '.gz') ? @gzdecode((string) @file_get_contents($path)) : @file_get_contents($path);

            if ($raw === false || $raw === '') {
                $this->warn("  unreadable: {$path}");

                continue;
            }

            $result = $ingest->ingestRound($user, $raw);
            $this->line(sprintf('  %-8s %s%s', $result['status'], basename($path), isset($result['reason']) ? ' — '.$result['reason'] : ''));

            if (isset($result['lobbyId'])) {
                $lobbies[$result['lobbyId']] = true;
            }
        }

        foreach (array_keys($lobbies) as $lobbyId) {
            $ingest->assembleLobby($user, $lobbyId);
        }

        $this->info(count($lobbies).' game(s) stored and assembled for '.$user->email.'.');

        return self::SUCCESS;
    }
}
