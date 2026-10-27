<?php

namespace App\Console\Commands;

use App\Http\Services\SpellNameTranslations;
use App\Models\ArenaRound;
use Illuminate\Console\Command;

/**
 * The English name for every non-English spell name in the stored rounds (2026-10-27,
 * docs/international.md), from Blizzard's localized names. Rounds uploaded from now on teach their
 * own names as they arrive (ArenaReviewIngestService); this is for the ones stored before, and is
 * safe to run again: only names not yet known are looked up.
 *
 *   php artisan wow:translate-spell-names
 *
 * About a second a name. Rebuild the player's pages after (BuildCoachPages) so their cards draw icons.
 */
class TranslateSpellNames extends Command
{
    protected $signature = 'wow:translate-spell-names {--user= : Only this user\'s rounds}';

    protected $description = 'Translate non-English spell names in stored rounds through Blizzard\'s localized names';

    public function handle(SpellNameTranslations $translations): int
    {
        $names = [];
        ArenaRound::query()
            ->when($this->option('user'), fn ($q, $user) => $q->where('user_id', (int) $user))
            ->select(['id', 'payload'])
            ->chunkById(50, function ($rounds) use (&$names) {
                foreach ($rounds as $round) {
                    $p = is_string($round->payload) ? json_decode($round->payload, true) : (array) $round->payload;
                    $this->collect($p['analysis'] ?? $p, $names);
                }
            });

        $todo = array_values(array_diff(array_keys($names), array_keys($translations->english(array_keys($names)))));
        $this->info(count($names).' non-English spell names, '.count($todo).' not yet known.');
        if ($todo === []) {
            return self::SUCCESS;
        }

        $missed = [];
        $bar = $this->output->createProgressBar(count($todo));
        $stored = $translations->fromBlizzard($todo, function (string $name, bool $found) use ($bar, &$missed) {
            if (! $found) {
                $missed[] = $name;
            }
            $bar->advance();
        });
        $bar->finish();
        $this->newLine();
        $this->info("{$stored} stored.".($missed ? ' Not found: '.implode(', ', array_slice($missed, 0, 30)).(count($missed) > 30 ? ' ...' : '') : ''));

        return self::SUCCESS;
    }

    /** Every spell name in a round: the casts line, control, and anything stored under `spell`. */
    private function collect(array $analysis, array &$names): void
    {
        foreach ($analysis['timeline']['casts'] ?? [] as $list) {
            foreach ($list as $c) {
                $this->add($c[1] ?? null, $names);
            }
        }
        foreach ($analysis['timeline']['control'] ?? [] as $c) {
            $this->add($c[2] ?? null, $names);
        }
        array_walk_recursive($analysis, function ($value, $key) use (&$names) {
            if ($key === 'spell') {
                $this->add($value, $names);
            }
        });
    }

    private function add(mixed $name, array &$names): void
    {
        if (is_string($name) && SpellNameTranslations::foreign($name)) {
            $names[$name] = true;
        }
    }
}
