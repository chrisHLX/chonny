<?php

namespace App\Console\Commands;

use App\Models\GameClass;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Console\Command;

/**
 * Populates spells.icon_name / classes.icon_name / specializations.icon_name from the
 * committed data/spelldata/icon-manifest.json — zero Blizzard API calls, zero
 * BLIZZARD_CLIENT_ID/SECRET required. Exists because the icon image files themselves are
 * committed to git (see storage/app/public/.gitignore) but the DB columns that say WHICH
 * spell/class/spec each file belongs to are not — they're wiped by every migrate:fresh, the
 * same routine this codebase runs after nearly every patch-data fix (see CLAUDE.md). Without
 * this command, committing the images alone would still leave every fresh DB needing a real
 * Blizzard API pass just to relink filenames — this command is what actually removes that
 * dependency for new machines (dev or production).
 *
 * The manifest itself is written by data/spelldata/fetch-spell-icons.php and
 * fetch-class-spec-icons.php (each refreshes only its own section from the DB's current
 * icon_name values every time it runs) — run those on a machine with real credentials
 * whenever a new patch adds spells/specs the manifest doesn't cover yet, then commit the
 * updated manifest + any newly downloaded files. This command only ever reads the manifest.
 *
 * Idempotent and additive: only fills icon_name where it's currently NULL, keyed by each
 * table's own stable external identifier (spells.spell_id, classes.slug,
 * specializations.external_spec_id) — never spells.id, which is an internal auto-increment
 * PK that is not guaranteed stable across a migrate:fresh + re-import.
 */
class ApplyIconManifest extends Command
{
    protected $signature = 'wow:apply-icon-manifest {--path= : Override the manifest path (used by tests; defaults to the committed data/spelldata/icon-manifest.json)}';

    protected $description = 'Populates icon_name columns from the committed icon manifest — no Blizzard API calls.';

    public function handle(): int
    {
        $path = $this->option('path') ?: base_path('data/spelldata/icon-manifest.json');

        if (!is_file($path)) {
            $this->error("Manifest not found at {$path}. Run fetch-spell-icons.php / fetch-class-spec-icons.php on a machine with Blizzard API credentials first, then commit the generated file.");
            return self::FAILURE;
        }

        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $spellsUpdated = 0;
        foreach ($manifest['spells'] ?? [] as $spellId => $filename) {
            $spellsUpdated += Spell::where('spell_id', (int) $spellId)
                ->whereNull('icon_name')
                ->update(['icon_name' => $filename]);
        }

        $classesUpdated = 0;
        foreach ($manifest['classes'] ?? [] as $slug => $filename) {
            $classesUpdated += GameClass::where('slug', $slug)
                ->whereNull('icon_name')
                ->update(['icon_name' => $filename]);
        }

        $specsUpdated = 0;
        foreach ($manifest['specs'] ?? [] as $externalSpecId => $filename) {
            $specsUpdated += Specialization::where('external_spec_id', (int) $externalSpecId)
                ->whereNull('icon_name')
                ->update(['icon_name' => $filename]);
        }

        $this->info("Spells: {$spellsUpdated} updated. Classes: {$classesUpdated} updated. Specializations: {$specsUpdated} updated.");
        $this->info('No Blizzard API calls were made.');

        return self::SUCCESS;
    }
}
