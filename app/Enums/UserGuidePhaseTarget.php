<?php

namespace App\Enums;

/**
 * Who a phase of a sequence is aimed at.
 *
 * This is the piece that turns a flat list of abilities into a readable plan. "Kidney, Blind,
 * Polymorph, Shadow Blades, Eviscerate" is a list; "SETUP — on their healer: Blind, Polymorph /
 * GO — on the kill target: Kidney, Shadow Blades, Eviscerate" is a go someone can actually run.
 * It is also the distinction the DR maths already depends on but could never show, since two
 * controls on DIFFERENT targets do not diminish each other in the way the section total implies.
 *
 * Roles rather than names, because a kill target is a role that changes game to game. A phase can
 * additionally name a specific enemy spec (payload target_spec_id) when the guide's enemy team
 * makes that meaningful — "on their Rogue" — and that wins over the role for display.
 *
 * Stored as a plain string inside the block payload, so adding a role needs no migration.
 */
enum UserGuidePhaseTarget: string
{
    case KillTarget = 'kill_target';
    case Healer = 'healer';
    case OffTarget = 'off_target';
    case YourTeam = 'your_team';

    public function label(): string
    {
        return match ($this) {
            self::KillTarget => 'Kill target',
            self::Healer => 'Their healer',
            self::OffTarget => 'Off-target',
            self::YourTeam => 'Your team',
        };
    }

    /**
     * The chip's full wording. Built here rather than as "on the ".label() at the call site,
     * because the preposition is not the same for every role — that produced "on the their
     * healer" the first time this rendered.
     */
    public function phrase(): string
    {
        return match ($this) {
            self::KillTarget => 'on the kill target',
            self::Healer => 'on their healer',
            self::OffTarget => 'on the off-target',
            self::YourTeam => 'on your team',
        };
    }

    /** Tailwind classes for the chip, so a target reads at a glance in a long guide. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::KillTarget => 'badge-orange',
            self::Healer => 'badge-blue',
            self::OffTarget => 'badge-gray',
            self::YourTeam => 'badge-green',
        };
    }
}
