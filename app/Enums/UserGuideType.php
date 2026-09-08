<?php

namespace App\Enums;

/**
 * What a guide is written about: a comp, or one spec.
 *
 * THIS IS NOT THE `user_guides.type` COLUMN THAT WAS DROPPED ON 2026-09-08, and reintroducing a
 * guide-level type is not churn — it is a different axis. The old column recorded whether a guide
 * was a chain or a go, which was wrong because one guide can hold both at once; that axis now lives
 * on the section (UserGuideSectionKind) and the two kinds have since merged anyway. This one
 * records the roster shape, which genuinely cannot vary within a guide: a guide is either about a
 * team or about a single spec, and the answer decides the layout, the roster cap and which
 * abilities the palette offers.
 *
 * Added 2026-09-08 on a direct report: the comp builder had no way to write "Rogue vs Disc", "Rogue
 * rotation" or "how to get pollies as Mage", and the whole-kit palette that a rotation guide needs
 * made the comp palette too complicated for what a 3v3 go actually is.
 *
 * Stored as a plain string column, never a DB enum — see the user_guides migration for why.
 */
enum UserGuideType: string
{
    /** 2-3 specs. Palette: the comp's control and its real offensive/defensive cooldowns. */
    case Comp = 'comp';

    /**
     * One spec, optionally against one named opponent. Palette: that spec's WHOLE pressable kit,
     * because a rotation or a technique is built out of the abilities a comp guide has no reason
     * to list — the filler, the mobility, the interrupt, the poison you press out of combat.
     */
    case ClassGuide = 'class';

    public function label(): string
    {
        return match ($this) {
            self::Comp => 'Comp guide',
            self::ClassGuide => 'Class guide',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Comp => 'A 2v2 or 3v3 team: the opener, the go, what to force.',
            self::ClassGuide => 'One spec: a rotation, a technique, or a specific matchup.',
        };
    }

    /** How many comp slots this kind of guide has. A class guide is about one spec. */
    public function maxMembers(): int
    {
        return $this === self::Comp ? 3 : 1;
    }

    /**
     * Whether the palette offers the spec's whole pressable kit rather than just control and
     * cooldowns. Only a class guide does: "we don't need utility or other in the 3v3 2v2 guide
     * section, that's for a different type of guide."
     */
    public function usesWholeKit(): bool
    {
        return $this === self::ClassGuide;
    }

    /** Whether this guide names a single opponent for the whole guide ("Rogue vs Disc"). */
    public function hasGuideOpponent(): bool
    {
        return $this === self::ClassGuide;
    }
}
