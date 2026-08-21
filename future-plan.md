# Future Plan

Ideas flagged for later — deliberately not built yet. Each entry should stay here until it's
either built (move the detail into `CLAUDE.md` as a dated "COMPLETE" section, same convention as
everything else there) or explicitly dropped.

## Tag spells as Hero Talents on spell displays (flagged 2026-08-22)

**Not current — do not build without a fresh decision.** Noted here per direct instruction after
building the Top Damage Rotations page, as a real gap worth remembering rather than acting on
immediately.

**The ask:** show, on a spell's card/detail (WoW Comps, Spell Explorer, Top Damage Rotations,
`<x-spells.table>`, `SpellDetailModal` — anywhere a spell renders today), whether it's
specifically a Hero Talent, the same way `source` already distinguishes `talent` / `pvp_talent` /
`baseline`.

**Why this isn't a one-line fix, per the user's own framing ("not obvious how it would work
unless modifiers or talents under hero talents are affecting the spells"):**

- The structural fact already exists in the data model and is cheap to check: a `TalentNode`
  belongs to a `TalentTree` whose `type` column can be `'hero'` (see `TalentTree.php` — hero
  trees also carry the `(exactly two, by game design) specs it's available to`, already modeled
  via a pivot). So "is this exact talent pick itself a hero-tree node" is a real, already-
  queryable fact today — `TalentSelectionService::allTalentSpellIds()` already unions hero trees
  into its result set, it just doesn't currently tag *which* union member a given spell_id came
  from.
- The harder, genuinely unresolved part is exactly what the user flagged: a spell's *display*
  cooldown/charges/description on this site is often the result of `ModuleSpellReferenceService`
  layering **modifiers** from other talents on top of a base spell (see `modifiersFor()`,
  `effectiveCooldown()`, `effectiveCharges()`, `resolveDescription()`'s Variables-block "Scales
  with" list — all documented at length in `CLAUDE.md`). A spell that is NOT itself a hero-talent
  node can still be substantially changed BY a hero-talent modifier (a base-tree spell whose
  cooldown a hero talent shortens, e.g. the same shape as Anti-Magic Barrier modifying Anti-Magic
  Shell, or Ultimate Radiance modifying Evangelism — both already-documented real cases, neither
  hero-tree-specific, but the exact same mechanism a hero talent could use). So "is this a hero
  talent" has at least two different real questions bundled into it:
  1. Is the spell's OWN talent pick itself in a hero tree? (cheap, already derivable)
  2. Is the spell's CURRENT effective number (cooldown/charges/description) being changed by a
     hero-talent modifier, even though the spell itself lives in the class or spec tree? (not
     cheap — needs walking `modifiersFor()`'s relationship results back to each modifier's own
     `TalentNode`/`TalentTree.type`, which no current code path does)

**What a real implementation would need to decide before being built:**
- Whether to show only case 1 (simple, honest, but won't explain "why does this cooldown look
  different" for case 2), or attempt both (more complete, but needs the modifier-tracing work
  above, and needs a decision on how to label a spell that's base-tree but hero-modified — "Hero
  Talent" would be a wrong/misleading label for that case, since the spell itself isn't the hero
  pick).
- Where the badge lives given multiple display surfaces already exist with their own established
  badge vocabulies (`source` badges — T/PvP — on WoW Comps/Spell Explorer entries; the `CD`/`DR`
  badges on rotation-tab steps) — needs to not collide visually or semantically with those.

Not scoped further than this — the point of this entry is to record that the idea was raised and
why it's genuinely a two-part question, not to pre-design the eventual feature.
