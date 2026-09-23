# ChrisO — the scope correction, 2026-09-23

**Who / what:** the project's player, correcting Part 0 of `arena-structure.md` directly.
**Tier:** primary. Same standing as `arena-structure-v1-reviewed-by-chriso.md` — this is the
person the model is derived from, revising his own earlier words, and it is not filtered through
any other source.

**What prompted it.** While designing the Matchup Lab's interactive mode, the question came up of
whether the engine should choose the kill target or the player should. The answer given was that
Part 0 puts that decision "out of reach", and quoted the table to prove it. He rejected the
premise — not the feature.

---

## 1. The prohibition was never what he said

> "the part in the structure md seems to suggest we can't pick a target. I don't think that
> should be in there because we are using arena structure md as context for making guides as
> well."

The Part 0 table was built from his own September quote:

> "Each state or situation is unique and we can't answer everyone for every situation... But as
> for individual outplays in the game, they seem to be embedded inside a context that we don't
> really have access to all the information."

**That is a statement about incomplete information, not a statement about forbidden output.** The
table converted one into the other. "We do not have access to all the information" and "do not
write about it" are different claims, and only the first one is his.

## 2. Humility instead of prohibition

> "It's ok if we get it wrong. I don't want to have information or prose that implies the AI
> can't do something. It's more my thoughts and our reasoning, but we honestly don't know 100%
> what's going to happen, and I feel like that's a humility I would like to instil into our
> system."

The distinction he is drawing: a framework that says *we might be wrong about this* is honest. A
framework that says *this cannot be done* is a different and unearned claim — it asserts a limit
on the reasoning rather than on the evidence.

## 3. Exploration is the virtue, not the risk

> "Even if the advice allows or helps a player get rank 1 or BlizzCon winner, I would like to
> maintain a level of humility that allows errors and promotes a creative, playful, exploratory
> [approach] as a virtue rather than something to avoid."

This is a direct instruction about the character of the whole system and it applies well beyond
Part 0. A model rewarded only for never being wrong produces nothing worth correcting — which
is a problem, because the machine-drafted guides exist *specifically* to be corrected. Caution
and the feedback loop this project is built on pull against each other, and he is calling it for
the loop.

## 4. The constraint is in the game, not in the document

> "I think the constraint is built into the actual facts of the game — that a creative expression
> of abilities, when reasoned over, will expose potential strategic weaknesses without forcing
> the system in the md."

The strongest of the four, and the one that makes the rest safe. A guide that proposes a bad kill
target is checkable **against the kit**: the target has too many answers, the chain does not
reach, the ability is on the wrong DR, the build cannot hold both talents. The spell data is the
discipline. A prose prohibition adds nothing the data does not already enforce, and costs the
reasoning that would have found the good line.

This is consistent with how the rest of the project already works — `TalentFeasibilityService`
catches a plan one character could not have, the description resolver catches an unresolved
number, the importer catches a duplicate override. None of those are rules against thinking; all
of them are checks against the facts.

## 5. The document was already contradicting itself

Not from the source — found while acting on it, and recorded here because it is what settles the
question rather than leaving it a preference.

Part 16, the authoring checklist, opens with:

> "**Who dies, and why them?** Ranked, with a reason per candidate — fewest answers, most
> pinnable, or most suppressible (pressure on them stops their peels). Never a single verdict.
> [OBS]"

A framework cannot both **require** a ranked kill-target list before a guide may have any steps
and **also** list the kill-target decision as out of reach. Part 16 is the one that survives: it
is more specific, it is tagged [OBS], and it already carries the humility the correction asks
for — *ranked, with reasons, never a single verdict*.

---

## What this does NOT change

- **The confidence tiers stay.** [OBS]/[DER]/[HYP] were always the right mechanism and this
  correction makes them the *only* one. Nothing here licenses stating a hypothesis as a fact.
- **Part 16's "things not to write" stays**, because every entry on it is about *how* something is
  stated or about a checkable error of fact — a fabricated number, control on a target the spell
  cannot affect — not about what may be reasoned over.
- **Part 0's scoping quote stays in the document.** It was not wrong. Only the table built from it
  was.

## Distillation log

| Date | Pulled | Landed in |
|---|---|---|
| 2026-09-23 | "Out of reach" reframed as a confidence split, not a permission split | `arena-structure.md` Part 0 |
| 2026-09-23 | Humility and exploration as the stated character of the system | `arena-structure.md` Part 0.2, `data/brain/brain.md` |
| 2026-09-23 | The game's own facts are the constraint; prose prohibitions add nothing | `arena-structure.md` Part 0.2 |
| 2026-09-23 | Part 0 vs Part 16 contradiction resolved in Part 16's favour | `arena-structure.md` Part 0 |
| 2026-09-23 | "Worth writing even when unsure" added as the positive counterpart | `arena-structure.md` Part 16 |
