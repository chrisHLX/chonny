<?php

namespace App\Console\Commands;

use App\Models\Patch;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Runs wow:discover-spec-spells for EVERY specialization in the database, one at a time,
 * writing findings to baseline-spec-overrides.txt as it goes (--apply --no-reimport — see
 * that command's docblock) but deliberately running import:spelldata only ONCE at the very
 * end, not once per spec — a full re-import takes minutes (see the memory-limit note in
 * DiffArenaSpells), so importing per-spec across ~40 specs would dominate total runtime for
 * no benefit; nothing needs to be live until the whole batch is done anyway.
 *
 * Built 2026-08-14 on direct user instruction, explicitly overriding this project's own
 * earlier "one spec per invocation, check in before bulk-running against a live third-party
 * site" caution (see wow:discover-spec-spells's docblock) — that caution was about checking
 * in first, not a hard rule, and the user made the call directly.
 *
 * One spec's failure (no match found, fetch error, etc.) does not abort the batch — logged
 * and skipped, same as any other per-item batch job in this codebase. A small delay between
 * specs (--delay, default 1s) is a basic courtesy to the third-party API, not a requirement
 * — no rate limit has ever been observed on this endpoint (see arena-log-api.md).
 *
 * Usage: php artisan wow:discover-all-specs
 *        php artisan wow:discover-all-specs --bracket=3v3 --pages=3 --delay=1
 */
class DiscoverAllSpecs extends Command
{
    protected $signature = 'wow:discover-all-specs
        {--bracket=3v3}
        {--pages=3}
        {--delay=1 : Seconds to sleep between specs}';

    protected $description = 'Run wow:discover-spec-spells --apply for every spec, then import:spelldata once at the end';

    public function handle(): int
    {
        $bracket = $this->option('bracket');
        $pages = (int) $this->option('pages');
        $delay = (int) $this->option('delay');

        $specs = Specialization::with('gameClass')->get()
            ->filter(fn ($s) => $s->gameClass !== null)
            ->sortBy([['gameClass.name', 'asc'], ['name', 'asc']])
            ->values();

        $this->info("Running discovery for {$specs->count()} specs (bracket={$bracket}, pages={$pages})...");
        $this->newLine();

        $summary = ['ok' => 0, 'no_match' => 0, 'failed' => 0];

        foreach ($specs as $i => $spec) {
            $classSlug = $spec->gameClass->slug;
            $specSlug = $spec->slug;
            $label = "[".($i + 1)."/{$specs->count()}] {$spec->gameClass->name}/{$spec->name}";

            $this->info("=== {$label} ===");

            try {
                $exitCode = $this->call('wow:discover-spec-spells', [
                    'classSlug' => $classSlug,
                    'specSlug' => $specSlug,
                    '--bracket' => $bracket,
                    '--pages' => $pages,
                    '--apply' => true,
                    '--no-reimport' => true,
                ]);

                $exitCode === self::SUCCESS ? $summary['ok']++ : $summary['failed']++;
            } catch (\Throwable $e) {
                $this->error("  Exception for {$label}: {$e->getMessage()}");
                $summary['failed']++;
            }

            $this->newLine();

            if ($delay > 0 && $i < $specs->count() - 1) {
                sleep($delay);
            }
        }

        $this->info("Discovery pass complete: {$summary['ok']} ran cleanly, {$summary['failed']} failed.");
        $this->newLine();

        $patch = Patch::where('is_current', true)->first();

        if (!$patch) {
            $this->warn('No current patch found — skipping the final import:spelldata. Run it manually.');

            return self::SUCCESS;
        }

        $this->info("Running the single end-of-batch import:spelldata wow {$patch->build_version}...");

        $result = Process::timeout(600)->run([
            PHP_BINARY, '-d', 'memory_limit=512M', base_path('artisan'), 'import:spelldata', 'wow', $patch->build_version,
        ]);

        if (!$result->successful()) {
            $this->error('Final import failed — override lines were written across the batch but are not yet applied. Output:');
            $this->line($result->errorOutput());

            return self::FAILURE;
        }

        $this->info('Import complete. All batch findings are now live.');

        return self::SUCCESS;
    }
}
