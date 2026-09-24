# Knowledge Gaps — Canonical Module vs. Raw Game Data

See also `arena-structure.md` — a companion file, different in kind: not a
findings log like this one, but a standing framework (the go/anti-go match
cycle + the rating-bracket skill ladder) for authoring and auditing
matchup-specific modules in the first place.

This file is a running ledger of concrete gaps found by cross-referencing an
**expert-dictated canonical module** (see CLAUDE.md's "Canonical Context Module
Template") against **imported raw game data** (see `game-data.md`) — the same
kind of comparison that already caught the Ultimate Penitence cooldown
discrepancy in the Discipline Priest (Oracle) module. That comparison was done
once, informally, and the result lived only in a seeder docblock and a CLAUDE.md
paragraph. This file exists so it stops being a one-off: every time this kind
of cross-check turns up something, it gets recorded here, in one place, in a
consistent shape.

**Why this matters beyond any one module:** CLAUDE.md's "AI-Assisted Game Data
Modeling" note already names the target architecture — raw data + expert
judgment + AI calibration, not raw data alone, and not expert dictation alone.
This file is the accumulating output of that calibration step. The long-term
goal (not built yet) is to use the *pattern* across these findings — which
kinds of mechanics experts reliably omit, which kinds of data ambiguities keep
recurring — to eventually let the platform proactively say "here's what's
probably missing from your understanding of X," instead of only ever
discovering gaps when someone happens to ask the right question in a
conversation. Every entry below is raw material for that, not just a fun fact.

## Before logging something here, dig first

A gap is only worth recording as structural once the easy explanations are
ruled out — not just "I checked our two local data folders and didn't find it."
The order that's worked, in practice:

1. Check whether the value is derivable from data *we already imported* —
   re-read the relevant `.txt`/`.json` file directly, don't trust a summary
   of it from memory or from a previous session.
2. Check whether our own fetch/parse script is silently discarding a field
   that's actually present in its source, rather than the source lacking it —
   e.g. a narrow field whitelist in a fetch script, or a parser regex that
   doesn't capture something it could. Read the actual code, don't assume.
3. If the answer still isn't there, query the *live* upstream source
   directly (e.g. Blizzard's Game Data API), bypassing our own cached/
   imported files entirely, to see the true raw shape — not a summary of what
   we happened to store from it last time.
4. Only once all three of those come back empty does something belong in
   this file as a genuine, confirmed structural gap.

**Why this matters:** the "PvP talents have no cooldown data" entry below
was first written after only step 1 — inspecting our own already-imported
JSON — and concluded "permanent gap, nothing to be done." It took a direct
challenge ("how would Wowhead have this, must be an older version?") to
actually do steps 2 and 3, which is what turned up that `fetch-talent-trees.php`
hand-picks a narrow field subset (step 2) and that the live API genuinely
has nothing more even when queried directly right now (step 3) — a
categorically stronger, actually-trustworthy conclusion than the original
one, even though it happened to land in the same place. The entry below is
now a good model of how much digging to do before writing CONFIRMED — an entry
that jumps straight to "flagged, can't fix" after only step 1 is probably
incomplete.

## How an entry gets in here

1. A canonical module (dictated prose) is cross-referenced against
   `data/spelldata/filtered/{class}/...` (and `data/talenttrees/`,
   `data/pvptalents/` where relevant).
2. A gap is either an **omission** (the data shows a mechanic that materially
   affects what the module teaches, and the module never mentions it) or a
   **discrepancy** (the module states a fact the data contradicts).
3. Nothing here gets silently folded back into the module's prose. A module's
   dictated content only changes when the *original expert* confirms the
   correction — same rule the Ultimate Penitence finding already established.
   This file is the holding area for "found, not yet confirmed."
4. Every entry gets a status:
   - **CONFIRMED** — the data is unambiguous and directly contradicts/extends
     the module; no expert input needed to trust the data side of it.
   - **FLAGGED** — the data suggests something real, but needs the original
     expert (or further testing) to confirm before treating it as settled.
   - **AMBIGUOUS** — the data itself is internally inconsistent or doesn't
     fully resolve the question; recorded so the same dead end isn't
     re-investigated from scratch later.

---

## Cross-cutting data-source gaps (not tied to one module)

Findings below aren't from a canonical-module-vs-data cross-check — they surface
from general platform usage (Spell Explorer, module Spells sections) rather
than an expert-dictation review, but they're the same kind of thing: a gap
between what the platform shows and what's actually true in-game, worth
tracking in one place rather than losing to a chat transcript.

### PvP talents have no cooldown data at all, for almost the entire set
- **Status:** CONFIRMED — a genuine, permanent gap in both underlying data
  sources, not a parser bug and not stale/beta data.
- **Symptom:** Psyfiend (Shadow Priest PvP talent, spell_id 211522) renders on
  the Spell Explorer with cooldown "—" and gets sorted into "Buffs & Passives"
  instead of "Active Abilities" (the cooldown-presence check is what drives
  that grouping) — despite being a clearly active, on-a-cooldown summon
  ability in-game.
- **Data shows:** neither of the two sources this importer reads from carries
  a cooldown value for PvP-talent-sourced spells in general. `data/pvptalents/{class}.json`
  (Blizzard's Game Data API PvP-talent endpoint) only ever includes `id`,
  `name`, `spell_id`, `spell_name`, `description`, `unlock_player_level`,
  `compatible_slots`, `playable_spec_id/name` — no cooldown field exists in
  that shape at all. The SimC spelldata dump (`data/spelldata/`) simply
  doesn't contain most PvP talent spell_ids as their own record — confirmed by
  grepping for spell_id 211522 (Psyfiend) directly across every raw and
  filtered priest file: zero matches.
- **Ruled out two more-hopeful explanations by checking directly, not
  assuming (2026-08-05):** the player asked whether Wowhead's 45s (see below)
  might just mean our imported patch is stale/beta. Queried Blizzard's live
  Game Data API directly — `GET /data/wow/pvp-talent/763` and
  `GET /data/wow/spell/211522` — bypassing both our cached JSON files and
  `fetch-talent-trees.php`'s own field selection, to see the *true* raw
  response. Confirmed current (`namespace=static-12.0.7_67808-us`, i.e. live,
  not cached/beta). Neither raw response contains a cooldown field anywhere —
  only `id`, `name`, `description`, `unlock_player_level`, `compatible_slots`,
  and spell/spec cross-refs. So this also isn't "our fetch script discards a
  field that's really there" — the field genuinely isn't in what Blizzard's
  public Game Data API serves, for this spell, right now. Wowhead's number
  (and the in-game tooltip, which the client renders from its own bundled
  data files) must come from a source deeper than this public API — most
  likely the game client's own DBC/DB2 files, which aren't exposed through
  `/data/wow/*` at all.
- **Quantified dataset-wide (2026-08-05):** of 249 distinct spell_ids sourced
  from `pvp_talents`, only **8** have any `cooldown_seconds` value at all
  (presumably because those 8 happen to *also* exist as a baseline/talent
  spell elsewhere in the SimC dump); **241** are permanently null under the
  current two data sources.
- **Why it matters:** this isn't fixable by improving `SpellDataFileParser` or
  `ImportSpellData` — there's nothing in either source file for a better
  parser to extract. Closing it would require a third data source (e.g. a
  players' addon export, the same mechanism `spellbook_snapshots` already
  uses for the in-game Spellbook verifier) or manually curating cooldowns for
  the ~241 affected spells. Not attempted here — flagged so it's a known,
  quantified gap rather than something that looks like a bug every time it's
  noticed.
- **Real value confirmed for Psyfiend specifically (2026-08-05):** the
  player provided a Wowhead spell-detail screenshot and a real in-game
  tooltip screenshot (talent-tree hover) — both agree: **45 second
  cooldown**. This is genuine ground truth, not a guess, but it doesn't come
  from either of our two import sources — Wowhead mines the game client's own
  data files directly (far deeper than Blizzard's public Game Data API), and
  the in-game tooltip is the client rendering that same underlying data. The
  Game Data API's `/data/wow/playable-specialization/{id}/pvp-talent-slots`
  endpoint this project's importer reads from genuinely does not expose
  cooldown at all — confirmed by inspecting the raw JSON shape, not assumed.
  This is a real limitation of the public API we import from, not something
  fixable by writing a better parser against the same source. A general fix
  (closing the gap for all 241 affected spells) would need either a new,
  richer data source or a persistent manual-override mechanism that survives
  a full data re-import — neither exists yet; see CLAUDE.md if one gets built.
- **Found:** 2026-08-05, investigating a user report that Psyfiend appeared
  hard to find on the Shadow Priest Spell Explorer page.

---

## Discipline Priest (Oracle) — `DiscPriestOracleModuleSeeder`

### Ultimate Penitence cooldown
- **Status:** FLAGGED — pending the original dictating player.
- **Module says:** 5 minute cooldown (module page 4, "Matchup/Situational Notes").
- **Data shows:** `data/spelldata/filtered/priest/discipline.txt` (Ultimate
  Penitence, id 421453) — `Cooldown: 240 seconds` (4 minutes).
- **First found:** already flagged prior to this file existing — see the
  module's own `questions()` docblock and CLAUDE.md's "Canonical Context
  Module Template" section. Recorded here for completeness, not a new finding.

### Weal and Woe (Discipline talent, id 390786/390787)
- **Status:** FLAGGED — the *mechanic* is CONFIRMED in the data; whether this
  specific dictated build actually took the talent point is unconfirmed.
- **Module says:** nothing — not mentioned in prose or in `mentionedSpellNames()`.
- **Data shows:** single-point `PASSIVE` talent node (not a `CHOICE` node —
  see `data/talenttrees/priest.json`, node id 82573), row 10 col 3, 20-point
  requirement. 101% proc chance per Penance bolt landed, stacking a buff
  (id 390787) up to **10 stacks**, each stack worth **+3% absorb** on your
  *next* Power Word: Shield (and Void Shield), 20 second duration. A fully
  stacked shield absorbs up to **30% more** than an unstacked one.
- **Why it matters:** the module's own flagship burst combo (Evangelism →
  Radiance → Mind Blast → Penance) fires several Penance bolts in one window —
  each is a near-guaranteed stack. The module never tells the reader to follow
  that combo with a shield to actually capture the value it just built.
- **Important correction to the original framing of this finding:** this is
  *not* a total blind spot on the module's page. CLAUDE.md's "Spells" section
  note (2026-07-25) already names "Weal and Woe on Power Word: Shield" as a
  confirmed case the `spell_relationships` graph walk catches — so Power Word:
  Shield's live Spells-section entry on this module's Show page likely already
  lists Weal and Woe as a modifier. The real gap is narrower than "invisible
  to the platform": it's a gap in the *taught strategy* (the prose never tells
  the reader to sequence a shield after a loaded Penance), not in the data
  surfaced on the page. Worth remembering when scanning for more of these —
  check the live Spells section before assuming something found in raw data
  is fully absent from what a player actually sees.
- **Found:** 2026-07-28, cross-referencing `data/spelldata/filtered/priest/discipline.txt:578`
  against the module's "Core Cooldowns" / "Priority Cooldowns" pages.

### Inner Focus (Discipline talent, id 390693)
- **Status:** FLAGGED (mechanic omission) + AMBIGUOUS (internal data
  inconsistency, see below).
- **Module says:** nothing — not mentioned anywhere.
- **Data shows:** single-point `PASSIVE`, row 8 col 3, 20-point requirement.
  +20% critical-heal chance. Tooltip text explicitly names *"Flash Heal,
  Power Word: Shield, Penance, and Power Word: Radiance"* as benefiting — but
  the same entry's structured `Affected Spells` list (the field the game
  engine actually reads) only contains Flash Heal, Penance, Shadow Mend,
  Power Word: Radiance, Dark Reprimand, Ultimate Penitence, and Benediction.
  **Power Word: Shield's spell IDs (17 / 1246768) are absent from the
  mechanical list despite being named in the tooltip.**
- **Why it matters:** can't be resolved from this data alone whether shields
  actually get the crit-heal bonus or the tooltip text is stale. Recorded so
  a future pass doesn't have to rediscover this same ambiguity — resolving it
  requires either a combat-log test in-game or a newer data source.
- **Found:** 2026-07-28, `data/spelldata/filtered/priest/discipline.txt:562-576`.

### Borrowed Time (id 390692)
- **Status:** CONFIRMED (mechanic) — omission from module is the finding.
- **Module says:** nothing — Power Word: Shield is only discussed via
  Protector of the Frail's Pain Suppression CDR.
- **Data shows:** casting Power Word: Shield grants a temporary Haste buff.
  Already known to the project generally (see CLAUDE.md's game-data.md
  pointer, which names this as an already-solved "description-text scan"
  case for `ModuleSpellReferenceService`) — but still absent from this
  module's own prose.
- **Why it matters:** a second, independent reason "shield uptime" is worth
  more than the module currently teaches, beyond the Pain Suppression CDR
  angle it already covers.
- **Found:** 2026-07-28, `data/spelldata/filtered/priest/discipline.txt:555-560`.

### Evangelism's instant Radiance cast is at 150% effectiveness, not 100%
- **Status:** CONFIRMED.
- **Module says:** Evangelism "instantly casts a free Power Word: Radiance."
- **Data shows:** Evangelism (id 472433) tooltip: *"Immediately Power Word:
  Radiance your target at $s2% effectiveness"* — effect #2's value is **150**.
  The instant cast is 50% stronger than a normal Radiance, not merely "free."
- **Why it matters:** phrasing risk, not a missing mechanic — "free" could
  read as "free at normal value" when the actual burst is materially bigger.
- **Found:** 2026-07-28, `data/spelldata/filtered/priest/discipline.txt:655-689`.

### Inner Light / Inner Shadow (PvP talent, id 355897) vs. Focused Power (id 1249230)
- **Status:** Inner Shadow's dual effect is CONFIRMED. Whether Focused Power
  also boosts Atonement healing is AMBIGUOUS.
- **Player's own account (2026-07-28, not yet in module prose):** Inner Light
  = -10% healing spell mana cost; Inner Shadow = +10% spell damage AND +10%
  Atonement healing (a toggle, 6s cooldown to swap). Player always takes
  Inner Shadow, believing it's a flat damage+healing buff because Atonement
  heals via damage. Also runs Focused Power (+3% spell damage), unsure if it
  affects Atonement healing the same way.
- **Data confirms Inner Shadow is not just a damage buff with a healing side
  effect** — Atonement's own healing-conversion spell (id 81751 / 94472) lists
  `Inner Shadow (355898 effect#2)` directly in its `Affecting Spells` field.
  The engine has a dedicated hook wiring Inner Shadow into the Atonement
  formula specifically, separate from its generic damage modifier.
- **Focused Power (id 1249230)** — a *generic class* talent (not
  Discipline-specific), and a `CHOICE` node (`select_idx=200`, competes
  against a sibling pick), not a free single-point passive like Weal and Woe
  or Inner Focus. Its tooltip says only "Increases the damage of your spells
  by 3%," and it is **not** present in Atonement's `Affecting Spells` list the
  way Inner Shadow is.
- **Why this is AMBIGUOUS rather than resolved:** the absent hook is
  suggestive but not conclusive — it depends on whether Atonement's "heal for
  X% of damage dealt" reads dynamically off the final post-modifier damage
  number (in which case Focused Power's 3% flows through automatically, no
  explicit hook needed) or off a separately-tracked value (in which case only
  explicitly-hooked talents like Inner Shadow affect it). Data alone can't
  distinguish these two engine designs.
- **Found:** 2026-07-28, `data/spelldata/raw/priest.txt:8462` (Inner Shadow),
  `data/spelldata/raw/priest.txt:15752` (Focused Power), `data/pvptalents/priest.json`.

### Penance: direct-heal cast vs. damage cast → Atonement conversion
- **Status:** CONFIRMED (coefficients) + FLAGGED (exact Atonement conversion %
  is inferred, not directly labeled — see caveat).
- **Player's own question (2026-07-28):** no way to know whether Penancing the
  low teammate directly vs. Penancing the enemy (for Atonement splash) heals
  that same person the same amount.
- **Data shows real, different coefficients:**

  | Cast | Spell ID | Effect | SP Coefficient |
  |---|---|---|---|
  | Penance on an ally (direct heal) | 47750 | Direct Heal to that target | 3.4884 |
  | — same cast, splash | 197419 | Also heals every other Atonement-holder | 0.1872 |
  | Penance on an enemy (damage) | 47666 | Damage; converts to healing for every Atonement-holder via the core Atonement mechanic | 0.8109 → ~50% of that converts per holder (≈0.4 equivalent) |

  Source: `data/spelldata/filtered/priest/baseline.txt` — Penance entries at
  lines 814 (47666), 833 (47750), 2767 (197419). The ~50% Atonement conversion
  factor is inferred from a `Dummy`-type effect (`Base Value: 50`) on the core
  Atonement buff (id 194384) — consistent with the widely-known ~50%
  conversion, but not a directly labeled percent field, hence FLAGGED rather
  than fully CONFIRMED.
- **Conclusion:** direct ally-Penance heals the targeted player roughly
  8–9x more per bolt than routing the same cast through enemy-Penance's
  Atonement conversion — but ally-Penance also splashes a smaller heal
  (0.1872) to every other Atonement-holder, and enemy-Penance's weaker
  per-person healing (≈0.4) lands on the *whole team* at once, while also
  damaging the enemy. Neither is strictly better — they're the mechanical
  basis for "save one person now" vs. "spread sustain + pressure."
- **Found:** 2026-07-28, in direct response to the player's own question.

---

## Open questions across all findings above (pending player / further testing)

- Does Power Word: Shield actually benefit from Inner Focus's crit-heal bonus,
  given the tooltip/Affected-Spells mismatch?
- Does Focused Power's generic +3% spell damage flow through to Atonement
  healing, or only explicitly-hooked talents like Inner Shadow do?
- Was Weal and Woe actually taken in the dictated Oracle build, or was a
  competing point in that talent row chosen instead?
- Ultimate Penitence: 5 min (dictated) vs. 4 min (data) — still open.

## Pattern to watch for future modules

Every omission found in this module so far is a **single-point, low-decision
passive** sitting under an ability the module *does* teach in detail (Weal and
Woe under Penance/Shield, Inner Focus under crit healing, Borrowed Time under
Shield). None are things a player would naturally narrate, because none of
them are active decisions — they're just always on. When cross-referencing
future canonical modules against raw data, check specifically for
low-opportunity-cost passives attached to already-discussed abilities first —
that's where the yield has been highest so far, not in the big cooldowns
themselves (which experts reliably get right).

---

## 2026-09-18 — Guide-reader corrections vs. talent-tree data

Source: 24 reader comments on the machine-drafted guides, exported with
`guides:export-feedback` from production. Most were confirmed against
`talent_nodes` / `talent_node_entries` and folded straight into the drafts.
These three did not resolve cleanly and are recorded rather than guessed at.

### FLAGGED — "the Death Knight has to play either the silence or Asphyxiate, he can't have both"

Reader's words, on `havoc-ele-vs-tsg`. **The talent data disagrees**, and it is
worth resolving before any guide asserts it either way:

- `Strangulate` has **no talent-tree entry at all** — it is baseline for Unholy
  in the current import. Nothing gates it against anything.
- `Asphyxiate` sits on node 17, type `CHOICE`, in the Death Knight class tree,
  where its one alternative is **`Death's Reach`** — not the silence.

So as imported, an Unholy DK holds Strangulate unconditionally and chooses
Asphyxiate against Death's Reach. Three possibilities, none yet tested:
the reader is describing a pairing that exists in the live game and is missing
from the SimC dump; the reader is recalling a different pair; or Strangulate is
a `spec_id = NULL` baseline row that is really spec-gated (the known-ambiguous
case — see CLAUDE.md rule 1). **Do not curate a baseline override off this
until someone checks it in-game.** The guides currently say nothing about the
pairing.

### CONFIRMED — Mighty Bash and Incapacitating Roar are one choice

Reader: *"the insight was mighty bash, using it as a second stun on the warrior
is actually legit where incap roar is useless in the comp."* Confirmed: both
are entries on node 676, type `CHOICE`, Druid class tree. A Feral has one or
the other, never both, so the reader's preference is a real talent decision and
not a stylistic one.

### CONFIRMED — abilities that do not work on players at all

Both were hedged in published guides as "probably doesn't work, but if it does
it's free value". The reader settled both, and the hedging was the actual error:

- **Banish** — demons and elementals only.
- **Shackle Horror** — pets and non-player targets only.

Neither is castable on any player, so neither belongs in any arena plan. The
spell data models *what a spell does*, not *what unit types it may target*, so
this class of mistake is invisible to every check the pipeline currently has.
**This is the open gap worth closing next:** a target-validity field would have
caught both, and nothing else will.

### OPEN — cooldowns are stored as constants, but a real one shrinks as you play

Found 2026-09-23 while building the Matchup Lab (`CooldownGraphService`), which
puts both teams' cooldowns on one clock and therefore depends on every number
on that clock being right.

The pipeline holds two cooldown values per ability: the **base** one from the
SimC dump, and the **talent-modified** one that
`ModuleSpellReferenceService::effectiveCooldown()` resolves against a build.
Between them they cover every modifier a *build* applies — flat reductions,
percentages, charges.

Neither covers reduction driven by what the player **spends during the game**:
"every cast of X takes 3 seconds off Y", "each spender refunds a charge". That
is a function of a rotation executed at a rate, and no rate exists anywhere in
this project's data — the rotation artifacts under `data/arena-logs/rotations/`
record *which* abilities a spec presses in its best window, never how often a
cooldown actually came back.

**Why it matters and which way it is wrong.** The error has a sign: a stored
cooldown is always the longest the ability can be, so **every period derived
from it is an upper bound**. A comp's real go rate is faster than any timeline
built from this data says, by an amount that differs per spec — badly for a
spec with heavy spend-driven CDR, not at all for one without. Any page that
compares two comps' cadences is therefore comparing two differently-wrong
numbers, and the direction of the error is not uniform.

The Matchup Lab states this on the page rather than correcting for it, because
an invented correction factor would be worse than a visible hole.

**What would close it:** C12 in `arena-open-questions.md` — measure, per spec,
the distribution of real intervals between consecutive casts of the same
cooldown across the 689-match archive, against that ability's stated number. A
median well under the stated cooldown is real spend-driven CDR and the ratio is
the correction factor. This needs no new data and the archive is fixed, so the
measurement is repeatable.

---

## 2026-09-24 — Generated quiz questions inherit "who can this be cast on"

Concept drills (`App\Learning\ConceptCoverage`, `/wow/quiz/{class}/{spec}/drill/{concept}`) build
crowd control questions from `dr_category` and `pvp_duration_seconds`. That means they reproduce
the gap recorded on 2026-09-18: **the data does not model who a spell may legally target.**

Drilling Discipline Priest, the Crowd Control mix asks "Which diminishing returns group is
Shackle Horror in?" and answers Incapacitate. That is what the column says and it is right about
the DR group — but Shackle Horror only lands on pets and NPCs, so a player reading the question is
being told it is a piece of player control. Same shape as the published-guide mistake, arriving
through a generator instead of an author.

**Why it was not filtered out.** There is no field to filter on. Adding one would mean a curated
`valid_targets` column on `spells`, hand-populated the way `baseline-spec-overrides.txt` is —
worth doing, but a curation project rather than a quiz change, and guessing at it in the
generator would be exactly the kind of structural inference this project has tried and reverted.

**Scope.** Affects the `dr_category`, `shares_dr_with` and `pvp_duration` question types, and only
for the handful of abilities whose targets are restricted — Banish, Shackle Horror, Hibernate,
Scare Beast, Turn Evil. It does not affect `usable_while_cc`, `cooldown_length` or the
offensive/defensive types, which say nothing about a target.

**What would close it:** a curated target-restriction list, one verified line at a time, read by
both the quiz builder and `guides:author`'s feasibility check. Until then the same warning applies
to a drill as to a guide.

---

## 2026-09-24 — Description tokens: what now resolves, and what never will

Patch 12.1.0.69933. Four values the SimC dump has always carried were never stored, so the
tokens that ask for them rendered "(varies)" in finished prose. They are stored now —
`spells.max_stacks`, `spells.proc_chance`, `spell_effects.radius_yards`,
`spell_effects.chain_targets` — and `$u`/`$U`, `$h`, `$aN`/`$AN`, `$xN` resolve, in both the
own-spell and the `$<id>` cross-spell form.

Measured across the 40 precomputed kits, comparing the committed artifacts against a rebuild:
**"(varies)" 2,531 → 1,932**. Alongside it, four rendering bugs that were visible on the page:
broken pluralisation ("(varies):stacks;") 250 → 0, leaked `$@spelldesc` pointers 164 → 0, leaked
raw `$` tokens 199 → 10, unresolved conditionals 30 → 10.

**What is left, and why each one stays.**

- **`$tN` — a periodic effect's tick interval, 545 occurrences.** ~~Searched for and not found.~~
  **CORRECTED 2026-09-24, later the same day: the period IS in the dump**, on the effect's TYPE
  line rather than its detail line — `Periodic Trigger Spell (23): Penance every 1 seconds`,
  `Periodic Heal (8): every 3 seconds`. **893 occurrences.** The original search grouped the
  detail lines by `Key:` prefix, which structurally could not see a value embedded in the type
  string, and the wrong conclusion was written up as fact. Still unparsed, but now known to be
  parseable — the next piece of work here, not a dead end.
- **`$oN` — total damage over time, 478 occurrences.** Base value x tick count over the duration.
  With the period above it becomes arithmetic; on its own it also needs spell-power scaling,
  which Pass 2 can now carry (see below). Worth revisiting together with `$tN`.
- **`$abs`, `$AP`, `$mas`, `$MHP`, `$versadmg`, `$auracaster`** — every one of these is a
  property of a specific character at a specific moment. There is no character. "(varies)" is
  the correct answer, not a gap to close.
- **21 spells still carry a leftover token** in their rendered description: `$@spellaura<id>`
  (24 raw occurrences), `$@switch<1>[a][an increased]` (9), and two malformed strings
  (`$?(varies)&1.`, `$/100;s2`). `$@spellaura` is plausibly "insert that aura's description",
  the same shape as `$@spelldesc` — but plausibly is not verified, and a wrong splice reads as
  fact. Left alone until someone checks one against an in-game tooltip.

**A gendered guess that is now being made.** `$ghe:she;` picks its form from the reading
character's gender, and this site has no character. Three descriptions use it, none of them an
arena ability (pet auto-cast lines). The resolver takes the **first** form, so the page reads
"when he is unable to cast spells". That is the game's own word rather than an invention, and it
replaced a broken "(varies):she;" — but it is a guess about a person, and the neutral rewrite
that would avoid it ("when they is unable") is ungrammatical without rewriting the sentence.
Worth revisiting if the token ever appears on something a player actually presses.

**A trap in the fetch tooling, found the same day.** `fetch-simc-dumps.php --auto-detect-live`
picks the most recently updated `data-update-live-*` branch. On 2026-09-24 the only such branch
was `data-update-live-69283` — **older than both the data already imported (69814) and the
`midnight` branch (69933)**, so the flag would have silently rolled the spell data backwards by
two builds. Check the header line of a dump before trusting either source; `midnight` was right
here.


---

## 2026-09-24 (second pass) — Conditional Variables blocks, and what a coefficient may be read as

`parseVariableDefs()` used to discard a spell's whole Variables block on finding a single `$?`
anywhere in it. That is why Penance rendered "causing (varies) Holy damage" while Blizzard's own
formula for it sat in our data:

```
$penancedamage=${$47666s1*$<darkside>*$<balanceofthings>*(3+$<castigation>+$<harsh>)}
```

Conditionals are now handled per definition. Penance reads **"≈279.6–975.3% of Spell Power"** —
the floor being three bolts at 93.2% of Spell Power each, the ceiling seven bolts with Power of
the Dark Side and Twilight Equilibrium up.

**Why a range and not a number.** The conditionals in a Variables block ask two different
questions in identical syntax: `$?a193134` is "is Castigation talented", a build fact, and
`$?a198069` is "is Power of the Dark Side procced right now", a moment in a fight.
`buildKitSpellIdsFor()` answers neither — it answers "can this spec have it", and it includes
every baseline spell. All three of Penance's proc conditions are baseline, so resolving them
against the kit would report every buff permanently active and print 975% as Penance's ordinary
damage. Both readings are computed instead and neither is asserted.

**A caveat on the range itself.** The two ends are "no condition met" and "every condition met",
not a proven minimum and maximum over every combination. For a formula that only multiplies and
adds they coincide; for one that subtracts a conditional term they would not.

### Three ways this was wrong before it was right

Worth recording, because each produced a plausible-looking number:

1. **Sibling recovery answered first.** `$47666s1` is a coefficient-only effect, so
   `findEffectByIndex()` went looking for a same-named spell "with a real value" and found
   Penance's own parent record, whose effect #1 is an unrelated Dummy holding 120. The formula
   rendered "≈360–1,310 Holy damage" — arithmetic on a number that means nothing. A coefficient
   is now read off the effect the token names, with no sibling fallback.
2. **Nested expressions were rounded between levels.** Evaluating `${a*${b}}` one level at a time
   sends the inner result back through `formatNumber()`, which rounds to one decimal for display.
   Twilight Equilibrium's 1.15 became 1.2 and the top of Penance's range inflated from 975.3% to
   1,017.7%. Definitions are flattened into one expression and evaluated once.
3. **A coefficient is not always a coefficient.** `sp_coefficient` is populated on Taunt,
   Shapeshift, Change Model, Charge, Fear, Modify Block% and Instant Kill. Reading those as
   damage produced "healing ≈53.5% of Spell Power injured allies in a ≈92.2% of Spell Power yd
   cone". Only an effect whose type is damage, a heal, a leech or an absorb is now treated as
   scaling with Spell Power.

### One render that is still wrong, and why it is being left

**Dream Breath (355941).** Its own description uses `$s1` and `$s3` twice over in one sentence —
once as a count of allies and a cone angle, once as healing amounts — while effects #1 and #3 are
a Periodic Heal and a Direct Heal. Both readings cannot be right, and the source gives no way to
tell which applies where, so it still renders "≈53.5% of Spell Power injured allies". Fixing it
would mean guessing from the English around the token, which is a parser this project should not
own for one spell in 6,189.

### What a number like this is and is not

"≈279.6% of Spell Power" is a tooltip-equivalent figure: the same arithmetic the game does, which
a player can check against their own spellbook. It is **not** a damage model. Multiplying it by a
real character's Spell Power would need Blizzard's character-statistics endpoint, which
`BattlenetCharacterSyncService` does not call — it fetches `/achievements/statistics`, the
achievement counters. Even then the result is an unbuffed tooltip number, not damage done in a
game: the target's versatility, absorbs and defensives are not in it. `brain.md` {#timeline}'s
rule stands — no damage model, and nothing here predicts an outcome.
