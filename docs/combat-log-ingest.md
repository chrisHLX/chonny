# Reading arena games from your own combat log

*Moved here word for word from CLAUDE.md on 2026-10-04, to keep that file to orientation and rules.*

This is rule 12's full text.

12. **New matches come from YOUR OWN combat log.** `wow:ingest-combatlog` reads
    `WoWCombatLog.txt` and writes the archive's own `raw/` + `metadata/` files directly, so the
    archive is a growing corpus again with no third party involved. Everything the WoWArenaLogs
    API returned is in the log already, and the derivation was checked field by field against
    their metadata for all 16 matches we hold both halves of — bracket, zone, ranked, duration,
    winner, rating, result and the full roster matched 16/16. See `CombatLogIngestService` and
    `tools/wow-addon/MindCollectorArenaLog/`.

    **Two mappings that are NOT guessable and were fitted to that archive.** `reaction` is
    friendly-or-hostile *as seen by the logging player*, so it is **not** the arena team id — the
    team only ever comes from `COMBATANT_INFO` field 2, and the two disagree in 12 of 16 matches.
    `playerTeamRating` is `ARENA_MATCH_END` field `3 + myTeam`; `result` is 2 for a loss, 3 for a
    win. **Advanced Combat Logging must be on** or the log carries no `COMBATANT_INFO`, so no
    specs, so nothing downstream can use the match.

    **A Solo Shuffle lobby is six matches, and none of its END line is usable** (measured over
    326 rounds in 56 lobbies, 2026-09-25). It writes one `ARENA_MATCH_START` **per round** and a
    single `ARENA_MATCH_END`; the old "a second START means the first never closed" rule — right
    for 2v2/3v3, where starts and ends ran 142 to 141 — kept only round six and silently dropped
    83% of the games. Each round re-emits the whole `COMBATANT_INFO` block with **re-dealt team
    ids**, so a round's teams can only come from its own block. `winningTeamId` on the END line is
    noise for shuffle (-1 twenty times, 0 twenty-two, 1 fourteen; it agrees with the final round's
    real loser 19 times in 55 — chance), and its two ratings are per-round averages of a roster
    that reshuffles, so `playerTeamRating` is written **null** rather than guessed. The winner
    comes from the deaths: **`UNIT_DIED`'s trailing field is `unconsciousOnDeath`** — 1 for a
    Hunter's Feign Death, 0 for a real one — and with that filter 324 of 326 rounds hold exactly
    one real death, the round-ending one. Without it a single feigning Hunter "dies" six times a
    lobby and inverts half the results. `isRanked` reads **0** on every `Rated Solo Shuffle` line,
    so the bracket name carries it instead. Verified independently: a 3-3 lobby is a draw, and the
    derived per-round record predicts the END line's `-1` draw flag in **55 of 55** lobbies while
    reading nothing from that field.

    **The WoWArenaLogs pullers are dead and stay dead** — `wow:pull-latest-matches`,
    `wow:pull-scarce-specs`, `wow:discover-all-specs`, `wow:pull-low-rated-spec`,
    `wow:discover-spec-spells`. That API returned `SEARCH_DISABLED` from 2026-09-09 (deliberate
    anti-scraping: *"automated scraping of search results has driven our hosting costs up
    sharply"*) and `UNAUTHENTICATED` behind a Battle.net sign-in from 2026-09-23. Signing in
    would make it permitted; the reason they built the gate has not changed, so do not resume
    bulk pulling through it.

## Position and facing (checked 2026-10-14)

**The log does record where each player stands.** Every event with an advanced block carries one
unit's position at that moment. The block starts 12 fields in for a spell event and 9 for a swing,
and its first field names the unit it describes (the caster on `SPELL_CAST_SUCCESS`, the target on
damage and heal lines). From the block's start: current and max health at +2 and +3, then
**x at +14, y at +15, a map id at +16 (0 in arenas), facing in radians at +17, item level at +18**
(format 22, patch 12.1.0; the block grew two fields since the layout most references give, so
count from a raw line, never from a reference). Checked three ways on the 996 archived games:
- **Units are yards.** Psychic Scream's 2,039 victims stood a median 5.2 yards from the priest's own
  cast position, 90% within 8.6 (an 8-yard radius plus a body); of 2,579 enemies it did not fear,
  220 were within 8 yards (immune, out of sight or already controlled).
- **Facing is radians from +x toward +y.** 94.6% of 10,444 casts that need the caster to face the
  target point within 90 degrees of it on that reading; swapping the axes gives chance (54%).
- **How fresh a position is depends on activity.** A unit is placed only when an event describes
  it: the median gap is 0.04s and 99% are under 0.85s in a fight, but a player who does nothing
  (stealth, drinking, a long cast) goes unplaced. At a random moment both of two players had a
  position under 0.5s old 73% of the time, under 1s 82%. A distance is trustworthy to about a yard
  with fresh samples; with a sample over a second old, fear victims "reached" 12+ yards. Use the
  sample's age, never interpolate across a gap.

**What it does not give:** the arena's walls and pillars. Distance and range are measurable; line of
sight is not (a player's own failed casts say "not in line of sight", nobody else's are logged).
The exploration that established this: `docs/learning/fear-windows-2026-10-14.md`.

## The format and patch each game was logged in (from 4 Oct 2026)

WoW writes a header whenever logging starts, so before every arena the addon logs:
`COMBAT_LOG_VERSION,22,ADVANCED_LOG_ENABLED,1,BUILD_VERSION,12.1.0,PROJECT_ID,1`. Each match now
records the header in force when it started (`combatLog` in its metadata: `version`, `advanced`,
`build`). Matches ingested earlier have none; every one said only `wowVersion: retail`.

Every offset above was measured on format **22** in patch **12.1.0**, and is read from the end of
the line. A format that adds a field would shift them all without one error. So
`wow:ingest-combatlog` prints `WARNING:` when a match's header differs from
`CombatLogIngestService::VERIFIED_LOG_VERSION` / `VERIFIED_BUILD`, and the desktop app raises it
in the tray:
- **A new format version:** re-measure the offsets against a fresh game before trusting it.
- **A new patch only:** import the spell data, then check one new game's card against what
  happened.

Then move the constant. A browser upload sends only the match, without a header, so it is not
checked.
