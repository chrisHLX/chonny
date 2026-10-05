<?php

namespace App\Console\Commands;

use App\Models\ArenaRound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Which offensive cooldowns each spec actually presses in a go, counted over every measured round
 * (both sides of every game), written to a committed file the comp page's "How to play it" reads.
 *
 *   php artisan wow:go-cooldowns
 *
 * Built 2026-10-05 because neither guess from the spell data names a spec's big button: the
 * longest cooldown made Shattering Throw Arms's lead, and the classifier's Buff/Spell label made
 * Tricks of the Trade Subtlety's. Counted from play, Arms presses Colossus Smash in 92% of its goes
 * and Shattering Throw in 5%.
 *
 * A go is RoundAnalysisService's (a run of offensive casts and crowd control); a spell counts once
 * per player per go. Only names, specs and shares are written, never a player, so the file is safe
 * to commit (rule 14: the page reads a committed artifact, never the stored rounds).
 * Re-run after a re-measure or a big batch of new games.
 */
class BuildGoCooldowns extends Command
{
    protected $signature = 'wow:go-cooldowns';

    protected $description = 'Count the offensive cooldowns each spec presses in its goes, for the comp page';

    public const PATH = 'data/comp-playbook/go-cooldowns.json';

    public function handle(): int
    {
        $count = [];
        $goes = [];
        $players = [];
        $rounds = 0;

        ArenaRound::query()->select('id', 'payload')->chunkById(50, function ($rows) use (&$count, &$goes, &$players, &$rounds) {
            foreach ($rows as $round) {
                $analysis = $round->payload['analysis'] ?? null;

                if (! $analysis) {
                    continue;
                }

                $rounds++;
                $specOf = collect($analysis['players'] ?? [])->mapWithKeys(fn ($p) => [$p['guid'] => $p['specExternalId'] ?? null])->all();

                foreach ($analysis['goes'] ?? [] as $go) {
                    $pressed = [];

                    foreach ($go['links'] ?? [] as $link) {
                        $spec = $specOf[$link['by'] ?? ''] ?? null;

                        if (($link['cat'] ?? null) === 'offensive' && $spec) {
                            $pressed[$spec][$link['by']][$link['spell']] = true;
                        }
                    }

                    foreach ($pressed as $spec => $byPlayer) {
                        foreach ($byPlayer as $guid => $spells) {
                            $goes[$spec] = ($goes[$spec] ?? 0) + 1;
                            $players[$spec][$guid] = true;

                            foreach (array_keys($spells) as $spell) {
                                $count[$spec][$spell] = ($count[$spec][$spell] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        });

        ksort($count);
        $specs = [];

        foreach ($count as $spec => $spells) {
            arsort($spells);
            $specs[(string) $spec] = [
                'goes' => $goes[$spec],
                'players' => count($players[$spec]),
                'spells' => collect($spells)->map(fn ($n, $name) => ['name' => $name, 'share' => round($n / $goes[$spec], 2)])->values()->all(),
            ];
        }

        File::ensureDirectoryExists(base_path(dirname(self::PATH)));
        File::put(base_path(self::PATH), json_encode([
            'generatedAt' => now()->toDateString(),
            'rounds' => $rounds,
            'specs' => $specs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info(count($specs).' specs from '.$rounds.' rounds -> '.self::PATH);

        return self::SUCCESS;
    }
}
