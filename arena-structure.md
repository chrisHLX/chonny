# Arena Structure

The working model of what an arena game *is*, used when authoring a guide, scoring a
plan, or deciding which spell-data facts matter and why.

This is **version 2**, rewritten 2026-09-22. It replaces a longer v1 that was written
mostly from reasoning. Two things changed it: the player's own line-by-line review of
v1 (he had been playing again, and corrected it from experience), and a full
transcript of Calvish's BlizzCon 2026 AWC Grand Finals breakdown — the first outside
account of arena this project has had from someone who just won a world title with
the plan he is describing.

Sources are preserved verbatim in `docs/arena/sources/`:

| File | What it is |
|---|---|
| `arena-structure-v1-2026-09-18.md` | v1, unedited. The reasoned framework. |
| `arena-structure-v1-reviewed-by-chriso.md` | v1 with the player's inline corrections. **The primary correction record.** |
| `kalvish-blizzcon-2026-awc-finals.md` | Calvish's post-tournament breakdown, incl. a game-by-game VOD review of the grand final. |

---

## How to read this file

v1's real flaw was not that it was wrong. It was that reasoned claims and observed
claims were written in the same confident voice, so nothing in it could be checked.
Every substantive claim here carries a tier:

- **[OBS]** — observed. Stated by a top player about real games, or by the project's
  player about his own play. Take it as true.
- **[DER]** — derived. Follows from game mechanics or data this project already holds.
  Checkable without new evidence.
- **[HYP]** — hypothesis. Reasoned, plausible, **untested**. Never assert one in a
  guide as if it were a fact. Each one names the test that would settle it; those are
  collected in `arena-open-questions.md`.

Where the two sources disagree, both readings are given. Do not resolve a
disagreement by picking the one that fits the model better.

---

## Part 0 — Scope: what this model can and cannot deliver

**The deliverable is a general game plan and playstyle per talent build and comp — not
an account of any particular moment.** [OBS] This is the player's own scoping call
after answering the first round of open questions, and it is the most important
constraint in this document:

> "Each state or situation is unique and we can't answer everyone for every situation.
> But I think there is information that we can say is almost 100% of the time correct,
> like what a long CC chain looks like or a good go looks like. What tools a team has
> available and how those tools could be used to counter a strategy. But as for
> individual outplays in the game, they seem to be embedded inside a context that we
> don't really have access to all the information."

So the model is deliberately split:

| Reliably authorable | Out of reach |
|---|---|
| What a long CC chain looks like for this comp | Whether to send *this* chain, in *this* round |
| What a good go looks like — its shape, order, simultaneity | Whether this specific go beats this specific opponent |
| What tools a team holds, and what each one counters | Individual outplays and reads |
| Which of their tools must be gone before a go is a kill | Whether they will press it |
| The playstyle a given talent build implies | Moment-to-moment adaptation |

A guide should live entirely in the left column. When it strays right, it starts
asserting things no data supports and the reader can tell.

**Who it is for, and what changes with rating.** [OBS] The player is 1700 on alts and
Gladiator on his mains, and reads the difference as mechanical, not strategic:

> "I'm 1700 on other toons, but I think that's a consequence of mechanics. Which makes
> me think that the analysis and reflection we are doing is for the difference between
> 2200 → multi-glad → rank 1. I think we are exploring the fringes of my understanding
> on my mains."

An earlier draft of this part read that as "the model does not help below 2000." **That
was wrong, and the player's correction is the important one:**

> "A better model of the go does help mechanics — it gives players something to
> practise."

The inference does not follow. That the binding constraint at 1700 is execution says
nothing about whether a model helps, because **execution is always execution *of*
something.** "Get better mechanically" is unactionable. "Land Maim and Scatter on the
same global, then Cyclone, and do it every 30 seconds" is a drill. The model is what
converts the first into the second, and a player who does not have it has nothing
specific to repeat.

There is direct evidence for this at the very top, in the one place the transcript shows
execution improving inside a single event. Calvish's team did not practise harder between
games 3 and 4 of the final — they **wrote a better opener**, and then executed better
every game afterwards: *"we do some variation of it every single game after the sewers
game. So we just immediately levelled up."* A specific plan was the practice target.

So what changes with rating is **what the model is used for**, not whether it applies:

| | Lower | Higher |
|---|---|---|
| The model supplies | a drill list — a named sequence to repeat until it is automatic | a plan, weighed against the enemy's plan |
| The question it answers | *what should I be practising?* | *is this go correct here?* |
| The binding constraint | executing the sequence | choosing between sequences |

This also lines up with what the framework already says elsewhere and should have been
read against: Part 11's deliverable is **memorisable thresholds, not a calculator**, and
Part 6 explicitly tiers advice by player, with *usage rate beating optimality* where
recognition is missing. Both are statements that the lower-rated reader is served by
something concrete and repeatable — which is exactly what a written go is.

**What a guide should still do is say which use it is written for.** A drill-shaped
guide and a plan-shaped guide are different documents even for the same matchup, and
the mistake is shipping one while implying the other.

**All three product artifacts belong to the comp builder.** [OBS] The per-matchup
opener, the memorised-threshold sheet, and the build-per-matchup recommendation are not
three pages — they are three outputs of one thing, and the comp builder is where they
live.

---

## Part 0.1 — Format

**This is a coordinated-3v3 model.** [OBS] Everything below assumes three players who
share a plan and talk. Calvish's entire account is tournament 3v3. The project
player's own account is 3v3 with regular partners.

**It is explicitly not a Solo Shuffle model, and applying it to shuffle has been
observed to lose games.** [OBS] From the review:

> "In 3s a Hunter should be trapping off CD and the priest should be following with
> fear. If you're getting ready to do that in a shuffle and the Hunter never traps you
> just get exposed for 'bad positioning'. But really it's just you're trying to play a
> different game."

He lost six straight shuffle rounds trying to force the win condition (land the fear)
that the 3v3 model prescribes. The model told him to set up a go; nobody was
setting up with him. A shuffle-specific model does not exist yet, and the comp builder
currently only works for 3v3 anyway. [OBS] Until one exists, a guide written from this
framework should say which bracket it is for.

---

## Part 1 — The cycle

Every match reduces to a repeating cycle: **opening → go → anti-go → reset → repeat**,
ending when a go lands or when resources run out. [OBS] This is the player's own
framing from direct experience and it is how Calvish narrates every game — he
describes matches almost entirely as a sequence of goes, with the state of both teams'
buttons between them.

Two things v1 got wrong about it:

**It is a state machine, not a sequence.** [OBS] Phases do not proceed in order. A
reset collapses back into a go; an anti-go becomes your go with no reset between.
Calvish's grand final has goes arriving back to back for minutes, and also has a
90-second stretch where nothing happens because one team has nothing to press.

**A go does not have to be a kill attempt to be correct.** [OBS] v1 defined a go as
"stacking CC + burst aimed at ending the game." Both sources contradict a strict
reading. Calvish repeatedly uses offensive cooldowns *defensively* — "this go
happened like two or three times in the series where I'm bombing WS and it looks like
a bad bomb, but I'm kind of doing it defensively" — and the player's own correction
is that a go can be aimed at "forcing cool-downs that make the next go more likely to
succeed."

So: a go is a **commitment of scarce resources aimed at changing the answer pool**
(Part 2). Killing is one way it pays. Emptying their buttons is another. Interrupting
their go is a third.

---

## Part 2 — The answer pool is the currency

**This is the single most strongly corroborated idea in this document, and it should
be the first thing any guide is organised around.** [OBS]

The player arrived at it independently in September:

> "Other players don't just die, unless AFK or lagged out — which makes me think the
> trinket doesn't matter as much if they have no defensives left."

Calvish says a version of it in almost every kill of the grand final, without ever
naming it as a principle:

> "They don't have anything that can actually save them when they trinket right now."
>
> "W has trinket evasion, but it's hard to trade these in a way that will save both of
> you... we can kill WS through evasion if we have extended CC on JT."
>
> "He has trinket, but he doesn't have anything to trinket into. So even if he does
> save W, he also can just die instantly."
>
> "This can happen to both teams — you can have trinkets, but if you don't have
> anything to press, it's so easy to just die on the spot."

**The rule.** Track, per enemy player, **the list of buttons they still hold** —
trinket, personal defensives, healer externals, escapes, immunities. A kill is not a
damage calculation against a health bar; it is a damage calculation against a *list*.
The trinket is not the currency, because a trinket only buys time to press something
else. A trinket with nothing behind it is a two-second delay.

**Consequences, all [DER] from the rule:**

- **"Forced a trinket" is not by itself a success.** Its value equals what it cost
  them from the rest of the pool, plus what you can start before it returns.
- **The kill target is whoever's list is shortest right now, re-derived every go** —
  not decided once at the opening. Calvish swaps mid-go on exactly this basis: "Cub
  pre-roars him for the swap and we go JT... he has no skin, he has no trinket, he has
  no frenzy proc. As long as we get uptime here, he's for sure dead."
- **A go into a full pool is a strip, and should be planned as one.** A go into an
  empty pool is a kill, and should be planned as one. They are different plays with
  different acceptable risk.
- **The pool is per-player, not per-team.** Their healer holding three externals does
  not help a target the healer cannot reach.

**For guide authoring:** a plan whose steps do not reference the enemy's remaining
buttons is describing a rotation, not a go. Every go step should carry a
precondition in answer-pool terms ("only if the target has no trinket *and* no
personal"), and every defensive step should say what it is being saved for.

---

## Part 3 — Overlap is the most expensive mistake in the game

**Two answers spent on one threat.** Not a minor inefficiency — a state change that
removes your ability to play. [OBS]

Calvish, on losing game 1 of the grand final:

> "We overlap iron bark, trinket, trinket, cloak. If you have ever played a game like
> this, you know exactly how unplayable the rest of the game is going to be. **You are
> not allowed to play until you have these buttons back.** So you will constantly be on
> the back foot until the game ends."

His own team does it again in the final game — "we end up overlapping trinket and
cloak, which is obviously a mistake from us" — and he does not blame the teammate,
because from outside it looked like he was dying.

The player's version, from the review, is the mechanism:

> "If you don't use a defensive early when you can, what usually happens is you end up
> overlapping. You use a defensive too late and end up burning 2. But the mistake I
> see people make is not understanding how long the defensive lasts for vs how long
> offensives last for."

**Why this is worth its own part.** v1 treated defensive selection as a ladder to
climb cheapest-first. That is right, but it buries the actual failure mode. The
common loss is not "used the wrong defensive" — it is **two players independently
solving the same threat**, which is a communication and timing failure, not a
knowledge failure. Both sources describe the same cause: hesitating, then answering
late, so a second answer lands on top.

**[DER] The computable form:** overlap happens when answer *B* is committed while
answer *A* is still live and sufficient. This project holds every defensive's duration
and every offensive window's length. A plan can therefore state, per defensive, the
window it covers and who is *not* to press during it. That is a guide instruction no
public guide currently gives.

**[OBS] How good teams actually prevent it: partly not at all, partly by pre-agreed
triggers.** The player's answer, from teams that did it well:

> "It's ad hoc — but also, if the healer is CC'd and both offensives are running, there
> is a calc we can run, which is basically *which defensive should the player press when
> the enemy team presses Y*. That's the pre-agreed trigger, like the Barkskin example
> with Kingsbane."

So there is no general protocol to teach, but there **is** a per-matchup lookup, and it
is the single most concretely buildable thing in this document:

> **Enemy presses X → you press Y**, resolved per matchup, per target, from durations
> we already hold.

That table is what turns an ad-hoc judgement into a pre-agreed trigger, which is the
only mechanism either source describes for avoiding overlap. It requires no new data —
offensive window lengths come from `BurstGuideBuilder`, defensive durations from the
spell data — and it is the answer to "what does a defensives section of a guide
actually contain".

**[OBS] The corollary the player found:** an 8-second defensive against two stacked
20-second offensive cooldowns buys 8 seconds and then leaves you exposed for 12. The
right play is usually to use the defensive to *reposition safely*, then layer a second
kind of answer (peel, LOS) on top — not to stay on target through it. Staying on
target through a defensive, with no kill threat of your own, is the dead-zone error
(Part 9).

---

## Part 4 — Damage has three jobs, not two

v1 said damage does exactly two useful things: **kill** (damage inside a live kill
window) and **strip** (forcing a defensive, mana, position or attention), and that
everything else is padding. The player's margin note agreed: *"padding damage would
just get healed through."*

**Calvish identifies a third job, and in the Rogue/Mage mirror he says it is the
entire win condition.** [OBS]

> "If you're keeping the consistent damage up and the pressure in this mirror, it
> enables your druid to go and CC the enemy druid... you want to be doing as much
> damage as possible to both the rogue and the mage, because if you're doing as much
> damage as possible you're building momentum for your druid over the enemy druid,
> to where your druid has a window to shut down the globals on the enemy druid."

And the inverse, stated as a loss spiral:

> "If you're behind on healing as a druid, that means you can't go CC. And if you
> can't go CC, then you're losing even more pressure. It's the most snowball effect of
> all time."

**The third job: damage buys your healer's globals and spends theirs.** A healer who
must heal cannot CC, cannot dispel proactively, cannot reposition. Damage that gets
fully healed is *not* padding if it consumed the globals your healer then spends on a
Cyclone.

This resolves the tension between the two notes. The player is right that healed-through
damage contributes nothing **to that kill**. Calvish is right that it contributes to
**the next go**, through the enemy healer's global budget. Both are true; v1 only had
the first.

**[DER] It also explains a play both sources describe without connecting:** training a
target you are not trying to kill. The player: *"training them isn't a bad thing — it
makes it easier to land kicks and stops them from peeling."* Same mechanism, applied
to a DPS instead of a healer. **Pressure on any enemy converts their globals from
offence to defence.** That is the general statement.

**[HYP] Where this breaks down is genuinely unknown, and the player declines to guess.**
Asked what tells you which game you are in:

> "I don't know, because 'just do damage' might also just waste all your damage
> cooldowns. This is beyond my understanding... It's unclear because it depends on
> individual playstyles and comps. Kalvish says the pressure stops the druid from being
> able to CC — but what if there is a playstyle or talents that counter that idea? Then
> he loses BlizzCon and we are reviewing someone else's blog."

That caution generalises and is worth holding onto: **a pro's rule is contingent on the
meta they won in.** Treat this whole part as *what worked for one team in one mirror at
one patch*, not as a law. Presumably it fails when their healer can heal the damage with
spare globals, i.e. when your damage is below their free throughput — but the threshold
is unknown and probably comp- and dampening-dependent.

Test: in the archive, compare enemy-healer offensive-global rate (CC casts) against
incoming damage rate on their team. Until that runs, **do not write "just do damage" as
a plan in any guide** — write it as a named alternative with its condition left open.

---

## Part 5 — A go is a set of moments, and its quality is globals denied

**The design criterion for a go is: how many free globals does it leave the enemy
team?** [OBS] The player derived this. Calvish's team used it, explicitly, to build
the opener they won the tournament with.

The player's version:

> "If the Paladin is free when the Priest gets stunned, he can use Sanctuary or
> Blessing of Protection on the Priest, or Sacrifice on the Warrior, to neutralise the
> go... the Feral stuns the Warrior and, on the same global, the Hunter scatters the
> Ret — because the two enemy DPS are stacked on the same target, so both are
> reachable and neither is left free to answer."

Calvish's team, between games 3 and 4 of the final, rebuilt their opener on the same
axis. Dazed asked "is there a way to make the opener better if I incap sweep the
druid?" and the answer they worked out was a **forcing sequence with no good reply**:

> "We incap sweep the druid. Rogue comes out to peel. I gouge the rogue as he opens,
> because I'm still in stealth while he's soloing the druid. **And if the rogue sits in
> stealth, then Dazed is going to solo the druid and get a cooldown from the druid for
> free.** So the rogue *has* to come out... then Cub NS clones the druid, and then we go
> the rogue off the pre-CC gouge."

Then they added a second layer for the mage:

> "The mage blinks and then DBs us... so what we added is Cub roots the mage and then
> vortexes him. So he needs to blink twice to get out of the vortex and peel us with
> DB, **and that's two globals for him.**"

**Three things follow.**

1. **[OBS] A good go is a zugzwang, not a burst.** Every enemy reply is either
   unavailable, too slow, or costs more than it saves. "The rogue has to come out" is
   the shape to aim for. v1's "commit everything at the opener" is the crude version of
   this; the real version is a sequence that makes their best answer bad.

2. **[DER] A plan must be able to express simultaneity.** One action *per player* per
   moment. A flat list of steps cannot say "these two land on the same global", which is
   the thing that makes the whole play work. The guide builder's current
   single-sequence shape cannot express it — see Part 16.

3. **[OBS] Openers are engineered between games, not looked up.** This one was invented
   in a hotel room and on stage, and it changed the series. This is the strongest
   available evidence for the product thesis: **a specific written opener is a discrete,
   installable improvement.** Calvish: "we do some variation of it every single game
   after the sewers game. So we just immediately levelled up."

---

## Part 6 — A chain is an economic trade

**Value per cooldown spent, not DR legality.** [OBS] The player's correction to v1,
and the sharpest single idea in the review:

> "Blind is a 2-minute Disorient that lasts 6 seconds. Fear is a 6-second Disorient
> with a 30-second cooldown, but it is dispellable. You shouldn't send a 2-minute
> cooldown DR'd after a Fear unless the extra 3 seconds is genuinely needed. Instead
> use it at full duration on the off-target as a peel — it might force a trinket
> without a stun."

So changing DR category is a *consequence* of a good chain, not the rule that produces
one. The metric is `effective_duration_after_DR ÷ cooldown`, and a legitimate output is
**"hold this — it is worth more elsewhere."**

**Modifiers:**

- **Dispellability** discounts a CC against a free healer. [DER]
- **Alignment** — spending a 2-minute cooldown is defensible when it lines up with
  another 2-minute cooldown. [OBS]
- **[OBS, corrective] Expected value is value × probability you actually use it.** The
  player's own caveat on his own rule: *"If you don't know when the right time is, it's
  often better to use Blind early rather than hold onto it for three minutes, because
  then you at least get value out of it."* A held cooldown that is never recognised as
  spendable is worth zero. This means banking advice is **tiered by player** — "use it
  early" and "hold it for the second go" are both correct answers to one position for
  different players, and a scoring system must not mark the first as an error.

Calvish shows the same trade being made consciously in both directions: he holds Blind
for four seconds of sap duration and regrets rushing it once ("maybe I could have
blinded earlier... if I did save blind for the next go, maybe we just win for free"),
and he spends Cloak of Shadows *offensively* to deny a peel because the exchange is
favourable — "I cloak the DB right here and W has to trinket because of it. So traded
cloak for a rogue trinket. Definitely worth it."

**[OBS] Cross-CC is an allocation, not a property of an ability.** The player:
*"Cross-CC depends on what CC is left over, so we can't really categorise something as
cross-CC until it's looked at within the context of a comp."* What a CC is *for* falls
out of solving the allocation — healer control first, kill-target control, then what
remains becomes cross-CC or a banked peel. The measured healer-share in
`CcTargetingAnalyzer` is a **prior, not a rule**.

**[OBS] Second allocation rule: control on an amplified enemy is paid twice.** The
player: *"landing a stun on the Warrior during Avatar and cross-CC'ing the Paladin
during Wings is a win-win. You stop damage and get a go."* One action, two currencies.
A generator that reads only role will never produce "stun the Warrior during Avatar."

---

## Part 7 — Time is counted in DRs and goes

**[OBS] Top players do not count seconds; they count how many goes are left before
something returns.** This is a genuine refinement and it runs through the whole
Calvish transcript:

> "We have one more DR before they have trinkets."
>
> "Their kill go will come before our kill go in terms of opportunities."
>
> "They're pretty much dead next DR no matter what."
>
> "We have combust kingsbane in 20 seconds."

The unit of planning is the **DR window** — the ~18s during which a category is usable
again at full value — and the **go**, which is the DR window plus whatever cooldowns
are aligned to it. Seconds appear only when the number is decision-relevant ("we have
55 seconds to set this up").

**[OBS] Cadence is chosen, not observed.** The player's 15.3 point stands and Calvish's
play is consistent with it: you deliberately align abilities onto a common period so
several land together. *"You get a multiplier effect using all three in 30 seconds
rather than DR'ing one ability or school and then having spells out of sync."* Scatter
Shot's 30s lining up with Maim's is the reason to bring Scatter back in Jungle.

**[OBS] A broken link costs the next go, not just this one.** If Maim lands and the
follow-up is stopped, the abilities that were aligned now come off cooldown at
different times, and the comp's real go rate drops until re-aligned. Failure is a
**desynchronisation** that propagates.

**[DER] What this means for a guide:** state the period the plan runs on ("this go
repeats every 30 seconds, off Maim"), state what it depends on being aligned, and say
what to do when the link breaks — which is usually "re-align", not "try again
immediately."

---

## Part 8 — Patience inside a window, and what actually goes wrong at the top

**The failure is not recognising the window. It is being patient inside it.** [OBS]
This is the most useful correction the Calvish transcript makes to v1's rating ladder.

v1 put "cash the only-us window" at 1800–2100 as a skill that gets learned and then
stays learned. In the grand final, holding a 55-second window in which the enemy had
nothing, the reigning world champion — while saying it out loud on comms — threw it:

> "We have 55 seconds to set this up. 55 seconds. And what's crazy, I'm the one that
> ends up rushing this go, and the entire time I'm saying in calms, I'm being super
> specific: 'Guys, we have 50 seconds to set this up, just do not rush it.' And I
> actually end up rushing it and completely throw this go... I think I was scared of
> the combustion. I don't really know. It's just a bad play. We had so much time to
> win there."

**So the ladder is wrong in shape.** Skills are not acquired and retired; the error
rate falls but never reaches zero, and the *cost* of each error rises because
opponents punish it. A diagnostic that says "you have mastered cooldown trading" is
making a claim no evidence supports.

**[DER] What patience concretely means**, from how Calvish spends the windows he does
hold: re-establish stealth/setup position, get the pre-CC down (a sap or gouge *before*
the chain starts), wait for the partner's resource (combo points), and only then open.
Nearly every kill in the final series begins with a pre-applied sap/gouge/incap that is
not part of the damage window at all.

**[OBS, corrective] The pre-CC is not a general rule — it is a property of comps whose
damage window is very narrow.** An earlier draft of this part generalised it. The
player's correction, and the mechanism:

> "No, this is a Windwalker/Sub thing. I think it's because the damage needs to line up
> or happen in a very small window, so you both need to be ready to press CDs as soon as
> the healer gets CC'd. When they set up with gouge or sap it buys time for the WW and
> Sub to stand on top of the target they want to kill while the druid gets CC on the
> healer. Then as soon as the CC lands they land the stun and burst. But they only have
> a very small window."

**So the generalisable form is: the narrower your damage window, the more of the setup
has to happen before it opens.** [DER] Pre-CC is one way to buy that time; it is bought
because the comp cannot afford to spend the window travelling or building resources
(compare Part 9's pre-loading, which is the resource version of the same constraint).
A comp with a wide window or sustained damage does not need it, and Jungle's go
genuinely can start on the first Maim.

This is a good example of the failure mode this document is trying to avoid: a pattern
observed in one comp's games, generalised because it appeared in every kill of one
series. It appeared in every kill because every kill was made by the same comp.

**[OBS] The opposite error is also real** and he names it in the same series: sitting on
a window until the enemy's answers return. Both his rushed go and his over-held Blind
are in the same game. There is no rule that resolves this; there is only the answer
pool (Part 2) and the clock (Part 7).

---

## Part 9 — Disengagement, uptime, and the dead zone

v1's five-component disengagement cost (decay, rebuild, resource, opportunity,
cooldown drift) is [DER] and still stands. Two corrections from the review:

**[OBS] Leaving should have a named reason.** *"Leaving a target should only happen for
a specific reason: either they have cooldowns running, or they are trying to set up, or
some other reason. If running denies something, it's worth it."* The test is unchanged
and good: **does my disengage force them to spend something?**

**[OBS] The dead zone is a symptom, not a cause.** v1 treated "not threatening, not
safe" as a positional mistake. The player's revised reading:

> "The dead zone was a symptom. After improving I realised we didn't have the right
> strategy — mainly using enough CC and cross-CC in every go. Without the extra CC you
> couldn't force enough cooldowns, so you were left feeling like you have no pressure.
> You feel like it when the plan isn't working."

And:

> "If you're unsure of what to do and retreat trying to come up with a plan, it means
> you don't know what resources you need. It's actually the biggest indication you
> don't understand the match."

**[DER] So "you are in a dead zone" is a diagnostic output, not an instruction.** The
fix is upstream: the go did not have enough control in it to force anything. A guide
that says "avoid the dead zone" is useless; a guide that says "this go needs cross-CC
on the off-DPS or it will not force a defensive" fixes the same thing.

**[OBS] Pre-loading is comp-specific and is a resource problem.** The player:

> "A BM Hunter doesn't need to build combo points for intimidation-trap. A Feral needs
> combo points for stun and damage. So it would be important, before the trap is
> available, to have bleeds up on the target and combo points ready for the go... if
> you're playing Feral and you have stun available in 2 secs but also need to refresh
> Rip, and you refresh it and delay the trap, that timing is now 5-6s slower. It can
> be the difference between an enemy peel or defensive coming back off cooldown."

This is a real, authorable per-spec fact: **what must be true N seconds before the go
so that the go is not delayed by resource-building.** It belongs in every guide's
setup step and is currently in none of them.

---

## Part 10 — Hidden state, and forcing it

The hard part of arena is that several of the terms you need are unobservable:
enemy DR state across the team, cooldowns never witnessed, talents, gear, resources,
and what they know about you. [DER]

**[OBS] The way top players handle it is to manufacture information, deliberately, at a
cost.** Calvish's routine in the Rogue/Mage mirror is the cleanest example of this the
project has:

> "I intentionally eat CS a lot. Even though I know at this point that I don't want to
> be casting sheep, I'm still intentionally casting sheep to intentionally eat the CS a
> lot of the time, because I can immediately call to Cubsy that the mage has no CS —
> and I call this every single time I get CS'd and I tell him to cast clone."

He spends a cast to convert "does their mage have Counterspell?" from hidden to known,
then his healer casts into the known gap. Then: *"look at what Cub does. He roars next
on his trinket and then half clones him. Things like this are happening every single
game in every single mirror that we play."*

**Two things this adds to v1's account of baiting.**

- **[OBS] The output is not just a strip, it is a broadcast.** The play only works
  because he *says it on comms immediately*. The information has to reach the player
  who can use it, inside a few seconds. Comms is a mechanic here, not a nicety.
- **[OBS] It is a routine, not an opportunistic read.** Every time, every game. A guide
  can prescribe it: "cast X expecting the kick; if it lands you got the CC, if it is
  kicked call it and the healer casts immediately."

**[OBS] The same reasoning runs on the defensive side** — reading what the enemy
assumes about *your* state. Cub's play in the final: roar → begin Cyclone → deliberately
eat the Counterspell → shadowmeld/rake → Cyclone for real. Calvish's reading: *"from
Raichu's perspective, he assumes that since he started with roar, the only CC is roar.
And if he CS's him, the go is over."* The fake works because the opponent has a model
of the chain, and the chain was built to violate it.

**[DER] The product consequence.** Observable terms are what tooling computes. Hidden
terms have to be taught as a **distribution** ("at this rating, this spec runs this
build roughly this often") and as **forcing routines** like the one above. The second
is why a coaching product beats a calculator — and it is authorable today, without any
new data.

---

## Part 11 — Determinism is reached by memorising thresholds, not by calculating

v1 proposed a two-tier evaluation: **Tier 1 deterministic** (target has zero live
options; survival is arithmetic) and **Tier 2 branching** (real counterplay exists,
evaluate against the best available reply). That split is [DER] and still sound.

The review's objection was that this is *"a lot of information that in my experience
you can't process during a game."* True as stated. But the Calvish transcript contains
a Tier-1 calculation executed live, under tournament pressure, in about two seconds:

> "W has evasion up at 20% HP. Touch of Death range is 15% HP, and Touch of Death is
> not dodgeable. What that means is if we get him into 15% health range, he will die to
> Touch of Death 100% of the time. So if we look at our tools — W is on stun DR, can't
> stun him and go through evasion. I could vanish shadowstrike him. But I also have
> blind, and **you can't dodge while you have evasion up**. So, while we're both out of
> range, blind is a ranged ability. I blind him. Dazed gets there, breaks the blind with
> blackout kick, gets him to 5% HP. Now the evasion doesn't do anything anymore. And now
> he Touch of Deaths him through the evasion and kills him."

**[OBS] What made this computable live is that every input was a memorised constant**:
Touch of Death's threshold, that it ignores dodge, that Blind suppresses Evasion's
dodge, that a Blind breaks on damage. He did not calculate; he recalled four facts and
composed them.

**This is the design instruction for the whole application.** [DER] The deliverable is
not a calculator the player consults mid-game — there is no time. It is a set of
**memorisable thresholds and interactions**, per matchup, that make the calculation
unnecessary in the moment. "Their Pain Suppression is 8 seconds and your Incarnation is
20" is a usable fact. A damage simulator is not.

**[DER] Known blocker for a real Tier-1 damage calculator**: static coefficient dumps
are not tied to a real character's stats. It needs either bracket-typical gear
assumptions or live combat-log data. Nothing has changed here.

---

## Part 12 — Comp intent, dampening, and the sign of time

**[OBS] Intent is chosen per matchup, not owned by a comp.** v1 drifted toward
labelling comps "setup" or "dampener". The player already hedged this. Calvish
demolishes it with his own comp: Windwalker/Sub-Rogue is a burst setup comp, and his
plan against Mage/Lock was to not go at all.

> "Windwalker Rogue basically auto-wins into Mage Lock because of how dampening works.
> If they try to Mage Lock us, we just sit behind a pillar for 10 minutes and then kill
> the lock through every cooldown. **This patch, their cooldowns are affected by
> dampening**, making it very difficult for Mage Lock to beat Windwalker Rogue."

And against Ret/Warrior, the comp that counters his: *"it is a very very very long
game... there's a lot of opportunities for them to just mess up and die."*

**So the real axis is not comp archetype. It is: which side does the clock favour, in
this matchup, this patch?** [OBS] Everything else follows — whether a failed go is
survivable, whether damage between goes matters, whether you can spend control
defensively.

**[OBS] Dampening is a first-class mechanic and v1 barely mentioned it.** It does not
merely reduce healing over time; in the current patch it scales defensive cooldowns
too, which means **the value of every answer in the enemy pool decays over the round**.
A pool that is sufficient at minute two is not sufficient at minute ten. This is the
mechanism behind "sit behind a pillar for 10 minutes and then kill the lock through
every cooldown."

**[DER] The table from v1 is still useful, but it describes a *plan*, not a *comp*:**

| | Playing for the go | Playing the clock |
|---|---|---|
| Damage is | concentrated in windows | continuous, windows on top |
| A go that forces answers but no kill is | a spent attempt | **a full success** |
| Damage between goes is | near-worthless | **the win condition** |
| Control can be spent defensively | rarely | **freely** |
| The clock is | an enemy | an ally |

**[HYP] The computable proxy for which plan a comp prefers is burst concentration** —
what share of a spec's damage lands inside its own anchor window. `BurstGuideBuilder`
already measures the windows, so this is arithmetic over existing data. Treat a
computed archetype as a prior, never a label.

**[OBS, from the review] There is a third category v1 missed: opportunistic comps.**
*"There are also opportunistic comps that can 100-to-0 someone quickly, but they differ
because their goes depend on less CC and are harder to execute. See Jungle and RMP have
such a large amount of CC."* A comp that kills from less setup is not a setup comp with
fewer tools; it trades CC redundancy for execution difficulty, which changes what a
guide for it should emphasise (execution precision over chain construction).

---

## Part 13 — Talents into matchups

**[OBS] At the very top, a meaningful share of the edge is talent selection per
matchup, prepared in advance and hidden.** This validates the first note the player
wrote on v1 — *"depending on what talents are taken, a class's playstyle and mechanics
can vary significantly... talents into matchups is something to be considered when
writing a guide"* — and it is probably the most directly actionable finding for this
project, because the talent data is already in the database.

Calvish's examples:

- A hidden Fire Mage build for Guild Bean: *"you swap Critical Mass and Fires, and you
  swap to Sunfury Execution, and then you go and play Shimmer. This build is very good
  into basically everything they play."* He kept it unplayed until it mattered.
- A Sub-Rogue talent chosen for the presence of one enemy spec: *"Shadowstep's cooldown
  is reduced by 67% when cast on a friendly target. I would just have Dazed permanently
  running at Raichu and then I'm just stepping and following him around."* Taken
  specifically because a Demo Lock in the game means the Destro Lock can be left open.
- A whole-comp counter: *"Windwalker Monk was a 100% hard counter into Feral Elemental
  Shaman. We played 30 games and had a 100% win rate. It didn't matter what we put in."*
- And the meta-point: *"that's how confident we are in swapping our talents and builds
  around to make it good."*

**[DER] The consequence for guides.** A guide that names one build is describing one
matchup. The plan and the build are the same artifact — changing the matchup can change
the build, and a build change can change which go is even possible. The existing
`TalentFeasibilityService` check (a plan must name a build one character could actually
have) is the floor, not the ceiling; the ceiling is **per-matchup build recommendations
with the reason attached.**

**[HYP] The reason a talent swap wins a matchup is usually that it changes which term
of the conjunction is available, not that it adds damage.** Shimmer is mobility;
Silhouette is a mobility/pressure enabler; the Sunfury swap is a burst-shape change.
None of the examples are "more throughput". Untested, but a useful lens when authoring.

---

## Part 14 — The two dimensions this project does not model

Both were flagged in the review as gaps. The Calvish transcript makes them look larger,
not smaller.

### 14.1 Positioning [OBS]

Nothing in the data model represents position, and positioning decides an enormous
number of the plays in the final:

- **Denying a specific ability with geometry:** *"I evasion and I put my back to the
  wall so that I can't get kidney shot... which is why I think he didn't press it."*
- **Baiting a movement read:** *"I bait him to walk this way because he thinks I'm going
  this way to chase him. But instead I step to Cub, and using the movement speed from
  Shadow Step I turn the corner really fast and stun him."*
- **AoE placement deciding a round:** *"look at how he bombs here. If he walked more
  this way and bombed, we are for sure dead."*
- **Pet/LOS:** combusting from behind a pillar so the bird cannot reach the target.
- **Cover as the plan:** the 10-minute pillar game vs Mage/Lock.
- **Enemy DPS stacked on one target is what makes same-global cross-CC reachable at
  all** (Part 5).

Positioning is not a garnish on the model; several of Part 5's parallelism claims
*depend* on it being true that both enemies are reachable. A guide can say this in
prose today ("this go requires both their DPS on the same target") without any map
data.

### 14.2 Comms and information distribution [OBS]

The team is not three players with one plan; it is three players with **three different
views of the state**, and the winning plays are frequently about moving information
between them fast enough:

- *"I can immediately call to Cubsy that the mage has no CS."*
- *"I pick up the eyes, I target W and I ping him. So now my entire team knows he's
  behind this pillar."* — followed immediately by a vortex and a rock from two
  teammates and the sap that set up the winning go.
- *"I instantly call that we have aggro trinkets. As soon as Cub roars JT I say: can we
  go JT? Go JT. We have aggro trinkets, just trinket aggro."*
- Cub holding position one global back, covering the case where a Death Butterfly lands
  on Calvish and he cannot re-stun: *"he's there to instantly maim him in case the DB
  doesn't hit."*

And the negative case from the review: the player's shuffle losses, where *"I didn't
communicate in the room"* and the framework's prescriptions became liabilities.

**[DER] What a guide can do about it today:** name the calls. A plan step that requires
information someone else holds should say who calls what, in what words. "Call the
trinket" is a real instruction and no guide in the project currently gives one.

---

## Part 15 — The rating ladder, revised

v1's ladder mapped brackets to the part of the cycle that breaks. It is still the best
diagnostic scaffold available, with one structural correction from Part 8: **these are
not stages that are passed. They are error rates that fall.** The world champion makes
the 1800-bracket error (rushing a held window) in a grand final.

| Bracket | What breaks | The state they cannot see | What to teach |
|---|---|---|---|
| 1400–1600 | Anti-go fails outright — defensives late or unused | That the enemy go has begun | Defensive usage; overlap avoidance (Part 3) |
| 1600–1800 | Goes are uncoordinated — burst with no control | That their own go is incomplete | Chain construction; globals denied (Part 5) |
| 1800–2100 | Cooldown trading decides the next go before it happens | That they are up on the answer pool | Reading the pool; patience (Parts 2, 8) |
| 2100–2300 | Execution degrades under matchup-specific pressure | Hidden state; disengagement cost | Forcing routines; pre-loading (Parts 9, 10) |
| 2300+ | Team holds different plans | That the three of them disagree about the target | Shared reads; comms protocol (Part 14.2) |

[DER] The reframing that matters for diagnostics: a trait ("you are reactive") is not
fixable. A state-recognition failure ("you play the same way whether or not you are up
on the answer pool") is specific, falsifiable, and teachable in a sitting.

---

## Part 16 — What this means for authoring a guide

**Answer these five before writing any steps.**

1. **Who dies, and why them?** Ranked, with a reason per candidate — fewest answers,
   most pinnable, or most suppressible (pressure on them stops their peels). Never a
   single verdict. [OBS]
2. **What does our go deny them?** Which enemy player is left with a free global, and
   what would they do with it. If the answer is "nothing is denied", the go is a strip
   at best. [OBS]
3. **What period does the plan run on?** Which ability is the slowest term, and what
   must be aligned to it. [OBS]
4. **Which side does the clock favour here?** This decides whether a failed go is
   survivable and whether damage between goes matters. [OBS]
5. **What must be true before the go starts?** Pre-CC down, resources built, position
   held. The go begins before the first damage cooldown. [OBS]

**Then the plan itself.**

- **Steps carry preconditions in answer-pool terms**, not just ordering. "Kidney the
  rogue" is a rotation. "Kidney the rogue *if* he has no cloak and their druid has no
  bark" is a plan.
- **State simultaneity where it matters.** Two actions on one global is the mechanism;
  a vertical list hides it. Until the builder supports moments, say it in the step text.
- **Give the abort conditions.** *"You don't Combust into Cloak"* — but note the player's
  own qualifier: *"the minute you delay a Combust but put the healer on poly DR, your
  big go is behind even if the rogue cloaked. The duration can go into the other
  target."* Aborting is not free; it desynchronises (Part 7). Prefer **redirect** over
  **hold** where redirect is possible.
- **Name the calls** (Part 14.2).
- **Say which build this plan assumes, and what changes if the matchup changes**
  (Part 13).
- **Do not write a five-step script.** Write what to do in each state. A script breaks
  the moment a partner misplays; a state table absorbs it.

**Things not to write.**

- Any [HYP] claim from this file, stated as fact.
- A number the data cannot produce. Show "no verified answer" instead.
- Advice that assumes the reader will recognise the perfect moment. Tier it: for most
  readers, usage rate beats optimality (Part 6).
- Control on a target the spell cannot legally affect. The data does not model who a
  spell can be cast on — Banish and Shackle Horror both shipped in published plans as
  control on players. Nothing in the pipeline catches this.

---

## Part 17 — What the data layer must supply

Small, stable in shape, volatile in value.

**Per spec**
- Damage profile: sustained / ramping / cooldown-gated / proc-driven
- Burst concentration (share of damage inside the anchor window) — the intent proxy
- Anchor window length, per spec, from `BurstGuideBuilder`
- Defensive classification: free / cheap / expensive / convertible, **with duration**
  (duration is what makes overlap computable — Part 3)
- Pre-load requirements: what must exist N seconds before the go (resources, DoTs,
  stealth, position) — Part 9
- Free disengage duration, and what decays past it
- Hidden-variable profile: which outs and burst sources are talent-gated

**Per comp**
- Which terms it supplies, and which are hard vs soft
- Redundancy of control sources (drives window frequency)
- Longest-cooldown term (drives go frequency)
- Natural period T: the cadence that maximises simultaneous availability
- Which side the clock favours, per matchup

**Per matchup**
- Recommended build, with the reason attached (Part 13)
- Which enemy answers must be gone before a go is a kill rather than a strip

**Per bracket (distributional, behavioural)**
- How often a spec runs a given build
- How often the correct save is actually taken

---

## Part 18 — Confidence, honestly

The player's closing note on v1 was that *"there are parts where the wording is strong,
suggesting a confidence that isn't warranted, as we haven't figured it out."* That is
the reason for the tiers.

What the Calvish transcript actually did to the model:

**Strongly confirmed** — the answer pool as the currency (Part 2); overlap as the
dominant loss condition (Part 3); globals-denied as the design criterion for a go
(Part 5); openers as engineered, iterable artifacts (Part 5); forcing information by
deliberate bait (Part 10); talents-into-matchups as a major edge (Part 13);
sending into an answer that has already landed as a top-level mistake.

**Corrected** — damage has a third job (Part 4); patience inside a window is the real
skill and it is never fully acquired (Part 8); intent is matchup-selected, not
comp-inherent, and dampening drives it (Part 12); Tier-1 determinism is reached by
memorised thresholds, not live calculation (Part 11).

**Raised in priority** — positioning and comms, both previously "noted as out of scope"
(Part 14).

**Neither confirmed nor denied, because the source says nothing about it** — anything
about Solo Shuffle, anything about brackets below Gladiator, anything about mana
attrition as a win condition (tournament dampening ends games before mana does), and
the whole question of whether these ideas survive contact with players who are not
professionals.

That last one is the real open question, and it is the product question: this framework
is derived from how the best players in the world and one Gladiator think. Whether it
*transfers* — whether a 1700 player given this reads a game better — is not established
by anything in these documents. `arena-open-questions.md` lists what would establish it.
