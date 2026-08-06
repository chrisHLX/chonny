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

## This ledger is a last resort, not a first response

**Before something gets logged here as an unfixable/structural gap, the
investigation has to actually be exhausted — not just "I checked our two
local data folders and didn't find it."** Concretely, that means, in order:

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

**Why this rule exists:** the "PvP talents have no cooldown data" entry below
was first written after only step 1 — inspecting our own already-imported
JSON — and concluded "permanent gap, nothing to be done." It took a direct
challenge ("how would Wowhead have this, must be an older version?") to
actually do steps 2 and 3, which is what turned up that `fetch-talent-trees.php`
hand-picks a narrow field subset (step 2) and that the live API genuinely
has nothing more even when queried directly right now (step 3) — a
categorically stronger, actually-trustworthy conclusion than the original
one, even though it happened to land in the same place. The entry below is
now a model of the *right* amount of work before writing CONFIRMED, not just
an example finding — a future entry that skips straight to "flagged, can't
fix" after only step 1 should be treated as incomplete, not done.

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
