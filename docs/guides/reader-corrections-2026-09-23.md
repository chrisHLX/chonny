# Reader corrections to the machine guides — the 2026-09-23 sweep

Every note ChrisO left on the 16 machine-drafted guides between 2026-09-13 and 2026-09-19,
extracted from `feedback-export-2026-09-23.md` (the full export, kept whole in this folder
because a note is unreadable without the step it was attached to).

**Why this file exists.** `user_guide_comments.user_guide_section_id` and
`user_guide_block_id` are both `cascadeOnDelete`, and `guides:author` replaces a guide's
sections wholesale on every run. So **26 of these 33 notes are destroyed by the next
re-author** and all 33 by a delete. They are the entire return on publishing the guides in
the first place, so they are written down here before that happens.

---

## 1. The instruction that drove the rewrite

> "The prose and reasoning is too long. The actual sequence + notes makes a lot more sense
> and it actually seems legit." — *Boomkin Cleave vs Jungle*

> "Really good analysis. The part that stands out was where you make the point that
> choosing a kill target takes away the strength of the comp — which is that it can kill
> anyone. That's a great point, **it's just buried in prose that's mostly useless**."
> — *Thug vs RMD*

Both say the same thing: the sequences and their short notes carry the value, and the essay
around them buries it. This is what `guide-writing.md` now enforces with a budget.

Also noted, and worth keeping: *"It's important to note this was written without reading
arena-structure.md."*

---

## 2. Factual errors to fix — these are wrong, not debatable

| # | Correction | Where it bit |
|---|---|---|
| 1 | **Rallying Cry keeps getting left out** of Warrior answer counts. Said twice, on two guides. | TSG, Turbo |
| 2 | **Shackle Horror only works on pets and NPCs.** Not castable on a player. | Shadowplay |
| 3 | **Banish is pets only** — same class of error. | DK/Lock |
| 4 | **Shadowfury and Howl of Terror are one choice node.** Cannot have both. | DK/Lock |
| 5 | **Death Knight picks Strangulate or Asphyxiate**, never both. | Havoc/Ele |
| 6 | **Die by the Sword reduces all damage taken by 30%** — the guide had it as a parry/mitigation read only. "Read the tooltip." | RMD |
| 7 | **Power Infusion applies to the Shadow Priest *and* its target**, per the talent. | Shadowplay |
| 8 | **Both Hammers of Justice are a 30s cooldown** — one on the healer, one on a DPS, every 30s. And it cannot cross-CC with Polymorph (same DR). | Fire/Fury |
| 9 | **Mage blinks out of the stun.** A stun-into-burst plan has to account for it. | Fire/Fury |
| 10 | **Warrior heroic leaps away**, and has Rallying Cry on top. | Havoc/Ele |
| 11 | **Mighty Ox Kick** — "I have never seen it taken or used." Do not build a plan on it. | Fire/Fury |
| 12 | **Rogue has Shadowstep and Sprint; Hunter has Disengage, Cheetah and Freedom.** The guide treated the comp as having no outs. | Shadowplay |
| 13 | **Pain Suppression has 2 charges on a 2-minute cooldown** — the healer can answer the stun. | Shadowplay |

Items 2, 3, 4 and 5 are the class of mistake nothing in the pipeline can catch — see
`knowledge-gaps.md`. Items 1, 6, 8, 9, 10 and 13 are the pipeline having the data and the
draft not reading it.

---

## 3. Reasoning errors — the plan was defensible, the argument for it was not

**Killing the Feral ends the game.** The guide argued that killing the healer stops the
sustain while killing the Feral "only removes one part":

> "It's actually not a bad plan, it's just the reasoning in some parts... as if to say
> killing the feral doesn't result in the game ending. Which in most cases it does."

**A comp that can kill anyone loses that by naming one target.** The sharpest point in the
whole sweep, and it belongs in the model as well as the guide:

> "Choosing a kill target takes away the strength of the comp, which is that it can kill
> anyone."

**Casting from nothing does not happen.** A chain that opens on a hard-cast is not a plan:

> "It's really hard to land casted spells from nothing — an easier approach would be stun,
> trap, into something."

**Control breaks on damage, and both teams have it.** A plan assuming the enemy never
answers in kind:

> "This assumes that the jungle won't CC the other team, or that roots, solar beam and
> blinding light won't break to damage. It also doesn't consider that if someone is not
> rooted in place when getting beamed, they won't walk out."

---

## 4. Strategy he supplied, which is better than what was drafted

These are [OBS] — a Gladiator on his own game — and the rewritten guides should use them.

**Havoc / Elemental vs TSG — kite, then swap, do not pick a target early:**

> "They can both kite relatively well and do huge burst and AoE in cooldowns, which lines
> up with their AoE CC. I think the play would be kite using vortex, earth grab totem,
> cyclones and darkness — and then if an opportunity presents itself, i.e. both DPS
> stacked, double stun, maim on healer, burst... Something to consider is rdruids take time
> to heal multiple targets and Shaman has purge; probably swapping between the two till one
> is dry on defensives is the correct play."

**Open on the instant, not the cast:**

> "I think I would start with Maim because it's instant — land it the same time the DH gets
> stun, then splash burst everything with chain lightning."

**Chaos Nova into Capacitor Totem, so the Druid is not exposed:**

> "I would try say Chaos Nova when they are stacked and incap on DK out... both warrior and
> DK will be stacked so you could probably Chaos Nova into cap totem. That way the Druid
> doesn't have to expose his position, which would make it easy for the melee to swap
> to him."

**Trinkets are the opening, not the damage:**

> "I could see the TSG winning by starving out the RMP, but once they see an opening — i.e.
> no trinkets or defensives on the RMP — they could start CCing to end the game."

**Stealth openers:**

> "Both druids will probably be in stealth and raking, as the DH/Ele would expose the Druid
> early."

---

## 5. What he approved of, so it is not lost in the rewrite

- *"That's a really good section, the heading is excellent, explains the game plan clearly."*
- *"This was a brilliant plan, very well explained and makes a lot of sense."* (WLS vs RMP —
  with the talent-feasibility caveat that produced `TalentFeasibilityService`.)
- *"This was actually really good... I want to see you use other comps."*
- *"I like the thinking about rake."* / *"Oh there's the disarm, nice."*

The pattern: **a heading that states the plan, and a step note that names a specific
interaction, both land. The paragraphs between them do not.**
