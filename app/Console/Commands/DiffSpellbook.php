<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellbookSnapshot;
use App\Models\SpellClassAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Diffs a spellbook snapshot (see wow:import-spellbook) against spell_class_availability for its
 * class/spec — the trusted-source verification layer's read side. Deterministic, no AI calls,
 * writes nothing to any existing table (see spellbook-verifier.md). Descriptions are captured on
 * the snapshot but deliberately not diffed here — Phase 2, not built yet.
 *
 * Class/spec are resolved from the snapshot's raw export (string token / Blizzard numeric spec
 * id) against local reference data at diff time, not at import time — a snapshot always imports
 * even when local data lags a patch; the diff is what surfaces that gap instead of failing.
 */
class DiffSpellbook extends Command
{
    protected $signature = 'wow:diff-spellbook
        {snapshot_id? : Snapshot id to diff (defaults to the latest snapshot)}
        {--json : Also write the full result to storage as JSON}';

    protected $description = 'Diffs a spellbook snapshot against spell_class_availability and prints mismatches.';

    public function handle(): int
    {
        $snapshot = $this->argument('snapshot_id')
            ? SpellbookSnapshot::find($this->argument('snapshot_id'))
            : SpellbookSnapshot::latest('id')->first();

        if (!$snapshot) {
            $this->error('No snapshot found.');

            return self::FAILURE;
        }

        $game = Game::where('slug', 'wow')->first();
        if (!$game) {
            $this->error("No 'wow' game row found — has import:spelldata ever been run?");

            return self::FAILURE;
        }

        $classSlug = $this->normalizeSlug($snapshot->class);
        $class = GameClass::where('game_id', $game->id)->where('slug', $classSlug)->first();

        if (!$class) {
            $this->error("Snapshot #{$snapshot->id}'s class '{$snapshot->class}' (slug '{$classSlug}') has no matching classes row — import:spelldata may not have run for this class yet.");

            return self::FAILURE;
        }

        $spec = Specialization::where('class_id', $class->id)->where('external_spec_id', $snapshot->spec_id)->first();

        if (!$spec) {
            $this->warn("Snapshot #{$snapshot->id}'s spec_id {$snapshot->spec_id} has no matching specializations row for {$class->name} — availability checks will only match class-wide (spec_id NULL) rows.");
        }

        $patch = $game->currentPatch ?? $game->patches()->latest('id')->first();

        if (!$patch) {
            $this->error("No patch found for game '{$game->slug}'.");

            return self::FAILURE;
        }

        $this->info("Diffing snapshot #{$snapshot->id} ({$snapshot->class}".($spec ? "/{$spec->name}" : '/unresolved spec'.$snapshot->spec_id).") against patch {$patch->build_version}.");

        [$missingSpell, $missingAvailability] = $this->directionA($snapshot, $class, $spec, $patch);
        $notInSpellbook = $this->directionB($snapshot, $class, $spec, $patch);

        $this->newLine();
        $this->info('=== Direction A: in game, missing/mistagged in DB (the real alarm) ===');
        $this->line('MISSING_SPELL: '.count($missingSpell));
        $this->line('MISSING_AVAILABILITY: '.count($missingAvailability));
        $this->newLine();
        $this->info('=== Direction B: in DB, not in game (informational — many legitimate hits expected) ===');
        $this->line('NOT_IN_SPELLBOOK_CANDIDATE: '.count($notInSpellbook));

        if ($missingSpell !== []) {
            $this->newLine();
            $this->warn('MISSING_SPELL details:');
            $this->table(['spell_id', 'name', 'kind'], array_map(
                fn (array $r) => [$r['spell_id'], $r['name'], $r['kind']],
                $missingSpell
            ));
        }

        if ($missingAvailability !== []) {
            $this->newLine();
            $this->warn('MISSING_AVAILABILITY details:');
            $this->table(['spell_id', 'name', 'kind'], array_map(
                fn (array $r) => [$r['spell_id'], $r['name'], $r['kind']],
                $missingAvailability
            ));
        }

        if ($notInSpellbook !== []) {
            $this->newLine();
            $this->comment('NOT_IN_SPELLBOOK_CANDIDATE details:');
            $this->table(['spell_id', 'name', 'source'], array_map(
                fn (array $r) => [$r['spell_id'], $r['name'], $r['source']],
                $notInSpellbook
            ));
        }

        if ($this->option('json')) {
            $result = [
                'snapshot_id' => $snapshot->id,
                'class' => $snapshot->class,
                'spec_id' => $snapshot->spec_id,
                'patch' => $patch->build_version,
                'missing_spell' => $missingSpell,
                'missing_availability' => $missingAvailability,
                'not_in_spellbook_candidate' => $notInSpellbook,
            ];

            $dir = storage_path('app/wow-diffs');
            File::ensureDirectoryExists($dir);
            $file = $dir.'/diff-snapshot-'.$snapshot->id.'-'.now()->format('Ymd-His').'.json';
            File::put($file, json_encode($result, JSON_PRETTY_PRINT));
            $this->newLine();
            $this->info("JSON written to {$file}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    private function directionA(SpellbookSnapshot $snapshot, GameClass $class, ?Specialization $spec, Patch $patch): array
    {
        $missingSpell = [];
        $missingAvailability = [];

        foreach ($snapshot->entries as $entry) {
            $spell = Spell::where('patch_id', $patch->id)->where('spell_id', $entry->spell_id)->first();

            if (!$spell) {
                $missingSpell[] = ['spell_id' => $entry->spell_id, 'name' => $entry->name, 'kind' => $entry->kind];

                continue;
            }

            $hasAvailability = SpellClassAvailability::where('spell_id', $spell->id)
                ->where('class_id', $class->id)
                ->where(function ($q) use ($spec) {
                    $q->whereNull('spec_id');
                    if ($spec) {
                        $q->orWhere('spec_id', $spec->id);
                    }
                })
                ->exists();

            if (!$hasAvailability) {
                $missingAvailability[] = ['spell_id' => $entry->spell_id, 'name' => $entry->name, 'kind' => $entry->kind];
            }
        }

        return [$missingSpell, $missingAvailability];
    }

    /**
     * @return array<int, array>
     */
    private function directionB(SpellbookSnapshot $snapshot, GameClass $class, ?Specialization $spec, Patch $patch): array
    {
        if (!$spec) {
            // Can't confidently scope "claims availability to this spec" without a resolved
            // spec row — direction B is informational only, skip rather than over-report.
            return [];
        }

        $snapshotSpellIds = $snapshot->entries->pluck('spell_id')->flip();

        $rows = SpellClassAvailability::where('class_id', $class->id)
            ->where('spec_id', $spec->id)
            ->with('spell')
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            $spell = $row->spell;

            if (!$spell || $spell->patch_id !== $patch->id) {
                continue;
            }

            if (!$snapshotSpellIds->has($spell->spell_id)) {
                $candidates[] = ['spell_id' => $spell->spell_id, 'name' => $spell->name, 'source' => $row->source];
            }
        }

        return $candidates;
    }

    private function normalizeSlug(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value));
    }
}
