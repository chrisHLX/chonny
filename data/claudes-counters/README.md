# Claude's Counters

A second, isolated, experimental folder — same precedent as `data/claudes-guides/` (see that
folder's own README first, since this one builds directly on it). Everything under
`data/claudes-counters/` and the one page that reads it (`/claudes-counters`,
`App\Livewire\ClaudesCounters`) is self-contained by design: nothing outside this folder writes to
it, nothing outside `ClaudesCounters.php` reads it.

## v2 (2026-09-02) — redesigned into a live, input-driven kit comparison

The original design below (browse 3 precomputed matchups) was replaced, not kept alongside a new
mode — two different mental models on one page would be confusing, and the live version is
strictly more general anyway. What v2 actually is:

1. **Pick any two real class/spec pairs** via two dropdowns — not limited to the 3 precomputed
   matchups anymore. Each side shows its FULL real kit (every currently-selected talent/PvP-talent/
   baseline entry from that spec's admin-default build, via `SpecKitComputer::compute()` — the same
   engine WowComps/SpellExplorer use), grouped by category, side by side. This directly answers what
   was asked for: "a closer look at each class's respective abilities."
2. **Every one of Side A's main spells is auto-paired with its closest Side B equivalent — no
   click required** (added same day, after the click-driven version's first real use: clicking
   Trueshot for Marksmanship Hunter correctly surfaced Shadow Blades on a Subtlety Rogue side,
   confirming the matching rule works, but "click one spell at a time" doesn't scale to "show me
   the whole kit's pairings at a glance"). `getAutoPairingsProperty()` runs the exact same
   `findClosestEquivalent()` rule across every non-passive entry in Side A's kit, grouped by
   category — same broad category first (required), then same `dr_category` for CC /
   `is_interrupt` / `is_peel` as a second-tier preference, then closest real cooldown as the final
   tiebreak. Deliberately never a hand-typed "Shadowstep = Gate" table. A category with genuinely
   nothing comparable on the other side shows "no comparable ability," not a cross-category guess.
   Clicking a spell in the full-kit browse grids further down still works too (pairs a specific
   spell — including a passive, or one on Side B instead — outside the automatic list).
3. **The pressure bar still exists**, same engine (`DuelSimulatorService`, unchanged), but now runs
   LIVE against whichever two full kits are currently selected instead of only being readable from
   a precomputed file. Same honest "abstract meter, not real damage" contract as before — see below.

**`DuelSimulatorService`/`wow:simulate-duel` themselves are untouched** — the redesign only changed
how `ClaudesCounters.php` calls them (live, from a full kit, instead of only reading a precomputed
JSON built from a guide's curated subset). The 3 original `.json` matchup files in this folder are
no longer read by the page but were left in place, not deleted — deleting real committed output is
a separate, more destructive decision than redirecting what reads it.

**`range` (melee/ranged) is now a manually-toggleable per-side button**, defaulting from a small
hardcoded `RANGE_DEFAULTS` map (most classes are consistently one range; a few hybrids —
Druid/Shaman/Paladin/Monk — vary by spec) rather than the old per-command-invocation hardcoded
argument. Still not derived from any real schema column, same reasoning as v1: WoW doesn't have a
single "range" attribute on a spec, and modeling melee/ranged/hybrid properly is real scope this
toy comparison doesn't need.

## v2 follow-up (same day) — real card presentation, then two real bugs it surfaced

Direct follow-up: reuse the exact same spell-card presentation as WowComps' Offensive/Defensive/
Mobility tabs (icon/name/badge row/CD stat block, click-to-expand description modal) instead of
this page's original smaller row style — done verbatim, same markup, same helpers
(`$categoryBadge`/`$fmtSeconds`/`$cooldownDisplay`/`$splitUnit`).

Switching to the fuller cards surfaced a real 4.3s render (598KB HTML) — traced (not guessed) to a
**stale precomputed spell-kit cache**: this session's code changes had shifted `SpecKitComputer`'s
code fingerprint, so `tryReadPrecomputed()` was silently rejecting every file and falling back to
the slow live-`compute()` path for every kit resolution. Fixed by re-running the existing
`wow:precompute-spell-kits` command for all 40 specs (the correct, already-established fix for
this — not a code change) — render time dropped to ~200ms.

**Two more real issues were then reported directly, verified against the live DB (not assumed) —
neither was a cache problem:**

1. **No-cooldown fillers were showing up as "main spells."** The auto-pairing/browse lists
   originally only excluded passives — real, non-passive, correctly-categorized core-rotation
   abilities with no cooldown at all (Death Strike, Festering Scythe, Necrotic Coil — confirmed via
   direct DB lookup) were still shown, producing low-value pairings like "Death Strike (no CD) ->
   Bursting Growth (no CD)." Fixed by `ClaudesCounters::isMainSpell()`, which reuses WowComps' OWN
   real filters per category instead of a bare `!is_passive` check: Offensive/Defensive require the
   same `isPriority` + real arena-log `offensiveDefensive` classification + `MIN_COOLDOWN_TAB_SECONDS`
   (25s) floor (with WowComps' own two named floor-exception lists) that tab already uses; Crowd
   Control requires a confirmed `dr_category`; Mobility requires the hand-curated `is_mobility` flag
   WowComps' own Mobility tab is actually gated on (a related but not identical signal to
   `categorize()`'s 'Mobility' category output). Cut the browse grids from ~105 entries/side to
   ~20-25 as a side effect, matching WowComps' own current tab scope, not just an incidental perf win.
2. **The same Side B spell was being reused as the "closest match" for several different Side A
   spells** (three separate DK cooldowns all landing on the same one Feral Druid ability) — looked
   exactly like a stale-cache bug but was `findClosestEquivalent()` being run independently per row
   with no memory of what it had already assigned. Fixed by tracking claimed Side B spell_ids across
   the whole `getAutoPairingsProperty()` loop and excluding them from later rows' candidate pool — a
   Side A spell with nothing left to pair against now honestly shows "no comparable ability" rather
   than reusing one. Applied to the manual click-to-pin flow too (`getComparisonProperty()`'s target
   pool is now filtered through the same `isMainSpell()` check for consistency, though a single pin
   has no "reuse" concept of its own).

## v1 — what this originally was, kept for history

A user asked for a simulated 1v1 "counters" page: given two classes I've already written a
Claude's Guide for, play out a GCD-by-GCD narrative of the exchange — crowd control landing and
diminishing, mobility opening/closing distance, defensives answering pressure, offensive cooldowns
cashing in while the opponent is locked down — as a readable timeline, not a literal combat
simulation with real damage math.

**This is explicitly a toy model, and says so on the page itself.** Three real limitations, stated
plainly rather than hidden:

1. **No real damage or health math.** "Pressure" (0-100, per side) is a fabricated, abstract
   meter — it exists only to decide when a defensive should trigger and to give an honest
   "who's ahead" read at the end. It is NOT derived from any real spell coefficient, and should
   never be read as a damage number. Real burst-window damage exists elsewhere on this site (Burst
   Windows / Class Guide) and is deliberately NOT reused here as a "damage model" — mixing one real
   number with a pile of fabricated ones would be worse than having no number at all.
2. **A uniform 1.5s "tick" stands in for every ability's real cast time/GCD.** Real GCDs vary with
   haste, some abilities have real cast times longer than a GCD, some are off-GCD entirely. This
   sim treats every single action as exactly one tick, which is the right level of fidelity for
   "gcd for gcd, roughly how it plays out," not for anything more precise.
3. **The decision logic is a simple, hand-written priority list** (see `DuelSimulatorService`'s own
   docblock for the exact rules), not a trained or searched strategy. It was tuned by generating a
   real matchup, reading the actual output, and fixing what looked wrong — twice, on the very first
   matchup generated (see the service's own inline comments for both bugs found this way) — the
   same "generate, read, refine" loop the burst-window/playstyle pipeline already uses.

**What IS real:** every ability referenced (cooldowns, `dr_category`, curated `pvp_duration_seconds`,
which section of that spec's own Claude's Guide it came from) is resolved live from the same
database and precomputed files every other page on this site uses, via
`SpecKitComputer::resolveEntriesForSpellIds()` — the exact same helper `ClaudesGuides.php` itself
uses to resolve a guide's spellGrid sections, extracted specifically so neither feature
re-implements "look up a handful of real spells for a spec" independently. Diminishing returns math
reuses `CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS` and the same confirmed 100%/50%/immune 2-step
falloff that tab already uses — a rolling ~20s reset window is layered on top here, since a single
duel runs long enough for DR to genuinely reset mid-fight (a static list, which is all
`CcChainBuilder` itself ever had to handle, has no time axis to reset against).

## How a matchup is generated

`php artisan wow:simulate-duel {classA} {specA} {rangeA} {classB} {specB} {rangeB} [--ticks=30]`

1. Requires a Claude's Guide to already exist for BOTH specs (`data/claudes-guides/{class}/
   {spec}.json`) — the user's own instruction was "pick 2 classes that you have created guides
   for." The command fails loudly, not silently, if either guide is missing.
2. Reads each guide's own `spellGrid` sections and buckets their spellIds into a role (offensive /
   defensive / cc / mobility) by matching the section's own heading — never re-derives a kit
   independently, and never relies on the site-wide `categorize()` heuristic's own known quirks
   (see the Havoc Demon Hunter guide's note on Eye Beam landing under Defensive) for this decision.
3. `range` (`melee`|`ranged`) is a small hand-authored hint passed on the command line, not derived
   from any schema column — WoW doesn't have a single "range" attribute on a spec, and adding one
   just for this experimental feature would be scope creep on the real schema for a toy model.
4. `DuelSimulatorService::simulate()` runs the deterministic turn-based sim and the result is
   written to `data/claudes-counters/{classA}-{specA}_vs_{classB}-{specB}.json` — precomputed once,
   same "generate a file, render from it" pattern as `wow:precompute-spell-kits`/burst-window
   rotations, never computed live on page load.

## Matchups so far

Three, picked deliberately for variety (a melee-vs-ranged control matchup, a melee-vs-melee
cooldown-trade matchup, and a ranged-vs-ranged CC-kit matchup) — all three specs pairs already had
Claude's Guides written for them, so no new guide work was needed to build this page.

- **Assassination Rogue vs Frost Mage** (`rogue-assassination_vs_mage-frost.json`) — the user's own
  example matchup. Frost Mage's five-category CC kit (2x Incapacitate, 1x Disorient, 2x Root) ends
  up locking the Rogue down for almost the entire simulated window after its one trinket is spent —
  a genuinely informative outcome, not a broken one: it's the same "make the control term so cheap
  and repeatable the other two barely need to be efficient" identity already written into that
  spec's own guide, just now visible mechanically rather than only asserted in prose. Worth being
  direct about the biggest honest limitation this exposes: a real 3v3 match has two teammates who
  can peel, break, or contest that lock — an isolated 1v1 format structurally favors any spec with
  an unusually deep, diverse CC kit, since nothing here can ever come to the other side's aid.
- **Arms Warrior vs Havoc Demon Hunter** (`warrior-arms_vs_demonhunter-havoc.json`) — a genuinely
  competitive, back-and-forth read (ends 36 vs 40 pressure). Both sides trade CC early, then settle
  into a real cooldown-and-defensive exchange — Havoc's real offensive cooldowns (Metamorphosis,
  Chaos Blades, The Hunt, Essence Break) each answered by a different, appropriately-"laddered"
  Warrior defensive (Spell Reflection, Berserker Shout, Die by the Sword) — a live demonstration of
  the "cheapest defensive first" principle documented in both classes' own guides.
- **Shadow Priest vs Balance Druid** (`priest-shadow_vs_druid-balance.json`) — Balance's own
  8-ability, 5-category CC kit (the largest in the whole Claude's Guides series) grinds Shadow down
  to 100 pressure by the end, similar in shape to the Rogue/Mage matchup and for the same
  underlying reason: CC-kit breadth is the single biggest lever in an isolated 1v1 with no
  teammates to share the load.

**A pattern worth naming, found across all three:** the deciding factor in every matchup generated
so far wasn't raw offensive cooldown damage — it was CC-kit *breadth* (how many independent,
different-category control options a spec has) relative to the other side's *trinket economy*
(exactly one break, once, for the whole encounter). This is a real, structural feature of both this
simulation's own rules AND of real WoW's DR/trinket system — not a modeling artifact specific to
this toy engine, though a genuine 3v3 with real teammates would blunt it considerably (see the
Rogue/Mage entry above).

## Known bugs found and fixed while building this (kept here, not just in the code, since they
## explain why the engine looks the way it does)

1. **CC-chain-forever, zero pressure ever landed.** The very first generated matchup had both
   sides re-locking each other indefinitely with neither ever pressing a real offensive cooldown —
   traced to checking "is a CC available" before "can I cash in an offensive on an already-locked
   opponent." Fixed by moving the cash-in check ahead of the general CC pick in `takeTurn()`.
2. **Reaching for an about-to-be-immune CC option over a still-fresh one.** A Frost Mage kept
   trying Polymorph even as it was heading toward full DR immunity, while Frost Nova/Dragon's
   Breath (untouched, still at 100%) sat unused — because CC selection only sorted by category
   priority, never by how diminished each option currently was. Fixed by sorting on DR freshness
   first, category priority second.
3. **Trinket threshold unreachable given how short real CC durations are.** An early version
   required a single CC application to itself last "3+ ticks" (4.5s+) before considering a trinket
   worthwhile — but this project's own curated `pvp_duration_seconds` values are mostly 2-6 ticks
   even at full duration (the confirmed flat ~6s PvP CC cap documented elsewhere in this project),
   so that bar was almost never cleared even during a genuine consecutive-CC chain. Fixed by
   dropping that requirement and relying on severity + (pressure taken OR consecutive-chain depth)
   instead — see `DuelSimulatorService::takeTurn()`'s own inline comment for the full reasoning.

Each `.json` file's own `beats[].pressure`/`beats[].cc_chain_depth` fields exist specifically so a
reader (human or AI) can audit exactly why the sim made each decision, the same "keep the output
inspectable" principle the whole burst-window/playstyle pipeline already follows.
