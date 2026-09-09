<?php

namespace App\Console\Commands;

use App\Console\Concerns\RegeneratesSpellKits;
use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\SpellCounterIndexer;
use App\Http\Services\TalentSelectionService;
use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Console\Command;

/**
 * Refreshes the two materialized immunity columns on `spells` and then rebuilds `spell_counters`
 * from them.
 *
 * YOU ALMOST CERTAINLY DO NOT NEED TO RUN THIS. Both steps already run automatically inside
 * every `import:spelldata`, via ImportSpellData::materializeSpellShape(), and `./deploy.sh` runs
 * that import automatically whenever a deploy touches data/spelldata or a spell/talent migration.
 * There is no routine workflow — code change, data change, or deploy — that requires calling this
 * by hand.
 *
 * It exists only as a targeted backfill for the case where a full re-import is not warranted:
 * an environment that already has current spell data but predates these columns existing, where
 * re-parsing every class file would be minutes of work to achieve something this does in seconds.
 * Same role `wow:apply-icon-manifest` plays for icon_name.
 *
 * Idempotent and safe to re-run: the columns are only written when their value actually changes,
 * and SpellCounterIndexer::rebuild() is replace-not-append for the patch it targets.
 */
class RebuildSpellCounters extends Command
{
    use RegeneratesSpellKits;

    protected $signature = 'wow:rebuild-spell-counters {--patch= : build_version to target (defaults to the current patch)}';

    protected $description = 'Backfill only — refresh the immunity columns and rebuild spell_counters (import:spelldata already does both)';

    public function handle(ModuleSpellReferenceService $service, SpellCounterIndexer $indexer, TalentSelectionService $talentService): int
    {
        $patch = $this->option('patch')
            ? Patch::where('build_version', $this->option('patch'))->first()
            : Patch::where('is_current', true)->first();

        if ($patch === null) {
            $this->error('No matching patch found.');

            return self::FAILURE;
        }

        $this->info("Patch: {$patch->build_version}");

        $spells = Spell::where('patch_id', $patch->id)->with('effects')->get();
        $this->info("Refreshing immunity columns for {$spells->count()} spell(s)...");

        $changed = 0;
        $bar = $this->output->createProgressBar($spells->count());

        foreach ($spells as $spell) {
            $ccImmunity = $service->ccImmunityFor($spell)->all();
            $schoolImmunity = $this->resolveGrantedSchoolImmunity($spell);

            if ($spell->grants_cc_immunity !== $ccImmunity || $spell->grants_school_immunity !== $schoolImmunity) {
                $spell->grants_cc_immunity = $ccImmunity;
                $spell->grants_school_immunity = $schoolImmunity;
                $spell->save();
                $changed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Immunity columns: {$changed} spell(s) updated.");

        $result = $indexer->rebuild($patch);
        $this->info("Spell counters: {$result['rows']} row(s) across {$result['counterable']} counterable spell(s).");

        // The detail modal/page and every kit render read these columns now, and the kit files
        // embed the version they were built against — same reason import:spelldata bumps it.
        $talentService->bumpSpellCacheVersion();
        $this->comment('Spell cache version bumped.');
        $this->regenerateSpellKits();

        return self::SUCCESS;
    }

    /**
     * Delegates to ModuleSpellReferenceService::grantedSchoolImmunityFor() — the single
     * implementation as of 2026-09-09. This used to be a verbatim third copy of that rule.
     */
    private function resolveGrantedSchoolImmunity(Spell $spell): ?string
    {
        return app(ModuleSpellReferenceService::class)->grantedSchoolImmunityFor($spell);
    }
}
