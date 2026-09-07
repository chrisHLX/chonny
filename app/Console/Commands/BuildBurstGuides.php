<?php

namespace App\Console\Commands;

use App\Http\Services\BurstGuideBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Computes and writes the Burst Guide for every real spec (or one class/spec via --only), using
 * App\Http\Services\BurstGuideBuilder — see that class's docblock for the full derivation.
 *
 * Writes data/claudes-guides/burst-guides/{classSlug}/{specSlug}.json. Stores spell_ids plus
 * measured statistics only — never a frozen name, cooldown or duration, same "resolve live,
 * don't freeze what will go stale" discipline as every other file in that folder (see
 * data/claudes-guides/README.md). App\Livewire\BurstGuides is the one page that reads it.
 *
 * READS THE ARENA ARCHIVE, NOT THIS REPO. The per-window corpus this aggregates
 * ({ARENA_LOG_ARCHIVE_PATH}/rotations/{class}/{spec}.jsonl) is hundreds of megabytes across 38
 * specs and is deliberately not committed here — only the small computed result is. That makes
 * this a build-time command like the data/spelldata/fetch-*.php scripts: it runs on a machine
 * that has the archive, and its committed output is what production actually serves. A spec
 * whose corpus is missing is reported and skipped, never silently degraded to a weaker source.
 *
 * Which specs are eligible is gated on this repo's own promoted rotation file existing too (see
 * BurstGuideBuilder::isBuildable()) — that file is what RefreshMatchDerived deletes when a spec
 * stops producing windows, so a spec whose matches were culled cannot be rebuilt out of a stale
 * archive corpus.
 *
 * Pure local computation from data already on disk — zero external calls, zero DB writes beyond
 * the read-only lookups the builder needs — so (unlike e.g. wow:import-murlok-defaults, which
 * hits a live third-party site) there is no "don't run this in bulk" concern: it defaults to
 * every eligible spec. Safe to re-run at any time; always fully overwrites its target file,
 * never appends. Run automatically as the final step of wow:refresh-match-derived.
 */
class BuildBurstGuides extends Command
{
    protected $signature = 'wow:build-burst-guides {--only= : classSlug or classSlug/specSlug — omit to build every eligible spec}';

    protected $description = 'Aggregate every archived burst window per spec into a phased Burst Guide (setup / commit / execute, measured go length and globals).';

    public function handle(BurstGuideBuilder $builder): int
    {
        $files = File::glob(base_path('data/arena-logs/rotations/*/*.json'));
        if ($files === [] || $files === false) {
            $this->error('No data/arena-logs/rotations/*/*.json files found — nothing to build for.');

            return self::FAILURE;
        }

        [$onlyClass, $onlySpec] = $this->parseOnly();

        $built = 0;
        $skipped = 0;
        $noCorpus = 0;

        foreach ($files as $path) {
            $classSlug = basename(dirname($path));
            $specSlug = basename($path, '.json');

            if ($onlyClass !== null && $classSlug !== $onlyClass) {
                continue;
            }
            if ($onlySpec !== null && $specSlug !== $onlySpec) {
                continue;
            }

            if (! File::exists($builder->corpusPath($classSlug, $specSlug))) {
                $this->warn("  {$classSlug}/{$specSlug}: no window corpus at {$builder->corpusPath($classSlug, $specSlug)} — skipped");
                $noCorpus++;

                continue;
            }

            $result = $builder->build($classSlug, $specSlug);
            if ($result === null) {
                $this->warn("  {$classSlug}/{$specSlug}: no guide computed (no usable anchor, or too few anchored windows)");
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
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );

            $this->info(sprintf(
                '  %s/%s: %d steps + %d fill from %d anchored windows (%d total, %d matches) — %.1fs go, %d globals @ %.2fs GCD%s',
                $classSlug,
                $specSlug,
                count($result['sequence']),
                count($result['fill']),
                $result['evidence']['anchoredWindows'],
                $result['evidence']['windows'],
                $result['evidence']['matches'],
                $result['window']['goLengthSeconds'],
                $result['window']['globals'],
                $result['evidence']['gcdSeconds'],
                $result['evidence']['gcdMeasured'] ? '' : ' (GCD not measurable — base 1.5s assumed)'
            ));
            $built++;
        }

        $pruned = $this->pruneStaleGuides($onlyClass, $onlySpec);

        // Deliberately does NOT bump the global spell cache version. It did at first, because the
        // page's cache was keyed only on that counter and rewriting these files was otherwise
        // invisible — but that counter also keys all 40 precomputed spell kits, so bumping it here
        // silently invalidated every one of them and dropped WoW Comps and Spell Explorer onto
        // their slow live-compute fallback until something regenerated them. Far too wide a blast
        // radius for writing a few small JSON files. BurstGuideClassBlock now includes a signature
        // of these files in its own cache key instead (see guideFilesSignature()), so this
        // command's output invalidates exactly its own page and nothing else.
        $this->line('');
        $this->info("Built {$built}, skipped {$skipped}, no corpus {$noCorpus}, pruned {$pruned}.");

        if ($noCorpus > 0) {
            $this->line('  Set ARENA_LOG_ARCHIVE_PATH to the wow-arena-archive checkout to build those specs.');
        }

        return self::SUCCESS;
    }

    /**
     * Deletes guide files for specs that no longer have a promoted rotation, mirroring
     * RefreshMatchDerived::promoteRotations()'s own handling of the same situation: writing is
     * not enough on its own, because a spec can STOP producing windows and nothing else would
     * ever remove what it left behind.
     *
     * Not hypothetical — this was added because the test suite caught four real stale files
     * (Blood Death Knight, Guardian Druid, Augmentation Evoker, Protection Warrior). Those specs
     * lost all their matches in the 2026-09-05 archive cull and their rotations were correctly
     * removed at the time, but their burst guides stayed on disk and kept being served, built
     * from matches that had been deliberately deleted for being unrepresentative.
     *
     * Scoped to whatever --only narrowed the run to, so `--only=rogue` can never delete another
     * class's guides.
     */
    private function pruneStaleGuides(?string $onlyClass, ?string $onlySpec): int
    {
        $guides = File::glob(base_path('data/claudes-guides/burst-guides/*/*.json'));
        if ($guides === [] || $guides === false) {
            return 0;
        }

        $pruned = 0;
        foreach ($guides as $path) {
            $classSlug = basename(dirname($path));
            $specSlug = basename($path, '.json');

            if ($onlyClass !== null && $classSlug !== $onlyClass) {
                continue;
            }
            if ($onlySpec !== null && $specSlug !== $onlySpec) {
                continue;
            }
            if (File::exists(base_path("data/arena-logs/rotations/{$classSlug}/{$specSlug}.json"))) {
                continue;
            }

            File::delete($path);
            $this->warn("  {$classSlug}/{$specSlug}: removed — no promoted rotation data for this spec any more");
            $pruned++;
        }

        return $pruned;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function parseOnly(): array
    {
        $only = $this->option('only');
        if (! $only) {
            return [null, null];
        }

        $parts = explode('/', $only, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
