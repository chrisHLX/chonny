<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Computes and writes the "Burst Guide" spell_id sequence for every real spec (or one
 * class/spec via --only), using ArenaLogService::buildBurstGuideSequence() — see that method's
 * own docblock for the full filter/truncation design.
 *
 * Writes data/claudes-guides/burst-guides/{classSlug}/{specSlug}.json — deliberately a new
 * subfolder under data/claudes-guides/ rather than overwriting/extending the existing
 * {classSlug}/{specSlug}.json guide files, since those are hand-written-per-spec (only 11 of the
 * 38 real specs have one) and this command's output covers every spec that has real rotation
 * data on file, independent of whether a hand-written guide exists for it. Stores ONLY the
 * ordered spell_id list plus computation metadata — never a frozen name/cooldown/duration, same
 * "resolve live, don't freeze what will go stale" discipline as every other file in this folder
 * (see data/claudes-guides/README.md). App\Livewire\BurstGuides is the one page that reads this
 * output.
 *
 * Pure local computation from data already on disk (data/arena-logs/rotations/) — zero external
 * calls, zero DB writes beyond the read-only lookups buildBurstGuideSequence() itself needs, so
 * (unlike e.g. wow:import-murlok-defaults, which hits a live third-party site and is deliberately
 * never bulk-run without a reason) this command defaults to processing every spec with rotation
 * data on file — there is no "don't run this in bulk" concern here.
 *
 * Safe to re-run any time (e.g. after wow:refresh-match-derived pulls fresh matches, or after a
 * data/arena-logs/spell-classification/*.json promotion changes what's offensive/defensive) —
 * always fully overwrites the target file, never appends.
 */
class BuildBurstGuides extends Command
{
    protected $signature = 'wow:build-burst-guides {--only= : classSlug or classSlug/specSlug — omit to build every spec with rotation data on file}';

    protected $description = 'Compute and store the filtered, ordered Burst Guide sequence for every spec (or one, via --only) from real archived burst-window data.';

    public function handle(ArenaLogService $service): int
    {
        $files = File::glob(base_path('data/arena-logs/rotations/*/*.json'));
        if ($files === [] || $files === false) {
            $this->error('No data/arena-logs/rotations/*/*.json files found — nothing to build from.');

            return self::FAILURE;
        }

        $only = $this->option('only');
        $onlyClass = null;
        $onlySpec = null;
        if ($only) {
            $parts = explode('/', $only, 2);
            $onlyClass = $parts[0];
            $onlySpec = $parts[1] ?? null;
        }

        $built = 0;
        $skipped = 0;

        foreach ($files as $path) {
            $classSlug = basename(dirname($path));
            $specSlug = basename($path, '.json');

            if ($onlyClass && $classSlug !== $onlyClass) {
                continue;
            }
            if ($onlySpec && $specSlug !== $onlySpec) {
                continue;
            }

            $result = $service->buildBurstGuideSequence($classSlug, $specSlug);
            if (!$result) {
                $this->warn("  {$classSlug}/{$specSlug}: no burst guide computed (no usable window / everything filtered out)");
                $skipped++;

                continue;
            }

            $dir = base_path("data/claudes-guides/burst-guides/{$classSlug}");
            File::ensureDirectoryExists($dir);

            $payload = array_merge([
                'classSlug' => $classSlug,
                'specSlug' => $specSlug,
                'generatedAt' => now()->toIso8601String(),
            ], $result);

            File::put(
                "{$dir}/{$specSlug}.json",
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );

            $storedCount = count($result['spellIds']);
            $truncNote = $result['truncatedAtRepeat'] ? " — stopped at {$storedCount} once it started repeating" : " — no repeat found, showing all {$storedCount}";
            $this->info("  {$classSlug}/{$specSlug}: {$result['rawStepCount']} raw → {$result['filteredStepCount']} after filtering{$truncNote} (from a {$result['sourceLengthSeconds']}s window)");
            $built++;
        }

        $this->line('');
        $this->info("Built {$built}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
