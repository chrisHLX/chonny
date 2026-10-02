<?php

namespace App\Console\Commands;

use App\Http\Services\GameCardService;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes a card for every game you have synced, for the desktop app (tools/log-manager) to show
 * when you click a game. The app runs this after each `wow:sync` and after a note is written.
 *
 *   php artisan wow:game-cards --dir="%APPDATA%/MindCollector/cards" --notes="%APPDATA%/MindCollector/notes.json"
 *
 * The folder holds one `{lobby}.html` per game and an `index.json`: each game's time, bracket,
 * note count and signature, plus every player's experience as last seen. Only a game whose
 * signature has changed is drawn again (GameCardService::build), and the app opens a card's file
 * directly. It used to be one JSON holding every card, redrawn in full each time: 15.6 MB and 4s
 * for 146 games on 2026-10-02. The experience in the index is fed back in, so a player's
 * experience outlives its 7-day cache. `--fresh` redraws everything (after a spell-data change).
 */
class BuildGameCards extends Command
{
    protected $signature = 'wow:game-cards
        {--dir= : The folder to write the cards and index.json into. Prints the index when omitted.}
        {--user= : Whose games (id or email). Defaults to the only user who has any.}
        {--notes= : The notes file the desktop app writes, to put your notes on each game}
        {--fresh : Redraw every card, not just the ones that changed}';

    protected $description = 'Write a summary card for each synced game, for the MindCollector Logs desktop app';

    public function handle(GameCardService $cards): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return self::FAILURE;
        }

        $dir = $this->option('dir');
        $index = $dir && File::exists("{$dir}/index.json") ? (json_decode(File::get("{$dir}/index.json"), true) ?: []) : [];

        // A card counts as known only if its file is still there.
        $known = [];
        if ($dir && ! $this->option('fresh')) {
            foreach ($index['games'] ?? [] as $lobby => $g) {
                if (isset($g['sig']) && File::exists("{$dir}/{$lobby}.html")) {
                    $known[$lobby] = $g['sig'];
                }
            }
        }

        $notes = [];
        if (($path = $this->option('notes')) && File::exists($path)) {
            $notes = json_decode(File::get($path), true)['notes'] ?? [];
        }

        $built = $cards->build($user, $index['experience'] ?? [], $notes, $known);

        $drawn = 0;
        if ($dir) {
            File::ensureDirectoryExists($dir);
            foreach ($built['games'] as $lobby => $g) {
                if ($g['html'] !== null) {
                    File::put("{$dir}/{$lobby}.html", $g['html']);
                    $drawn++;
                }
            }
            // A game no longer synced (wow:forget-games) loses its card.
            foreach (File::glob("{$dir}/*.html") as $file) {
                if (! isset($built['games'][basename($file, '.html')])) {
                    File::delete($file);
                }
            }
        }

        $games = array_map(fn ($g) => array_diff_key($g, ['html' => true]), $built['games']);
        $json = json_encode(['generatedAt' => now()->toIso8601String(), 'games' => $games, 'experience' => $built['experience']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (! $dir) {
            $this->line($json);

            return self::SUCCESS;
        }

        File::put("{$dir}/index.json", $json);
        $this->info(sprintf('%d game card(s); %d drawn, %d unchanged. In %s', count($games), $drawn, count($games) - $drawn, $dir));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        if ($ref = $this->option('user')) {
            $user = is_numeric($ref) ? User::find((int) $ref) : User::where('email', $ref)->first();
            if (! $user) {
                $this->error("No user matching '{$ref}'.");
            }

            return $user;
        }

        $owners = ArenaRound::query()->distinct()->pluck('user_id');

        if ($owners->count() === 1) {
            return User::find($owners->first());
        }

        $this->error($owners->isEmpty() ? 'No synced games yet - run wow:sync first.' : 'Games from more than one user here - say which with --user=');

        return null;
    }
}
