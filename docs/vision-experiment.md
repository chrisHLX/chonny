# Reading a game from the screen: the vision experiment

**Status: open experiment, n=1, nothing proven.** Started 2026-10-20. This file is the context a
new session needs to continue it. Nothing here has been checked against a combat log yet.

## The question

Everything MindCollector knows today comes from the combat log: a structured account the game
writes about itself. The experiment asks a different question.

> **What can be known about a game from the picture alone, and how reliably?**

It matters for two reasons, and they are worth keeping apart because they have different answers:

1. **As an input.** Could video be an observation source where no log exists? (Weak on current
   evidence — see "What this is not for".)
2. **As an information boundary.** The log records *what happened*. It cannot record *what the
   player could see*: whether the healer was on screen, whether a pillar broke line of sight,
   whether a cast bar was visible. Every coaching read the site makes today silently assumes the
   player had perfect information. The picture is the only source that can say otherwise.

The second is the one worth pursuing. It serves the current focus (understanding your own games)
instead of replacing it, and it needs no new abstraction layer.

## What this is not for

Do not justify this work as "opening up games without combat logs". It does not survive checking:

- **League of Legends** exposes Riot's `match-v5` API with a full timeline endpoint.
- **StarCraft II** — this repo *already* uses structured data. `data/sc2/techtree.json` is read
  from the game through Blizzard's API at patch 5.0.14.

Both of the games on the roadmap already explain themselves through structured data. A general
"shared timeline of observations" layer spanning several games is premature until a second game
actually needs one. See CLAUDE.md's first principle: fewest moving parts that do the job.

## The protocol

A model that knows WoW can produce a confident, plausible description of a frame without reading
the frame. That failure is invisible unless the method forces the distinction, so **every claim
carries its source**:

| Tag | Meaning |
|---|---|
| `[READ]` | Read off specific pixels. Name where — which bar, which frame, which icon. |
| `[INFER]` | Not visible. Deduced from what is visible plus how the game works. |
| `[PRIOR]` | Not visible and not deducible. Pattern-matched from knowing WoW. **A `[PRIOR]` that turns out correct is still a failure** — the system guessed. |
| `[UNKNOWN]` | A competent observer would want this and it cannot be got from the frame. |

This mirrors `arena-structure.md`'s `[OBS]`/`[DER]`/`[HYP]`, and inherits its correction: these are
**not a confidence ladder**. They say what kind of thing would falsify the claim. A `[READ]` is
re-checkable by looking at the frame again, which is what makes it the checkable one.

**Two scores matter**, and the second is the real one:

1. What fraction of `[READ]` claims survive checking.
2. **How many `[PRIOR]`s are emitted at all.**

### Rules that keep the result meaningful

- Observations are committed **before** the log is consulted. A post-hoc read is worthless.
- The frame is handed over with **no context** — no comp, no map, no outcome.
- Prefer a frame from a **video**, not a native screenshot. Video is the realistic case and a
  harder read (compression, letterboxing).

## Frame 1 — 2026-10-20

A video still, letterboxed and compressed. Black Rook Hold Arena, 3v3, six players alive.

### What was read correctly off the frame

Scoreboard (`Gold Team: 3 Players Remaining`, `Time Remaining: 18:08`), zone name, level 90
nameplates, all six player names split into the right teams, three enemy health values
(916 K / 1042 K / 1146 K), two nameplate health readings, a `Cyclone` cast with its target named,
a floating `Absorb` indicator, two cooldown timers on enemy frames, and a damage meter.

**A cross-check that worked, and is worth reusing:** Soulrulerxc's nameplate read `1.1M` and his
arena frame read `1146 K`. Two independent regions of the UI agreeing on one unit is a cheap,
automatable confidence signal — and the basis of a self-check a real detector could run.

### The trap in this frame

**The damage meter is not this match.** Its six names include Addarray, Wutainx and Longtimenose,
none of whom is on either visible team — almost certainly an Overall segment spanning earlier
fights. A naive reader attributes 27.8M of damage to this round and is wrong by an unknown factor.

Generalise it: **the UI shows state from outside the current frame's scope.** Any detector that
reads a number off the screen and treats it as a fact about the current round will inherit this.
It is the single most valuable thing frame 1 produced.

### What could not be read, and should not be faked

**Class and spec were not identifiable.** The icons are roughly 20px and compressed past reliable
identification. A plausible comp could have been produced from the spell names and bar colours —
that is precisely the `[PRIOR]` failure, and it was declined. If a future run reports a comp from a
frame like this, check it hard.

Also unavailable: which player is the user, cooldowns remaining, positioning and line of sight (the
camera shows one corner; most players are off-screen), and anything about the preceding seconds.

### Not yet done

**None of this has been diffed against the combat log.** Until it is, frame 1 is an unscored
sample. The diff needs frame-time ↔ log-time alignment to about ±100ms; for one frame, align by
hand.

## What the structured data added, tested not assumed

The second half of the question: does the repo give a reader capability its training does not?
Two lookups against the live database, both from text read off the frame.

**1. The red error string `…that while horrified`.**

```
php artisan wow:abilities --while-cc=horror
  Pain Suppression    instant  180s  2 charges   Discipline Priest
  Roar of Sacrifice   instant  120s              Survival Hunter
  Sigil of Chains     instant   60s              Havoc Demon Hunter
```

Three abilities in the entire game are castable while horrified. That is a short, exact,
patch-current list attributed to the specs that can press it — and it turns a red error message
into a specific question about what was still available.

**2. The readable `Cyclone` cast.**

```
php artisan wow:abilities --class=druid --category="Crowd Control"
  Cyclone    Disorient  mech:Banish  cast
```

Two facts a single frame cannot give: Cyclone is on the **Disorient** DR category, so whether it
lands full duration depends on prior Disorients on that target; and it is a **cast**, not instant,
so the bar in the frame is interruptible. Both are actionable, and neither is visible.

> `mech:Banish` is a known data artifact, not a claim about the spell. Nothing models who a spell
> may be cast on, which is why Banish and Shackle Horror shipped as player CC (CLAUDE.md, machine
> guides). Do not read the mechanic field as gospel here.

**The finding:** the picture supplies *what and when*; the database supplies *what that means and
what else was possible*. Neither is much use alone. This is the strongest result so far and it
points at the overlay being a join between two sources rather than a video feature.

## Risks to carry forward

- **UI dependence is the big one.** Reading health bars, nameplates and cooldown icons means
  reading *one player's* interface — addons, Plater/ElvUI profile, nameplate scale, bar layout. The
  combat log is UI-independent; the picture is not. A detector tuned on one player's recordings may
  fail completely for the next. **The informative next frame is from a different player's UI, not
  another frame from the same one.**
- **A general multimodal read is an upper bound on quality and a poor guide to cost.** A real
  pipeline would use cheap specialised detectors for frequent things (health bars, nameplates) and
  reserve model calls for interesting moments.
- **n=1.** Nothing here is a rate.

## Next steps, in order

1. **Diff frame 1 against its combat log.** Score the `[READ]` claims. Needs the round id.
2. **Build the diff harness** — takes a round id and a list of claimed observations, scores
   precision, recall and timing error against the stored timeline. This is what makes every later
   run measurable instead of impressionistic. Not built yet, deliberately: not worth writing until
   there are enough samples to score.
3. **A frame from a different player's UI**, to size the dependence risk.
4. **Only then** consider recording integration — as an input mechanism, not the feature.

## Related

- `arena-structure.md` — the `[OBS]`/`[DER]`/`[HYP]` convention this borrows from, and why it is
  not a ladder.
- `match-review.md` — the combat-log pipeline that provides the ground truth to score against.
- `RoundAnalysisService::timeline()` — the 0.1s-resolution series (casts, health readings, deaths)
  a vision timeline would be diffed against. `CombatLogIngestService::timestampMs()` gives
  millisecond absolute time per log line, which is what makes alignment possible at all.
