<?php

namespace App\Enums;

/**
 * What one section of a guide is.
 *
 * Replaces the guide-level UserGuideType (removed 2026-09-08): a guide can hold several chains, a
 * go, some prose and an opponent's defensives all at once, so "what kind of thing is this" is a
 * property of the section, not the guide. It is also the thing that decides which abilities the
 * section's palette offers, which is why it lives next to the section it governs.
 *
 * Stored as a plain string column, never a DB enum — see the user_guides migration for why.
 */
enum UserGuideSectionKind: string
{
    /** Control only. Palette: the comp's pressable crowd control. */
    case Chain = 'chain';

    /**
     * A coordinated go: the control that creates the window and the damage that spends it.
     * Palette: the comp's crowd control plus its real offensive cooldowns.
     */
    case Go = 'go';

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
            self::Chain => 'CC chain',
            self::Go => 'Go',
            self::Defensives => 'Defensives to force',
            self::Text => 'Notes',
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

    /** Whether the palette should include offensive cooldowns alongside control. */
    public function includesOffensive(): bool
    {
        return $this === self::Go;
    }

    /** Whether diminishing returns and control time mean anything for this section. */
    public function tracksControl(): bool
    {
        return $this === self::Chain || $this === self::Go;
    }
}
