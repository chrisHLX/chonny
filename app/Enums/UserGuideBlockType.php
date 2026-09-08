<?php

namespace App\Enums;

/**
 * The block vocabulary a guide is composed from — the ordered, typed units a builder canvas drags
 * around. This is the piece the codebase did not previously have anywhere: ModulePage, the nearest
 * existing thing to authored content, is a single longText markdown blob with no composition model
 * at all.
 *
 * Every block's real content lives in user_guide_blocks.payload (JSON). The one rule that governs
 * every payload shape: store REFERENCES and free text only, never resolved game data. See
 * UserGuideBlock's docblock for the full reasoning.
 *
 * Stored as a plain string column, never a DB enum — see the migration's docblock for why.
 */
enum UserGuideBlockType: string
{
    /**
     * A single ability. payload: { "external_spell_id": int, "note": ?string }
     *
     * external_spell_id is Blizzard's own spell id, NOT spells.id — see UserGuideBlock.
     */
    case Spell = 'spell';

    /** A section heading. payload: { "text": string } */
    case Heading = 'heading';

    /** Free prose. payload: { "text": string } */
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Spell => 'Ability',
            self::Heading => 'Heading',
            self::Note => 'Note',
        };
    }

    /**
     * Whether this block references a game entity that has to be resolved at render time. Only
     * these blocks participate in validation (is this ability in the spec's kit, is this step
     * diminished by an earlier one) and only these are affected by a patch bump.
     */
    public function referencesSpell(): bool
    {
        return $this === self::Spell;
    }
}
