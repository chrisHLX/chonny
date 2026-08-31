<?php

namespace App\Console\Commands;

use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Orchestrates every LIVE, match-data-derived surface's regeneration in one command — built
 * 2026-08-31 after a real, confirmed gap: pulling 100 new matches this same day only had
 * wow:extract-arena-spells and wow:find-cc-chains run against it, and even find-cc-chains was
 * initially run WITHOUT --json, silently leaving the actually-live-consumed JSON corpus 8 days
 * stale while the .txt sibling looked fresh. Burst Windows/mechanics/Class Guide were untouched
 * entirely. Same "orchestrate the mechanical half in one command" precedent as wow:patch-update
 * on the spelldata side — this is that command's counterpart for the arena-log-derived side.
 *
 * Run this after ANY wow:pull-latest-matches / wow:pull-scarce-specs / wow:discover-all-specs /
 * wow:pull-comp-log / wow:pull-low-rated-spec — anything that adds matches to the archive.
 * wow:pull-latest-matches prints a reminder pointing here when it actually pulls new matches.
 *
 * ---- Two tiers, deliberately not treated the same way ----
 *
 * TIER 1 — safe to fully automate, run unconditionally below. Each of these either writes
 * directly to this repo's own live-read data/arena-logs/ path (no promotion gate exists at all),
 * or its promotion step is a plain mechanical file copy with no documented review requirement:
 *   1. wow:find-cc-chains --json  — cc-chains/*.json, read live by CcChainStatsService ->
 *      CcFormulaService -> WowComps' Crowd Control section.
 *   2. wow-arena-archive/scripts/all-spec-rotations.php — regenerates every spec's burst-window
 *      rotation.export.json in the STAGING archive.
 *   3. Promote step 2's output — plain File::copy() into data/arena-logs/rotations/{class}/
 *      {spec}.json (this repo's own live-read copy). No human-review gate is documented for
 *      rotations anywhere in this codebase (unlike spell-usage — see Tier 2 below) — this is a
 *      pure repo-boundary artifact (the script lives in a sibling repo), not a deliberate
 *      checkpoint, so automating the copy doesn't override anyone's design decision.
 *   4. wow:enrich-rotation-talents — mutates each promoted window in place with the real talent
 *      build the match's own player had selected.
 *   5. wow:enrich-rotation-mechanics — mutates each promoted window in place with real champion/
 *      target buff+debuff facts for that exact window.
 *   6. wow:analyze-spec-playstyle {class} {spec}, looped over every real spec — writes directly
 *      to data/arena-logs/playstyle/{class}/{spec}.json, read live by the Class Guide page.
 *      Skippable via --skip-playstyle — the slowest step (~40 specs x a real N-match analysis
 *      each), worth skipping for a quick CC/burst-window-only refresh.
 *   7. TalentSelectionService::bumpSpellCacheVersion() — every step above changes what a cached
 *      wow_spell_references:* entry would compute, and forgetting this bump is the single most
 *      recurring class of bug documented in CLAUDE.md this whole project. Automatic, every run,
 *      unconditionally — there is no scenario where skipping it is correct.
 *
 * TIER 2 — deliberately NOT run here, printed as a manual checklist instead. Both have an
 * EXPLICIT "requires human review before going live" note already on record — overriding that
 * silently here would be exactly the kind of "fix" config/arena_logs.php's own docblock already
 * warns not to make without re-reading it first:
 *   - wow:extract-arena-spells --all — writes to the STAGING spell-usage/ copy only; promoting
 *     it into this repo's own data/arena-logs/spell-usage/ is documented as a deliberate manual
 *     "only once you're confident it's correct" gate (config/arena_logs.php's own docblock).
 *   - wow-arena-archive/scripts/classify-cooldowns.php + all-spec-cooldowns.php — feeds
 *     ArenaLogService::offensiveDefensiveClassification() (live in WowComps), and that method's
 *     own docblock says outright: "produced by... classify-cooldowns.php... then MANUALLY
 *     PROMOTED HERE ONCE REVIEWED. NOT computed live by this project." Same posture.
 *   Neither depends on anything Tier 1 already ran, so running Tier 2 by hand afterward (or
 *   before, order doesn't matter between the two tiers) is always safe.
 *
 * Also NOT run here, unrelated to "new matches landed": wow:import-murlok-defaults (talent-build
 * meta-picks, not match-derived at all — its own documented policy already covers when to run
 * it) and anything under wow:diff-arena-spells/wow:discover-cc-spells --apply (spell-curation
 * tools that read the spell-usage corpus, which Tier 2 above already gates).
 */
class RefreshMatchDerived extends Command
{
    protected $signature = 'wow:refresh-match-derived
        {--skip-cc-chains : Skip step 1 (wow:find-cc-chains --json)}
        {--skip-rotations : Skip steps 2-5 (burst-window regeneration + talent/mechanics enrichment)}
        {--skip-playstyle : Skip step 6 (wow:analyze-spec-playstyle, looped over every spec — the slowest step)}';

    protected $description = 'Regenerates every live, match-data-derived surface (Burst Windows, mechanics, Crowd Control, Class Guide) from the current arena-log archive. Run after pulling new matches.';

    public function handle(TalentSelectionService $talentService): int
    {
        $archivePath = config('arena_logs.archive_path');
        $scriptsDir = "{$archivePath}/scripts";

        $this->step('1/6 — Crowd Control chain corpus (wow:find-cc-chains --json)', fn () => $this->option('skip-cc-chains')
            ? $this->skipped()
            : $this->callArtisan('wow:find-cc-chains', ['--json']));

        if (!$this->option('skip-rotations')) {
            $ok = $this->step('2/6 — Regenerate burst-window rotations (all-spec-rotations.php)', function () use ($scriptsDir) {
                $script = "{$scriptsDir}/all-spec-rotations.php";
                if (!File::exists($script)) {
                    $this->error("  Not found: {$script} — is ARENA_LOG_ARCHIVE_PATH pointing at a real wow-arena-archive checkout?");

                    return false;
                }

                return $this->runScript($script);
            });

            if ($ok) {
                $this->step('3/6 — Promote regenerated rotations into the live repo', fn () => $this->promoteRotations($archivePath));
                $this->step('4/6 — Enrich promoted windows with real talent builds', fn () => $this->callArtisan('wow:enrich-rotation-talents'));
                $this->step('5/6 — Enrich promoted windows with real champion/target mechanics', fn () => $this->callArtisan('wow:enrich-rotation-mechanics'));
            } else {
                $this->warn('  Skipping promotion + enrichment — rotation regeneration itself failed.');
            }
        } else {
            $this->step('2-5/6 — Burst-window regeneration + enrichment', fn () => $this->skipped());
        }

        $this->step('6/6 — Class Guide playstyle data (wow:analyze-spec-playstyle, every spec)', fn () => $this->option('skip-playstyle')
            ? $this->skipped()
            : $this->refreshPlaystyle());

        $talentService->bumpSpellCacheVersion();
        $this->newLine();
        $this->info('Spell cache version bumped — every already-cached page will pick up today\'s data on next load.');

        $this->newLine();
        $this->warn('Tier 2 — deliberately NOT run automatically (both require human review before going live, see this command\'s own docblock):');
        $this->line('  1. php artisan wow:extract-arena-spells --all');
        $this->line('       then manually copy wow-arena-archive/spell-usage/*/*.txt into data/arena-logs/spell-usage/');
        $this->line('       once you\'re confident the new matches\' contributions look right.');
        $this->line('  2. php {archive}/scripts/classify-cooldowns.php && php {archive}/scripts/all-spec-cooldowns.php');
        $this->line('       then manually promote data/arena-logs/spell-classification/*.json once reviewed.');

        return self::SUCCESS;
    }

    /** Runs a step, printing a header first, and reports pass/fail without stopping the whole command. */
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

    /** Shells out to a plain (non-Laravel) CLI script by absolute path, streaming its output live. */
    private function runScript(string $absolutePath): bool
    {
        $result = Process::timeout(0)
            ->run(['php', $absolutePath], function (string $type, string $output) {
                $this->output->write($output);
            });

        return $result->successful();
    }

    /**
     * Shells out to an artisan subcommand as its OWN fresh PHP process, streaming output live —
     * deliberately NOT $this->call(), which runs in-process and shares one memory budget across
     * every step of this orchestrator. Real bug, found running this command for the first time
     * (2026-08-31): wow:enrich-rotation-mechanics raw-log-decompresses one match per spec, and by
     * the time it ran as this orchestrator's 5th in-process step it inherited whatever steps 1-4
     * had already allocated, hit PHP's default 128MB CLI limit partway through, and crashed the
     * ENTIRE orchestrator (steps 6 never ran, nothing after the crash point got refreshed). A
     * fresh `-d memory_limit=512M` subprocess per step — same explicit limit this codebase
     * already reaches for on `import:spelldata` — fixes both the ceiling and the accumulation at
     * once, and matches how step 2 (all-spec-rotations.php) already had to be a real subprocess
     * anyway, since it lives in a different repo entirely.
     */
    private function callArtisan(string $command, array $args = []): bool
    {
        $result = Process::timeout(0)
            ->run(['php', '-d', 'memory_limit=512M', base_path('artisan'), $command, ...$args], function (string $type, string $output) {
                $this->output->write($output);
            });

        return $result->successful();
    }

    /**
     * Plain mechanical copy — every {class}/{spec}.export.json produced by
     * all-spec-rotations.php into this repo's own live-read data/arena-logs/rotations/
     * {class}/{spec}.json. See this class's own docblock for why this one IS safe to automate
     * (no documented review gate), unlike spell-usage/spell-classification.
     */
    private function promoteRotations(string $archivePath): bool
    {
        $files = File::glob("{$archivePath}/rotations/*/*.export.json");

        if ($files === [] || $files === false) {
            $this->warn('  No rotation exports found to promote.');

            return false;
        }

        $count = 0;
        foreach ($files as $file) {
            $classSlug = basename(dirname($file));
            $specSlug = basename($file, '.export.json');
            $destDir = base_path("data/arena-logs/rotations/{$classSlug}");
            File::ensureDirectoryExists($destDir);
            File::copy($file, "{$destDir}/{$specSlug}.json");
            $count++;
        }

        $this->line("  Promoted {$count} rotation file(s).");

        return true;
    }

    /** Loops wow:analyze-spec-playstyle over every real spec — that command has no --all of its own. */
    private function refreshPlaystyle(): bool
    {
        $specs = Specialization::with('gameClass')
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->get()
            ->sortBy(fn ($s) => $s->gameClass->name.$s->name);

        $ok = 0;
        $failed = 0;

        foreach ($specs as $spec) {
            $class = $spec->gameClass;

            if ($this->callArtisan('wow:analyze-spec-playstyle', [$class->slug, $spec->slug])) {
                $ok++;
            } else {
                $failed++;
            }
        }

        $this->line("  {$ok} spec(s) refreshed, {$failed} failed/skipped (no match evidence, etc.).");

        return $failed === 0;
    }
}
