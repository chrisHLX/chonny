# wowarenalogs.com API (undocumented, reverse-engineered 2026-08-14)

This is a completely separate, unrelated service from Warcraft Logs (warcraftlogs.com). The
`WARCRAFT_LOGS_CLIENT_ID`/`SECRET` in `.env` are for that other, real API and are not used
anywhere for what's documented here.

wowarenalogs.com has no public API docs — everything below was found by downloading and
reading the site's own Next.js JS bundles directly (`curl` the page, find the `<script src>`
chunks, `grep` them for endpoint strings and query text). No login, no API key, no rate-limit
headers observed on any of it.

## The endpoint

`POST https://wowarenalogs.com/api/graphql` — a standard GraphQL endpoint, introspection is
enabled (`{ __schema { ... } }` works), unauthenticated for at least `matchById` and
`latestMatches`.

## Fetching one match: `matchById`

```graphql
query($matchId: String!) {
  matchById(matchId: $matchId) {
    __typename
    ... on ArenaMatchDataStub {
      id
      wowVersion
      logObjectUrl
      result
      winningTeamId
      durationInSeconds
      startTime
      endTime
      startInfo { bracket zoneId isRanked }
      units { id name spec class reaction affiliation }
    }
    ... on ShuffleRoundStub {
      id
      wowVersion
      logObjectUrl
      result
      winningTeamId
      killedUnitId
      sequenceNumber
      durationInSeconds
      startTime
      endTime
      startInfo { bracket zoneId isRanked }
      units { id name spec class reaction affiliation }
    }
  }
}
```

`matchById` returns a `CombatDataStub` union — `ArenaMatchDataStub` (a normal 2v2/3v3 match)
or `ShuffleRoundStub` (one round of Rated Solo Shuffle; a full shuffle session is several of
these, linked via `shuffleMatchId`/`sequenceNumber`). The match ID is the value after `?id=`
on a `wowarenalogs.com/match?id=...` URL.

**`logObjectUrl`** points at the actual raw WoW combat log for the match — a public Google
Cloud Storage object, no auth needed, same text format the game itself writes to
`Logs/WoWCombatLog.txt`. This is where all the real per-event detail lives (casts, damage,
aura applies/removes, interrupts, dispels, deaths) — the GraphQL layer only ever gives you
match-level summary + participant list. `units[].spec` is Blizzard's real numeric
specialization ID (e.g. `264` = Restoration Shaman) — matches this project's own
`specializations.external_spec_id`, no separate lookup needed. `units[].class` is **not**
Blizzard's class ID — its exact mapping was never resolved (not worth it — `spec` alone is
sufficient and unambiguous). `units` includes every unit that appeared at all: real players,
pets, totems, and other summons (totems especially — several dozen for a 3v3 with 1-2
shamans). Filter to real players via `type === 1` or by checking for a name containing a
realm suffix.

`talent`/gear/PvP-talent selections are **not** in this GraphQL response at all — they live
inside the raw log's `COMBATANT_INFO` line (see below), not a separate API call.

## Fetching this project's copy

`php artisan wow:fetch-arena-log {matchIdOrUrl}` — see `app/Console/Commands/FetchArenaLog.php`.
Pulls both the metadata (via the query above) and the raw log (via `logObjectUrl`), writes:

```
data/arena-logs/raw/{matchId}.log        — the raw combat log, verbatim
data/arena-logs/metadata/{matchId}.json  — match + unit metadata, human-readable
```

Fetch-only — does not parse events, does not write to the app database.

## Searching/filtering matches: `latestMatches`

```graphql
query($wowVersion: String!, $bracket: String, $minRating: Float,
      $compQueryString: String, $lhsShouldBeWinner: Boolean,
      $offset: Int = 0, $count: Int = 50) {
  latestMatches(
    wowVersion: $wowVersion, bracket: $bracket, minRating: $minRating,
    compQueryString: $compQueryString, lhsShouldBeWinner: $lhsShouldBeWinner,
    offset: $offset, count: $count
  ) {
    queryLimitReached
    combats { __typename ... on ArenaMatchDataStub { id playerTeamRating units { id name spec reaction } } }
  }
}
```

This is the query behind the site's own `/search` page (`wowVersion: "retail"` is the only
value seen/used; `bracket` takes the same string as `startInfo.bracket`, e.g. `"3v3"`,
`"2v2"`, or `"Rated Solo Shuffle"`).

**`compQueryString` format** — pulled directly from the search page's own JS, not guessed:

```js
// team1SpecIds/team2SpecIds are arrays of spec ID numbers
team2.length > 0
  ? team1.sort().join("_") + "x" + team2.sort().join("_")
  : team1.sort().join("_")
```

So a single-team/"contains this spec somewhere" filter is just `"259"` (sorted, `_`-joined
spec IDs); a full two-team comp search is e.g. `"102_255_262x256_264_270"`. Confirmed live:
`compQueryString: "259"` only ever returns matches with an Assassination Rogue (spec 259) on
one side or the other.

**⚠ REAL BUG found and fixed 2026-08-14 — `.sort()` here is JavaScript's default sort,
which is LEXICOGRAPHIC STRING sort, not numeric.** This project's first implementation
(`ArenaLogService`) sorted spec IDs numerically in PHP, which only happens to produce the
same order as JS when every spec ID has an equal number of digits (e.g. `105/258/259`, all
3-digit — the one comp used to validate the mechanism early on, which is exactly why the bug
went unnoticed at first). Any comp mixing digit-counts sorts differently: `[64, 256, 261]`
(Frost Mage/Disc Priest/Sub Rogue — "RMP") gives `"64_256_261"` under numeric sort but the
site's real index is keyed on the STRING-sorted `"256_261_64"` (`"64"` sorts last because
`'6' > '2'` as the first character). The numeric version returned `combats: []` — silently
wrong, not an error — even though real, high-rated RMP wins existed and were trivially
findable once the query string was corrected. **Lesson: an empty result from this endpoint
is not safe to treat as "this comp hasn't been played recently" without first confirming
the query string is actually string-sorted** — `ArenaLogService::sortSpecIdsForQuery()` is
the fix, do not go back to a plain numeric `sort()` for anything sent to this API.

Also confirmed the same day: **`count` is silently capped at 50 server-side**, no matter how
high a value is requested — `count: 200`/`500`/`1000` all return exactly the same 50 results
as `count: 50`. `offset` is the real way to page deeper (confirmed: `offset: 50` on the same
query returns a genuinely different next batch). A search returning few/no results is not
evidence of a small underlying dataset unless `offset` has actually been paged through.

An unmatched/too-specific query (e.g. a real 3-spec comp + a rating floor with no recent
match satisfying both) returns `combats: []`, not an error — a filter combination that looks
wrong is indistinguishable from one that's just too narrow for current data, worth widening
`offset`/dropping `minRating` before assuming the format is bad (see the bug above for the
other, much easier way to get a false empty result).

`sibling fields not yet tested`: `lhsShouldBeWinner` (presumably restricts to matches where
team1 won), `latestMatches`'s siblings `myMatches`/`userMatches`/`characterMatches`/
`recentMatchesWithCombatant`/`matchesWithOwnerId` (all in the schema, none exercised —
`characterMatches(realm, characterName)` and `recentMatchesWithCombatant(combatantName,
serverName, region)` in particular look like they'd let you pull a specific known player's
match history directly, worth trying if that need comes up).

## The raw combat log

Real event-type inventory from one actual 3v3 match (236 seconds, retail, advanced combat
logging on) — `f3026e050f7c7833099b62bd33255ec5`, 24,218 lines / 6,806,332 bytes total:

| Event | Lines | Bytes | Notes |
|---|---:|---:|---|
| SPELL_ABSORBED | 2,617 | 772,637 | largest single category — shield absorb ticks |
| SPELL_PERIODIC_HEAL | 2,785 | 960,442 | HoT ticks |
| SPELL_PERIODIC_DAMAGE | 2,472 | 884,813 | DoT ticks |
| SPELL_AURA_REFRESH | 2,321 | 479,662 | |
| SPELL_AURA_APPLIED | 2,290 | 471,182 | |
| SPELL_AURA_REMOVED | 2,104 | 431,258 | |
| SPELL_DAMAGE | 1,552 | 547,282 | direct-cast damage, incl. amount |
| SPELL_CAST_SUCCESS | 1,164 | 368,822 | |
| SPELL_AURA_APPLIED_DOSE | 857 | 180,811 | stacking auras |
| SPELL_HEAL | 866 | 286,843 | |
| DAMAGE_SPLIT | 575 | 208,118 | |
| SPELL_AURA_REMOVED_DOSE | 456 | 96,270 | |
| SPELL_ENERGIZE | 446 | 149,681 | resource gains |
| SPELL_CAST_FAILED | 515 | 98,753 | |
| SWING_DAMAGE_LANDED | 471 | 153,120 | melee autoattack |
| SPELL_MISSED | 711 | 156,828 | |
| SPELL_PERIODIC_MISSED | 703 | 159,488 | |
| SWING_DAMAGE | 418 | 135,895 | |
| SPELL_PERIODIC_ENERGIZE | 353 | 122,365 | |
| SPELL_CAST_START | 215 | 38,082 | |
| SPELL_DISPEL | 70 | 14,947 | |
| SPELL_SUMMON | 69 | 14,727 | pets/totems |
| SWING_MISSED | 101 | 18,513 | |
| SPELL_EXTRA_ATTACKS | 41 | 8,285 | |
| SPELL_AURA_BROKEN_SPELL | 17 | 3,972 | |
| COMBATANT_INFO | 6 | 15,096 | one per real player, see below |
| PARTY_KILL | 7 | 1,237 | |
| UNIT_DIED | 5 | 743 | |
| SPELL_INTERRUPT | 8 | 1,687 | records BOTH the interrupting spell and the interrupted one |
| SPELL_CREATE | 2 | 440 | |
| ARENA_MATCH_START/END | 2 | 115 | |

Every event line is CSV-shaped: `timestamp  EVENT_TYPE,sourceGUID,"sourceName",sourceFlags,
sourceRaidFlags,destGUID,"destName",destFlags,destRaidFlags,spellId,"spellName",spellSchool,
...`. `SPELL_AURA_*` events carry an `AURA_TYPE` (`BUFF`/`DEBUFF`) after the spell fields.
With advanced combat logging on (`hasAdvancedLogging: true` in the match metadata — true for
this match, may not always be), cast/damage/heal lines carry a long extra tail: current
resources, absorbed amount, x/y position, facing, item level, spec id.

**Real capabilities confirmed by directly grepping this file** (not assumed from general
combat-log knowledge):
- CC duration: `SPELL_AURA_APPLIED` → `SPELL_AURA_REMOVED` (or `_REFRESH` extending it)
  timestamps, diffed directly. Used to confirm a real Cheap Shot ran exactly 6.000s
  uninterrupted in this match (see `PVP duration.txt` in this folder for the full trace and
  a real, unresolved contradiction with the user's own broader in-game observation).
- Damage to a specific ability: `SPELL_DAMAGE`/`SPELL_PERIODIC_DAMAGE` carry the spell name
  and exact amount.
- Interrupts: `SPELL_INTERRUPT` names both spells — e.g. a real line in this match records
  Muzzle (187707) interrupting Cyclone (33786).

### `COMBATANT_INFO` — talents and gear are embedded here, not a separate call

One line per real player, written once at match start (or once per round, for Solo
Shuffle). Confirmed by breaking down one real line byte-for-byte rather than assuming the
documented Blizzard format still matches:

- A bracketed list of `(id,id,rank)` triples — the player's full class+spec+hero talent
  tree selection. 77 entries in the example checked.
- A 4-number group `(0,212295,409835,426352)` — the 4 PvP talent slots (0 = empty). Same
  4-slot shape this project's own `MindCollectorExport` addon work already found the hard
  way for the live API (`main.lua`'s PvP talent collector) — independent confirmation, two
  different sources agreeing.
- A second bracketed list of `(itemID, itemLevel, (bonusIDs), (gemIDs), (enchantIDs))`
  tuples — the full equipped gear loadout. 18 entries in the example checked.

So: **no separate Blizzard/armory lookup is needed for a match's gear or talents** — it's
all in the one raw log file, same as everything else.

## Comp-based pulling: `wow:pull-comp-log`

Built 2026-08-14, on top of everything above. `App\Http\Services\ArenaLogService::pullBestWinForComp()`
+ `php artisan wow:pull-comp-log {specs}` (comma-separated Blizzard spec IDs, e.g.
`256,64,261` for Disc Priest/Frost Mage/Sub Rogue). Given a comp, searches for the
highest-rated recent win by that *exact* comp and stores it — but only if it beats whatever
this project already has on file for that comp (`data/arena-logs/comp-index.json` tracks one
best-known match per comp signature, keyed by sorted spec IDs joined `_`). Re-running for a
comp that's already the current best is a genuine no-op — confirmed live.

**`lhsShouldBeWinner` + `units{info{teamId}}` verified empirically before trusting it**: found
a real match where the queried comp's units all carried `info.teamId` equal to
`winningTeamId`, and the opposing team's specs didn't match the query at all.
`ArenaLogService::searchCompWins()` re-verifies this on every result rather than trusting the
API filter blindly (recomputes the winning team's actual spec set from `units`/`teamId` and
discards anything that doesn't exactly match the query).

**Correction, 2026-08-14 — the RMP "empty result" below was wrong, and was actually the
sort-order bug documented above, not real data sparsity.** Originally: searching
`256,64,261` (Disc Priest/Frost Mage/Sub Rogue, "RMP") returned zero results even at
`--count=200`, while `105,258,259` (Holy Priest/Resto Druid/Assassination Rogue) returned a
real 2370-rated win on the first 50-match scan — and this was written up as expected
behavior ("a real comp simply not having been played recently is indistinguishable from a
comp that doesn't exist"). The user found a real, 2327-rated RMP win by browsing the site
directly and asked why our search missed it. Root cause: `105/258/259` are all 3-digit spec
IDs, so numeric and string sort happen to agree — RMP mixes a 2-digit spec (64) with two
3-digit ones, so they don't. After fixing `sortSpecIdsForQuery()` (see above),
`wow:pull-comp-log 256,64,261` immediately found not just that match but a *higher*-rated
one (2465) on the very first page. **`latestMatches` genuinely does still only search a
recent window with no full-history/rating-sort capability — that part was correct** — but
"returns []" must never be trusted as "this comp hasn't been played recently" without first
confirming the query string is right, since a bad sort order produces the identical symptom.

**Deliberately scoped narrow for this first pass**: one exact comp, best win regardless of
opponent, command-only — not wired into the live `WowComps` Livewire page (that would mean a
real user interaction triggering a third-party HTTP search, worth its own explicit decision
rather than bundling into this pass). "Find one example of this comp against every distinct
opposing comp" was named as a planned next step, not built here.

## Per-spec spell discovery: `wow:discover-spec-spells`

Built 2026-08-14, same day as the comp-puller and its sort-order bug fix above. One command,
one spec: `php artisan wow:discover-spec-spells {classSlug} {specSlug}` finds that spec's
highest-rated recent match (any comp, win or loss — `ArenaLogService::pullHighestRatedMatchForSpec()`,
tracked separately in `data/arena-logs/spec-index.json`, same "only replace on genuine
improvement" rule as the comp-puller's `comp-index.json`), fetches it, extracts that spec's
real cast-spell list into `data/arena-logs/spell-usage/{classSlug}/{specSlug}.txt`
(`ArenaLogService::mergeSpellUsage()` — cumulative across every match ever run through it),
then runs the exact same diff `wow:diff-arena-spells` does.

**What this is explicitly NOT** (stated directly by the user when this was requested, worth
repeating here since it's easy to misread the output): a single match — or even several — is
not a completeness check. A real Unholy DK simply not casting Strangulate in one match is not
evidence that spell shouldn't be tagged for Unholy. The only thing this surfaces is the other
direction: a spell that WAS positively cast but isn't correctly tagged is strong evidence;
absence is never evidence of anything. `wow:diff-arena-spells` already has no "not seen"
direction for exactly this reason (see its own docblock) — this command doesn't change that.

**First real run, Unholy Death Knight**, found a real 2684-rated match and surfaced 12 spells
never tagged to Unholy at all (Death Coil, Death Grip, Death and Decay, Chains of Ice,
Anti-Magic Zone, Mind Freeze, among others) plus one CONTRADICTION (Icebound Fortitude tagged
only to Frost) — same under-scoped-to-one-spec pattern already found via the Rogue/Warlock
manual pass. None of this was applied to `baseline-spec-overrides.txt` automatically — every
PROMOTION_CANDIDATE/CONTRADICTION this pipeline finds still goes through the same manual
verify-then-add step as every other line in that file.

**Deliberately one spec per invocation** — same "don't bulk-run against a live third-party
site unattended" precedent already established for `wow:import-murlok-defaults`. Looping this
across all ~40 specs automatically is a plausible future step, not built here.

## Tank specs are genuinely scarce in this data source — do not keep re-pulling for them

Confirmed 2026-08-15, while running `wow:pull-scarce-specs` (see `app/Console/Commands/PullScarceSpecs.php`) to top up the specs with the fewest recorded kill-sequence examples. Three specs stayed stuck at 0 examples even after a deep search (`--pages=8`, 400 recent 3v3 matches scanned via `latestMatches`):

- **Monk / Brewmaster** — 0 matches found at all, at any search depth tried.
- **Death Knight / Blood** — only 2 matches exist in the pool, both already on disk, both losses for that spec (so 0 kill-sequence rows — `findPreKillWindow()` only ever records the *winning* team's sequence).
- **Demon Hunter / Vengeance** — only 3 matches exist in the pool, same shape as Blood DK.

This is a real, structural fact about the population `latestMatches` draws from (rated 3v3), not a search-depth or query-string problem: tank specs essentially don't queue rated 3v3 arena in any meaningful volume, so there's very little for this pipeline to find regardless of how deep it searches. **Decision (direct user instruction): stop deliberately targeting these — "no use forcing something, they will surface naturally if people start playing them."** `wow:pull-scarce-specs` will still incidentally pick up a tank spec's match if one appears as a free byproduct of pulling for some other spec in the same match (every match's full 6-player roster gets extracted regardless of which spec was originally targeted), but don't spend additional search budget hunting for these three specifically.

## Open questions / not yet tried

- `myMatches`/`userMatches`/`characterMatches`/`recentMatchesWithCombatant`/
  `matchesWithOwnerId` — untested, look useful for pulling a specific player's history.
- Whether `latestMatches` has any practical rate limit — `queryLimitReached` exists in the
  schema but was never observed `true` in testing.
- Whether Solo Shuffle's `ShuffleRoundStub` rounds share one `logObjectUrl` per session or
  one per round — not tested, only `ArenaMatchDataStub` matches pulled so far.
