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
