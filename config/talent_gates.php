<?php

/**
 * Point-threshold "gates" in the WoW Class/Spec talent tree system: a node at or below a gated
 * display_row can't be selected until a minimum number of points has been spent elsewhere in
 * that SAME tree — separate from, and in addition to, the per-node prerequisite chain already
 * captured in talent_node_edges (see TalentSelectionService::isNodeLocked()).
 *
 * ⚠ UNVERIFIED for the current "Midnight" system — flagged deliberately, not guessed silently.
 * Blizzard's own Midnight patch notes confirm a real "first gate" exists (e.g. "Gloom Ward is
 * now a 2-point talent and has been moved to the first gate"), so the MECHANISM is real and
 * current. Neither the exact display_row it sits at, nor the exact points-required number, nor
 * how many gates exist has been confirmed against live current-patch data — Blizzard's Game Data
 * API response for a talent tree carries no field for this at all (checked directly: no "gate",
 * "req", "min", "spent", or "total" key anywhere in a fetched tree JSON), so it can't be derived
 * from data already on hand the way display_row/display_col could be.
 *
 * The two entries below are carried over from the prior (Dragonflight-era) system as a
 * reasonable placeholder ONLY, per Warcraft Wiki: "row 5 was locked until a total of 8 talent
 * points had been spent in that tree, and row 8 was locked until a total of 20 points had been
 * spent." That page states explicitly it does NOT know whether Midnight kept these numbers,
 * changed them, or restructured the gates — do not treat this as confirmed. Verify against a
 * real character (either in-game directly, or a captured spellbook/talent export) and correct
 * this file the moment real numbers are known, then remove this warning.
 *
 * A gate applies to every node whose display_row >= its own display_row (gates stack — a node
 * past a later gate must satisfy every earlier gate's threshold too, which in practice just
 * means the highest applicable threshold wins, since thresholds only increase with row).
 */
return [
    'gates' => [
        ['display_row' => 5, 'points_required' => 8],
        ['display_row' => 8, 'points_required' => 20],
    ],
];
