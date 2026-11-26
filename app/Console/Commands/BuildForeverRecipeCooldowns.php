<?php

namespace App\Console\Commands;

use App\Support\ForeverRecipes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Which WoW Forever recipes have a cooldown, from the game's own tables (wago.tools, product `wowf`),
 * baked into the committed data/forever/recipe-cooldowns.json that the Crafting Profits page reads
 * (2026-11-26). A craft you can make once every four days earns its profit once every four days, so
 * the page says so beside the number.
 *
 * SpellCooldowns gives each recipe spell its own cooldown (RecoveryTime: Mooncloth, 342,000,000 ms)
 * or a cooldown shared with its category (CategoryRecoveryTime: Transmute: Arcanite, 169,200,000 ms,
 * category 310 "Transmute - Alchemy" in SpellCategories/SpellCategory, so one transmute starts every
 * transmute's cooldown). Both in milliseconds; the larger is kept.
 *
 *   php artisan forever:recipe-cooldowns {dir} --download
 */
class BuildForeverRecipeCooldowns extends Command
{
    protected $signature = 'forever:recipe-cooldowns
        {dir : A folder of wago.tools wowf DB2 CSVs}
        {--download : Fetch the three tables from wago.tools into the folder first}';

    protected $description = "WoW Forever's recipe cooldowns into data/forever/recipe-cooldowns.json";

    private const TABLES = ['SpellCooldowns', 'SpellCategories', 'SpellCategory'];

    public const PATH = 'data/forever/recipe-cooldowns.json';

    public function handle(): int
    {
        $dir = rtrim($this->argument('dir'), '/\\');
        if ($this->option('download') && ! $this->download($dir)) {
            return self::FAILURE;
        }
        foreach (self::TABLES as $t) {
            if (! is_file("{$dir}/{$t}.csv")) {
                $this->error("{$t}.csv is missing from {$dir} (run with --download)");

                return self::FAILURE;
            }
        }

        $spells = array_flip(array_column(ForeverRecipes::recipes(), 'spell'));
        $groupNames = [];
        foreach ($this->rows("{$dir}/SpellCategory.csv") as $r) {
            $groupNames[(int) $r['ID']] = $r['Name_lang'];
        }
        $category = [];
        foreach ($this->rows("{$dir}/SpellCategories.csv") as $r) {
            if ($r['DifficultyID'] === '0' && isset($spells[(int) $r['SpellID']])) {
                $category[(int) $r['SpellID']] = (int) $r['Category'];
            }
        }

        $out = [];
        foreach ($this->rows("{$dir}/SpellCooldowns.csv") as $r) {
            $spell = (int) $r['SpellID'];
            if ($r['DifficultyID'] !== '0' || ! isset($spells[$spell])) {
                continue;
            }
            $own = (int) $r['RecoveryTime'];
            $shared = (int) $r['CategoryRecoveryTime'];
            $ms = max($own, $shared);
            if ($ms <= 0) {
                continue;
            }
            $group = $shared >= $own && $shared > 0 ? ($groupNames[$category[$spell] ?? 0] ?? '') : '';
            $out[$spell] = [intdiv($ms, 1000), $group];
        }
        ksort($out);

        file_put_contents(base_path(self::PATH), json_encode([
            'source' => 'wago.tools DB2, product wowf: SpellCooldowns, SpellCategories, SpellCategory',
            'build' => $this->build(),
            'about' => 'Recipe spell id -> [cooldown in seconds, the shared cooldown group or ""]. Built by forever:recipe-cooldowns.',
            'cooldowns' => $out,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info(count($out).' recipes with a cooldown -> '.self::PATH);

        return self::SUCCESS;
    }

    /** @return \Generator<int, array<string, string>> */
    private function rows(string $path): \Generator
    {
        $h = fopen($path, 'r');
        $head = fgetcsv($h, escape: '');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        while (($r = fgetcsv($h, escape: '')) !== false) {
            if (count($r) === count($head)) {
                yield array_combine($head, $r);
            }
        }
        fclose($h);
    }

    private function download(string $dir): bool
    {
        @mkdir($dir, 0775, true);
        foreach (self::TABLES as $t) {
            $res = Http::timeout(120)->get("https://wago.tools/db2/{$t}/csv", ['product' => 'wowf']);
            if (! $res->successful() || ! str_contains(strtok($res->body(), "\n"), 'ID')) {
                $this->error("  FAILED {$t}");

                return false;
            }
            file_put_contents("{$dir}/{$t}.csv", $res->body());
            $this->line("  got {$t}");
        }

        return true;
    }

    private function build(): string
    {
        try {
            return (string) (Http::timeout(20)->get('https://wago.tools/api/builds/wowf/latest')->json('version') ?? 'unknown');
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
