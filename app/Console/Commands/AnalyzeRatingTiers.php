<?php

namespace App\Console\Commands;

use App\Http\Services\RatingTierAnalysisService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Turns the ad hoc rating-tier comparison scripting from the 2026-08-15 Havoc DH session (raw
 * damage/spell-cast-rate/CC/interrupt/death/win-loss/kill-window analysis, previously a set of
 * throwaway Node.js scratchpad scripts run by hand) into a real, repeatable command — direct user
 * request after asking "are you automating this or reviewing it manually" (it was manual).
 *
 * Reads only matches already on disk under data/arena-logs/ — pulling more is a separate,
 * deliberate step via wow:pull-low-rated-spec / wow:discover-spec-spells / wow:pull-scarce-specs.
 * This command purely computes and persists; it never fetches.
 *
 * Output: data/arena-logs/rating-tiers/{classSlug}/{specSlug}.json — a JSON blob (not JSONL,
 * unlike spell-usage/kill-sequences — see RatingTierAnalysisService's docblock for why), fully
 * regenerated on every run from whatever matches currently exist on disk. Same "research phase,
 * not a DB table yet" posture as the Kill Sequence tab (data/arena-logs/kill-sequences/*.jsonl,
 * read live by WowComps::killSequenceDataFor() with no caching layer) — this file is meant to be
 * read the same way by a future UI, not treated as a migration-ready schema yet.
 *
 * Bands default to a 3-tier split (sub-2100 / 2100-2400 / 2400+) covering the two comparisons run
 * by hand this session, overridable via --bands for a different cut (e.g. the original ~2800 vs
 * ~2100 split). Format: comma-separated "label:min-max" triples, max exclusive; use a large
 *
 * Each band's numbers are also broken down by hero talent tree (RatingTierAnalysisService::
 * detectHeroTree(), parsed directly from each match's own COMBATANT_INFO talent picks — not
 * guessed from ability names) — added 2026-08-15 after a real report that Havoc's cast-rate/
 * damage-share numbers were silently averaging together two different hero-tree playstyles
 * (Aldrachi Reaver vs Fel-Scarred), which diluted signal for hero-tree-exclusive abilities like
 * Fury of the Aldrachi. See `heroTreeBreakdown` in the output JSON, or the `[TreeName] ...` lines
 * this command prints under each band. A class with no hero trees, or a match whose
 * COMBATANT_INFO didn't resolve, groups under 'unknown' rather than being dropped.
 * number (e.g. 99999) for an open-ended top band.
 *
 * Usage:
 *   php artisan wow:analyze-rating-tiers demonhunter havoc
 *   php artisan wow:analyze-rating-tiers demonhunter havoc --bands="sub-2100:0-2100,2100-2400:2100-2400,2400-plus:2400-99999"
 *   php artisan wow:analyze-rating-tiers --all-specs   (loops every spec with at least --min-matches on disk)
 *
 * --all-specs decompresses every raw log for every eligible spec in one PHP process — confirmed
 * 2026-08-15 to exceed the default 128MB CLI memory limit once past ~100 matches for a single
 * spec (same class of issue already documented for `import:spelldata` in this codebase). Run
 * with a raised limit for --all-specs: `php -d memory_limit=512M artisan wow:analyze-rating-tiers --all-specs`.
 * A single classSlug/specSlug run stays comfortably under the default limit.
 */
class AnalyzeRatingTiers extends Command
{
    protected $signature = 'wow:analyze-rating-tiers
        {classSlug? : Omit along with specSlug when using --all-specs}
        {specSlug?}
        {--bracket=3v3}
        {--bands=sub-2100:0-2100,2100-2400:2100-2400,2400-plus:2400-99999}
        {--all-specs : Loop every spec that has at least --min-matches matches on disk for this bracket}
        {--min-matches=15 : Minimum on-disk matches required for a spec to be included under --all-specs}';

    protected $description = 'Compute and persist a rating-band comparison (damage, spells, CC, deaths, win/loss, kill-window) for one spec or every spec with enough data on disk';

    public function handle(RatingTierAnalysisService $service): int
    {
        $bands = $this->parseBands($this->option('bands'));

        if ($bands === null) {
            $this->error('Could not parse --bands. Expected format: label:min-max,label:min-max,...');

            return self::FAILURE;
        }

        if ($this->option('all-specs')) {
            return $this->handleAllSpecs($service, $bands);
        }

        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

        if (!$classSlug || !$specSlug) {
            $this->error('Pass both classSlug and specSlug, or use --all-specs.');

            return self::FAILURE;
        }

        $spec = $this->resolveSpec($classSlug, $specSlug);

        if ($spec === null) {
            return self::FAILURE;
        }

        $this->analyzeAndStore($service, $spec, $classSlug, $specSlug, $bands, verbose: true);

        return self::SUCCESS;
    }

    private function handleAllSpecs(RatingTierAnalysisService $service, array $bands): int
    {
        $bracket = $this->option('bracket');
        $minMatches = (int) $this->option('min-matches');

        $specs = Specialization::with('gameClass')->get()->filter(fn ($s) => $s->gameClass !== null);
        $eligible = [];

        foreach ($specs as $spec) {
            $count = $this->countMatchesOnDisk($spec->external_spec_id, $bracket);
            if ($count >= $minMatches) {
                $eligible[] = ['spec' => $spec, 'count' => $count];
            }
        }

        if ($eligible === []) {
            $this->warn("No spec has {$minMatches}+ matches on disk for bracket {$bracket}. Pull more first (wow:pull-scarce-specs / wow:discover-spec-spells).");

            return self::SUCCESS;
        }

        usort($eligible, fn ($a, $b) => $b['count'] <=> $a['count']);

        $this->info(count($eligible)." spec(s) with {$minMatches}+ matches on disk:");
        foreach ($eligible as $row) {
            $this->line("  {$row['count']}  {$row['spec']->gameClass->name} / {$row['spec']->name}");
        }
        $this->newLine();

        foreach ($eligible as $row) {
            $spec = $row['spec'];
            $classSlug = $spec->gameClass->slug;
            $specSlug = $spec->slug;
            $this->info("=== {$spec->gameClass->name} / {$spec->name} ===");
            $this->analyzeAndStore($service, $spec, $classSlug, $specSlug, $bands, verbose: false);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function analyzeAndStore(RatingTierAnalysisService $service, Specialization $spec, string $classSlug, string $specSlug, array $bands, bool $verbose): void
    {
        $result = $service->analyzeSpec($spec->external_spec_id, $this->option('bracket'), $bands);

        foreach ($result['bands'] as $band) {
            if ($band['n'] === 0) {
                $this->line("  {$band['label']}: no matches on disk in this range");

                continue;
            }

            $this->line(sprintf(
                '  %s: n=%d (%d players)  avgRating=%s  dps=%s  castsPerMin=%s  winRate=%s%%  deaths=%s',
                $band['label'],
                $band['n'],
                $band['players'],
                $band['avgRating'],
                $band['avgDps'],
                $band['avgCastsPerMin'],
                $band['winRate'] ?? 'n/a',
                $band['avgDeaths'],
            ));

            if ($verbose) {
                $this->line("    avgDeathsInWins={$band['avgDeathsInWins']}  avgDeathsInLosses={$band['avgDeathsInLosses']}  killWindow.n={$band['killWindow']['n']}");
            }

            foreach ($band['heroTreeBreakdown'] ?? [] as $treeName => $treeStats) {
                if (($treeStats['n'] ?? 0) === 0) {
                    continue;
                }
                $this->line(sprintf(
                    '    [%s] n=%d  dps=%s  castsPerMin=%s  winRate=%s%%  deaths=%s',
                    $treeName,
                    $treeStats['n'],
                    $treeStats['avgDps'],
                    $treeStats['avgCastsPerMin'],
                    $treeStats['winRate'] ?? 'n/a',
                    $treeStats['avgDeaths'],
                ));
            }
        }

        $dir = base_path("data/arena-logs/rating-tiers/{$classSlug}");
        File::ensureDirectoryExists($dir);
        File::put(
            "{$dir}/{$specSlug}.json",
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        $this->info("  → data/arena-logs/rating-tiers/{$classSlug}/{$specSlug}.json");
    }

    private function countMatchesOnDisk(int $specExternalId, string $bracket): int
    {
        $count = 0;
        foreach (File::glob(base_path('data/arena-logs/metadata/*.json')) as $path) {
            $meta = json_decode(File::get($path), true);
            if (!$meta || ($meta['startInfo']['bracket'] ?? null) !== $bracket) {
                continue;
            }
            foreach ($meta['units'] ?? [] as $unit) {
                if (str_starts_with($unit['id'], 'Player-') && (int) ($unit['spec'] ?? 0) === $specExternalId) {
                    $count++;

                    break;
                }
            }
        }

        return $count;
    }

    private function resolveSpec(string $classSlug, string $specSlug): ?Specialization
    {
        $class = GameClass::where('slug', $classSlug)->first();
        if (!$class) {
            $this->error("No class found for slug '{$classSlug}'.");

            return null;
        }

        $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();
        if (!$spec) {
            $this->error("No spec found for slug '{$specSlug}' under {$class->name}.");

            return null;
        }

        return $spec;
    }

    /**
     * @return array<int, array{label: string, min: int, max: int}>|null
     */
    private function parseBands(string $raw): ?array
    {
        $bands = [];

        foreach (explode(',', $raw) as $chunk) {
            if (!preg_match('/^([a-zA-Z0-9_-]+):(\d+)-(\d+)$/', trim($chunk), $m)) {
                return null;
            }
            $bands[] = ['label' => $m[1], 'min' => (int) $m[2], 'max' => (int) $m[3]];
        }

        return $bands === [] ? null : $bands;
    }
}
