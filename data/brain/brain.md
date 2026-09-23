---
title: The MindCollector Brain
subtitle: What we think an arena game actually is — and how sure we are about each part.
updated: 2026-09-23
---

## Why this page exists {#why}
::tier open::

Every Claude-drafted guide on this site is written from this document. So if a guide
gives you bad advice, the fault is probably here, and this is the page to argue with.

It is a model of what an arena game *is*: the currency both teams are actually
spending, what makes a go work, and what a plan has to say before it is a plan. It came from three
places: real games reasoned over in detail, the full spell and talent data behind this
site, and a transcript of Calvish's breakdown of the team that won BlizzCon 2026.

**Nothing here is settled.** Each section is tagged with what's behind it:

- **Observed** — a top player said it about real games, or our own player found it in his.
- **Derived** — it follows from game mechanics or data we hold, and you can check it.
- **Hypothesis** — reasoned, plausible, and *untested*. We have not proved it and we
  might be wrong.

Those are three different kinds of backing, not a ranking. Derived is the one you can
actually re-check yourself; observed means a good player told us, about the meta he was
playing at the time.

Comment on any section. A correction to this page changes every guide downstream of it,
which is worth more than a correction to any single guide.

## Who this is for {#audience}
::tier observed::

Anyone who wants something specific to practise.

We nearly wrote something worse here — that this is for 2000 and up, and that below
that your problem is mechanical so a model of the go won't help you. That's wrong, and
it's worth saying why, because it's a common way of being condescending about lower
ratings.

**Execution is always execution *of* something.** "Get better mechanically" is not
something you can go and do. "Land Maim and Scatter on the same global, then Cyclone,
and do it every 30 seconds" is. A plan is what gives practice a target, so the less
automatic your play is, the more you need one written down — not less.

There's a good example of this at the very top. Between games in the BlizzCon final,
Calvish's team didn't practise harder. They wrote a better opener, and then executed
better in every game after it: *"we do some variation of it every single game after the
sewers game. So we just immediately levelled up."*

What changes with rating is what you use this for. Lower down it's a drill list — a
sequence to repeat until you don't have to think about it. Higher up it's a plan you
weigh against their plan. Same model, different question.

It *is* a **3v3 model, not a Solo Shuffle one.** Applying it to shuffle has already lost
games — our player went 0-6 in a lobby trying to force the win condition this model
prescribes, against opponents nobody was coordinating with. Shuffle needs its own model
and doesn't have one yet.

## How sure we are, and where we're guessing {#scope}
::tier observed::

Some things about arena are reliably true. Others we can only reason about. We'd rather
tell you which is which than only tell you the first kind.

**What we can state:** what a long CC chain looks like for this comp, what a good go looks
like, what tools a team holds and what each one counters, which of their answers must be
gone before a go is a kill, and the playstyle a given talent build implies. These come out
of the game's own data and you can check them.

**What we can only propose:** who to kill and in what order, whether to send *this* chain
in *this* round, whether they'll actually press the button. We'll still give you an answer
— a ranked one, with the reason attached — because "it depends" helps nobody. But it's our
reasoning, not a fact, and it's labelled that way.

**This page used to say the second list was off-limits, and that was wrong.** Not having
complete information isn't the same as having nothing useful to say. A guide that only
ever tells you what's already certain gives you nothing to argue with, and arguing with it
is the point — corrections here are what make the next version better.

**What keeps that honest isn't caution, it's the game.** A bad call exposes itself when you
check it: the target we named has three defensives left, the chain doesn't reach, the build
can't hold both talents. The data does that work. So we'd rather be specific and
occasionally wrong than vague and safe — as long as we're clear which parts are which.

## What actually decides a kill {#answer-pool}
::tier observed::

Not their health bar. **The list of buttons they still hold** — trinket, personals,
healer externals, escapes, immunities. Track it per enemy player.

A trinket only buys time to press something else, so a trinket with nothing behind it is
a two-second delay. This is the best-supported idea on the page, and the first thing to
organise a plan around. Calvish narrates almost every kill in the BlizzCon final this
way:

> "You can have trinkets, but if you don't have anything to press, it's so easy to just
> die on the spot."

Three things follow:

- **"Forced a trinket" is not by itself a success.** Its value is what it cost them from
  the rest of the pool.
- **The kill target is whoever's list is shortest right now, re-derived every go** — not
  decided once at the gates. Swapping mid-go on that basis is normal at the top.
- **A go into a full pool is a strip. A go into an empty pool is a kill.** Different
  plays, different acceptable risk. Plan them differently.

## Two defensives spent on one threat {#overlap}
::tier observed::

Two of you answer the same threat, so two buttons are gone and only one was needed.
This is not a small inefficiency. It removes your ability to play at all.

> "We overlap iron bark, trinket, trinket, cloak. If you have ever played a game like
> this, you know exactly how unplayable the rest of the game is going to be. **You are
> not allowed to play until you have these buttons back.**"
>
> — Calvish, on losing game one of the BlizzCon final

It is usually not a knowledge problem. It is an information problem: one player can see
their own mitigation, the other can only see a health bar, so both solve the same threat.
It happened to the team that went on to win the tournament, twice.

There is no general protocol for preventing it. What there *is* — and what we think is
the most useful thing this site can build — is a per-matchup lookup: **enemy presses X →
you press Y.** Every input already exists in our data. That table is what a defensives
section of a guide should actually contain.

## The three jobs of damage {#damage}
::tier hypothesis::

The obvious two: **kill** (damage inside a live window) and **strip** (forcing a
defensive, mana, position or attention).

The third is less obvious and we are not sure how far it generalises: **damage buys your
healer's globals and spends theirs.** A healer who must heal cannot CC, cannot dispel
early, cannot reposition. Damage that gets fully healed is not wasted if it consumed the
globals their healer would have spent on a Cyclone.

Calvish calls this the entire win condition of the Rogue/Mage mirror, and describes the
losing side of it as a spiral: behind on healing → can't go CC → less pressure → further
behind.

**Why this is only a hypothesis.** It is one team, in one mirror, at one patch. Our own
player's objection is the right one: *"just do damage"* can also mean wasting every
damage cooldown into a healer who was never under strain. Nobody has established where
the threshold is. Until we measure it, treat "just do damage" as a named alternative
with an unknown condition — not as a plan.

## Making a go they can't answer {#zugzwang}
::tier observed::

Score a go by **how many free globals it leaves the enemy team.** If someone on their
side is untouched and holding an answer, your go is incomplete no matter how much damage
is in it.

The best illustration is the opener Calvish's team invented mid-series and won the
tournament with. Every enemy reply to it is bad:

> "We incap sweep the druid. Rogue comes out to peel. I gouge the rogue as he opens,
> because I'm still in stealth. **And if the rogue sits in stealth, then Dazed is going
> to solo the druid and get a cooldown from the druid for free.** So the rogue *has* to
> come out."

Then they added a layer against the mage, scored purely in globals: root, then vortex, so
he must blink twice before he can peel — *"and that's two globals for him."*

Two consequences:

- **Simultaneity is the mechanism.** Two actions landing on the same global is what
  denies the answer. A plan written as a vertical list of steps cannot express it, which
  is a real limitation of our own guide builder today.
- **Openers are engineered, not looked up.** That one was invented in a hotel room and
  refined between games. It is the strongest evidence we have that a specific written
  opener is a thing you can install and immediately be better.

## When to spend a long cooldown {#chains}
::tier observed::

Not a DR-avoidance puzzle. Changing DR category is a *consequence* of a good chain, not
the rule that produces one. The real comparison is **control gained against cooldown
spent**:

> "Blind is a 2-minute Disorient that lasts 6 seconds. Fear is a 6-second Disorient with
> a 30-second cooldown, but it is dispellable. You shouldn't send a 2-minute cooldown
> DR'd after a Fear unless the extra 3 seconds is genuinely needed. Instead use it at
> full duration on the off-target as a peel — it might force a trinket without a stun."

So a legal chain can still be badly played, and a correct output is sometimes **"hold
this one."**

**But holding has a catch, and it inverts the advice for most players.** A two-minute
cooldown saved for a perfect window you never recognise is worth exactly zero. If you
don't yet know when the right moment is, using it early is better than saving it
forever. Both "use it now" and "bank it" are correct answers to the same position, for
different players — and nothing here should mark the first one as a mistake.

## Who your CC should land on {#allocation}
::tier observed::

You cannot label an ability "cross-CC" in the abstract. Cross-CC is whatever control is
left over once the healer and the kill target are covered — so it falls out of solving
the allocation, it isn't a property of the spell.

Two rules for allocating:

- **DR is per target**, so two abilities sharing a category are fine on two players and
  wasted on one.
- **Control on an enemy inside an offensive cooldown is paid twice.** Stunning the
  Warrior during Avatar both removes damage from their window and creates the setup for
  yours. One button, two currencies.

## How good players count time {#clock}
::tier observed::

Top players plan in **DR windows** and **goes**, not in seconds. *"We have one more DR
before they have trinkets."* *"Their kill go will come before our kill go."* Seconds
appear only when the exact number decides something.

This is why alignment matters. You deliberately put abilities on a common period so
several land together — and when a link in a chain gets stopped, the real cost is not
the lost attempt. It is that everything you had aligned now comes off cooldown at
different times, and your go rate drops until you deliberately re-align.

## Waiting when you're ahead {#patience}
::tier observed::

When you survive a go, you hold a window in which they have nothing. The classic mistake
is not failing to notice it — it's failing to be patient inside it.

We assumed this was a skill you acquire around 1800 and then keep. It isn't. In the
BlizzCon grand final, holding a 55-second window, **while saying the rule out loud on
comms**, the reigning champion threw it:

> "The entire time I'm saying in calls, I'm being super specific: 'Guys, we have 50
> seconds to set this up, just do not rush it.' And I actually end up rushing it and
> completely throw this go... We had so much time to win there."

The opposite error is in the same series — he also held a Blind too long and regretted
it. There is no rule that resolves this. There is only the answer pool and the clock.

It also means any tool that tells you a skill is *mastered* is claiming something the
evidence doesn't support. Error rates fall. They don't reach zero.

## Setting up before the damage starts {#setup}
::tier derived::

The narrower your damage window, the more of the setup has to be finished before it
starts.

Windwalker/Sub kills in the final almost all begin from a target already sapped or
gouged — not as part of the chain, but *before* it — because the comp's damage window is
tiny and can't afford to be spent walking into position. The same constraint shows up as
a resource problem elsewhere: a Feral needs combo points banked before the trap is
available, and refreshing Rip at the wrong moment delays the go by five seconds, which
is enough for a peel to come back.

This does **not** generalise to every comp. A comp with a wide window or sustained damage
doesn't need a pre-CC, and Jungle's go genuinely can start on the first Maim. What
generalises is the constraint, not the trick.

## Making them show you what they have {#information}
::tier observed::

Most of what decides a go is hidden — their DR state, their cooldowns, their talents,
what they know about you. Strong players don't guess better. They spend something to
convert a hidden fact into a known one, and then say it out loud.

> "I intentionally eat CS a lot... I'm still intentionally casting sheep to intentionally
> eat the CS, because I can immediately call to Cubsy that the mage has no CS — and I
> call this every single time I get CS'd and I tell him to cast clone."

Note both halves. The bait is half of it; the **call** is the other half, and it has to
land within a couple of seconds or the information is worthless. This is a routine run
every game, not a clever read — which means a guide can prescribe it.

## Knowing a kill is guaranteed {#thresholds}
::tier derived::

There is no time to calculate mid-game. What there *is* time for is recalling three or
four constants and composing them.

In the final, Calvish killed a rogue sitting at 20% behind Evasion, in about two seconds
of thought, out of four facts he already knew: Touch of Death executes at 15%, Touch of
Death can't be dodged, Blind suppresses Evasion's dodge, and Blind breaks on damage. So:
blind him at range, have a teammate break it with a kick for damage, and execute through
the Evasion.

**This is the design instruction for this whole site.** The useful deliverable is a set
of memorisable thresholds and interactions per matchup — not a calculator you'd never
have time to open.

## Whether the clock is on your side {#intent}
::tier observed::

We used to sort comps into "setup" and "dampener". That's too coarse. Calvish's comp is a
burst setup comp, and his plan against Mage/Lock was to not go at all:

> "We just sit behind a pillar for 10 minutes and then kill the lock through every
> cooldown. This patch, their cooldowns are affected by dampening."

The axis that actually matters is **which side does the clock favour, in this matchup,
this patch.** Everything else follows from it: whether a failed go is survivable, whether
damage between goes counts, whether you can spend control defensively.

## Talents are part of the plan {#talents}
::tier observed::

A guide that names one build is describing one matchup. At the top, a real share of the
edge is talent selection prepared per opponent and kept hidden — a Fire Mage build swapped
specifically for one team, a Rogue talent taken because of which *enemy* spec was in the
lobby, a comp with a measured 100% win rate into one other comp across 30 games.

None of those examples were about more damage. They changed which term of the plan was
*available*. That's the lens to read a build with.

## Both teams on one clock {#timeline}
::tier derived::

Everything above tells you what to look for. None of it tells you **when**.

"Go into an empty pool" is a true sentence you cannot act on until something says at
which point in *this* round their pool is empty. So we draw it. Every cooldown on both
teams has a known length, so both sides' resources can be put on one timeline and read
off: when their trinkets come back, when your next full go is up, and whether those two
moments are the same moment.

**Two lines, and the second one is per player.** The first is what a team can commit at a
given second — not damage, we can't compute damage, but *what is off cooldown and how
much of the enemy team your control reaches at once*. The second is how many answers each
enemy player can **press right now**, which is not the same as how many they own. A
healer in a trap has none, whatever is on their bars. That gap is the whole point: a team
can hold six defensives and have none of them reachable.

**Where a rising line meets a flat-zero one, that's a window.** It is the moment a go is
a kill attempt instead of a strip — not a moment a kill is guaranteed. We don't have a
damage model and won't pretend to. An empty answer list means they have no button left,
not that what you're about to do is lethal.

**The clock is not 30 seconds.** Thirty is Jungle's number because Maim and Scatter are
both thirty. Your period is whatever your slowest aligned ability is, and it is different
for every comp — which is exactly why a generic "go every 30s" guide is wrong for most
teams reading it.

You can play with this on the **Matchup Lab**: pick both comps and see whose window
opens first, and why. Two honest limits are printed on that page and worth repeating
here. Cooldowns that shrink as you spend resources aren't in our data, so every period
we show is the **slowest** it could be — real goes come round sooner. And there is no
win probability anywhere on it, because there is no match-outcome data to fit one to;
inventing a percentage would make the page feel more authoritative and be worth less.

**Execution is a setting, not a rating.** The same matchup reads differently depending on
whether the defending team trades cleanly or answers late and burns two cooldowns for one
threat. That isn't a lower-rated version of the same picture — it's a different picture,
and often a different plan. Read a matchup at the setting you actually play at.

## What we don't model {#gaps}
::tier observed::

**Positioning.** Nothing in our data represents where anyone is standing, and positioning
decided round after round of the final — backing into a wall so a Kidney can't land,
baiting a movement read to cut a corner, where a bomb was placed, whether a pet had line
of sight. Some of what we claim about simultaneous CC only works *because* their two DPS
were stacked on the same target.

**Communication.** A team is three players with three different views of the state, and
a lot of the winning plays are about moving information between them fast. We can't model
it, but a guide can at least name the calls.

## What would prove us wrong {#falsification}
::tier open::

Things we'd genuinely like to be corrected on, in rough order of how much they'd change:

1. **Does any of this transfer?** All of it has been reasoned over carefully. Almost
   none of it has been tested. That a player handed this reads a game better than one
   who hasn't is *not* established.
2. **The third job of damage.** If pressure doesn't measurably suppress an enemy
   healer's CC output, that section shrinks back to two jobs.
3. **Overlap as the dominant loss condition.** If lower-rated games don't show more
   double-defensive spends, we've overstated it.
4. **The tail of a damage window.** Your buff outlives their defensive by ten seconds —
   is that waste, or is it the actual kill window? We don't know.
5. **The order you answer a threat in.** We rank it control the caster, then break line
   of sight, then a personal, then an external, then trinket — cheapest thing that
   works, first. That ordering is reasoned, not observed, and there are surely threats
   where it inverts. If you know one, that's the correction we want.

If you have a view on any of these, especially the first, the comment box under each
section is the most useful thing on this page.
