# Question audit: the stored bank against the Brain and the spell data

Stage 2 of `system-integration.md`. Every question attached to a WoW concept, read against
`data/brain/brain.md` (doctrine) and against the live `spells` table (facts).

**Scope: 100 questions**, those attached to concepts 78–84 (Role Fundamentals, Crowd Control,
Cooldown Management, Positioning, Target Switching, Awareness & Tracking, Team Composition)
across 9 modules. By skill type: **61 recall / 20 application / 19 analysis**. By format: 61 mcq,
24 true/false, 8 matching pairs, 7 ordering.

Run against patch id 1 on the local dev database, 2026-09-24. Spell ids are given so every claim
below is re-checkable.

---

## Summary

| | Questions |
|---|---|
| Stored answer contradicts the Brain | **9** |
| A distractor is defensible, so there is no single key | **4** |
| Stored answer contradicts the live spell data | **5** |
| States a fact no field can confirm | **1** |
| Tests vocabulary the model never defines | **3** |
| Checked and still correct | the remainder |

The drift is **not** where `system-integration.md` predicted it. That document reported "only 7
[questions] contain a hard number", and concluded the questions are overwhelmingly conceptual so
the rot must be doctrinal. Re-run over the answers as well as the prompts, the count is **16 by
the document's own regex** (4 in the prompt, 12 more only in the options) and **20** by a looser
one. The numbers were never in the prompts. They are in the multiple-choice options, which is
where a wrong number actually does its damage.

And three of them are now wrong. So the rot is in both halves, and the data half is the one that
will keep happening.

---

## 1. Stored answer contradicts the Brain

### Q84 — "What is the primary win condition in most arena matches?"

Stored: *"Killing the enemy healer to remove the team's source of sustain."*

`{#answer-pool}` says the kill target is **whoever's list of buttons is shortest right now,
re-derived every go** — not decided at the gates, and not a role. `{#intent}` supplies a
counter-example from the BlizzCon final: against Mage/Lock the plan was to not go at all — *"we
just sit behind a pillar for 10 minutes and then kill the lock through every cooldown"* — which
makes the distractor "surviving until the arena timer expires" a real plan rather than a joke
answer.

This question is the single furthest from the model, and it is an `easy`/`recall` question in
**Arena Fundamentals**, so it is close to the first thing a new player is told.

### Q91 — "All offensive cooldowns spent, enemy healer still at full health"

Stored: *"The kill attempt failed — your team must now play defensively and wait for cooldowns
to reset."*

Two separate contradictions.

- `{#answer-pool}`: **"A go into a full pool is a strip. A go into an empty pool is a kill."**
  A go that forced nothing failed; a go that spent their defensives did its job. The stored
  answer scores the go by the health bar, which is the exact framing that section opens by
  rejecting ("Not their health bar").
- `{#clock}`: you do not wait for everything to reset. The next go is set by what came back
  first, and deliberate re-alignment is a named cost.

This is also the question the author's own note flags: a Gladiator answered it with the swap —
the kill target has defensives left, we still have offensives rolling, so move — and was marked
wrong. That answer is `{#answer-pool}`'s re-derivation almost verbatim.

### Q101 — "An incapacitate breaking on damage means your team should hold all damage"

Stored: **true**.

`{#thresholds}` is a worked example of doing the opposite on purpose: Blind the rogue, have a
teammate *break it with a kick for the damage*, and execute through Evasion — because Blind
suppresses the dodge and Touch of Death cannot be dodged. Break-on-damage is a property to
exploit, not a rule to obey. Stated as an unconditional true, this teaches a player to discard
the mechanism behind one of the best kills in the source material.

### Q104 — "Why is chaining CC sequentially more effective than applying it simultaneously?"

Stored: *"Sequential CC maximises total disable time; simultaneous CC overlaps durations and
wastes cooldowns to DR."*

True **on one target**. The question does not say so, and `{#zugzwang}` — the section built on
the opener that won the tournament — says the opposite in general:

> **Simultaneity is the mechanism.** Two actions landing on the same global is what denies the
> answer.

Incap sweep the druid *and* gouge the rogue as he comes out. Root the mage *and* vortex him so
he must blink twice. Adding "on the same target" to the prompt fixes it entirely.

### Q131 — "The enemy DPS just overextended past a pillar, separated from their healer"

Stored: *"Apply CC and commit offensive cooldowns immediately — the overextension is a free kill
window with the target cut off from heals."*

Position is not the answer pool. Separation removes the healer's **externals**; it removes
nothing from the target's own trinket, personals, immunities or escape. `{#answer-pool}` is
explicit that the list of buttons decides, and `{#timeline}` that an empty list is *"the moment
a go is a kill attempt instead of a strip — not a moment a kill is guaranteed."* "Free kill
window" is precisely the over-claim the whole model is organised against.

### Q93, Q107, Q117, Q141 — the four ordering questions

All four encode a go as a vertical sequence: confirm → CC the healer → burst → chain more CC →
secure. `{#zugzwang}` names that format as a defect, in this project's own tooling:

> A plan written as a vertical list of steps cannot express it, which is a real limitation of
> our own guide builder today.

So the question **format** carries the doctrine, not just the text: an ordering question can only
ever assert that the go is sequential. These four cannot be corrected by editing their steps.
They are also, all four, built around CCing the enemy healer, which inherits Q84's assumption.

---

## 2. No single correct answer

These are not wrong. They have a defensible distractor, so grading them marks a good player wrong
— the failure the tier system exists to prevent, arriving through a different door.

- **Q91** — as above: the swap is `{#answer-pool}`; the reset is `{#clock}`. Both are correct
  under conditions the prompt does not state.
- **Q103** — partner has stunned the healer, you also hold a stun. Marked wrong: *"use your stun
  on the enemy DPS instead."* That is `{#allocation}` exactly — *"control on an enemy inside an
  offensive cooldown is paid twice"* — and `{#chains}`'s off-target peel at full duration.
- **Q115** — enemy committed their offensive on your healer; stored answer is to use the major
  defensive **immediately**. `{#falsification}` item 5 ranks the response *cheapest thing that
  works first*: control the caster, break line of sight, a personal, an external, then trinket.
  The Brain also labels that ordering **reasoned, not observed** — so the honest treatment is
  not to regrade this question but to stop grading it.
- **Q116** — "generally better to wait for the enemy defensive before committing." `{#chains}`:
  *"a two-minute cooldown saved for a perfect window you never recognise is worth exactly
  zero… nothing here should mark the first one as a mistake."* For the player this question is
  aimed at, committing early is the better habit.

---

## 3. Stored answer contradicts the live spell data

The important finding. **Of the three questions that state a crowd-control duration, two state
the PvE duration** — on a site about arena.

| Q | Stored | Live data | |
|---|---|---|---|
| **Q280** | Intimidation is a **5 second** stun | `duration_seconds 5.0`, **`pvp_duration_seconds 3.0`** (spell 19577 / 24394) | wrong in arena |
| **Q281** | Hammer of Justice: 10 yd, **6 second** stun | `range_yards 10` ✓, `duration_seconds 6.0`, **`pvp_duration_seconds 5.0`** (spell 853) | wrong in arena |
| **Q265** | Pain Suppression has **2 charges** | `charges = 1`, `cooldown_seconds 180` (spell 33206) | contradicted at base; no talent in the module's own build grants a second |
| **Q266** | Fade's cooldown is **20 seconds** | base `cooldown_seconds 30` (spell 586); 20 only with Improved Fade 2/2 | build-dependent, stated flat — and **Q277 states the same fact correctly**, in the same subject |
| **Q285** | *"Polymorph doesn't appear in either the filtered or raw project spell data for Mage"* | spell 118 is present: `dr_category Incapacitate`, `pvp_duration_seconds 6.0` | the data has since falsified the question |

Q285 is the purest case in the whole audit: a question whose stored answer is a claim *about the
project's own data coverage*. That class of question rots the moment the gap it describes is
filled, and nothing tells you.

**Q284** — Dragon's Breath *"a Disorient effect in a 90 degree cone with a 12 yard radius"* — is
a sixth case of a different kind. The Disorient is confirmed (spell 31661). Nothing in the schema
holds a cone angle or an effect radius, so the rest of the answer cannot be checked by anything
and never will be. Not wrong; unbackable.

### What passed

Checked and still correct: Q96 (second application at 50% — matches `CcChainBuilder::annotate()`,
which is 100/50/immune), Q267 (Psychic Scream base 40s, spell 8122), Q264 (Evangelism base 90s,
spell 472433), Q278 (Shadow Word: Death 1 charge, 10s, spell 32379), Q282 and Q287 (Freezing Trap
`range_yards 100`, spell 3355), Q283 (Divine Steed: no range, Mobility), Q133–Q135 (DR is per
category per target), Q100 (Fear is Disorient, not Stun).

One wording note: **Q78** says repeated CC "becomes shorter each time". The project's own model is
two steps then immunity — 100 / 50 / 0 — not an indefinite taper. It is still the best of the four
options offered.

---

## 4. Vocabulary the model never defines

**Q87** and **Q90** grade the definition of *"tempo"*. **Q114** grades *"pressure cycle"*. Neither
term appears in `brain.md`. The definitions given are reasonable, but there is nothing to link
them to, nothing to correct them against, and no way to find them if the model later says
something different. This is what Layer 2's section-id anchor is for, and these three questions
are the ones that cannot have one.

---

## What this changes

1. **Layer 1 is justified by the data half, not the doctrine half.** Two of three CC durations in
   the bank are the PvE number. A generated question reading `pvp_duration_seconds` cannot make
   that mistake, in any patch, ever. That is a stronger argument for generating facts than "the
   questions might go stale" — they already did, silently, and only a field-by-field check found
   it.

2. **Four ordering questions cannot be repaired by rewriting.** The format asserts a sequential
   go. Either the format goes, or `{#zugzwang}` is wrong.

3. **Grading is the problem, not the answers.** Q91, Q103, Q115 and Q116 all have a defensible
   distractor. Three of the four are `analysis` or `application` — the skill types the platform
   most wants to measure. Layer 2's rule (a `[HYP]` claim is not a graded question) needs to
   extend to application questions whose conditions the prompt does not state.

4. **Q84 first.** It is an easy recall question in Arena Fundamentals, and it teaches the one
   thing `{#answer-pool}` exists to correct.
