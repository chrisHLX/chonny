# Arena Structure — A Framework for Reading Data and Authoring Modules

This file is a standing reference, not a findings log (see `knowledge-gaps.md` for
that). It exists to answer one practical problem: matchup-specific knowledge (the
kind needed to actually push past 2100+ toward Gladiator) is where public guides
run dry — generic "here's what Fade does" content is easy to find, "here's what you
do in the third go of an arena vs. a hunter/paladin comp when your trinket is down"
is not. This framework is the lens for building and auditing that kind of content:
both when authoring a matchup module by hand, and when deciding which raw spell-data
facts (cooldowns, charges, DR categories) actually matter and why.

It has three parts that combine:

- a **match structure** (what actually happens, moment to moment, in an arena game)
- a **decision model** (how to tell, from state alone, whether a go is a kill, a
  bait, or a mistake — Parts 2–9)
- a **rating ladder** (what limits a player at each bracket from executing the
  structure well)

None alone is enough — the structure without the ladder is just a description of a
video game; the ladder without the structure is an abstract skill list with nothing
to hang it on; and both without the decision model produce content that describes
what a go *is* without ever saying whether a given go was *correct*.

**A note on layering.** Parts 1–9 are patch-durable structure: they specify
*variables*, not values. Which comps are strong, what a given spec's burst profile
currently is, and which talents are live are all volatile inputs that belong in the
data layer (Part 12) and must come from a current source or a real high-rated
player. Nothing in this framework should be read as a claim about the present meta.

---

## Part 1 — The Go / Anti-Go Cycle

This is the player's own framework, from direct Gladiator-level experience, not
sourced from a guide. Every arena match, regardless of comp or bracket, reduces to
this cycle:

1. **Opening** — pre-commitment maneuvering. Positioning, initial poke/pressure,
   scouting the enemy's opener, calling a provisional kill target. Nobody is
   all-in yet.
2. **The Go** — one team commits: stacks CC on the enemy kill target (usually the
   healer) and layers offensive cooldowns to try to force a kill inside that CC
   window. This is the "Kill Window" concept surfaced in the arena-structure
   research last session — a go is not "using a cooldown," it's the coordinated
   stacking of CC + burst aimed at ending the game right now.
3. **Anti-Go (defense)** — the team being gone on responds: defensive cooldowns,
   externals for the kill target, trinket/CC-breaks, peels, repositioning to a
   pillar. Surviving a go without spending everything is itself a win — it banks
   cooldown advantage for the counter-go.
4. **Reset** — both teams are off (or nearly off) their committed cooldowns.
   Pressure drops, positioning resets, information gets reassessed (what did I
   learn about their CDs/trinket/DR state from that exchange?).
5. **Repeat** — go, anti-go, reset — until one of two end conditions is hit:
   - **A go succeeds** — someone dies.
   - **Resource attrition** — healers run out of mana before either team lands a
     clean go. This is a real, distinct win condition, not a failure state to
     avoid — some comps/strategies deliberately play toward it.

**Why this matters for reading raw data:** a cooldown's real value is contextual to
*which phase it's used in*, and canonical module prose should say so explicitly
rather than just listing cooldowns. Concretely, from the Disc Priest Oracle module
already seeded:

- Pain Suppression's "2 charges" isn't baseline — the raw data shows a **1-charge,
  180 second (3 minute)** base cooldown (`data/spelldata/filtered/priest/discipline.txt:5-14`).
  The second charge comes specifically from **Protector of the Frail**, the same
  talent that grants the per-shield-cast CDR the module already mentions. At a
  180s base, this is not a per-go resource to budget between "this go vs. the
  next" the way Fear (30–40s) is — it's closer to a once-or-twice-a-match
  resource whose real availability is a product of how much you've been
  shielding *beforehand*, not of tempo-planning within a single cycle. That ties
  directly to the "on-target mitigation is free" point below: shielding is
  free-economy *and* it quietly banks faster access to your biggest defensive at
  the same time — a real synergy the module doesn't currently name.
- Weal and Woe's shield-absorb stacking (see `knowledge-gaps.md`) is most valuable
  immediately *after your own go* (Evangelism/Penance burst has just stacked it),
  not during a defensive anti-go — a detail invisible if you only look at the
  spell's numbers in isolation.
- The dictated Fade/Death matchup notes are themselves already implicitly
  organized by anti-go decisions ("the hunter is baiting your Death before
  trapping you") — this framework is a way to make that structure explicit and
  repeatable for the *next* matchup module, instead of it only existing
  informally in one dictation.

### 1a. The cycle is a state machine, not a sequence

The five phases above describe what a match *looks like* from the outside. They are
not a script. In a real game the phases do not proceed in order — a reset can
collapse straight back into a go, an anti-go can become your go without a reset in
between, and openings recur whenever both teams disengage.

What actually determines which phase you are in is **state**, defined in Parts 2–3.
This matters for authoring: a matchup module written as a five-step sequence breaks
the moment a partner misplays or the enemy skips a phase. A module written as a
state table absorbs that, because "our Feral lost uptime" is not a broken script —
it is a transition into a different state that the table still covers.

---

## Part 2 — The Kill Conjunction

A go is not a quantity of pressure. It is a **conjunction** — several conditions
that must be true *simultaneously* for a kill to be possible:

1. **Control on the enemy healer**, long enough to matter
2. **Your damage available** — the burst that makes the window lethal
3. **The target's outs are gone** — trinket down, defensives down, no escape

If any single term is false, there is no go. There is only pressure.

Two consequences, and they are the practical core of the whole framework:

- **Defending: you only have to falsify one term.** You do not out-heal a go — you
  break it. Peel the stun, make the target's trinket available, deny the burst,
  reposition out of the damage. Whichever removal is cheapest wins. This is a
  strictly better defensive model than "use defensives earlier," because it tells
  you *which* button, not just *when*.
- **Attacking: terms do not substitute.** More damage does not compensate for the
  healer being free. A go missing a term is not a smaller go; it is not a go.

**Hard vs. soft terms.** Some components are strict requirements (without healer
control there is simply no kill window). Others are preferences (you would like
your burst, but sustained pressure could grind it). Per matchup, knowing which of
your terms are hard tells you which absences mean *don't go* versus *go slower*.

**Terms substitute across teammates, not across categories.** A team with two
independent sources of healer control has a far higher go frequency than a team
with one — same total cooldown count, very different window rate. This is a real
comp-evaluation axis and it is invisible from a flat cooldown list.

---

## Part 3 — The Four States

Applying Part 2 to both teams at once yields four states. These are the states a
matchup module should be organized around.

| State | Condition | What the round is |
|---|---|---|
| **Neutral** | Neither team can assemble a complete conjunction | Setup phase. Strip, scout, position. Do not attempt a kill. |
| **Only us** | Our conjunction is satisfiable, theirs isn't | The window. It expires on its own. Cash it. |
| **Only them** | Their conjunction is satisfiable, ours isn't | Defensive phase. Remove one of their terms — cheapest available. |
| **Contested** | Both satisfiable | Whoever commits first usually wins. These genuinely are coinflips. |

The **reset** phase of Part 1 is the transition machinery between these states, and
its real function is *information update* — see Part 9.

**Attrition, structurally defined.** The mana/resource win condition flagged in
Part 1 is not a separate strategy layered on top of the cycle. It is the terminal
behaviour of a round that stays in **Neutral**: if neither team's conjunction ever
becomes satisfiable, the round resolves on resources by default. That gives
"resource attrition" a real home in the model — it is the outcome of a persistent
state, not an unrelated third thing. (See Open Questions on whether this earns a
Concept.)

---

## Part 4 — The Cooldown Ledger

Part 1 already notes that surviving a go banks cooldown advantage. This section
makes that computable, because the banked advantage is the single most commonly
wasted resource in the game.

**The ledger is not a sum.** "We have six cooldowns, they have four" is not a
meaningful statement. The ledger is the two-line boolean of Part 3: can we assemble
a conjunction, can they.

**Surviving a go creates a temporary asymmetry with an expiry date.** Their
components are spent and rolling; yours are not. That is the **only us** state, and
it is the payoff for everything you spent surviving.

**The window's duration equals the return of the *last* missing term of their
conjunction — not the first.** Their short cooldowns coming back does not reopen
their window; the binding constraint is the slowest component. In practice this
window is substantially longer than it feels, which is why players habitually
under-use it.

**An uncashed window leaves you net negative, not neutral.** You spent defensives,
externals and resources surviving. They spent cooldowns attempting. If you convert
nothing, both sides return to cooldown parity — but you are down the resources you
burned and further into the attrition clock. This is the precise, teachable reason
that "we survived that" so often precedes losing the round.

**Your go frequency is governed by the longest-cooldown term of your own
conjunction.** Not by your burst, not by your CC — by whichever comes back slowest.
That number is how many genuine attempts a round contains, and therefore what a
wasted attempt costs. Three windows in a round means a go spent on a full-outs
target burned a third of your kill chances.

---

## Part 5 — Pressure: Strip and Kill

Damage has exactly two legitimate jobs. Damage doing neither is padding, and
padding is negative rather than neutral — it costs positioning and resource, and it
trains the enemy healer's attention onto pressure that was never going to kill,
which makes the real go easier to read.

**Kill** — damage landing inside a satisfied conjunction.

**Strip** — making a term of *their* conjunction false, or forcing the resources
that would let them defend one of yours. Strips are not all the same thing:

- **Cooldown strip** — forced a defensive. Value = the length of what you forced.
- **Resource strip** — healer mana, GCDs spent healing rather than setting up.
- **Positional strip** — their healer had to move, break LOS with their own team,
  or leave a pillar. Frequently the most valuable and the least tracked.
- **Attention strip** — they are watching the wrong health bar. Enables the real go.

**Strips have timing value, not just occurrence value.** Forcing a long defensive
is worth almost nothing if your next window is three minutes away and worth the
round if it is in fifteen seconds. The same play has wildly different value
depending on ledger state — which is why "just do damage" is wrong even when the
damage is real.

**The bait go.** A committed-looking go with no kill intent, made specifically to
extract a trinket or a defensive, timed so the extracted thing cannot return before
your real window. Pure strip, no kill, and frequently higher value than a genuine
attempt. It also does double duty as scouting (Part 9).

**Threat is pressure even when damage isn't landing.** A target that must be
respected consumes enemy attention and positioning. This is why maintaining a
credible position matters through stretches where you are not connecting.

---

## Part 6 — Disengagement Cost, Uptime and Kiting

This is the per-spec variable that most generic advice ignores, and the reason
kiting advice written for one spec actively harms players on another.

**When you leave a target you do not return to neutral — you return to whatever
your economy decayed to.** The cost decomposes into five components, and players
typically only feel the first:

1. **Decay** — what falls off while you are away (bleeds, stacks, buffs).
2. **Rebuild** — time from re-engagement to full output. Usually the largest
   component and the most hidden, because nothing on screen displays it.
3. **Resource** — capping, overflow, wasted regeneration.
4. **Opportunity** — what the enemy accomplished unopposed.
5. **Cooldown drift** — your cooldowns coming up while you are out of position, so
   they either sit unused or get spent at low value on return. This converts a
   scheduled window into a wasted one.

**Three states, not two.** Players model themselves as either *pressuring* or
*safe*. There is a third: the **dead zone** — not threatening enough to force
respect, not disengaged enough to be irrelevant. Disengaging usually lands you
there, and it is where you are *most killable*, because you can be attacked without
consequence. Good teams swap onto whoever just entered a dead zone; it reads as
random and is not.

**A DPS in a dead zone is the worst possible object to heal** — the healer spends
real resources sustaining something that generates nothing. This is the healer-side
signature of the problem and is worth stating explicitly in healer modules.

**The cost is non-linear: every spec has a free disengage duration.** Below it,
nothing decays and nothing needs rebuilding, so positional defence is close to
costless. Above it, you pay a full reset. Knowing that threshold per spec is
directly actionable and belongs in the data layer.

**Planned vs. reactive disengage differ enormously at identical duration.** Planned:
apply everything, dump resource, *then* leave. Reactive: caught mid-rotation, full
cost. Much of the skill gap on expensive-disengage specs is simply that stronger
players pre-load their disengages. Likewise the dead zone can be shortened from
both ends — pre-load before leaving, re-engage with setup already in hand rather
than arriving and only then beginning to build.

**Kiting is an asymmetric trade with four terms**, not two: your damage lost, their
damage lost, your resources spent, their resources spent. The fourth term is what
usually flips the conclusion, and it gives a clean test:

> **Does my disengage force them to spend something?**

If they must burn a gap-closer or a cooldown to maintain contact, you converted
uptime into their cooldown — that is a strip, and often an excellent one. If they
can simply walk after you, it is pure loss and the health bars are lying about it.

A fifth term applies where the round clock is meaningful: **time has a sign.**
Extending the round favours whichever team's kill conditions improve with time. If
time does not favour you, expensive defence costs twice.

---

## Part 7 — Defensive Taxonomy

Defensives are a ladder, not a binary:

- **Free** — mitigation usable while fully on target. No offensive cost.
- **Cheap** — brief positional plays: one pillar clip to break a specific cast, a
  short LOS. Stays inside the free disengage duration.
- **Expensive** — sustained kiting, full disengage, dead-zone entry.
- **Convertible** — mitigates *and* generates, or punishes being attacked. Ahead of
  everything else whenever available.

Two rules follow:

**Ladder to the threat, cheapest first.** The common error is opening with your
largest defensive against pressure that was never a kill attempt — now your real
answer is gone for the window that actually matters. Your biggest defensive is a
term-removal tool for a *complete* enemy conjunction. Spending it on incomplete
pressure is the defensive equivalent of padding.

**A defensive's value equals what it denied.** Against padding, zero. Against a
satisfied conjunction, it removed a term and ended the go. Same button, wildly
different value, and the difference is entirely ledger-reading.

**Positional defence requires a named threat.** If you cannot say what specific
thing you are avoiding, you are not defending — you are leaving, and paying full
disengagement cost for nothing.

---

## Part 8 — The Opener as a Distinct Case

The opener deserves its own treatment because high-rated play there looks
irrational (commit everything immediately) and is not.

- **It is the only moment your conjunction is guaranteed complete.** Every later go
  requires correctly tracking enemy state and manufacturing setup against active
  resistance. At the gates you know with certainty what you hold. Highest-confidence
  window in the round, and the only free one.
- **Setup is uncontested.** Pre-positioning and opening CC land from an unpressured
  state. Later, the setup CC must beat a peel, positioning and awareness. That is an
  enormous discount on the most expensive term of the conjunction.
- **It is the highest-EV bait available.** Their trinket is guaranteed up, so there
  are two acceptable outcomes and no bad one: it kills, or it strips a trinket.
- **Cooldowns depreciate against the round clock.** A cooldown spent at second five
  returns for a second attempt; one held for ninety seconds bought one attempt where
  you could have had two. Strong players maximise *number of windows*, not the
  quality of any single one — because each go is partly a coinflip on execution and
  reads, so more attempts is straightforwardly better. The "perfect moment" often
  never arrives, precisely because good opponents are preventing it.
- **First commitment sets the cycle.** Whoever goes first makes the other team react
  on their terms, and the lead compounds: you are always the one with cooldowns
  returning while they are always the one spending.
- **The risk is at its minimum.** The counter-go faces a *complete enemy defensive
  package* — full trinkets, full defensives, full peels. Opening hard is not
  reckless; it is taken at the moment the downside is smallest.
- **You fight the phase your comp wins.** If your team's strength is a coordinated
  burst window rather than attrition, a long round drifts toward whatever the other
  comp is better at.

**The execution caveat, worth stating in modules:** an opener that is 70% clean
forces less and costs exactly the same. A loose CC chain or a slow swap spends the
round's best window for a partial strip. This is why the play feels wrong when
attempted at lower brackets and right when observed at high ones.

---

## Part 9 — Observable vs. Hidden Variables

The genuine difficulty of arena is not that the conjunction has many terms. It is
that **several terms are unobservable**, so you are not solving an equation — you
are playing against a probability distribution over hidden state.

**Hidden outs (break your kill)**

- CC-breaks beyond the trinket: class-native removals, healer-provided removals and
  protections, talent-gated breaks, racials, immunity windows where CC never lands
- Damage avoidance: immunities, unexpectedly large reductions, **pre-applied
  absorbs** (cast during the calm, invisible before the go), positional escapes,
  pet/guardian mitigation
- External saves: healer externals, DPS-provided externals, off-heals from
  non-healers, and cheat-death effects — the most common reason "provable" math is
  wrong

**Hidden damage (breaks your defensive plan)**

- Talent-dependent burst — same spec, radically different profile, build not visible
- Gear and trinket procs
- Racial cooldowns
- **Non-cooldown-gated burst** — proc-driven damage that no ledger can track
- Execute thresholds — survival computed at 40% is wrong if the profile changes at 20%
- Ramp/stacking mechanics where window damage is nonlinear, not rate × time
- **Cooldown reduction** (haste, resource spending, talents). The nastiest one: it
  does not break your kill math, it breaks your *clock*, corrupting every downstream
  window calculation

**Unobservable state**

- Precise enemy DR across the full team, under pressure
- Enemy resource pools
- Cooldowns never witnessed being used — absence of evidence is not evidence of absence
- Talents and gear
- What they know about *your* state — the asymmetry runs both ways

**Non-state variables**

- Terrain and pillar geometry, which changes what positional defence costs
- Execution variance and latency — the lethal go where a GCD slipped
- Enemy decision quality (see Part 10, Tier 2)
- The round clock, where healing scales down over time — the same conjunction
  becomes satisfiable later that was not earlier

**How strong players actually handle this: they force the information.** This
upgrades the bait go from Part 5 — it is not only a strip, it is **scouting**.
Commit enough that they must answer; their answer reveals which hidden terms were
true. Trinket up or down, which defensive they reach for first, whether an external
exists on that team. The real go is then made against known state instead of
guessed state. This is also the deeper function of the **reset** phase in Part 1:
reset is where the model of hidden variables gets updated.

For content, the observable/hidden split is directly a schema decision: observable
terms are what tooling tracks and computes; hidden terms are what must be **taught
as a distribution** ("at this bracket, this spec runs this build roughly this
often"). The second category is precisely why a coaching product beats a calculator.

---

## Part 10 — Evaluating a Go: Deterministic vs. Branching

The sharpest thing a module can say to a player is whether a given go was math or a
bet. Two tiers:

**Tier 1 — deterministic.** At the moment of commitment the target has zero live
options: trinket down, no escape, healer's CC-breaks down, no external available.
Then survival is not a probability, it is arithmetic — damage output over the CC
window versus remaining health. Damage side: coefficients × casts possible in the
window (bounded by GCD/cast time). Mitigation side: cooldowns, charges, DR state.
All of this is data the project already has or can get. "This was not a gamble, it
was math, and here is the number" is the highest-value sentence in a matchup module.

**Tier 2 — branching.** Real counterplay exists (an escape is up, the healer's
trinket is up, a peel is possible). This splits again:

- **Worst-case / minimax framing** — assume optimal opposition: does the best
  available branch save them? Still fully computable from spell data, no behavioural
  modelling needed. This is the more useful framing for teaching, because it tells a
  player whether a go is sound *regardless* of what the opponent does. A go that
  only works if the opponent misplays is a bet, not a win condition.
- **Probabilistic framing** — given real players at this rating, how often do they
  actually take the save? Requires behavioural data from real matches (combat-log
  frequencies such as "trinket wasted", "failed to dispel CC"), which is a different
  data source than static spell data.

**Applied to defence, the same machinery answers "was the peel obligatory?"** Same
arithmetic, run before the go instead of after it. This is the version that has to
be pre-loaded into a player's head, because in the moment there is no time to
compute it. Module prose should therefore state preconditions, not verdicts: "this
rotation is only a kill if their trinket is already down — otherwise it is a bait,
not a real attempt."

**Known blocker for a real Tier 1 calculator:** static coefficient dumps are not
tied to a real character's actual stats. A working calculator needs either
typical-gear assumptions per rating bracket, or live combat-log data.

---

## Part 11 — The Rating Ladder

From `knowledge-gaps.md`'s companion research (ArenaCoach.gg, real combat-log
analysis — see that file's sourcing). Restated through the go/anti-go lens, and
extended with the state model from Part 3:

| Bracket | What actually breaks in the cycle | State-recognition failure | Primary skill |
|---|---|---|---|
| 1400–1600 | Anti-go fails outright — defensives used too late or not at all | Doesn't recognise **only them** until damage has already landed | Defensive usage |
| 1600–1800 | Goes are uncoordinated — burst without CC setup, cooldowns spent for no kill | Attempts a go with an **incomplete conjunction** | CC coordination |
| 1800–2100 | Goes are decided before they happen — whoever traded cooldowns better wins the next go | Fails to recognise and **cash the only-us window** | Cooldown trading |
| 2100–2300 | Execution inside the go/anti-go gets inconsistent under real matchup pressure | Misreads **hidden variables** (unknown outs, unexpected burst) and mis-costs disengages | Preparation / execution precision |
| 2300+ | Individual execution is no longer the bottleneck | Team holds **different conjunctions** — no shared kill target or shared window | Team synergy |

Read this way, the ladder is not a separate skill checklist — it is literally
"which part of the go/anti-go cycle is currently the weak link," and, in the third
column, *which state the player cannot see*. That reframing matters for diagnostics:
a trait ("you have a defensive, reactive mindset") is not fixable, because nobody
wakes up and stops being reactive. A state-recognition failure ("you play the same
way whether or not you are up on the ledger") is specific, falsifiable, and
teachable in one sitting.

This is also exactly why matchup-specific content is where public knowledge runs
dry. Everything below 2100 is teachable in general terms (defend earlier,
coordinate CC, don't waste cooldowns). Past 1800–2100 the content needed is "here
is what cooldown trading looks like *in this matchup*, here is what a go looks like
*against this comp*" — which only exists in a real 2400+ player's head, which is
why the expertise-capture pattern (dictation → module) is this project's answer.

One thing the framework changes about that dependency: the **structure** of a
matchup module (Parts 1–9) can be authored without an expert. Only the **values**
require one. That reduces what has to be extracted per dictation and gives the
dictation an agenda instead of an open-ended prompt.

---

## Part 12 — Implications for Authoring Matchup Modules

Structure content around the state table, not a flat ability list and not a rigid
five-step sequence.

**Per matchup, answer the three planning questions first:**

1. **Who dies?** The target with the fewest outs, whose defensive profile your
   damage beats, and whom their healer can least reach. Modules currently say
   "call a provisional kill target" without saying how — this is the how.
2. **What is our conjunction against that target?** Which CC on which player, which
   cooldowns, in what order. Which terms are hard and which are soft.
3. **What breaks it, and can we pre-strip those?** This is what "setting up a go"
   actually means: spending the neutral phase manufacturing a state where their
   conjunction is incomplete and yours is not.

**Then the state table:**

- **Opening** — what do you do before either side commits? What does the opener
  bait, specifically, and what does their answer tell you?
- **Neutral** — what are you stripping, and which strip type matters most here?
- **Our go (only us)** — the sequence, the preconditions, and the Tier 1/Tier 2
  verdict: is this a kill or a bait?
- **Their go (only them)** — which term of their conjunction is cheapest to falsify
  in this matchup, and what is the ladder of defensives in order?
- **Contested** — does this matchup favour committing first, and why?
- **Reset / information read** — what did the exchange reveal about hidden state?
- **The attrition path** — does this matchup resolve by kill or by mana war? Worth
  asking the dictating player explicitly; it was not asked in either pilot module.

**Per-spec, capture the uptime economy** (see Part 13) — it is the variable that
determines whether the generic advice in Parts 6–7 helps or actively harms.

---

## Part 13 — What the Data Layer Must Supply

The framework specifies variables. These are the fields that make it computable.
Small, stable in shape, volatile in value — exactly the split that lets a
non-expert maintain the structure while an expert or a live data source supplies
the numbers.

**Per spec:**

- Damage profile: sustained / ramping / cooldown-gated / proc-driven
- Disengagement cost: what decays, and the rebuild duration to full output
- Free disengage duration (the threshold below which leaving is ~free)
- Defensive classification: which cooldowns are free / cheap / expensive / convertible
- Conjunction contribution: which terms this spec supplies to a team's kill package
- Hidden-variable profile: which outs and burst sources are talent- or gear-gated

**Per comp:**

- Kill package composition, and which terms are hard vs. soft
- Redundancy of control sources (drives window frequency)
- Longest-cooldown term (drives go frequency per round)
- Whether time favours this comp (drives the sign on extending the round)

**Per bracket (distributional, from behavioural data):**

- How often a given spec runs a given build
- How often the correct save is actually taken (Tier 2 probabilistic framing)

---

## Open Questions

**Concepts/Axes — partially resolved.** The rating ladder maps cleanly onto WoW's
existing `Proficiency` tiers (see `knowledge-gaps.md`) — low-risk to formalize
whenever proficiency `outcomes` get filled in.

The go/anti-go cycle still does not map onto a Concept or Axis the way "Positioning"
or "Crowd Control" do — it is a temporal scaffold, not a skill dimension, and
probably should not become one artificially. However, Part 3 gives **resource
attrition** a structural definition it previously lacked: it is the terminal
behaviour of a round that never leaves the Neutral state. That is a stronger case
for representing it, but it also suggests the missing Concept may not be "mana" —
it may be something closer to *tempo* or *state control*: the ability to move the
round out of Neutral in your favour. Flagged, not acted on.

**Two candidate Concepts surfaced by this framework that have no current home:**

- **Uptime economy** — Part 6 is not covered by `Positioning` (which is about where
  you stand) or `Cooldown Management` (which is about ability cooldowns). The cost
  of disengaging is its own dimension and it is spec-specific.
- **State recognition** — Part 11's third column. Arguably the single most
  diagnostic-relevant dimension in the whole framework, and currently unrepresented.

**Validation before further build-out.** The cheapest test of this framework is not
architectural: take one matchup, write its state table, and check whether the model
correctly diagnoses ten of the player's own losses in that matchup by naming which
state was misread. If it does, the framework justifies tooling. If it does not, no
amount of schema or addon work rescues it.

**Not yet captured anywhere:** terrain. Part 9 lists pillar geometry as a variable
that changes the cost of positional defence, but nothing in the project models maps
at all. Possibly out of scope; noted so the omission is deliberate.
