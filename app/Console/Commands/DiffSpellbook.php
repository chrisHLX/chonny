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
 *
 * Direction C added 2026-08-06 after tracing a real report (Mind Sear — a Shadow-only spell —
 * showing up on a Discipline Priest's kit on WowComps/Spell Explorer). Root cause: Mind Sear's
 * only spell_class_availability row is `spec_id = NULL` (Blizzard's own SimC-format export never
 * states a spec restriction for it at all — confirmed by hand against the raw source file,
 * unlike e.g. Mind Blast's parsed `free=(...)` restriction). Direction B, as originally written,
 * only ever compared EXPLICIT spec_id matches (`->where('spec_id', $spec->id)`) — a NULL-tagged
 * row was invisible to it entirely, so this exact mismatch sat undetected in snapshot #1 (a real
 * Discipline Priest export that has never had Mind Sear) despite the ground truth already being
 * on hand. Direction C closes that: same "in DB, not in game" comparison as Direction B, but
 * scoped to `spec_id IS NULL` rows instead of explicit ones. A hit here is a much stronger signal
 * than Direction B's — it's not "this spell just isn't in the spellbook right now" (talent not
 * picked, hero tree not chosen, etc., the normal/expected Direction B case), it's "the DB claims
 * this spell is available to every spec of this class, and a real character of this exact spec
 * doesn't have it" — i.e. a real spec-tagging correction candidate, not background noise.
 *
 * Narrowed the same day it was built: an unfiltered first run against snapshot #1 returned 615
 * hits — technically correct (all 615 really are absent from this character's real spellbook)
 * but useless as an actionable list, since the overwhelming majority are the exact same junk
 * (hidden internal duplicates, pet-family/pet-proc spells, old-expansion artifact/covenant
 * remnants) that TalentSelectionService::alwaysAvailableAbilityIds() already learned to filter
 * out when deciding what to actually display. Direction C now applies that identical filter
 * (not_in_spellbook=false, is_passive=false, no `(desc=...)` suffix, real cooldown/charges data)
 * before comparing — turning this from "615 things nobody expected to see anyway" into a direct
 * validation of what the Spells page actually shows: does the real character's spellbook agree
 * with what alwaysAvailableAbilityIds() would currently render for this spec?
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
        $ambiguousSpecMismatch = $this->directionC($snapshot, $class, $spec, $patch);

        $this->newLine();
        $this->info('=== Direction A: in game, missing/mistagged in DB (the real alarm) ===');
        $this->line('MISSING_SPELL: '.count($missingSpell));
        $this->line('MISSING_AVAILABILITY: '.count($missingAvailability));
        $this->newLine();
        $this->info('=== Direction B: in DB (spec-explicit), not in game (informational — many legitimate hits expected) ===');
        $this->line('NOT_IN_SPELLBOOK_CANDIDATE: '.count($notInSpellbook));
        $this->newLine();
        $this->info('=== Direction C: in DB (spec_id=NULL / class-wide claim), not in game (real spec-tagging correction candidates) ===');
        $this->line('AMBIGUOUS_SPEC_MISMATCH: '.count($ambiguousSpecMismatch));

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

        if ($ambiguousSpecMismatch !== []) {
            $this->newLine();
            $this->warn('AMBIGUOUS_SPEC_MISMATCH details (spec_id=NULL in DB, absent from this spec\'s real spellbook):');
            $this->table(['spell_id', 'name', 'source'], array_map(
                fn (array $r) => [$r['spell_id'], $r['name'], $r['source']],
                $ambiguousSpecMismatch
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
                'ambiguous_spec_mismatch' => $ambiguousSpecMismatch,
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

        $rows = SpellClassAvailability::where('class_id', $class->id)
            ->where('spec_id', $spec->id)
            ->with('spell')
            ->get();

        return $this->notFoundInSpellbook($snapshot, $rows, $patch);
    }

    /**
     * The `spec_id = NULL` counterpart to directionB() — see this class's docblock for why it
     * exists and what a hit here actually means (a real spec-tagging correction candidate, not
     * background noise). Same "in DB, not in game" comparison, just scoped to the rows Direction
     * B structurally can't see (an exact spec_id match is required there; these rows have none).
     *
     * @return array<int, array>
     */
    private function directionC(SpellbookSnapshot $snapshot, GameClass $class, ?Specialization $spec, Patch $patch): array
    {
        if (!$spec) {
            return [];
        }

        // Same "plausibly real, currently displayed" filter as
        // TalentSelectionService::alwaysAvailableAbilityIds() — see this class's docblock for why
        // an unfiltered query here is technically correct but practically useless (615 hits,
        // almost all already-known junk). Restricted to source='baseline' to match that method
        // exactly — a null-spec_id 'talent'/'pvp_talent' row is a different mechanism entirely
        // (whether it was actually picked, via selectedSpellIds(), not whether it's spec-correct)
        // and mixing it in here produced false triggers (e.g. Halo, Mass Dispel — real, class-wide
        // Priest talents this admin build simply hasn't picked, not spec mismatches).
        $rows = SpellClassAvailability::where('class_id', $class->id)
            ->whereNull('spec_id')
            ->where('source', 'baseline')
            ->whereHas('spell', function ($q) {
                $q->where('is_passive', false)
                    ->where('not_in_spellbook', false)
                    ->where('name', 'not like', '%(desc=%')
                    ->where(fn ($q2) => $q2->whereNotNull('cooldown_seconds')->orWhereNotNull('charges'));
            })
            ->with('spell')
            ->get();

        return $this->notFoundInSpellbook($snapshot, $rows, $patch);
    }

    /**
     * Shared comparison behind directionB()/directionC(): which of the given
     * spell_class_availability rows claim a spell this snapshot's real export never actually
     * shows.
     *
     * @param  \Illuminate\Support\Collection<int, SpellClassAvailability>  $rows
     * @return array<int, array>
     */
    private function notFoundInSpellbook(SpellbookSnapshot $snapshot, $rows, Patch $patch): array
    {
        $snapshotSpellIds = $snapshot->entries->pluck('spell_id')->flip();
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
