# Playstyle Analysis (`PlayerMatchAnalysisService`)

Turns a real archived arena match into a structured read of **how one player used their
build** — every talent tied to what it actually did in that match, with a verdict. Sits on
top of the arena-log archive and the talent/spell data model; see `arena-log-api.md` and
`spell-acquisition-model.md` for those layers.

Two entry points, one service:

| Command | What it does |
|---|---|
| `wow:analyze-player {matchId} {player} [--json]` | One player, one match — the full breakdown, printed or as JSON. |
| `wow:analyze-spec-playstyle {classSlug} {specSlug} [--top=10] [--min-duration=45] [--one-per-player]` | Runs the above over the N highest-rated archived matches that contain the spec, writes `data/arena-logs/playstyle/{classSlug}/{specSlug}.json` (per-match analyses + a per-talent roll-up). |

The `.json` file is the promoted, view-readable output — same home and convention as
`data/arena-logs/rotations/` (small, in-repo, not the bulky raw archive). It is what the
Class Guide page reads.

## The pipeline (`analyze()`)

1. **`resolvePlayer()`** — metadata `units[]` lookup by name (with/without realm) or GUID → `{guid, name, reaction, specExternalId}`. `rosterFor()` splits allies/enemies by `reaction`.
2. **Build** — `ArenaLogService::extractCombatantInfo()` + `resolveCombatantTalents()` (these already solve the `COMBATANT_INFO` entryId ↔ our `external_talent_id` mismatch and CHOICE-node disambiguation — see their own docblocks / CLAUDE.md's "Real per-match talent builds embedded in Burst Windows" section). Output: `talents[]` (name, spellId, rank, treeType, nodeId, entryId) + `pvpTalents[]`.
3. **`parseLog()`** — one gzdecode, one pass over every `SPELL_*` line where the player is source and/or dest:
   - `casts` — `SPELL_CAST_SUCCESS` grouped by spellId (count, first/last time). **Raw** — periodic ticks and Ascendance-window proc-riders (Doom Winds' pulse `469270`, Windstrike) are *not* filtered, so counts overstate. The archive's `offensive-rotations.php` has the real exclusion list; porting it is a follow-up.
   - `castFailed` — `SPELL_CAST_FAILED` with failure reasons.
   - `interrupts` — `SPELL_INTERRUPT`, records the interrupted spell.
   - `selfBuffs` — `SPELL_AURA_*` where source == dest == player: apply count, total uptime (paired APPLIED/REMOVED, stack-based), max stack (from `_DOSE` lines).
   - `seenNames` / `seenIds` — every spell name **and** spell_id the player was ever the *source* of (cast / damage / energize / aura). This is the "did this ability happen at all" set that modifier/proc linkage checks against — auto-attack procs like Windfury Attack never `CAST_SUCCESS` but fire constantly.
   - `localeAsciiRatio` — fraction of cast names that are pure ASCII. < 0.6 ⇒ a locale-translated (usually zh-CN) log.
4. **`resolveEnglish()`** — the combat log writes spell names in the *recording client's* locale. Bulk-resolves every observed spellId → English `spells.name`, adds `enName` to casts/selfBuffs, and folds every resolved English name into `seenNames`. Ids not in our data (internal / tick ids) keep the raw log name as the only fallback. **Without this, a zh-CN-logged match matches nothing and flags ~44 talents.**
5. **`linkTalents()`** — the analytical core (below).
6. **`buffWeb()`** — top self-buffs by uptime: `{buff (English), uptimePct, applies, maxStack, feedingTalents}` — feeders = selected talents whose description text names the buff.

## Talent ↔ usage linkage

Matching is **name-first against the English name**, with a **spell_id fallback** for the ids that lined up (`wasSeen(name, id)` checks both `seenNames` and `seenIds`). Per talent, first matching rule wins:

| Rule | Condition | Verdict examples |
|---|---|---|
| **active-ability** | not passive, and (cast seen \| own name/id seen \| has a cooldown/charges) | `used (9x)` · `used` · `UNUSED — active ability, never pressed` |
| **weapon imbue** | description contains "imbue your" | `active — weapon imbue, proc seen` (checks for a `<stem> Attack` in `seenNames`) · `UNUSED — weapon imbue, no proc seen` |
| **buff** | its own name matches a self-buff with uptime ≥ 3s or ≥ 2 applies | `active — 8x apply, max 10 stk` (Maelstrom Weapon, Hot Hand, Flurry) |
| **passive (broad modifier)** | > 6 `spell_relationships` targets | `passive — broad modifier (12/34 affected spells seen)` — enumerating 30 internal spell names is noise; it behaves like a passive |
| **modifier** | 1–6 `modifies` / `modifies_cooldown` / `modifies_charges` / `replaces` targets | `active — modifies Stormstrike, Windstrike` · `DEAD MODIFIER — modified spell(s) never seen` (self-referential rows filtered; each target flagged `seen` via `wasSeen`) |
| **proc** | description has `$@spellname<id>` / `$<id>` refs to other spells | `procs — referenced effect seen` · `NO PROC SEEN — referenced effect never fired` |
| **castable, unused** | not passive, has a `cast_type`, no other signal | `UNUSED — castable, never used` (Chain Heal in a 46s game) |
| **passive / unknown** | everything else | `passive (always on)` · `no measurable in-match signal` (a PvP talent not used that match) |

**Flagged** = verdict starts with `UNUSED` / `DEAD` / `NO PROC` — talent taken, no measurable benefit that match. This is the "wasted pick / very situational" signal the whole thing exists to surface (the original prompt: *"some people play a talent like Totemic Projection but don't use it, essentially wasting the talent"*).

## Output shape — `data/arena-logs/playstyle/{class}/{spec}.json`

```
{
  generatedAt, class, spec, externalSpecId, sampleSize,
  ratingRange: [max, min],
  talentSummary: [                       // one row per talent seen across the sample
    { talent, took, used, flagged, passive, verdicts: {"<verdict>": n} }
  ],                                     // sorted by [took, used] desc
  matches: [                            // each = a full analyze() result + rating/mirror
    { match:{id,durationSec,result,player,spec,roster}, build:{talents,pvpTalents,...},
      usage:{casts,castFailed,interrupts,selfBuffs}, talentAnalysis:[...], buffWeb:[...],
      localeAsciiRatio, rating, mirror }
  ]
}
```

`talentSummary` is the roll-up the Class Guide page leads with: `took` = how many of the N
sampled players selected it, `used` = how many got a non-flagged, non-passive verdict.
`took N/N` + high `used` = a real core pick; `took N/N` + high `flagged` = a common mispick
or a talent that only matters in longer games / specific matchups.

## Known limitations (as of 2026‑08‑28)

- **Cast counts are raw.** Doom Winds shows `9x`, Windstrike inflated during Ascendance — periodic/proc-ride events log as `CAST_SUCCESS`. Fine for "was it used" (≥1), misleading for frequency.
- **Broad-modifier collapse is a heuristic** (`> 6 targets ⇒ passive`). A genuinely active talent that happens to touch many spells would be under-described.
- **Some CHOICE nodes look permanently dead** across the whole sample (e.g. `Storm's Wrath` 9/9 flagged for Enh) — likely `resolveCombatantTalents()` picking the wrong option for that node's offset band, not 9 players all mispicking. Cross-check against known meta before trusting an "everyone flagged this" row.
- **Proc linkage only follows `$@spellname` / `$<id>` refs** — a talent that grants a buff named only in prose ("gain X") with no spell_id token won't link (Shamanism → "Bloodlust" via "Heroism" text).
- **`SPELL_SUMMON` isn't parsed** — Feral Spirit / totems that summon off another cast read as unlinked.
- **Match "rating" is `metadata.playerTeamRating`** (recording team's CR), a proxy — in rated 3v3 the teams are MMR-matched so it tracks the analysed player's rating closely enough for ranking a sample.

## Relation to the rest

- **`BurstWindowTalents`** (`/top-damage-rotations/{class}/{spec}/{length}/talents`) is the spec-level counterpart — the canonical burst window's own talent build, rendered in the read-only talent-calculator grid. Playstyle analysis is the player-level counterpart — did a real player *use* those talents.
- The **Class Guide page** (Stage 4) combines them: the talent grid + this file's `talentSummary` + `buffWeb` + `rotationForSpec()`.
- `wow:pull-latest-matches` (generic archive puller) feeds the match pool `wow:analyze-spec-playstyle` samples from.
