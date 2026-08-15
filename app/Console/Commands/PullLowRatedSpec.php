<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;

/**
 * Pulls several low-rated recent matches for one spec — the low-rating counterpart to
 * wow:discover-spec-spells / ArenaLogService::pullTopMatchesForSpec(), which only ever chase
 * the highest rating. Built 2026-08-15 for rating-tier comparisons (e.g. how a 2800 Havoc DH's
 * play differs from a ~2100 one) that need a real sample on the low end, not just whatever
 * happened to already be on disk from earlier comp/spec pulls.
 *
 * Thin wrapper — all the gather/filter/fetch logic lives in
 * ArenaLogService::pullLowRatedMatchesForSpec(). No manifest tracking (unlike the "best match"
 * pullers) — there's no single canonical low-rated match to converge on, so this just grabs up
 * to --top distinct matches at or under --max-rating on each run.
 *
 * Usage:
 *   php artisan wow:pull-low-rated-spec demonhunter havoc
 *   php artisan wow:pull-low-rated-spec demonhunter havoc --max-rating=2000 --top=15 --pages=8
 */
class PullLowRatedSpec extends Command
{
    protected $signature = 'wow:pull-low-rated-spec
        {classSlug}
        {specSlug}
        {--bracket=3v3}
        {--pages=5 : How many pages of 50 recent matches to scan (offset-paged)}
        {--top=10 : Max number of matches to fetch+store in this run}
        {--max-rating=2300 : Only consider matches at or below this rating}
        {--min-rating=0 : Only consider matches above this rating (for targeting a specific band, e.g. 2100-2400)}';

    protected $description = "Pull several low-rated recent matches for one spec (the low-rating counterpart to wow:discover-spec-spells' highest-rated pull)";

    public function handle(ArenaLogService $service): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

        $class = GameClass::where('slug', $classSlug)->first();
        if (!$class) {
            $this->error("No class found for slug '{$classSlug}'.");

            return self::FAILURE;
        }

        $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();
        if (!$spec) {
            $this->error("No spec found for slug '{$specSlug}' under {$class->name}.");

            return self::FAILURE;
        }

        $bracket = $this->option('bracket');
        $pages = (int) $this->option('pages');
        $top = (int) $this->option('top');
        $maxRating = (int) $this->option('max-rating');
        $minRating = (int) $this->option('min-rating');

        $rangeLabel = $minRating > 0 ? "between {$minRating} and {$maxRating}" : "at or below {$maxRating}";
        $this->info("Scanning up to ".($pages * 50)." recent {$bracket} matches for {$class->name}/{$spec->name} {$rangeLabel} rating...");

        $results = $service->pullLowRatedMatchesForSpec($spec->external_spec_id, $bracket, $pages, $top, $maxRating, $minRating);

        if ($results === []) {
            $this->warn("No matches found at or below {$maxRating} rating in the scanned window. Try a larger --pages or a higher --max-rating.");

            return self::SUCCESS;
        }

        $stored = 0;
        $alreadyOnDisk = 0;
        $failed = 0;

        foreach ($results as $r) {
            $label = match ($r['status']) {
                'stored' => '✓ stored',
                'already_on_disk' => '· already on disk',
                'fetch_failed' => '✗ fetch failed',
                default => $r['status'],
            };
            $this->line("  {$r['matchId']}  rating={$r['rating']}  {$label}");

            match ($r['status']) {
                'stored' => $stored++,
                'already_on_disk' => $alreadyOnDisk++,
                'fetch_failed' => $failed++,
                default => null,
            };
        }

        $this->newLine();
        $this->info("Done: {$stored} newly stored, {$alreadyOnDisk} already on disk, {$failed} failed.");

        return self::SUCCESS;
    }
}
