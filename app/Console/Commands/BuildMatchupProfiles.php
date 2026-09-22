<?php

namespace App\Console\Commands;

use App\Http\Services\MatchupProfileService;
use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\SpecKitComputer;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\TalentBuild;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Writes every spec's Matchup Lab profile to data/matchup-profiles/{class}/{spec}.json — the
 * small, flat artifact CooldownGraphService reads six of to put two comps on one clock. See
 * MatchupProfileService for the shape and for why it is an artifact rather than a live read.
 *
 * RUN IT AFTER wow:precompute-spell-kits, NOT BEFORE. A profile is a narrowing of the same kit
 * that command writes, so this one tries the precomputed file first (SpecKitComputer::
 * tryReadPrecomputed(), which validates the spell cache version and the deployed code
 * fingerprint for us) and only falls back to a live SpecKitComputer::compute() when that file is
 * missing or stale. With fresh kits a whole sweep is a few seconds of JSON reads; with stale ones
 * it is the full ~7s-per-spec computation forty times over. deploy.sh runs them in that order.
 *
 * ONE PROCESS PER SPEC on a full sweep, for exactly the reason PrecomputeSpellKits documents: a
 * whole-sweep run in one process accumulates memory across specs and reproducibly OOMed on
 * production after 36 of 40, leaving the same four specs stale on every deploy. The fallback
 * path here is that same computation, so it inherits that failure mode and the same fix.
 *
 * Usage:
 *   php artisan wow:build-matchup-profiles                 # every spec with an admin-default build
 *   php artisan wow:build-matchup-profiles rogue subtlety
 */
class BuildMatchupProfiles extends Command
{
    protected $signature = 'wow:build-matchup-profiles {classSlug?} {specSlug?}';

    protected $description = 'Write each spec\'s Matchup Lab profile to data/matchup-profiles/{class}/{spec}.json';

    public function handle(
        MatchupProfileService $profiles,
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

        if (! ($classSlug && $specSlug)) {
            return $this->sweep($specs);
        }

        $written = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($specs as $spec) {
            $class = $spec->gameClass ?? GameClass::find($spec->class_id);
            if (! $class) {
                continue;
            }

            // Patch-scoped, same correction PrecomputeSpellKits carries: talent_builds is
            // patch-scoped, so an unfiltered first() on a database holding more than one patch
            // row returns the OLDEST patch's default build.
            $build = TalentBuild::where('spec_id', $spec->id)
                ->where('patch_id', Patch::where('is_current', true)->value('id'))
                ->where('is_default', true)
                ->first();

            if (! $build) {
                $this->line("  {$class->name}/{$spec->name}: no admin-default build yet, skipped.");
                $skipped++;

                continue;
            }

            try {
                $entries = $kitComputer->tryReadPrecomputed($spec, $talentService);
                $source = 'precomputed kit';

                if ($entries === null) {
                    $entries = $kitComputer->compute($spec, $build, $service, $talentService);
                    $source = 'live compute (kit was missing or stale)';
                }

                $profile = $profiles->build($spec, $class, $entries, $build);
                $path = $profiles->pathFor($class->slug, $spec->slug);

                File::ensureDirectoryExists(dirname($path));
                File::put($path, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                $this->info(sprintf(
                    '  %s/%s: %d offensive, %d answers, %d control, %d mobility — %s',
                    $class->name,
                    $spec->name,
                    count($profile['offensive']),
                    count($profile['answers']),
                    count($profile['control']),
                    count($profile['mobility']),
                    $source
                ));
                $written++;
            } catch (\Throwable $e) {
                $this->error("  {$class->name}/{$spec->name}: FAILED — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. Written {$written}, skipped (no default build) {$skipped}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Specialization>  $specs
     */
    private function sweep($specs): int
    {
        $written = 0;
        $failed = 0;

        foreach ($specs as $spec) {
            $class = $spec->gameClass ?? GameClass::find($spec->class_id);
            if (! $class) {
                continue;
            }

            $result = Process::timeout(0)->run(
                ['php', '-d', 'memory_limit=1024M', base_path('artisan'), 'wow:build-matchup-profiles', $class->slug, $spec->slug],
                fn (string $type, string $output) => $this->output->write($output)
            );

            $result->successful() ? $written++ : $failed++;
        }

        $this->info("Done. {$written} spec(s) processed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
