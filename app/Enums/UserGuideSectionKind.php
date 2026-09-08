<?php

namespace App\Enums;

/**
 * What one section of a guide is.
 *
 * Replaces the guide-level UserGuideType (removed 2026-09-08): a guide can hold several sequences,
 * some prose and an opponent's defensives all at once, so "what kind of thing is this" is a
 * property of the section, not the guide. It is also the thing that decides which abilities the
 * section's palette offers, which is why it lives next to the section it governs.
 *
 * WHY `Chain` AND `Go` COLLAPSED INTO ONE `Sequence` (2026-09-08, direct report: "they are both
 * the same thing"). They were: both an ordered list of abilities, both DR-tallied, both rendered
 * by the same component. The only difference in code was that a Go's palette also offered
 * offensive cooldowns — so the choice made at creation time silently decided which half of your
 * own kit you were allowed to reach for, and there was no way to convert one into the other
 * afterwards. An author who started a "chain" and then wanted to add the damage it sets up had to
 * delete it and start again.
 *
 * Now there is one sequence kind whose palette offers the whole pressable kit, and what a section
 * IS is carried by its title — which the author writes anyway, and which says far more than
 * "chain" or "go" ever did ("Opener into trap", "Kidney into Convoke", "Peel when Sub goes"). The
 * thing that actually distinguishes one guide from another is its comp, not its section types.
 *
 * Stored as a plain string column, never a DB enum — see the user_guides migration for why. The
 * retired 'chain' and 'go' values were migrated to 'sequence' in place; see
 * 2026_09_08_000004_merge_guide_chain_and_go_sections.
 */
enum UserGuideSectionKind: string
{
    /**
     * An ordered run of abilities: control, the damage it sets up, the defensive you weave in,
     * the utility that makes it work. Palette: everything pressable in the author's own comp.
     */
    case Sequence = 'sequence';

    /**
     * What you are trying to force out of the opponent. Palette: the DEFENSIVE cooldowns of the
     * section's own opponent_spec_id — a different spec's kit entirely from the rest of the guide,
     * which is the whole point of a VS section.
     */
    case Defensives = 'defensives';

    /** Prose. Markdown in the section's body; no abilities, no metrics. */
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Sequence => 'Sequence',
            self::Defensives => 'Defensives to force',
            self::Text => 'Notes',
        };
    }

    /** One line of "what is this for", shown beside the add button. */
    public function hint(): string
    {
        return match ($this) {
            self::Sequence => 'An ordered run of abilities — a go, a chain, an opener, a rotation.',
            self::Defensives => "A named opponent's defensive cooldowns, so you can plan what to force.",
            self::Text => 'Free notes in Markdown.',
        };
    }

    /** Whether this section holds an ordered list of abilities rather than prose. */
    public function isSequence(): bool
    {
        return $this !== self::Text;
    }

    /** Whether the palette should draw from an opponent rather than from the author's own comp. */
    public function usesOpponent(): bool
    {
        return $this === self::Defensives;
    }

    /** Whether diminishing returns and control time mean anything for this section. */
    public function tracksControl(): bool
    {
        return $this === self::Sequence;
    }
}
