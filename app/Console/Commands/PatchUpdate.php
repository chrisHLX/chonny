<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Orchestrates the mechanical, safe half of a WoW patch update — see the "Patch 12.1
 * Readiness" analysis (CLAUDE.md pointer: search "patch update runbook") for the full
 * reasoning behind what is and isn't automated here.
 *
 * Runs, in order: fetch-talent-trees.php, fetch-simc-dumps.php, regenerate-filtered.php,
 * import:spelldata, fetch-spell-icons.php. All additive — see ImportSpellData's own docblock
 * and CLAUDE.md's patch_id-scoping explanation for why none of this can touch existing users,
 * diagnostics, or a prior patch's data.
 *
 * The spellbook-snapshot sanity check used to be this orchestrator's own step 6/6 — moved
 * INTO ImportSpellData::handle() itself (2026-08-13) so it also fires on a plain
 * `import:spelldata` re-run, not only through this full orchestrator. Step 4/5 below (the
 * import call) now triggers it as a side effect; nothing further to do here.
 *
 * Deliberately does NOT automate, and never will without a separate explicit decision:
 *   - Admin default talent-build curation (wow:import-murlok-defaults) — CLAUDE.md's own
 *     documented policy for that command is "one spec per invocation, manual/on-demand
 *     only... not license to run this unattended or in bulk," out of respect for hitting a
 *     third-party site. Looping this across every spec here would silently violate that
 *     decision. Printed as a manual checklist item instead.
 *   - Module-linked talent-build re-decoding — bounded to 1-2 modules, needs a human to
 *     judge whether the affected spec's tree actually moved enough to warrant it.
 *   - git deploy — outside this codebase's domain; assumed to already be handled by
 *     whatever deploy process the operator uses.
 *   - Actually looking at the site — no script can do this part.
 */
class PatchUpdate extends Command
{
    protected $signature = 'wow:patch-update
        {build : Patch build version, e.g. 12.1.0.69123}
        {--branch= : SimC branch to pull raw spell dumps from, e.g. midnight or data-update-live-69123}
        {--auto-detect-live : Auto-detect the current data-update-live-* SimC branch instead of --branch=}
        {--only= : Comma-separated class slugs to limit every step to, e.g. --only=priest,hunter}
        {--skip-trees : Skip the Blizzard talent-tree/PvP-talent fetch (fetch-talent-trees.php)}
        {--skip-dumps : Skip the SimC raw spell-dump fetch (fetch-simc-dumps.php)}
        {--skip-icons : Skip the icon backfill (fetch-spell-icons.php)}
        {--current : Mark this patch as the current one once imported}';

    protected $description = 'Runs the mechanical, non-destructive half of a WoW patch data update, end to end.';

    public function handle(): int
    {
        $build = $this->argument('build');
        $only = $this->option('only');
        $branch = $this->option('branch');
        $autoDetect = $this->option('auto-detect-live');

        if (!$this->option('skip-dumps') && !$branch && !$autoDetect) {
            $this->error('Pass --branch=<name> or --auto-detect-live (or --skip-dumps if you already have data/spelldata/raw/ populated).');

            return self::FAILURE;
        }

        $this->step('1/5 — Blizzard talent trees + PvP talents', fn () => $this->option('skip-trees')
            ? $this->skipped()
            : $this->runScript('data/talenttrees/fetch-talent-trees.php', $only ? ["--only={$only}"] : []));

        $this->step('2/5 — SimC raw spell dumps', function () use ($only, $branch, $autoDetect) {
            if ($this->option('skip-dumps')) {
                return $this->skipped();
            }

            $args = $autoDetect ? ['--auto-detect-live'] : ["--branch={$branch}"];
            if ($only) {
                $args[] = "--only={$only}";
            }

            return $this->runScript('data/spelldata/fetch-simc-dumps.php', $args);
        });

        $ok = $this->step('3/5 — Regenerate filtered dumps', fn () => $this->runScript('data/spelldata/regenerate-filtered.php', []));
        if (!$ok) {
            $this->warn('regenerate-filtered.php failed — stopping before the database import. Fix the raw dumps and re-run.');

            return self::FAILURE;
        }

        $this->step('4/5 — Import into the database (also runs the spellbook-snapshot sanity check internally)', function () use ($build, $only) {
            $args = ['game' => 'wow', 'patch' => $build];
            if ($only) {
                $args['--only'] = $only;
            }
            if ($this->option('current')) {
                $args['--current'] = true;
            }

            return $this->call('import:spelldata', $args) === self::SUCCESS;
        });

        $this->step('5/5 — Backfill spell icons', fn () => $this->option('skip-icons')
            ? $this->skipped()
            : $this->runScript('data/spelldata/fetch-spell-icons.php', []));

        $this->newLine();
        $this->info('Mechanical steps done. This did NOT touch users, diagnostics, or the previous patch\'s data — see the patch_id-scoping note in CLAUDE.md if you want to verify that yourself.');
        $this->newLine();
        $this->warn('Manual steps still needed — deliberately not automated, see this command\'s own docblock for why:');
        $this->line('  1. Re-curate admin default talent builds for the specs you actually need:');
        $this->line('       php artisan wow:import-murlok-defaults {spec} {bracket} --apply   (one spec at a time — do not loop this)');
        $this->line('       or by hand at /admin/talent-builds');
        $this->line('  2. Re-decode module-linked talent builds if their spec\'s tree structurally changed');
        $this->line('       (Discipline Priest Oracle, etc. — via TalentSelectionService::getOrCreateModuleBuild())');
        $this->line('  3. Walk the live site — WowComps, Spell Explorer, canonical module pages');
        $this->line('       (prioritize whichever specs actually got reworked this patch)');
        $this->line('  4. Deploy this repo\'s updated data/ files if you ran this locally, not on the server directly');

        return self::SUCCESS;
    }

    /** Runs a step, printing a header first, and reports pass/fail without stopping the whole command (each script already prints its own detail). */
    private function step(string $label, \Closure $fn): bool
    {
        $this->newLine();
        $this->info("=== {$label} ===");
        $ok = (bool) $fn();
        if (!$ok) {
            $this->error("  ✗ {$label} reported a failure — see output above.");
        }

        return $ok;
    }

    private function skipped(): bool
    {
        $this->line('  (skipped)');

        return true;
    }

    /** Shells out to one of the plain (non-Laravel) CLI scripts under data/, streaming its output live. */
    private function runScript(string $relativePath, array $args): bool
    {
        $path = base_path($relativePath);
        $result = Process::timeout(0)
            ->run(['php', $path, ...$args], function (string $type, string $output) {
                $this->output->write($output);
            });

        return $result->successful();
    }
}
