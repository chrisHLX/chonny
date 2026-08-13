# PvP Diminishing Returns — Reference (secondary_source_unverified tier)

**Source:** [Wowhead — Diminishing Returns in PvP](https://www.wowhead.com/guide/diminishing-returns-in-pvp-6024), by Ribsx, updated 2022/02/17 ("This Is A User-Submitted Guide! Wowhead only updates official guides, so information might not be up-to-date.")

**Status: STALE, not patch-current.** This is Shadowlands-era content (mentions Venthyr covenant abilities, Kul Tiran/Pandaren racials alongside current abilities, several removed spells). DR reset timer here is **15 seconds** — the domain expert's own taxonomy for the current patch states **16→20 seconds**, confirming this source predates at least one real DR system change. Treat the *category structure* and *stable, long-standing spell-to-category groupings* (Polymorph/Sap/Freezing Trap/Hex as Incapacitates; Fear/Dragon's Breath/Cyclone as Disorients) as reliable — these groupings have been stable across many expansions. Do NOT treat the specific spell list as complete or current without verification against live game data (`spells.mechanic`, current talent trees) — many named abilities here no longer exist, and DR category assignments occasionally get reworked by Blizzard.

**Open question, not yet resolved (2026-08-11):** this source separates DR into 7 categories (Roots, Incapacitates, Disorients, Stuns, Silences, Knockbacks, Disarms). The domain expert's current taxonomy for this project merges these into 5 (combining Silences+Disarms, and Roots+Knockbacks). Whether that merge reflects a real current-patch consolidation or is a simplification is unconfirmed — do not assume either answer.

## The DR mechanic itself

**Corrected 2026-08-11 — this source's falloff description is wrong for the current patch, confirmed directly by the domain expert.** This source originally claimed: 1st application = 100% duration, 2nd = 50%, 3rd = 25%, 4th+ = full immunity (a 3-step falloff). **Actual current-patch (12.1) behavior: 1st = 100%, 2nd = 50%, 3rd+ = full immunity — only two diminished steps, not three.** `App\Http\Services\CcChainBuilder` implements the corrected version. Treat this file's original 100/50/25 claim as an example of exactly the staleness this file's own header already warned about — the mechanic itself, not just the spell-list specifics, had drifted.

**The 6-second PvP duration cap (sourced from Icy Veins, added 2026-08-11)** — a separate, additional mechanic this file didn't originally cover at all: every CC effect is clamped to a flat ~6 seconds the instant it lands on a player, independent of its PvE tooltip duration, *before* DR is even applied on top. Not a formula or ratio of PvE duration — a hard engine-level clamp, with one documented exception (Evoker's Oppressing Roar raises the ceiling for buffed allies). Formalized as `CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS = 6` — a real code constant, not just this file's prose. Not yet applied as a computed per-entry duration anywhere (see that constant's own docblock for why — no trustworthy per-spell base duration exists yet to take `MIN(base, 6s)` against).

**Reset window: 20 seconds, confirmed current for patch 12.1** (this source's own 15-second claim is stale; a separate research pass suggested 16s "down from 18," also superseded — the domain expert's direct confirmation of 20s is the one to use).

**Per-spell real PvP durations, confirmed by the domain expert (2026-08-11) — see `spells.pvp_duration_seconds`.** These are the first concrete, spell-specific numbers checked against the 6-second-cap theory above, and they mostly confirm it directly:

| Spell | dr_category | Confirmed PvP duration | Stored `duration_seconds` (unreliable/PvE-scoped) |
|---|---|---|---|
| Kidney Shot | Stun | 6s | 3.00 (a low-combo-point value, not what's experienced near max CP) |
| Cheap Shot | Stun | 6s | 6.00 (already matched) |
| Fear | Disorient | 6s | null (never captured) |
| Polymorph | Incapacitate | 6s | 60.00 (the PvE tooltip value) |
| Blind | Disorient | 6s | 60.00 (the PvE tooltip value) |
| Gouge | Incapacitate | **3s — a real exception below the cap, not clamped to it** | 4.00 |

Four of six land exactly on the 6s cap — real, direct confirmation of `CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS`, not just a source citation anymore. Gouge is the one genuine counter-example: it doesn't get *capped down to* 6s because its own real duration is already shorter than the cap. This is why `pvp_duration_seconds` is hand-curated per spell rather than derived by a flat `MIN(duration_seconds, 6)` formula — Kidney Shot alone disproves that formula (stored `duration_seconds` of 3.00 would wrongly win over the real 6s answer, since 3 < 6 the MIN would just return the wrong stored value unchanged).

**Still open, explicitly flagged rather than guessed:**
- **Silences: "most silences last 4s"** (domain expert, 2026-08-11) — a category-level generalization, not yet applied to any specific spell. Checked: 7 spells currently carry `dr_category = Silence` (Strangulate, Sigil of Silence, Solar Beam, Avenger's Shield, Shield of Virtue, Silence, Garrote), with wildly inconsistent stored `duration_seconds` (2–18s) — Garrote's 18s in particular is almost certainly the bleed DoT's duration bleeding into the wrong field, not its silence component. "Most" implies real exceptions exist within this category too (plausibly Garrote, given how much of an outlier its stored value already is) — needs the same one-spell-at-a-time confirmation as the six above, not a bulk 4s fill.
- **Cyclone** — the domain expert's comment ("seemed like you got the right cc there") reads as confirming the existing Disorient category tag is correct, not supplying a new duration number. No `pvp_duration_seconds` added.
- **Incapacitating Roar** — "some of them are correct and I'm not sure how" — only one real (non-hidden) copy exists in the current dataset (spell_id 99), already correctly tagged Incapacitate. No new duration number was given for it specifically; left uncurated.

**Knockback DR is mechanically different from every other category** — not the standard falloff. A second knockback within the DR window is fully immune (100% → 0%, no partial-duration middle step). Relevant if Roots and Knockbacks end up sharing one `dr_category` value — they'd need different DR-math handling despite sharing a category label, since Roots use the standard falloff and Knockbacks don't. (Unverified against the corrected 2-step falloff above — confirm before relying on it.)

**Some effects apparently don't DR at all** — this source names Summon Infernal, Death Grip, Thunderstruck as immune to diminishing returns entirely (though it inconsistently also lists Summon Infernal/Summon Abyssal in the Stuns table with a duration — the source isn't fully self-consistent on this point). Distinct from the CC-chain-exceptions concept (combos that work *because of* how DR interacts) — this is about individual spells that sit outside the DR system altogether. Death Grip is already named in the project's own exceptions-table plan for a different reason (a specific comp combo, not a DR-exemption claim) — don't conflate the two without checking which is actually true for the current patch.

## Category tables (as given by the source — NOT verified against current spell data)

### Roots
Entangling Roots, Frost Nova, Earthgrab Totem, Mass Entanglement, Nature's Grasp, Wild Charge, Charge (Hunter), Harpoon, Steel Trap, Tracker's Net, ~~Binding Shot~~, Freeze, Frostbite, Glacial Spike, Ice Nova, Disable, Clash, Entrenched in Flame, Thunderstruck, several DK/DH root effects.

**Confirmed wrong (2026-08-11):** this table lists Binding Shot as a Root. The domain expert confirmed it's actually a **Stun** for the current patch — see `chonny` CLAUDE.md's "Synergies tab" section for the correction. Binding Shot's real mechanic (roots on contact, stuns if the target tries to move away) plausibly explains the mismatch — this table may be describing only the root-on-contact half. Treat any other entry here with the same "verify, don't defer" posture now that one has been directly disproven, not just presumed stale.

### Incapacitates
**Polymorph**, Sap, **Freezing Trap**, Hex, Mortal Coil, Imprison, Detainment, Incapacitating Roar, Hibernate, Scatter Shot, Ring of Frost, Paralysis, Repentance, Holy Word: Chastise, Shackle Horror, Gouge, Sundering, Banish, Quaking Palm (racial).

### Disorients
Fear, Psychic Scream, Blind, Intimidating Shout, **Dragon's Breath**, Blinding Sleet, Sigil of Misery, **Cyclone**, Scare Beast, Song of Chi-Ji, Incendiary Breath, Blinding Light, Turn Evil, Mind Control, Howl of Terror, Mesmerize/Seduction (Warlock pets), Agent of Chaos (Venthyr — removed).

### Stuns
Cheap Shot, **Kidney Shot**, Leg Sweep, **Hammer of Justice**, Mighty Bash, Asphyxiate, Fel Eruption, Chaos Nova, Illidan's Grasp, Maim, Rake (from stealth), **Intimidation**, Double Barrel, Wake of Ashes, Censure, Psychic Horror, Lightning Lasso, Capacitor Totem, Axe Toss, Shadowfury, Shockwave, Storm Bolt, Warpath, War Stomp/Haymaker (racials).

### Silences
Strangulate, Sigil of Silence, Solar Beam, Spider Sting, Avenger's Shield, Shield of Virtue, Silence (Priest), Garrote.

### Knockbacks
Ursol's Vortex, Thunderstorm, High Explosive Trap, Typhoon, Overrun, Bursting Shot, Blast Wave, Ring of Peace, Mighty Ox Kick, Shining Force, Dragon Charge, Gorefiend's Grasp, Fellash/Whiplash (Warlock pets).

### Disarms
Faerie Swarm, Grapple Weapon, Dismantle, Disarm (Warrior).

## Offensive/defensive framing (matches this project's arena-structure.md go/anti-go cycle)

A CC-heavy comp (the guide's own worked example: RMP) waits until an opponent is off relevant DRs before committing a kill attempt, then chains a full-duration stun on the kill target while simultaneously incapacitating/disorienting the healer to prevent peels — the exact "go" moment `arena-structure.md` already documents narratively. Defensively, a team facing heavy CC pressure holds CC in reserve to answer an opponent's own burst window, and tracks its own active DRs to know when it's vulnerable vs. protected.
