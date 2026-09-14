<?php

namespace App\Http\Services;

use App\Models\Spell;
use App\Models\SpellChange;
use App\Models\SpellDataUpdate;
use Illuminate\Support\Facades\DB;

/**
 * Remembers what an import:spelldata run changed on spells that already existed, and writes it as
 * one SpellDataUpdate when the run finishes — the source of the "game data updated" items in the
 * Home feed.
 *
 * WHAT COUNTS. Only the class-record pass of the importer calls capture() (see
 * ImportSpellData::$recordSpellChanges). The curated passes that run afterwards — override files,
 * scalar corrections, materialized columns — are this project's own edits, not the game changing,
 * and presenting them as "patch changes" would be false.
 *
 * WHO SEES IT. flush() keeps only spells a player can actually press or pick: a talent entry, a PvP
 * talent, or a verified baseline ability. SimC's dump is thousands of hidden auras and proc
 * records; a changed duration on one of those is real data and meaningless in a feed.
 *
 * A parser change in this codebase can also rewrite thousands of descriptions without the game
 * changing at all. Descriptions are therefore recorded (the detail is useful later) but the feed
 * never lists them individually — see SpellDataUpdate's rendering in <x-feed.data-update>.
 */
class SpellChangeRecorder
{
    /** Tracked field => how a player reads it. */
    public const TRACKED_FIELDS = [
        'cooldown_seconds' => 'Cooldown',
        'charges' => 'Charges',
        'duration_seconds' => 'Duration',
        'description' => 'Tooltip',
    ];

    /** @var array<int, array{spell_id:int, field:string, old:?string, new:?string}> */
    private array $pending = [];

    /** Call BEFORE saving an existing Spell whose attributes have just been filled. */
    public function capture(Spell $spell): void
    {
        if (! $spell->exists) {
            return;
        }

        foreach (array_keys(self::TRACKED_FIELDS) as $field) {
            if (! $spell->isDirty($field)) {
                continue;
            }

            $old = $this->normalise($field, $spell->getRawOriginal($field));
            $new = $this->normalise($field, $spell->getAttributes()[$field] ?? null);

            if ($old === $new) {
                continue;
            }

            $this->pending[] = ['spell_id' => $spell->id, 'field' => $field, 'old' => $old, 'new' => $new];
        }
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Write the run's changes, restricted to pressable spells. Returns null (and writes nothing)
     * when nothing a player could see changed, so an import that only touched hidden records
     * never produces an empty feed item.
     */
    public function flush(?int $patchId, ?string $buildVersion): ?SpellDataUpdate
    {
        if ($this->pending === []) {
            return null;
        }

        // A spell can be written more than once in a run (the same record appears in several of a
        // class's files). Keep the value it had before the run and the one it ended on; drop it if
        // those are the same.
        $merged = [];
        foreach ($this->pending as $r) {
            $key = $r['spell_id'].'|'.$r['field'];
            $merged[$key] = isset($merged[$key])
                ? array_merge($merged[$key], ['new' => $r['new']])
                : $r;
        }
        $merged = array_filter($merged, fn ($r) => $r['old'] !== $r['new']);
        $this->pending = [];

        $visible = $this->visibleSpellIds(array_unique(array_column($merged, 'spell_id')));
        $rows = array_values(array_filter($merged, fn ($r) => isset($visible[$r['spell_id']])));

        if ($rows === []) {
            return null;
        }

        return DB::transaction(function () use ($rows, $patchId, $buildVersion) {
            $update = SpellDataUpdate::create([
                'patch_id' => $patchId,
                'build_version' => $buildVersion,
                'changed_spell_count' => count(array_unique(array_column($rows, 'spell_id'))),
            ]);

            $now = now();
            foreach (array_chunk($rows, 500) as $chunk) {
                SpellChange::insert(array_map(fn ($r) => [
                    'spell_data_update_id' => $update->id,
                    'spell_id' => $r['spell_id'],
                    'field' => $r['field'],
                    'old_value' => $r['old'],
                    'new_value' => $r['new'],
                    'created_at' => $now,
                ], $chunk));
            }

            return $update;
        });
    }

    /** @return array<int, true> internal spells.id => true, for spells a player can press or pick. */
    private function visibleSpellIds(array $spellIds): array
    {
        $ids = collect();

        foreach (array_chunk($spellIds, 1000) as $chunk) {
            $ids = $ids
                ->merge(DB::table('talent_node_entries')->whereIn('spell_id', $chunk)->pluck('spell_id'))
                ->merge(DB::table('pvp_talents')->whereIn('spell_id', $chunk)->pluck('spell_id'))
                ->merge(DB::table('spell_class_availability')
                    ->where('source', 'verified_override')
                    ->whereIn('spell_id', $chunk)
                    ->pluck('spell_id'));
        }

        return $ids->unique()->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** Numbers compare as numbers ("30.00" and 30 are the same cooldown); text is trimmed. */
    private function normalise(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($field === 'description') {
            return trim((string) $value);
        }

        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') : (string) $value;
    }
}
