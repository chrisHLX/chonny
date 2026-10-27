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
 *   php artisan wow:translate-spell-names            # look up, then write the report
 *   php artisan wow:translate-spell-names --report   # only the report (every deploy runs this)
 *
 * The report, storage/app/spell-names-to-add.md, lists every name still untranslated by how often it
 * was pressed and by which specs, with a line ready to paste into the hand file
 * (data/spelldata/spell-name-translations.txt). About a second a name to look up. Rebuild the player's
 * pages after (BuildCoachPages) so their cards draw icons.
 */
class TranslateSpellNames extends Command
{
    protected $signature = 'wow:translate-spell-names
        {--user= : Only this user\'s rounds}
        {--report : Only write the report of names still untranslated; look nothing up}';

    protected $description = 'Translate non-English spell names in stored rounds through Blizzard\'s localized names';

    public const REPORT = 'app/spell-names-to-add.md';

    public function handle(SpellNameTranslations $translations): int
    {
        $names = $this->names();
        $todo = array_values(array_diff(array_keys($names), array_keys($translations->english(array_keys($names)))));
        $this->info(count($names).' non-English spell names, '.count($todo).' not yet known.');

        if ($todo !== [] && ! $this->option('report')) {
            $bar = $this->output->createProgressBar(count($todo));
            $stored = $translations->fromBlizzard($todo, fn () => $bar->advance());
            $bar->finish();
            $this->newLine();
            $this->info("{$stored} stored from Blizzard.");
            $todo = array_values(array_diff($todo, array_keys(app(SpellNameTranslations::class)->english($todo))));
        }

        $this->report(array_intersect_key($names, array_flip($todo)));

        return self::SUCCESS;
    }

    /**
     * Every non-English spell name in the rounds, with how often it was pressed and by which specs.
     *
     * @return array<string, array{presses: int, seen: int, specs: array<string, int>}>
     */
    private function names(): array
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

        return $names;
    }

    /** The casts line (with who pressed it), control, and anything stored under `spell`. */
    private function collect(array $analysis, array &$names): void
    {
        $spec = array_column($analysis['players'] ?? [], 'spec', 'guid');
        foreach ($analysis['timeline']['casts'] ?? [] as $guid => $list) {
            foreach ($list as $c) {
                if ($this->add($c[1] ?? null, $names)) {
                    $names[$c[1]]['presses']++;
                    $who = $spec[$guid] ?? '?';
                    $names[$c[1]]['specs'][$who] = ($names[$c[1]]['specs'][$who] ?? 0) + 1;
                }
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

    private function add(mixed $name, array &$names): bool
    {
        if (! is_string($name) || ! SpellNameTranslations::foreign($name)) {
            return false;
        }
        $names[$name] ??= ['presses' => 0, 'seen' => 0, 'specs' => []];
        $names[$name]['seen']++;

        return true;
    }

    /** @param  array<string, array{presses: int, seen: int, specs: array<string, int>}>  $missing */
    private function report(array $missing): void
    {
        uasort($missing, fn ($a, $b) => [$b['presses'], $b['seen']] <=> [$a['presses'], $a['seen']]);
        $lines = [
            '# Spell names to add by hand',
            '',
            'Written by `php artisan wow:translate-spell-names` on '.now()->toDateTimeString().' UTC (every deploy runs it with `--report`).',
            'Every non-English spell name in the stored rounds that neither the rounds nor Blizzard\'s API could translate, most pressed first.',
            '',
            'To add one: find the English name (the specs that pressed it narrow it down), and add the line to',
            '`data/spelldata/spell-name-translations.txt` as `log name|English name|spell id`. Nothing automated writes that file.',
            '"Pet:" names are a pet\'s casts and can usually wait.',
            '',
            '| Presses | Seen | Name | Pressed by | Line to paste |',
            '|---:|---:|---|---|---|',
        ];
        foreach ($missing as $name => $n) {
            arsort($n['specs']);
            $by = implode(', ', array_map(fn ($s, $k) => "{$s} {$k}", array_keys(array_slice($n['specs'], 0, 3, true)), array_slice($n['specs'], 0, 3, true)));
            $lines[] = "| {$n['presses']} | {$n['seen']} | {$name} | {$by} | `{$name}||` |";
        }
        if ($missing === []) {
            $lines[] = '| | | Nothing left to add. | | |';
        }
        file_put_contents(storage_path(self::REPORT), implode("\n", $lines)."\n");

        $top = array_slice(array_keys($missing), 0, 5);
        $this->info(count($missing).' spell name(s) to add by hand'.($top ? ' (most pressed: '.implode(', ', $top).')' : '').' -> '.storage_path(self::REPORT));
    }
}
