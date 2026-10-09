<?php

namespace App\Console\Commands;

use App\Http\Services\ClassLibraryService;
use App\Http\Services\CompLibraryService;
use App\Http\Services\GameCardService;
use App\Http\Services\ImprovementService;
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
 *
 * It also writes the Improve page for each character you have played (`improve-{hash}.html`,
 * ImprovementService), listed under `characters` in the index by full character name, and the comp
 * library: a page per enemy comp (`comp-{hash}.html`, CompLibraryService) listed under `comps`,
 * and the Classes page: for each spec met, the highest-rated and the most experienced player of it
 * (`player-{hash}.html`, ClassLibraryService) listed under `classes`.
 */
class BuildGameCards extends Command
{
    protected $signature = 'wow:game-cards
        {--dir= : The folder to write the cards and index.json into. Prints the index when omitted.}
        {--user= : Whose games (id or email). Defaults to the only user who has any.}
        {--notes= : The notes file the desktop app writes, to put your notes on each game}
        {--fresh : Redraw every card, not just the ones that changed}
        {--web : Point images at /storage/... for the website (/wow/coach) instead of file:/// paths}';

    protected $description = 'Write a summary card for each synced game, for the MindCollector Logs desktop app';

    public function handle(GameCardService $cards, ImprovementService $improve, CompLibraryService $library, ClassLibraryService $classLibrary): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return self::FAILURE;
        }
        if ($this->option('web')) {
            config(['desktop.web_assets' => true]);
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
            // A game no longer synced (wow:forget-games) loses its card. The Improve pages are not
            // cards and are tidied below.
            foreach (File::glob("{$dir}/*.html") as $file) {
                if (! preg_match('/^(improve|comp|player)-/', basename($file)) && ! isset($built['games'][basename($file, '.html')])) {
                    File::delete($file);
                }
            }
        }

        // The Improve page, one per character you have played (ImprovementService). Drawn again only
        // when a round or the page's code changed: notes and experience do not move it.
        $characters = $index['characters'] ?? [];
        $improveSig = $improve->signature($user, $built['experience']);
        $improveDrawn = 0;
        $have = $dir && ($index['improveSig'] ?? null) === $improveSig && ! $this->option('fresh')
            && collect($characters)->every(fn ($c) => File::exists("{$dir}/{$c['file']}"));
        if (! $have) {
            $characters = [];
            foreach ($improve->build($user, $built['experience']) as $full => $c) {
                $file = 'improve-'.substr(md5($full), 0, 10).'.html';
                $characters[$full] = ['name' => $c['name'], 'spec' => $c['spec'], 'games' => $c['games'], 'file' => $file];
                if ($dir) {
                    File::put("{$dir}/{$file}", $c['html']);
                    $improveDrawn++;
                }
            }
            if ($dir) {
                foreach (File::glob("{$dir}/improve-*.html") as $file) {
                    if (! in_array(basename($file), array_column($characters, 'file'), true)) {
                        File::delete($file);
                    }
                }
            }
        }

        // The comp library: one page per enemy comp (CompLibraryService), drawn again only when a
        // round, the page's code or anyone's experience changed.
        $comps = $index['comps'] ?? [];
        $compSig = $library->signature($user, $built['experience']);
        $compsDrawn = 0;
        $haveComps = $dir && ($index['compSig'] ?? null) === $compSig && ! $this->option('fresh')
            && collect($comps)->every(fn ($c) => File::exists("{$dir}/{$c['file']}"));
        if (! $haveComps) {
            $comps = [];
            foreach ($library->build($user, $built['experience']) as $key => $c) {
                $file = 'comp-'.substr(md5($key), 0, 10).'.html';
                $comps[$key] = array_diff_key($c, ['html' => true]) + ['file' => $file];
                if ($dir) {
                    File::put("{$dir}/{$file}", $c['html']);
                    $compsDrawn++;
                }
            }
            if ($dir) {
                foreach (File::glob("{$dir}/comp-*.html") as $file) {
                    if (! in_array(basename($file), array_column($comps, 'file'), true)) {
                        File::delete($file);
                    }
                }
            }
        }

        // The Classes page: the strongest player of each spec you met (ClassLibraryService), drawn
        // again only when a round, the page's code, the norms or anyone's experience changed.
        $classes = $index['classes'] ?? [];
        $classSig = $classLibrary->signature($user, $built['experience']);
        $playersDrawn = 0;
        $haveClasses = $dir && ($index['classSig'] ?? null) === $classSig && ! $this->option('fresh')
            && collect($classes)->every(fn ($c) => File::exists("{$dir}/{$c['file']}"));
        if (! $haveClasses) {
            $classes = [];
            foreach ($classLibrary->build($user, $built['experience']) as $full => $c) {
                $file = 'player-'.substr(md5($full), 0, 10).'.html';
                $classes[$full] = array_diff_key($c, ['html' => true]) + ['file' => $file];
                if ($dir) {
                    File::put("{$dir}/{$file}", $c['html']);
                    $playersDrawn++;
                }
            }
            if ($dir) {
                foreach (File::glob("{$dir}/player-*.html") as $file) {
                    if (! in_array(basename($file), array_column($classes, 'file'), true)) {
                        File::delete($file);
                    }
                }
            }
        }

        $games = array_map(fn ($g) => array_diff_key($g, ['html' => true]), $built['games']);
        $json = json_encode([
            'generatedAt' => now()->toIso8601String(), 'games' => $games, 'experience' => $built['experience'],
            'characters' => $characters, 'improveSig' => $improveSig,
            'comps' => $comps, 'compSig' => $compSig,
            'classes' => $classes, 'classSig' => $classSig,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (! $dir) {
            $this->line($json);

            return self::SUCCESS;
        }

        File::put("{$dir}/index.json", $json);
        $this->info(sprintf('%d game card(s); %d drawn, %d unchanged. %d Improve page(s) %s. %d comp page(s) %s. %d player page(s) %s. In %s',
            count($games), $drawn, count($games) - $drawn, count($characters), $improveDrawn ? 'drawn' : 'unchanged',
            count($comps), $compsDrawn ? 'drawn' : 'unchanged', count($classes), $playersDrawn ? 'drawn' : 'unchanged', $dir));

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
