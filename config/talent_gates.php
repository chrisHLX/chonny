<?php

/**
 * Point-threshold "gates" in the WoW Class/Spec talent tree system: a node at or below a gated
 * display_row can't be selected until a minimum number of points has been spent elsewhere in
 * that SAME tree — separate from, and in addition to, the per-node prerequisite chain already
 * captured in talent_node_edges (see TalentSelectionService::isNodeLocked()).
 *
 * Also the per-tree point budgets, added 2026-09-25 — see `budgets` below.
 *
 * ── Where these numbers come from ────────────────────────────────────────────────────────────
 *
 * Blizzard's Game Data API exposes none of this. Checked directly, twice: a fetched talent tree
 * JSON carries no "gate", "req", "min", "spent" or "total" key, and re-checked against the
 * 12.1.0.69933 trees the only budget-shaped key in the whole response is `max_ranks`, which is
 * per node. A synced character's own talent snapshot has no such field either (14 distinct keys,
 * none of them a budget). So none of this can be derived from the data on hand, and it is
 * recorded here with its source instead.
 *
 * GATES — row 5 at 8 points, row 8 at 23 points.
 *   The rows and the 8 come from Warcraft Wiki's Talent page: "row 5 was locked until a total of
 *   8 talent points had been spent in that tree, and row 8 was locked until a total of 20 points
 *   had been spent." The 20 is out of date: Blizzard's own Midnight announcement says "the point
 *   requirement to unlock the final node will be increasing from twenty to twenty-three".
 *   https://news.blizzard.com/en-us/article/24230699/level-up-your-talents-in-midnight
 *   That is a primary source on the current system, so 23 is used and the wiki's 20 is not.
 *   Whether Midnight also moved the row-5 threshold is not stated anywhere found — unchanged
 *   here, and still the weakest number on this page.
 *
 * BUDGETS — spec 34, class unknown, hero not a budget at all.
 *   The spec figure is OBSERVED, not looked up: every level-90 character synced to this site
 *   shows exactly 34 summed ranks in its spec tree, across ~40 specs and all 12 classes. It also
 *   matches Blizzard's stated "+4 talent points" for the spec tree in Midnight on top of the
 *   prior expansion's 30.
 *
 *   The CLASS budget is deliberately null. Blizzard states the delta (+3) but no source found
 *   states the total, and the observed class-tree ranks cannot supply it: they run 35 to 40 by
 *   class because auto-granted class talents are included in the count and this schema does not
 *   record which talents are granted. The dump's `free=(Spec)` annotation was tested as a proxy
 *   and does not account for the gap — subtracting it leaves 35, 36, 37 and 39 rather than one
 *   number. A cap guessed at here would grey out real, legal picks, which is worse than not
 *   capping; `null` means "no cap enforced", exactly as before.
 *
 *   HERO IS NOT A BUDGET. Every node in a hero tree is granted by max level — Warcraft Wiki on
 *   the system: "Points are earned at every level from 71 to 80, so that by level 80 every
 *   talent in the tree will be acquired", and Midnight adds a column to each tree. So a hero
 *   tree is complete by definition once chosen, bar the picks inside its CHOICE nodes, and a
 *   "points spent" counter against it is measuring the wrong thing. Recorded as the observed
 *   node total rather than a spend limit.
 */
return [
    'gates' => [
        ['display_row' => 5, 'points_required' => 8],
        ['display_row' => 8, 'points_required' => 23],
    ],

    /**
     * Summed ranks a tree can hold. Null means no cap is enforced — see the header.
     * `hero_is_granted` says the hero tree is completed by levelling rather than spent into.
     */
    'budgets' => [
        'class' => null,
        'spec' => 34,
        'hero_is_granted' => true,
    ],
];
