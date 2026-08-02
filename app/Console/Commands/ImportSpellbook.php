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
 * /reload) into an immutable spellbook_snapshots + spellbook_snapshot_entries pair. See
 * spellbook-verifier.md — this is the trusted-source verification layer's import step; nothing
 * here writes to spells/spell_class_availability, and class/spec are stored as the addon's raw
 * export (string token / Blizzard numeric spec id) rather than resolved to local FKs, so an
 * import never fails just because local reference data hasn't caught up to a new patch yet
 * (resolution happens at diff time — see wow:diff-spellbook).
 */
class ImportSpellbook extends Command
{
    protected $signature = 'wow:import-spellbook {path : Path to the exported SavedVariables .lua file}';

    protected $description = 'Imports a MindCollectorExport SavedVariables file as a new spellbook snapshot.';

    public function handle(SavedVariablesLuaParser $parser): int
    {
        $path = $this->argument('path');

        if (!File::exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $contents = File::get($path);
        $hash = hash('sha256', $contents);

        $existing = SpellbookSnapshot::where('source_file_hash', $hash)->first();
        if ($existing) {
            $this->info("Skipped, duplicate hash — matches existing snapshot #{$existing->id}.");

            return self::SUCCESS;
        }

        try {
            $data = $parser->parseVariable($contents, 'MindCollectorExportDB');
        } catch (RuntimeException $e) {
            $this->error("Failed to parse SavedVariables file: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach (['exported_at', 'build', 'class', 'spec_id', 'loadout_string', 'spellbook'] as $required) {
            if (!array_key_exists($required, $data)) {
                $this->error("Malformed export — missing required field '{$required}'.");

                return self::FAILURE;
            }
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

        $this->info("Created snapshot #{$snapshot->id}, {$entryCount} entries.");

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
