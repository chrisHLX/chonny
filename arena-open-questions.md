# Arena — Open Questions

Companion to `arena-structure.md`. Everything here is something the framework
currently guesses at, or asserts without evidence.

**How to use it.** Answer inline under each question — a sentence is enough. Write
`ANSWER:` and then whatever you have. If you don't know, write `DON'T KNOW` — that is
a useful answer too, because it tells me not to write the claim into a guide. When a
question gets answered, the rule it settles moves into `arena-structure.md` with its
tier upgraded, and the question gets struck through here rather than deleted.

Each question says **who can answer it**:

- **YOU** — only your experience or another high-rated player can settle it.
- **ARCHIVE** — the 689-match log corpus can settle it; I can run the measurement.
- **DATA** — the spell/talent database already knows; I can just look.
- **SOURCE** — needs another pro account (another Calvish-style transcript, an
  interview, a coach).
- **TEST** — needs a deliberate experiment in real games.

---

## A. The questions that decide whether the product works

These come first because everything else is downstream of them.

~~**A1. Does this framework transfer to someone who isn't you?** [TEST]~~ — **ANSWERED, graduated into `arena-structure.md`.**
Everything in `arena-structure.md` is derived from how a world champion and one
Gladiator think. Nothing establishes that a 1700 player handed it reads a game any
better. The cheapest real test: give one player one matchup's plan written in this
shape, and check whether they can afterwards name, for a loss, which state they
misread. If they can't, the framework is a good description and a bad teaching tool.

Do you know anyone at 1600–1900 who'd sit for that?

ANSWER: It's weird I'm 1700 on other toons. But I think thats a consequence of mechanics. Which makes me think that the analysis and reflection we are doing 
is for the difference between 2200 - multi glad - rank 1. I think we are exploring the fringes of my understanding on my mains if that makes sense.

FOLLOW-UP (chriso): A better model of the go does help mechanics — it gives players something to practise.

→ Taken. The first reading of this answer was that the model is for 2200+ only. That does not
follow from "the gap on my alts is mechanical", and it has been corrected in both the framework
and on /brain. The remaining question A1 actually asked — does a 1700 player handed this read a
game better — is still open and still needs a [TEST].

~~**A2. Which of these is the product?** [YOU]~~ — **ANSWERED, graduated into `arena-structure.md`.**
The Calvish transcript supports three different artifacts, and they are not the same
build:

  (a) **A per-matchup opener.** One engineered forcing sequence, written down, that
      you install before a session. This is what changed his series.
  (b) **A memorised-threshold sheet.** "Their Pain Sup is 8s, your Incarnation is 20s,
      Touch of Death is undodgeable at 15%." Facts that make a live calculation
      unnecessary. This is what he actually used to kill in the final.
  (c) **A build-per-matchup recommendation.** The hidden Shimmer build, Silhouette
      into a Demo Lock. This is where a lot of his prep edge came from and it is the
      one we have the most data for already.

Which one do you want to be good first? They share a data layer but they are
different pages. 

ANSWER: I think they are all related to the comp builder. 

~~**A3. Is the app aimed at 3v3, shuffle, or both?** [YOU]~~ — **ANSWERED, graduated into `arena-structure.md`.**
`arena-structure.md` is now explicitly a 3v3 model and says so. You lost six shuffle
rounds applying it. If shuffle is in scope, it needs its own model, and I'd want to
build it from your losses rather than from theory — see section D.

ANSWER: Currently the comp builder really only works for 3v3

---

## B. Questions only you (or another high-rated player) can answer

~~**B1. What is the actual overlap rule?** [YOU]~~ — **ANSWERED, graduated into `arena-structure.md`.**
Part 3 says overlap is the dominant loss condition, which both sources agree on.
Neither says how a good team *prevents* it. Candidates:

  - The lowest-HP player calls their own defensive and nobody else acts until they do.
  - The healer calls it.
  - Each defensive has a pre-agreed trigger (health %, specific enemy cooldown).
  - It's ad hoc and good teams just overlap less often.

Which is it in a team you've played in that did it well?

ANSWER: It's adhoc but also if the healer is ccd and both offensives running there is a calc that we can run which is basically which defensive the player should press when the enemy team press y. thats more point 3 each defensive has a pre-agreed trigger like the barkskin example with kings bane

~~**B2. When does "just do damage" beat "set up a go"?** [YOU]~~ — **ANSWERED: unknown, and deliberately left unknown.** Recorded in `arena-structure.md` Part 4; the archive test (C-new) is the way out.
Calvish says in the Rogue/Mage mirror that constant damage is the win condition
because it buys his druid's globals and spends theirs. You wrote that padding just
gets healed through. Both are clearly true sometimes. What tells you which game you're
in? Is it comp, dampening level, healer spec, or something else?

ANSWER: I dont know prior, because just do damage might also just waste all your damage cooldowns. this is beyond my understanding. In some cases or in shuffles for example pressuring a target like a hunter makes it hard for him to get a trap but when paired with a good feral they have tools to get set. It's unclear because it depends on individual playstyles and comps for example kalvish says the pressure stops the druid from being able to cc but what if there is a playstyle or talents that counter that idea. then he loses blizzcon and we are reviewing someone elses blog.

~~**B3. Is the pre-CC universal?** [YOU]~~ — **ANSWERED, graduated into `arena-structure.md`.**
Almost every kill in the Calvish final starts from a sap/gouge/incap applied *before*
the chain, not part of it. Is "the go starts from a pre-CC'd target" a general rule, or
a Rogue-comp property? Does Jungle have an equivalent, or does the Feral/Hunter go
genuinely start on the first Maim?

ANSWER: no this is a ww sub thing. I think its because the damage needs to line up or happen in a very small window that you need to both be ready to press cds as soon as the healer gets ccd. so when they set up with gouge or sap it buys time for the ww and sub to stand on top of the target they want to kill while the druid gets cc on the healer then as soon as the cc lands they land the stun and burst. but they only have a very small window.

**B4. Abort vs redirect.** [YOU]
You wrote: *"the minute you delay a Combust but put the healer on poly DR, your big go
is behind even if the rogue cloaked. The duration can go into the other target."* So
the rule is not "abort into an answer" — it's "redirect if you can, abort only if you
can't". Is that right? And what makes a redirect impossible — is it purely range and
CC state, or is there more?

ANSWER: 

**B5. How much of a go do you plan versus react?** [YOU]
Part 5 treats a go as an engineered sequence with branches ("the rogue has to come
out"). Part 8 says the go starts from a pre-CC. But Calvish also improvises constantly.
Roughly: is a go a rehearsed sequence with two or three branches, or a set of
principles applied live? This matters a lot for what a generated plan should look
like — a script, or a decision table.

ANSWER:

**B6. What does the healer's plan look like?** [YOU]
The framework is written almost entirely from a DPS seat, and the one time a healer's
view appears — a druid who can't CC because he's behind on healing — it's decisive.
You play Disc. What's the healer's version of "the answer pool"? What are you tracking
that the DPS aren't?

ANSWER:

**B7. The Disc-vs-Hpal shuffle losses.** [YOU/TEST] — **[OUT OF SCOPE]** by Part 0: this is "what was the right play in situation X", which the model has stopped trying to answer. Left here because the underlying puzzle (why a coordinated-3v3 read loses in shuffle) is real — it is section D's problem, not a matchup problem.
You lost every round, against players you'd looked up and judged less experienced,
and you don't know why. That's the single most interesting unexplained result in
either document. Do you still have the talent builds involved, or the match IDs? With
the specs (Destro, BM, Arms, Feral, Disc vs Hpal) I can pull what the spell data says
about the matchup, and we can at least generate hypotheses to check against a replay.

ANSWER:

---

## C. Questions the archive or the database can answer

I can run all of these. They are listed in rough order of how much they'd change the
framework.

**C1. Do teammates actually align their major offensive cooldowns?** [ARCHIVE]
v1 asserted alignment as a principle; you believe it's "always better to line things
up unless there's a specific reason not to"; Calvish's team clearly does it. The
archive can measure it directly: the distribution of time between two teammates' major
offensive cooldowns. Clusters at zero = alignment is real and universal. Spread =
it's comp-specific or aspirational.

**C2. What happens in the tail of a damage window?** [ARCHIVE]
The unresolved question from v1, still unresolved. A Feral's Incarnation is 20s; the
healer trinkets at second 3; the target takes an 8s Pain Suppression. Is the remaining
12 seconds waste, or is it the real kill window (the part that lands after their
answer expires)? Measurable: for every real burst window where an enemy defensive
lands mid-window, what do high-rated players do with the remainder — keep hitting,
swap, or stop — and does the round's kill land in the tail or in a later go.

**C3. Do dampener-style comps beat setup comps disproportionately by attrition?**
[ARCHIVE] Your hypothesis from v1, never tested. Now more interesting because Calvish
shows a *setup* comp choosing the attrition plan into a bad matchup.

**C4. Burst concentration per spec.** [ARCHIVE/DATA]
The computable proxy for "does this comp want the go or the clock". `BurstGuideBuilder`
already measures the windows; this is share-of-damage-inside-window arithmetic on top.
Cheap, and it feeds Part 12 directly.

**C5. Overlap frequency by rating.** [ARCHIVE]
If Part 3 is right that overlap is the dominant loss condition, the archive should show
double-defensive-inside-N-seconds happening measurably more often in lower-rated
matches. If it doesn't, Part 3 is overstated. This is the best single falsification test
in the document.

**C6. Which defensives actually get overlapped?** [ARCHIVE]
If C5 holds — is it always trinket + personal, or are there specific pairs? A named
pair is a guide instruction.

**C7. Defensive durations vs offensive window lengths, per spec pair.** [DATA]
Pure lookup. Produces the "your window outlives their best single answer by N seconds"
statement, which is a fact rather than a recommendation, and is directly usable in
guide text.

**C8. Does dampening scale defensive cooldowns this patch?** [DATA/SOURCE]
Calvish states it plainly ("this patch, their cooldowns are affected by dampening") and
builds a comp choice on it. I can't confirm it from the spell data alone. If true it
belongs in the framework as a headline mechanic; if it's a tournament-ruleset thing,
that changes its weight for ladder players.

**C10. When does continuous damage beat setting up a go?** [ARCHIVE] — *new, from B2.*
The one measurable way out of the question the player declined to guess at. Compare the
enemy healer's offensive-global rate (CC casts, not heals) against the incoming damage
rate on their team. If healer CC output falls measurably as incoming damage rises, Part
4's third job is real and has a threshold; if it doesn't, it is a mirror-specific
artifact of one matchup and Part 4 should shrink back to two jobs.

**C11. Build the enemy-presses-X → you-press-Y table.** [DATA] — *new, from B1.*
Not a question so much as the most concretely buildable thing to come out of this
round. Offensive window lengths from `BurstGuideBuilder`, defensive durations from the
spell data, resolved per matchup and per target. It is what a guide's defensives section
should actually contain, and it needs no new data. Worth doing before anything else in
section C.

**C12. How much does spend-driven cooldown reduction shorten a real period?** [ARCHIVE]
— *new, from the Gemini source (Part 19.5).*
The database holds base and talent-modified cooldowns, but not reduction driven by what
a player *spends* during a game ("each cast of X takes 3s off Y"), because that is a
rotation run at a rate and no rate is recorded anywhere. So **every period the Matchup
Lab computes is an upper bound** — real goes come round sooner, by an unknown amount
that differs per spec. Measurable without new data: for each spec, the distribution of
real observed intervals between consecutive casts of the same cooldown in the 689
matches, against that ability's stated cooldown. A spec whose observed median is well
under its stated number has meaningful spend-driven CDR; one that matches does not. That
per-spec ratio is the correction factor the engine currently does not apply.

**C13. Is the priority cascade the order players actually use?** [TEST/YOU]
— *new, from the Gemini source (Part 19.2).*
Part 19.2 ranks answers **control the source → break line of sight → personal → external
→ trinket**, composed from Parts 3 and 6. Nobody observed that ordering; it is reasoned
from cooldown cost, and it is `[HYP]` until a player says otherwise. Two things would
settle it: your own read on whether the ranking is right (and where it inverts — there
must be threats where the personal is correct before the peel), and whether a ranked
trigger table is more usable than Part 3's single "enemy presses X → you press Y".

ANSWER:

**C9. How often is the correct save actually taken, by bracket?** [ARCHIVE]
The Tier-2 probabilistic framing from Part 11, and the input to the "usage rate beats
optimality" tiering in Part 6. Combat-log frequencies: trinket wasted, CC not
dispelled, external not used.

---

## D. Shuffle — **[DEFERRED]**

Deferred by A3: the comp builder is 3v3 only, so nothing here is on the path right now.
Kept because the questions are good and the moment shuffle enters scope they are the
starting point.

Nothing in either source document addresses Solo Shuffle, and the one time the 3v3
framework was applied to it, it lost.

**D1. What is the shuffle win condition?** [YOU]
In 3v3 it's the go. In shuffle, your read was that it's safe play — *"the same
calculated risk doesn't exist"*. Is the shuffle model closer to "survive your bad
rounds and win your good ones", i.e. a variance-management game rather than a
setup game?

ANSWER:

**D2. Is the answer pool still the right currency in shuffle?** [YOU]
Part 2 is the strongest claim in the framework. Does it survive when nobody is
coordinating? My guess is yes — and that it might be *more* important, because
uncoordinated teams empty their pools faster. Untested guess, don't trust it.

ANSWER:

**D3. Should the app treat shuffle rounds as a separate guide type?** [YOU]
A shuffle plan would be per-spec-in-a-lobby rather than per-comp. Different artifact,
different page. Worth building, or a distraction from 3v3?

ANSWER:

---

## E. Gaps in the model the framework admits to

**E1. Positioning.** Nothing in the data model represents position, and Part 14.1 shows
it deciding round after round in the final. Is this permanently out of scope, or is
there a cheap partial — e.g. tagging abilities as requiring LOS / range / a stacked
enemy, so a plan can at least state its positional preconditions in prose?

ANSWER:

**E2. Comms.** Part 14.2 argues the calls are a mechanic. The cheap version is that
guide steps can name the call ("call the trinket", "call CS down"). Do you want that in
the builder as a step type, or is prose enough?

ANSWER:

**E3. Spell targeting legality.** [DATA — unfixable without curation]
The data does not model who a spell can legally be cast on. Banish and Shackle Horror
both shipped in published machine guides as control on players. There's no automated
catch for this class of error. Options: a curated "valid targets" field on the
~200 control spells that matter, or accept it as a review-only check. Which?

ANSWER:

**E4. Terrain and maps.** Still entirely unmodelled. Calvish picks comps partly by map
size ("we figured we'd lock it on the big maps"). Out of scope, or a future axis?

ANSWER:

---

## F. More sources

**F1. Which other pros should I read?** [YOU]
You said you'd accumulate more pro transcripts. The Calvish one was unusually good
because it was a *post-hoc explanation of a plan that worked*, with the VOD in front of
him. What I'd most want next, in order:

  1. Another account of the **same event from the other side** (Echo / Raichu). The
     framework is currently built on one team's reading of games they won.
  2. A **healer's** account of anything. The model is DPS-shaped.
  3. Anything from a **Ret/War or other clock-favoured comp**, because Part 12's
     "playing the clock" column is the least evidenced part of the document.

ANSWER:

**F2. How should transcripts be stored?** [YOU]
Right now: `docs/arena/sources/`, raw, verbatim. I'd suggest keeping them raw and never
summarising them in place — the value of the Calvish file is in the specific quotes,
and a summary would have lost every one of them. Agreed?

ANSWER:

---

## Answered so far

| Q | Settled as | Landed in |
|---|---|---|
| A1 | **Revised 2026-09-22.** First read as "the audience is 2200+, below that the limiter is mechanics so the model doesn't help" — a non-sequitur, corrected by the player: *"a better model of the go does help mechanics, it gives players something to practise."* Execution is always execution *of* something. What changes with rating is the use (drill list vs plan), not whether it applies. | `arena-structure.md` Part 0, `/brain` #audience |
| A2 | All three artifacts are outputs of the comp builder, not separate pages. | Part 0 |
| A3 | 3v3 only; the comp builder does not do shuffle. | Part 0.1 |
| B1 | Ad hoc in general, **but** there is a per-matchup "enemy presses X → you press Y" lookup. Buildable from data we hold. | Part 3 |
| B2 | Unknown, and left unknown. Pro claims are contingent on the meta they won in. | Part 4 |
| B3 | Pre-CC is a narrow-damage-window property, not a general rule. Generalised form: the narrower the window, the more setup happens before it opens. | Part 8 |

---

## The scope this settles

The player's note after the first answering pass, which is now Part 0 of the model:

> "Each state or situation is unique and we can't answer everyone for every situation.
> But I think there is information that we can say is almost 100% of the time correct,
> like what a long CC chain looks like or a good go looks like. What tools a team has
> available and how those tools could be used to counter a strategy. But as for
> individual outplays in the game, they seem to be embedded inside a context that we
> don't really have access to all the information.
>
> But I think what is reasonable is to be able to have the general gameplan and
> playstyle for each talent build and comp. I think we have more than enough context and
> information to help get someone relatively high rated."

**[REVISED 2026-09-23 — see `docs/arena/sources/chriso-scope-correction-2026-09-23.md`.]**
This was read as retiring a whole class of question: anything of the form "what is the
right play in situation X" was marked out of scope and not to be asked again. **That went
further than the quote does.** "We don't have access to all the information" prices a
question's answerability; it does not forbid the question. The player's correction:

> "I don't want to have information or prose that implies the AI can't do something...
> we honestly don't know 100% what's going to happen, and I feel like that's a humility I
> would like to instil into our system."

So what this section really settles is **which answers can be stated and which can only
be proposed** — the split now in Part 0 of the model:

*Statable, because the data carries them:*

- What is reliably true about a comp's tools, chains, windows and counters.
- What a build implies about how the comp is played.
- Which enemy answers must be gone before a go is a kill rather than a strip.
- Measurements the archive can settle.

*Proposable, ranked and reasoned, never stated flat:*

- Who to kill, and how to rank the candidates (Part 16 already **requires** this).
- Whether a given go beats a given opponent.
- What to do in a named situation, given the state we can actually see.

Questions below still marked **[OUT OF SCOPE]** are kept with that tag for the audit
trail, but the tag now means *"no clean answer is expected"* rather than *"do not ask"*.
A question nobody can settle is still worth a reasoned, labelled attempt.

---

## Struck-through (answered)

*Nothing yet.*


I didn't answer the rest of those questions. I think what I find is that each state or situation is unique and we cant answer everyone for every situation. but I think there is information that we can say is almost 100% of the time correct. like what a long cc chain looks like or a good go looks like. 
what tools a team has available and how those tools could be used to counter a strategy. But as for individual outplays in the game they seem to be imbedded inside a context that we don't really have access to all the information. 

But I think what is reasonable is to be able to have the general gameplan and playstyle for each talent build and comp. I think we have more than enough context and information to help get someone relatively high rated. 