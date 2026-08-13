<?php

namespace App\Console\Commands;

use App\Http\Services\SavedVariablesLuaParser;
use App\Models\SpellbookSnapshot;
use App\Models\SpellbookSnapshotEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Imports a MindCollectorExport addon SavedVariables file (produced in-game via /mcexport,
 * /reload) into an immutable spellbook_snapshots + spellbook_snapshot_entries pair per export.
 * See spellbook-verifier.md — this is the trusted-source verification layer's import step;
 * nothing here writes to spells/spell_class_availability, and class/spec are stored as the
 * addon's raw export (string token / Blizzard numeric spec id) rather than resolved to local
 * FKs, so an import never fails just because local reference data hasn't caught up to a new
 * patch yet (resolution happens at diff time — see wow:diff-spellbook).
 *
 * FIXED 2026-08-12, matching the addon-side fix (see main.lua's "FIXED 2026-08-12" note): the
 * addon used to overwrite MindCollectorExportDB with a single flat export, so this command only
 * ever had one export per file and deduped on the WHOLE FILE's hash. Now the addon accumulates
 * multiple exports (one per class/spec/timestamp) into the same file, so this command loops over
 * every export in the file and imports each independently, deduped by a PER-EXPORT hash instead
 * of a per-file one — otherwise re-importing an updated file (with old + new exports mixed
 * together) would either re-create every already-imported snapshot or, worse, silently skip the
 * whole file because the file-level hash changed. `source_file_hash`'s column name is kept
 * as-is (no migration) — it now stores a hash of one export's own content, not the file.
 *
 * Still handles an old-format file gracefully: if the parsed table has 'exported_at' as a
 * top-level key, it's a single legacy flat export from before this fix — wrapped as a one-entry
 * collection rather than erroring.
 */
class ImportSpellbook extends Command
{
    protected $signature = 'wow:import-spellbook {path : Path to the exported SavedVariables .lua file}';

    protected $description = 'Imports every export in a MindCollectorExport SavedVariables file as new spellbook snapshots.';

    public function handle(SavedVariablesLuaParser $parser): int
    {
        $path = $this->argument('path');

        if (!File::exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $contents = File::get($path);

        try {
            $parsed = $parser->parseVariable($contents, 'MindCollectorExportDB');
        } catch (RuntimeException $e) {
            $this->error("Failed to parse SavedVariables file: {$e->getMessage()}");

            return self::FAILURE;
        }

        // A legacy single-export file has 'exported_at' directly at the top level; a current
        // multi-export file has it one level down, under each export's own key.
        $exports = array_key_exists('exported_at', $parsed) ? ['legacy_export' => $parsed] : $parsed;

        $imported = 0;
        $skipped = 0;

        foreach ($exports as $exportKey => $data) {
            $missing = array_diff(['exported_at', 'build', 'class', 'spec_id', 'loadout_string', 'spellbook'], array_keys($data));
            if (!empty($missing)) {
                $this->warn("  Skipping export '{$exportKey}' — missing required field(s): ".implode(', ', $missing));
                $skipped++;

                continue;
            }

            $hash = hash('sha256', json_encode($data));

            $existing = SpellbookSnapshot::where('source_file_hash', $hash)->first();
            if ($existing) {
                $this->line("  Skipped '{$exportKey}', duplicate hash — matches existing snapshot #{$existing->id}.");
                $skipped++;

                continue;
            }

            $entryCount = 0;

            $snapshot = DB::transaction(function () use ($data, $hash, &$entryCount) {
                $snapshot = SpellbookSnapshot::create([
                    'class' => $data['class'],
                    'spec_id' => $data['spec_id'],
                    'client_build' => $data['build'],
                    'loadout_string' => $data['loadout_string'],
                    'exported_at' => date('Y-m-d H:i:s', (int) $data['exported_at']),
                    'source_file_hash' => $hash,
                ]);

                foreach ($data['spellbook'] ?? [] as $item) {
                    $this->createEntry($snapshot, $item, 'spellbook');
                    $entryCount++;
                }

                foreach ($data['talents']['selected'] ?? [] as $item) {
                    $this->createEntry($snapshot, $item, 'talent');
                    $entryCount++;
                }

                foreach ($data['talents']['known_pvp'] ?? [] as $item) {
                    $this->createEntry($snapshot, $item, 'pvp_talent');
                    $entryCount++;
                }

                return $snapshot;
            });

            $this->info("  Created snapshot #{$snapshot->id} from '{$exportKey}' ({$data['class']}/{$data['spec_id']}), {$entryCount} entries.");
            $imported++;
        }

        $this->info("Done: {$imported} snapshot(s) created, {$skipped} skipped (duplicates or malformed).");

        return self::SUCCESS;
    }

    private function createEntry(SpellbookSnapshot $snapshot, array $item, string $kind): void
    {
        SpellbookSnapshotEntry::create([
            'snapshot_id' => $snapshot->id,
            'spell_id' => $item['id'],
            'name' => $item['name'] ?? "Unknown Spell #{$item['id']}",
            'kind' => $kind,
            'resolved_description' => $item['desc'] ?? null,
        ]);
    }
}
