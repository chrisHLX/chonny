<?php

namespace App\Console\Commands;

use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\SpecKitComputer;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\Specialization;
use App\Models\TalentBuild;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Precomputes every spec's full spell kit (every real talent, PvP talent, and baseline ability —
 * description, category, talent-adjusted cooldown/charges, and what modifies/could-modify each
 * one) against its admin-default TalentBuild, and writes the result to
 * data/spell-kits/{class}/{spec}.json — one readable, git-diffable file per spec, in the same
 * "run a script, tag the spells, look at the output" spirit as the arena-log pipeline
 * (wow:refresh-match-derived, wow:analyze-spec-playstyle) rather than computing this live on
 * every cache miss.
 *
 * WHY THIS EXISTS (2026-09-01, direct request — "implement it with the redesign of our system"):
 * the live computation this replaces (WowComps::computeSpellReferencesFor(), now moved to
 * SpecKitComputer — see that class's own docblock) was the confirmed source of every real
 * WoW Comps performance investigation this session. Even after three rounds of query-level
 * optimization (memoizing modifiersFor()/resolveDescription(), bulk-preloading kit membership
 * checks — see CLAUDE.md's dated notes), a genuinely cold computation still costs several
 * seconds of real CPU work per spec — because ~150-250 spells each need their own modifier-graph
 * walk, tooltip parse, and talent-adjusted number, regardless of how few of them a given tab
 * ends up displaying. That's inherent to what the computation IS, not a query-count problem —
 * the actual fix is not doing it live at all for the case that matters (nearly every real page
 * view: a guest, or any user who hasn't personally customized this spec's talents).
 *
 * WHAT THIS DOES NOT COVER, ON PURPOSE: a viewer's own personal, saved TalentBuild for a spec.
 * That can't be precomputed for every possible user — WowComps::spellReferencesFor() still
 * computes those live (via the same SpecKitComputer engine) and Redis-caches them exactly as
 * before this command existed. This command only ever replaces the SHARED admin-default path,
 * which is the one responsible for nearly all real cost (guests never have a personal build; most
 * logged-in users never customize talents for a given spec either).
 *
 * "KEEP THE SERVICE OUTPUT" — this command does not reimplement any resolution logic. It calls
 * SpecKitComputer::compute(), the exact same engine WowComps calls live, which itself calls
 * ModuleSpellReferenceService/TalentSelectionService unchanged. Every hard-won correctness fix in
 * those services (CDR modifiers, rank-scaling, the 'potential' modifiers bucket, mobility
 * tagging, etc.) applies identically here — this command is pure orchestration + serialization,
 * nothing more.
 *
 * FRESHNESS: each written file embeds the spellCacheVersion() and deployedCodeFingerprint() that
 * were current at precompute time. WowComps::readPrecomputedKit() checks both against their
 * CURRENT values before trusting a file — a stale file (spelldata re-imported, an admin default
 * build edited, or a code deploy since the file was written) is detected and silently falls back
 * to the existing live-compute-and-cache path, never served as if it were still correct. This
 * command must be re-run after any of those three events for the precomputed file to actually be
 * the thing serving real traffic again — same "regenerate after the trigger, not on a schedule"
 * discipline as wow:refresh-match-derived.
 *
 * Usage:
 *   php artisan wow:precompute-spell-kits                 # every spec with an admin-default build
 *   php artisan wow:precompute-spell-kits rogue assassination
 */
class PrecomputeSpellKits extends Command
{
    protected $signature = 'wow:precompute-spell-kits {classSlug?} {specSlug?}';

    protected $description = 'Precompute every spec\'s full spell kit against its admin-default talent build and write it to data/spell-kits/{class}/{spec}.json';

    public function handle(
        SpecKitComputer $kitComputer,
        ModuleSpellReferenceService $service,
        TalentSelectionService $talentService
    ): int {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

        $query = Specialization::with('gameClass')->orderBy('name');
        if ($classSlug) {
            $query->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug));
        }
        if ($specSlug) {
            $query->where('slug', $specSlug);
        }

        $specs = $query->get();
        $written = 0;
        $skippedNoDefault = 0;
        $failed = 0;

        foreach ($specs as $spec) {
            $class = $spec->gameClass ?? GameClass::find($spec->class_id);
            if (!$class) {
                continue;
            }

            $defaultBuild = TalentBuild::where('spec_id', $spec->id)->where('is_default', true)->first();

            if (!$defaultBuild) {
                $this->line("  {$class->name}/{$spec->name}: no admin-default build yet, skipped.");
                $skippedNoDefault++;
                continue;
            }

            try {
                $entries = $kitComputer->compute($spec, $defaultBuild, $service, $talentService);
                $payload = [
                    'generatedAt' => now()->toAtomString(),
                    'spellCacheVersion' => $talentService->spellCacheVersion(),
                    'codeFingerprint' => $talentService->deployedCodeFingerprint(),
                    'entries' => $kitComputer->toJsonSafeArray($entries),
                ];

                $dir = base_path("data/spell-kits/{$class->slug}");
                File::ensureDirectoryExists($dir);
                File::put(
                    "{$dir}/{$spec->slug}.json",
                    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );

                $this->info("  {$class->name}/{$spec->name}: {$dir}/{$spec->slug}.json (" . count($entries) . ' entries)');
                $written++;
            } catch (\Throwable $e) {
                $this->error("  {$class->name}/{$spec->name}: FAILED — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. Written {$written}, skipped (no default build) {$skippedNoDefault}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
