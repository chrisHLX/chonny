# Distilled — Gemini "Cooldown Graph Engine", 2026-09-23

**Source:** `gemini-cooldown-graph-2026-09-23.md`.
**Who:** Google Gemini, in conversation with ChrisO, who was looking for adjacent fields
of inquiry (game theory and similar) to `arena-structure.md`.
**Why it is worth reading:** it proposes a *formalisation* — a way to draw the model —
rather than a claim about how arena is played. That is judgeable without a pro, because
the test is "is this computable from data we hold", not "is this true of the game".

**Standing caveat, and it is a heavy one.** Every other source in this folder is a
player. This is a language model that had `arena-structure.md` in its context. **Where it
agrees with the model, that is not corroboration — it is the model being read back.**
Nothing here may be promoted to `[OBS]` on this source's authority, ever. No patch, no
bracket, no format stated; it inherits the model's, which is coordinated 3v3.

The useful reading is: *what shape does the existing model take when you force it onto a
time axis, and what breaks when you try?* The breakages are the valuable part, and
sections 6, 8 and 10 below are the ones that changed something.

---

## 1. Arena as two curves over one time axis — a representation, not a claim

`[adds — not in the model]`

> "The engine models an arena match (from 0 to 300 seconds) as two overlapping state
> curves... **The Offense Curve (Threat Spikes)**... **The Defense Curve (Answer
> Pool)**... **System Collapse (The Red Kill Window)**: When an Offense Spike occurs
> while the enemy's Defensive Capacity line is near zero (their 'Answer Pool' is empty),
> the lines intersect."

The substance is Part 2 (the answer pool is the currency) and Part 7 (time is counted in
DRs and goes) with nothing added. What *is* new is putting both on a shared, drawable
time axis, so "their pool is empty" acquires a **when**. Part 2 states the rule; it never
says where in the round the rule fires, and a plan that cannot say when is a plan the
reader has to time by feel.

This is the only genuinely new thing in the source and it is a presentation idea, not a
finding. Tiered `[DER]` in the model, because the curves are arithmetic over cooldowns
the database already holds.

## 2. "Mathematically guaranteed" — reject this phrase outright

`[contradicts Part 11]`

> "This visually proves the exact window where a kill is mathematically guaranteed."

It does not, and Part 11 already names why:

> "**[DER] Known blocker for a real Tier-1 damage calculator**: static coefficient dumps
> are not tied to a real character's stats. It needs either bracket-typical gear
> assumptions or live combat-log data."

An empty answer pool means *the target has no button left*, not *the incoming damage is
lethal*. The distance between those is a damage model this project does not have, and
Part 11 says so. The honest output is **"a kill window opens here"** — a window in which
a go is a kill attempt rather than a strip — never "a kill happens here".

**Recorded as a contradiction, not resolved in the source's favour.** The model keeps
Part 11's reading. Anything built from this source carries the weaker claim.

## 3. The single defence curve is wrong — the pool is per player

`[contradicts Part 2]`

The source draws one "Defensive Capacity line" per team. Part 2 is explicit:

> "**The pool is per-player, not per-team.** Their healer holding three externals does
> not help a target the healer cannot reach."

A team-level curve averages away the exact quantity that decides the go: *whose* list is
shortest. Part 2 also has the kill target **re-derived every go** on that basis, and a
team-summed curve cannot express a swap.

**The model's reading stands.** Any implementation draws a curve per player, and a team
line is at most a convenience view over them.

## 4. Two-tier periodicity, 30s and 90–120s

`[corrects — the source, not the model]`

> "Spikes upward every 30 seconds (DR resets / minor setups) and reaches massive peaks
> every 90–120 seconds (major offensive CDs + cross-CC)."

Two numbers are conflated here. Part 7's unit is the **DR window (~18s)**, and the 30s
figure in the model is a *chosen* cadence, not a mechanical reset:

> "**[OBS] Cadence is chosen, not observed.** ... 'You get a multiplier effect using all
> three in 30 seconds rather than DR'ing one ability or school and then having spells out
> of sync.' Scatter Shot's 30s lining up with Maim's is the reason to bring Scatter back
> in Jungle."

So 30s is Jungle's period because Maim and Scatter are both 30s — not a property of
arena. **A graph engine that hardcodes 30 is wrong for every comp whose slowest aligned
term is not 30.** The period has to be derived per comp, which Part 17 already asks the
data layer for: "Natural period T: the cadence that maximises simultaneous availability".

This correction is the difference between a graph that is right for one comp and a graph
that is right.

## 5. The ticking clock — a good phrasing of something the model already has

`[confirms Part 3]`

> "They fail to model how trading a short cooldown for a long one creates a 'ticking
> clock' that causes a team to lose two minutes into the future."

Part 3 has the same thing as a state rather than a rate, quoting Kalvish:

> "**You are not allowed to play until you have these buttons back.** So you will
> constantly be on the back foot until the game ends."

Nothing to change. Kept because "you lose two minutes into the future" is the clearest
one-line statement of why a guide should show a *timeline* rather than a checklist, and
it is worth having a sentence like that to hand when writing for a reader.

## 6. Priority Cascade — the source's best contribution

`[adds — not in the model as an ordering]`

> "The engine cannot assume a rigid 1-to-1 answer (e.g., 'Avatar ALWAYS means Pain
> Supp'). It requires a **Priority Cascade** that evaluates low-cost control options
> first, falling back to high-cost defensives only if the defender is locked down in CC."

Every ingredient is already in the model, in three different places, and none of them
orders the others:

- Part 3's per-matchup lookup: **"Enemy presses X → you press Y."** A one-to-one map,
  which is precisely what the source says is too rigid.
- Part 3's corollary: use the defensive to reposition, then **layer a second kind of
  answer** (peel, LOS) on top.
- Part 6: **"control on an amplified enemy is paid twice"** — *"landing a stun on the
  Warrior during Avatar... You stop damage and get a go."* That is a control answer
  chosen over a defensive answer, stated as a win-win but never as a *rank*.

The cascade is the composition: **control the source → break line of sight → spend a
personal → spend an external → trinket**, stopping at the first option that is both
available and sufficient. This turns Part 3's lookup into a *ranked list* rather than a
single answer, which is also more honest about what Part 3 admits — that good teams
handle this "partly not at all, partly by pre-agreed triggers."

`[HYP]` on the ordering itself. Nobody observed this ranking; it is reasoned from
cooldown cost. The cheapest test is in Part 3's own terms: put a trigger table that
lists ranked options, rather than one answer, in front of a player.

## 7. CC stagger — the computable form of a rule Part 2 already states

`[confirms Part 2]`

> "If a healer is trapped, their defensive line must be artificially suppressed to $0$
> until they trinket or the CC expires."

This is Part 2's per-player pool ("a target the healer cannot reach") expressed on the
time axis, and it is *exactly* what makes the curve representation earn its place: the
enemy pool is not the buttons they own, it is the buttons they can press **in this
window**. A team can hold six answers and have none of them reachable.

Part 5's globals-denied criterion is the same quantity seen from the attacking side. The
graph makes them one number: **a go's quality is how far it pushes the defender's
reachable pool toward zero.**

The reaction-time half of the source's point (0.5–2s of human lag) is **not adopted** —
see section 11.

## 8. Dynamic CDR — a real blocker, and a new one for this project

`[adds — and it is a gap, not a feature]`

> "Modern WoW abilities rarely have static timers. Passives, resource spenders, and
> talents constantly reduce cooldowns during live play (e.g., a 60s CD becoming a 38s
> CD)."

The database holds **base** and **talent-modified** cooldowns
(`ModuleSpellReferenceService::effectiveCooldown()`), which covers the flat and
percentage modifiers a build applies. It does not hold **spend-driven** reduction — "each
cast of X takes 3 seconds off Y" — because that is a function of a rotation executed at a
rate, and no rate is recorded anywhere.

This is the honest limit of any timeline built from this data, and it biases in one
direction: **every period the engine computes is an upper bound.** Real goes come round
sooner than the graph says, by an unknown amount that differs per spec.

Filed in `knowledge-gaps.md` and as C12 in `arena-open-questions.md`. The archive could
in principle measure real inter-cast intervals per spec across the 689 matches, which is
the one way to close it without new data.

## 9. Execution as a parameter, not a level

`[confirms Part 3, extends Part 15]`

> "High-level teams trade 1-for-1 efficiently. Mid-tier teams panic under high pressure
> and overlap defensives (e.g., pressing both *Karma* and *Pain Supp* for the same
> push). **The Need:** Introducing an 'Execution Efficiency/Rating Slider'."

Part 3 already says overlap is the dominant loss condition, and Part 15's revision says
these are **error rates that fall, not stages that are passed** — the world champion
makes the 1800 error in a grand final. Making the error rate an *input* is the natural
consequence of that revision, and the model did not draw it.

It is also the answer to what ChrisO asked this for: a guide written against "both teams
trade cleanly" is a different document from one written against "the defender overlaps
roughly once a go", and Part 0 already requires a guide to **say which use it is written
for** (drill-shaped versus plan-shaped). The parameter makes that choice explicit and
mechanical rather than a tone the author picks.

`[HYP]` on any specific overlap *rate*. That a lower-rated defender overlaps more is
[OBS] from Part 3; how often, at what rating, is measured by nothing.

## 10. Dampening is absent, and that is the significant silence

`[the source is silent — Part 12 says it should not be]`

Part 12:

> "in the current patch it scales defensive cooldowns too, which means **the value of
> every answer in the enemy pool decays over the round**. A pool that is sufficient at
> minute two is not sufficient at minute ten."

A curve model without dampening is a model in which both lines are stationary, and Part
12 says one of them slopes the whole time. Dampening is also the mechanism behind "which
side does the clock favour" (Part 12) — one of the five questions Part 16 requires
answered before any steps are written. **An engine built from this source must add it;
the source will not prompt you to.**

## 11. What else the source does not support

None of these are failures of the source, but nobody may later cite it for them:

- **Positioning** (Part 14.1). The representation structurally cannot hold it — a curve
  has no geometry — and several of Part 5's simultaneity claims *depend* on positioning.
- **Comms** (Part 14.2). Same.
- **Mana**, and attrition as a win condition.
- **Who the kill target is.** Follows from section 3: with no per-player pool the source
  cannot express Part 2's re-derivation of the target every go.
- **Human reaction time.** Proposed as a 0.5–2s lag, but no source measures it and
  applying an invented constant to every trade would move every number on the page by an
  amount nobody can check. The *structural* half of the same point — a defender in CC
  cannot press anything — is adopted (section 7); the *latency* half is not.
- **What the offence curve is in units of.** Never stated. Given Part 11's blocker it
  cannot be damage, so it can only be an **availability** curve: what is off cooldown, and
  how much of the enemy team it reaches. Anything drawn as though it were damage is
  fabrication.
- **Anything empirical.** No match, no measurement, no player, no VOD. Nothing in this
  file is evidence about the game; it is evidence about how the model could be drawn.

---

## Distillation log

| Date | Pulled | Landed in |
|---|---|---|
| 2026-09-23 | Two-curve representation on a shared time axis, tiered [DER] | `arena-structure.md` Part 19 |
| 2026-09-23 | "Mathematically guaranteed" rejected against Part 11's damage-model blocker | `arena-structure.md` Part 19, kill-window wording |
| 2026-09-23 | Per-player, not per-team, defence curve — source corrected by Part 2 | `arena-structure.md` Part 19 |
| 2026-09-23 | Hardcoded 30s period corrected to a per-comp derived T | `arena-structure.md` Part 19; confirms Parts 7 and 17 |
| 2026-09-23 | Priority Cascade as a ranked answer list over Part 3's one-to-one lookup | `arena-structure.md` Part 19.2, tiered [HYP] |
| 2026-09-23 | CC stagger as the time-axis form of Part 2's reachability rule | `arena-structure.md` Part 19.1 |
| 2026-09-23 | Spend-driven CDR named as an unmodelled term; periods are upper bounds | `knowledge-gaps.md`, `arena-open-questions.md` C12 |
| 2026-09-23 | Execution as an input parameter rather than a rating band | `arena-structure.md` Part 19.3; extends Part 15 |
| 2026-09-23 | Dampening's absence flagged as the source's significant silence | `arena-structure.md` Part 19.4; confirms Part 12 |
