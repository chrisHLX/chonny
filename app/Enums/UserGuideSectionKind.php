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
     * The enemy's side of the matchup: what to watch out for and what to force. Palette: the
     * OPPONENT's kit — their crowd control (grouped by DR category), interrupts, and offensive and
     * defensive cooldowns — drawn from the section's own opponent, else the guide's enemy team,
     * else a class guide's single opponent.
     *
     * STORED AS 'defensives', and the case is named for that history. Until 2026-09-14 this section
     * offered only the opponent's defensive cooldowns ("defensives to force"); it was broadened
     * because the enemy's CC and go are at least as much a part of a matchup as their answers
     * ("we have the option for defensives but we also need to show offensives and potentially
     * CC"). Every existing section already means "the opponent's side", so the value was kept
     * rather than migrated — renaming it would rewrite live rows to say the same thing.
     *
     * Not a sequence in the DR sense: it is a list of separate threats, usually from several
     * enemy players, so no DR is tallied across it (see tracksControl()).
     */
    case Defensives = 'defensives';

    /**
     * One ability and the talents that change it — Penance with Power of the Dark Side,
     * Castigation and Harsh Discipline; Weal and Woe with Power Word: Shield.
     *
     * The first block is the SUBJECT (payload.role = 'subject'); the rest are its MODIFIERS. The
     * author picks both, but does not have to go hunting: spell_relationships already knows which
     * talents modify a given ability and by how much, so the builder offers that list and the
     * author ticks what the point is about and writes why it matters. The numbers stay live — a
     * patch that changes Castigation changes this section without anyone re-authoring it — while
     * the reason it is worth knowing is the author's, which is the half no data has.
     */
    case Synergy = 'synergy';

    /** Prose. Markdown in the section's body; no abilities, no metrics. */
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Sequence => 'Sequence',
            self::Defensives => 'Enemy abilities',
            self::Synergy => 'Ability & talents',
            self::Text => 'Notes',
        };
    }

    /** One line of "what is this for", shown beside the add button. */
    public function hint(): string
    {
        return match ($this) {
            self::Sequence => 'An ordered run of abilities — a go, a chain, an opener, a rotation.',
            self::Defensives => 'Their CC, interrupts, offensive and defensive cooldowns — what to watch for and what to force.',
            self::Synergy => 'One ability and the talents that change it, with what each one does to it.',
            self::Text => 'Free notes in Markdown.',
        };
    }

    /** Whether this section holds an ordered list of abilities rather than prose. */
    public function isSequence(): bool
    {
        return $this !== self::Text;
    }

    /**
     * Whether this section is one subject ability plus the things that modify it, rather than a
     * run of abilities. Its blocks are unordered in the DR sense and its first block is special.
     */
    public function isSynergy(): bool
    {
        return $this === self::Synergy;
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
