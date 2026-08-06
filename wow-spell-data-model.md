# How WoW Spell Data Actually Works

## The core fact

**There is no table anywhere in WoW's data that lists which spells belong to a spec.**
The question "what spells does an Arcane Mage have?" is never asked by the game, so the answer is never stored. This is not missing data or bad tagging — it is the data model.

## The actual model

**A character is a bag of spell IDs.** Server-side, a character's spellbook is a per-character set of known spell IDs. Nothing more.

**Rules are the only things that change the bag.** Spells enter and leave a character's list through grant rules:

| Mechanism | What it does |
|---|---|
| `SkillLineAbility` (classMask) | Grants baseline spells to every character of a class. Class-level only — **no spec field.** |
| `SpecializationSpells` | "Spec X learns spell Y at level Z." The rule knows the spell; the spell never knows the rule. |
| Trait tree (talents) | One tree per class (class + spec trees are one structure internally). Nodes are spec-gated; committing a build appends each picked node's spell ID. Some nodes are free-granted per spec. |
| Overrides | Knowing spell B with `overridesSpellID = A` displays B in place of A. Remove B, A resurfaces. |

**Spec switching is a transaction, not a lookup.** The server revokes the old spec's granted IDs, runs the new spec's rules, appends the results. By the time a button is pressable, the ID is simply in the bag — no gate checks "is this a Mage spell" at cast time.

## The direction of reference

**Grant rules point at spells. Spells point at nothing.**

- Class leaves one mark on the data: the classMask on grant rows. Class-level derivation therefore mostly works.
- Spec leaves **no mark on spells at all**. Spec exists only inside rules. Spec-level availability can only be computed by evaluating every rule — it can never be looked up.

Analogy: courses aren't labeled with which students take them — enrollment rules are. "Which courses do sophomores take?" requires reading every rule and computing the union.

## Why the data looks dirty

Blizzard's model is sound engineering for their problem: rules compose. A spell can be baseline for one spec, a talent for another, granted by a proc, replaced by a Hero Talent — all without touching the spell record.

The price: **removing a spell means deleting a grant rule, and stale class-level rows left behind harm nothing in-game.** A spell can be truthfully tagged "Priest" by classMask while no grant path delivers it to anyone (the Mind Sear case). These vestigial rows are permanent weather, not bugs Blizzard will fix — nothing on their side breaks.

## Consequences for us

1. **Availability is computed, not stored.** "Spells available to a spec" is a reachability computation over the grant graph. The game runs rules forward, per character, at runtime. We run them in reverse, per spec, offline.
2. **Availability is really a function of a loadout**, not a spec. Baseline-per-spec is a useful projection; build-aware is the truth. Our dimmed unpicked-talent-siblings already reflect this.
3. **SimC's spec lists are a curated computation of this graph** — the maintainers' resolved answer, which is why they beat Blizzard's raw API for spec restrictions. WoWAnalyzer reached the same conclusion and hand-maintains spellbooks per spec in code.
4. **Corrections are the designed residue, not technical debt.** Where the computed answer disagrees with the live game (vestigial rows, missing spec data), we record an attributed correction. This is the correct permanent posture toward a source that is authoritative about grants and silent about removals.

## The transferable lesson

We were asking the data "what belongs to what?" The data only answers "what happens when?"
Ontology questions asked of a procedural system return garbage that looks like bad tagging. Any time derived data seems inexplicably dirty, check whether the source stores **rules** while you're asking for **memberships**.
