<?php

namespace App\Console\Commands;

use App\Models\ArenaRound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Which offensive cooldowns each spec actually presses in a go, and where each crowd control spell
 * lands in one (their healer, the go's target, or someone else), counted over every measured round
 * (both sides of every game), written to a committed file the comp page's "How to play it" reads.
 *
 *   php artisan wow:go-cooldowns
 *
 * Each spec's own runs of control on the healer ("healerRuns": Intimidation > Freezing Trap for a
 * Hunter, Psychic Scream for a Disc Priest) were added the same day, from Chriso's idea: a comp's
 * healer lock is mostly its specs' signature combos put together.
 *
 * The placements were added the same day, because the kill-target pick was wrong: the CC formula
 * reserved Binding Shot for the kill target, while in play it lands on the healer 43 times to the
 * target's 26; and Kidney Shot, which lands on the target 158 times to 71, was missing.
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

    /** Placements on a healer or a target a player needs before their habit counts as a vote. */
    private const VOTE_MIN = 5;

    /** Seconds between one player's controls on the healer for them to be one run. */
    private const RUN_GAP = 6.0;

    public function handle(): int
    {
        $count = [];
        $control = [];
        $runs = [];
        $healerGoes = [];
        $goes = [];
        $players = [];
        $rounds = 0;

        ArenaRound::query()->select('id', 'payload')->chunkById(50, function ($rows) use (&$count, &$control, &$runs, &$healerGoes, &$goes, &$players, &$rounds) {
            foreach ($rows as $round) {
                $analysis = $round->payload['analysis'] ?? null;

                if (! $analysis) {
                    continue;
                }

                $rounds++;
                $specOf = collect($analysis['players'] ?? [])->mapWithKeys(fn ($p) => [$p['guid'] => $p['specExternalId'] ?? null])->all();

                foreach ($analysis['goes'] ?? [] as $go) {
                    $pressed = [];
                    $onHealer = [];

                    foreach ($go['links'] ?? [] as $link) {
                        $spec = $specOf[$link['by'] ?? ''] ?? null;

                        // Where crowd control lands inside a go: their healer, the go's target, or
                        // someone else ("cross"). Pooled by spell across specs, since where a stun
                        // goes is the spell's job more than the spec's.
                        if (($link['cat'] ?? null) === 'control' && in_array($link['role'] ?? null, ['healer', 'target', 'cross'], true)) {
                            $control[$link['spell']][$link['by']][$link['role']] = ($control[$link['spell']][$link['by']][$link['role']] ?? 0) + 1;

                            if ($link['role'] === 'healer' && $spec) {
                                $onHealer[$spec][$link['by']][] = $link;
                            }
                        }

                        if (($link['cat'] ?? null) === 'offensive' && $spec) {
                            $pressed[$spec][$link['by']][$link['spell']] = true;
                        }
                    }

                    // Each player's own run of control on the healer: their spells in order, each
                    // within RUN_GAP of the last. Hunter's "Intimidation > Freezing Trap" is one.
                    foreach ($onHealer as $spec => $byPlayer) {
                        foreach ($byPlayer as $links) {
                            $run = [];
                            $last = null;

                            foreach ($links as $link) {
                                if ($last !== null && $link['t'] - $last > self::RUN_GAP) {
                                    break;
                                }
                                if (! in_array($link['spell'], $run, true)) {
                                    $run[] = $link['spell'];
                                }
                                $last = $link['t'];
                            }

                            $healerGoes[$spec] = ($healerGoes[$spec] ?? 0) + 1;
                            $key = implode(' > ', $run);
                            $runs[$spec][$key] = ($runs[$spec][$key] ?? 0) + 1;
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

        // Every spec seen doing either: a healer can lock a healer without ever pressing an
        // offensive cooldown in a go.
        $all = array_unique([...array_keys($count), ...array_keys($runs)]);
        sort($all);
        $specs = [];

        foreach ($all as $spec) {
            $spells = $count[$spec] ?? [];
            $specRuns = $runs[$spec] ?? [];
            arsort($spells);
            arsort($specRuns);
            $specs[(string) $spec] = [
                'goes' => $goes[$spec] ?? 0,
                'players' => count($players[$spec] ?? []),
                'spells' => collect($spells)->map(fn ($n, $name) => ['name' => $name, 'share' => round($n / $goes[$spec], 2)])->values()->all(),
                'healerGoes' => $healerGoes[$spec] ?? 0,
                'healerRuns' => collect($specRuns)->take(6)
                    ->map(fn ($n, $run) => ['chain' => explode(' > ', $run), 'share' => round($n / $healerGoes[$spec], 2)])
                    ->values()->all(),
            ];
        }

        File::ensureDirectoryExists(base_path(dirname(self::PATH)));
        File::put(base_path(self::PATH), json_encode([
            'generatedAt' => now()->toDateString(),
            'rounds' => $rounds,
            'specs' => $specs,
            'control' => collect($control)->sortKeys()->map(fn ($byPlayer) => $this->placement($byPlayer))->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info(count($specs).' specs from '.$rounds.' rounds -> '.self::PATH);

        return self::SUCCESS;
    }

    /**
     * Where one spell lands, counted per player as well as per cast. Per cast, one player who
     * plays a lot decides it: Rastic puts Maim on the healer 108 to 36 and Crawlordx on the target
     * 64 to 22, and Rastic's count won. `vote` is the mean, over players with at least
     * VOTE_MIN placements on a healer or a target, of each one's share on the target; `voters`
     * is how many there were. A vote near the middle means players use it both ways.
     *
     * @param  array<string, array<string, int>>  $byPlayer  guid => role => count
     */
    private function placement(array $byPlayer): array
    {
        $sum = fn (string $role) => array_sum(array_map(fn ($r) => $r[$role] ?? 0, $byPlayer));
        $shares = collect($byPlayer)
            ->filter(fn ($r) => ($r['healer'] ?? 0) + ($r['target'] ?? 0) >= self::VOTE_MIN)
            ->map(fn ($r) => ($r['target'] ?? 0) / (($r['healer'] ?? 0) + ($r['target'] ?? 0)));

        return [
            'healer' => $sum('healer'), 'target' => $sum('target'), 'cross' => $sum('cross'),
            'voters' => $shares->count(),
            'vote' => $shares->isEmpty() ? null : round($shares->avg(), 2),
        ];
    }
}
