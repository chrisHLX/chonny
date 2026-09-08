<?php

namespace App\Console\Concerns;

use Illuminate\Support\Facades\Process;

/**
 * Regenerating the 40 precomputed spell kits after bumping the shared spell-cache version.
 *
 * WHY THIS IS A SHARED CONCERN RATHER THAN A LINE IN EACH COMMAND. Every
 * data/spell-kits/{class}/{spec}.json file embeds the spellCacheVersion and codeFingerprint it
 * was built against, and SpecKitComputer::tryReadPrecomputed() returns null the moment either
 * stops matching. That fallback is SAFE but ~25x more expensive, and — this is the part that
 * makes it dangerous — completely silent: nothing logs, nothing warns, the page just gets slow.
 *
 * So `bumpSpellCacheVersion()` invalidates all 40 files, and any command that bumps without
 * regenerating leaves the WHOLE SITE on the slow path until a human happens to run
 * `wow:precompute-spell-kits` by hand. That has now happened twice:
 *
 *   - 2026-09-05: 37 of 40 files found stale across several import runs. Fixed in
 *     ImportSpellData only.
 *   - 2026-09-08: all 40 found stale again (file v8, live v10), from the four OTHER commands that
 *     bump — ApplyDefaultTalents, RebuildSpellCounters, RefreshSpellIcons, RefreshMatchDerived —
 *     none of which had been given the same fix. Measured at the time: one user-guide palette
 *     build cost 9,545ms and 4,290 queries stale, against 376ms and 87 fresh, which is what a
 *     report of "selecting abilities takes a while to register" actually was.
 *
 * The pattern is the bug: a fix applied at one of five call sites is not a fix. Every bump site
 * now calls this, so adding a sixth means calling one method rather than remembering a rationale.
 *
 * Shelled out as its own subprocess rather than $this->call(), matching
 * RefreshMatchDerived::callArtisan(): computing all 40 kits inside an already-heavy process
 * compounds memory in the way that class's own docblock documents.
 */
trait RegeneratesSpellKits
{
    protected function regenerateSpellKits(): void
    {
        $this->newLine();
        $this->line('Regenerating precomputed spell kits (the version bump above invalidated all 40)...');

        $result = Process::timeout(0)->run(
            ['php', '-d', 'memory_limit=1024M', base_path('artisan'), 'wow:precompute-spell-kits'],
            fn (string $type, string $output) => $this->output->write($output)
        );

        if (! $result->successful()) {
            // Never fatal: the caller's own work is already done and committed, and a stale kit
            // is slow rather than wrong. Loud, though — silence is exactly what let this sit
            // unnoticed twice.
            $this->error('Spell-kit regeneration FAILED. Every page is on the slow live-compute');
            $this->error('path until `php artisan wow:precompute-spell-kits` is run by hand.');
        }
    }
}
