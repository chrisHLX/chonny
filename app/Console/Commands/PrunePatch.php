<?php

namespace App\Console\Commands;

use App\Models\Patch;
use App\Models\Spell;
use App\Models\TalentBuild;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a non-current patch row and everything hanging off it.
 *
 * WHY THIS EXISTS. Every distinct patch string passed to `import:spelldata` creates a NEW
 * `patches` row, and essentially all game data is patch-scoped by foreign key — so one mistyped
 * or well-meaning version argument silently forks the entire dataset instead of updating it in
 * place. That happened on production: a second patch row appeared on 2026-08-18 and sat there
 * doubling `spells`, `talent_trees`, `pvp_talents` and `talent_builds`. Nothing displayed it
 * (every read filters to the current patch) but it was not harmless — three callers looked up a
 * spec's admin-default TalentBuild WITHOUT filtering by patch and got the older row back, so
 * every spec kit was being built from a stale build. See the 2026-09-07 notes in CLAUDE.md.
 *
 * Deleting the stray row is therefore real cleanup, not tidying. But it is also the single most
 * destructive operation available in this codebase, which is why it is a reviewed command with a
 * dry run rather than something typed into `tinker` against production at speed.
 *
 * WHAT IS SAFE TO LOSE, AND WHAT IS NOT. Almost everything attached to a patch is derived: it
 * was parsed out of data/spelldata and data/talenttrees, and `import:spelldata` reproduces it
 * exactly. Two things are NOT derived and must never be cascade-deleted:
 *
 *   - `module_spell_references` — the hand-curated list of abilities a canonical module's prose
 *     actually names, resolved to spell ids once at seed time. On production 31 of its 32 rows
 *     pointed at the stale patch. Deleting the patch without handling these would have silently
 *     emptied the Spells section of the canonical modules, with nothing to rebuild it from.
 *   - `talent_builds` that belong to a user or a module. Admin defaults (user_id and module_id
 *     both null) are curated too, but are reproducible from data/spelldata/default-talent-builds
 *     .txt via `wow:apply-default-talents`; a personal or module-linked build is not.
 *
 * So this command REMAPS rather than deletes wherever a curated row has an equivalent on the
 * current patch, matching on Blizzard's external `spell_id` (stable across patches, unlike the
 * internal auto-increment primary key these columns actually store — a distinction that has
 * caused real bugs here before). Anything curated that cannot be remapped ABORTS the run: losing
 * hand-authored data is never an acceptable side effect of cleanup.
 *
 * Usage:
 *   php artisan wow:prune-patch 1            # dry run — reports, changes nothing
 *   php artisan wow:prune-patch 1 --apply    # remap what is curated, then delete
 *
 * Take a database dump first. The command asks for confirmation and refuses to touch the
 * current patch, but neither of those brings data back.
 */
class PrunePatch extends Command
{
    protected $signature = 'wow:prune-patch {patchId : id of the patches row to delete}
                            {--apply : actually remap and delete (default is a dry run)}';

    protected $description = 'Delete a stale, non-current patch row and its derived data, remapping curated references first';

    public function handle(): int
    {
        $patch = Patch::find($this->argument('patchId'));
        $current = Patch::where('is_current', true)->first();

        if (! $patch) {
            $this->error("No patches row with id {$this->argument('patchId')}.");

            return self::FAILURE;
        }

        if (! $current) {
            $this->error('No patch is marked is_current — refusing to run, since there is nothing to remap curated rows onto.');

            return self::FAILURE;
        }

        if ($patch->id === $current->id) {
            $this->error("Patch {$patch->id} ({$patch->build_version}) IS the current patch. Refusing.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->info("Target : patch {$patch->id}  {$patch->build_version}  (created {$patch->created_at})");
        $this->info("Current: patch {$current->id}  {$current->build_version}");
        $this->newLine();

        // --- What would be deleted (derived data — reproducible by import:spelldata) ----------
        $spellIds = Spell::where('patch_id', $patch->id)->pluck('id');

        $this->line('Derived rows that would be deleted (all reproducible from data/spelldata):');
        foreach ([
            'spells' => Spell::where('patch_id', $patch->id)->count(),
            'spell_effects' => DB::table('spell_effects')->whereIn('spell_id', $spellIds)->count(),
            'spell_class_availability' => DB::table('spell_class_availability')->whereIn('spell_id', $spellIds)->count(),
            'spell_relationships' => DB::table('spell_relationships')->whereIn('source_spell_id', $spellIds)->orWhereIn('target_spell_id', $spellIds)->count(),
            'talent_trees' => DB::table('talent_trees')->where('patch_id', $patch->id)->count(),
            'pvp_talents' => DB::table('pvp_talents')->where('patch_id', $patch->id)->count(),
        ] as $table => $count) {
            $this->line(sprintf('    %-28s %s', $table, number_format($count)));
        }
        $this->newLine();

        // --- Curated rows: remap, or abort ---------------------------------------------------
        $curatedBuilds = TalentBuild::where('patch_id', $patch->id)
            ->where(fn ($q) => $q->whereNotNull('user_id')->orWhereNotNull('module_id'))
            ->count();

        if ($curatedBuilds > 0) {
            $this->error("ABORT: {$curatedBuilds} talent_builds on this patch belong to a user or a module.");
            $this->error('Those are not reproducible from a committed file. Move or export them first.');

            return self::FAILURE;
        }

        $defaultBuilds = TalentBuild::where('patch_id', $patch->id)->count();
        $this->line("Admin-default talent_builds to delete: {$defaultBuilds}");
        $this->line('    (reproducible via wow:apply-default-talents from the committed file)');
        $this->newLine();

        $toRemap = [];
        $unremappable = [];

        foreach (DB::table('module_spell_references')->get() as $row) {
            $spell = Spell::find($row->spell_id);

            if (! $spell || $spell->patch_id !== $patch->id) {
                continue;
            }

            // Match on the EXTERNAL spell_id: the internal primary key differs per patch.
            $equivalent = Spell::where('patch_id', $current->id)
                ->where('spell_id', $spell->spell_id)
                ->first();

            $equivalent
                ? $toRemap[] = ['row' => $row->id, 'to' => $equivalent->id, 'name' => $spell->name]
                : $unremappable[] = "\"{$spell->name}\" (external id {$spell->spell_id})";
        }

        $this->line('Curated module_spell_references pointing at this patch: '.(count($toRemap) + count($unremappable)));
        $this->line('    remappable to the current patch: '.count($toRemap));

        if ($unremappable !== []) {
            $this->newLine();
            $this->error('ABORT: these curated references have no equivalent on the current patch,');
            $this->error('so deleting would destroy hand-curated data with nothing to rebuild it from:');
            foreach ($unremappable as $u) {
                $this->error("    {$u}");
            }

            return self::FAILURE;
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run — nothing was changed. Re-run with --apply to remap and delete.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Delete patch {$patch->id} ({$patch->build_version}) and all of the above?", false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        // Remap first, in its own transaction: if this fails, the patch is still intact and the
        // curated rows still point somewhere valid.
        DB::transaction(function () use ($toRemap) {
            foreach ($toRemap as $r) {
                DB::table('module_spell_references')->where('id', $r['row'])->update(['spell_id' => $r['to']]);
            }
        });
        $this->info('Remapped '.count($toRemap).' curated module_spell_references onto the current patch.');

        $patch->delete();
        $this->info("Deleted patch {$patch->id} ({$patch->build_version}); dependent rows cascaded.");

        $orphans = DB::table('module_spell_references')
            ->leftJoin('spells', 'spells.id', '=', 'module_spell_references.spell_id')
            ->whereNull('spells.id')
            ->count();

        if ($orphans > 0) {
            $this->error("WARNING: {$orphans} module_spell_references now point at a missing spell.");

            return self::FAILURE;
        }

        $this->info('Verified: every curated module_spell_reference still resolves to a real spell.');

        return self::SUCCESS;
    }
}
