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

## 6. What the tags are for — a follow-up the same day

Asked whether guide steps should carry a confidence field so the tags could be enforced,
he declined, and in declining corrected what the tags had been taken to mean:

> "I don't think we need to assign a confidence to those tiers just yet. I think it's
> better to not make the assumption a calculated or reasoned-over approach to cooldowns or
> guides is de facto worse than observed. I think for us it's good to know if something is
> a hyp or obs, but for the product I don't think it matters. As long as the guide is
> usable and our graph or modeller is also usable and we build it with our structure in
> mind."

And on what they were introduced to do in the first place:

> "I really just wanted the AI to synthesise or correct parts in the file as supported by
> what Kalvish said in his prose, or what I said in notes. It wasn't really a way to make
> distinctions on what approach is best. The whole arena structure md is the approach or
> reference tool to help create a guide, or if you ever need help understanding how to
> analyse arena data or information."

**Three things follow.**

**The tags are provenance, not grades.** They answer *what is behind this claim* — a pro's
prose, the player's notes, or the data — so a later pass can see what it is arguing with.
They were never a quality ranking.

**The ladder they had acquired is upside down where it matters.** "How to read this file"
said of [OBS] *"take it as true"*, and the synthesis process spoke of *upgrading* a claim
from [HYP] to [OBS]. But by those same definitions [DER] is the checkable one: a
derivation can be redone, a recollection cannot, and this project's own standing cautions
say a pro's claim is contingent on the meta he won in. Both framings are corrected.

**Nothing in the product should read them.** A guide is judged on whether it is usable and
whether it is built on this structure. No confidence field in the draft format, and no
check gating a step on its tag.

**This retracts an argument made a few hours earlier.** Part 0.2 had justified removing the
scope prohibition on the grounds that the tags did the same policing per-claim. They do
not police anything — nothing reads them and no check verifies them — so the justification
was itself an overstatement of the kind this correction is about. Part 0.2 now rests on
the argument he actually made: the defect the old table named was the *asserting*, and the
kit is what disciplines a proposal.

---

## What this does NOT change

- **The tags stay.** [OBS]/[DER]/[HYP] remain on every claim — see section 6 for what they
  are actually for. Nothing here licenses stating a hypothesis in the voice of a fact; that
  is a matter of how a sentence is written, not of what the tags permit.
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
| 2026-09-23 | Tags reframed as provenance, not grades; "take it as true" and "upgrade the tier" both removed | `arena-structure.md` "How to read this file", `docs/arena/synthesis-process.md` step 4, `data/brain/brain.md` |
| 2026-09-23 | Part 0.2's "the tags do the policing" argument retracted and replaced | `arena-structure.md` Part 0.2 |
| 2026-09-23 | No confidence field in the machine-guide draft format — declined | (no change; recorded so it is not re-proposed) |
