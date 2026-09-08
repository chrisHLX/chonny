<?php

namespace App\Models;

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuidePhaseTarget;
use Illuminate\Database\Eloquent\Model;

/**
 * One ordered unit of a user guide.
 *
 * Read the create_user_guide_blocks_table migration first — it carries the reasoning for the
 * payload contract, and in particular why a spell block stores Blizzard's EXTERNAL spell id rather
 * than spells.id.
 *
 * The payload is intentionally schemaless JSON rather than a column per block type: the block
 * vocabulary is expected to grow (a target marker, a timing offset, an opponent-spec condition),
 * and each addition would otherwise be a migration adding columns that are null for every existing
 * row. What is NOT flexible is the rule about what may go in it — references and free text only,
 * never a resolved name, cooldown or duration. Accessors here are the sanctioned way to read it so
 * that rule has one place to be enforced.
 */
class UserGuideBlock extends Model
{
    protected $fillable = [
        'user_guide_section_id',
        'position',
        'block_type',
        'payload',
    ];

    protected $casts = [
        'block_type' => UserGuideBlockType::class,
        'payload' => 'array',
        'position' => 'integer',
    ];

    public function section()
    {
        return $this->belongsTo(UserGuideSection::class, 'user_guide_section_id');
    }

    /**
     * Blizzard's own spell id for a spell block, or null for every other block type.
     *
     * This is NOT spells.id. Resolving it means a patch-scoped lookup on spells.spell_id — the two
     * id spaces are trivially confusable and have silently produced wrong-but-clean results in
     * this codebase twice already (see the migration docblock). Read through this accessor rather
     * than reaching into payload directly, so a caller cannot quietly get the wrong one.
     */
    public function externalSpellId(): ?int
    {
        if (! $this->block_type->referencesSpell()) {
            return null;
        }

        $id = $this->payload['external_spell_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** Free text carried by a note, heading or phase block. Null for spell blocks. */
    public function text(): ?string
    {
        $value = match ($this->block_type) {
            UserGuideBlockType::Note, UserGuideBlockType::Heading => $this->payload['text'] ?? null,
            UserGuideBlockType::Phase => $this->payload['name'] ?? null,
            UserGuideBlockType::Spell => null,
        };

        return is_string($value) ? $value : null;
    }

    /**
     * The role a phase is aimed at, or null when it names none. Unknown values resolve to null
     * rather than throwing — payload is JSON and an older or hand-edited row must not break a
     * whole guide's render.
     */
    public function phaseTarget(): ?UserGuidePhaseTarget
    {
        $value = $this->payload['target'] ?? null;

        return is_string($value) ? UserGuidePhaseTarget::tryFrom($value) : null;
    }

    /** A specific enemy spec this phase is aimed at, which wins over the role for display. */
    public function phaseTargetSpecId(): ?int
    {
        $id = $this->payload['target_spec_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** An author's optional annotation on a spell block ("only if they trinketed"). */
    public function note(): ?string
    {
        $note = $this->payload['note'] ?? null;

        return is_string($note) && $note !== '' ? $note : null;
    }
}
