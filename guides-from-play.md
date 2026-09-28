# Guides from observed play

How the games we play become guides on MindCollector, and why. This is the loop that turns a
combat log into something a player can learn from, and keeps it learning.

---

## Where the idea started

On 2026-09-28, Chriso reviewed fifteen 3v3 games his team played on 26 Sep (Discipline Priest,
Unholy DK, Windwalker Monk: "Walking Dead") with the match review tools. The review found a lot:
which goes converted, what the teams that beat us did differently, how a Gladiator-level
Shadow Priest / Survival / Mistweaver team took apart our go, where the healer's trinket went.
All of it was true, and none of it was in a form a player could use.

His observation was that it was **one thing seen at different levels of detail**, each useful
for a different purpose:

| Detail | Example from 26 Sep | Useful for |
|---|---|---|
| **The comp** | Walking Dead's successful goes, its finishers (Touch of Death, Dread Plague Erupt), its kill targets, its matchups (Warrior/Frost/Druid 0–2) and how a named Gladiator team played it | a player on that comp |
| **The role** | the healer's Medallion gone before the enemy's kill go; defensives traded well or overlapped | any healer, any comp |
| **Any comp** | a go forces resources and the kill comes on a later go; the enemy neutralises a go by answering at the right moment | every player |

And that **guides are the right place to express the comp level**, not a separate notes file,
because then every session of games produces educational content, and the guides grow as more
games are played.

## What we want to achieve

**A growing, evolving system for arena guides and for player development.**

- **Every session of games we play produces guide content**, derived from measurement, not
  from memory or opinion.
- **A guide says what level of play it describes.** Games where every player is a Gladiator
  describe Gladiator-level play; a guide drawn from them is a Gladiator-level guide, and says
  so. That is what validates it.
- **Guides evolve.** As more games are played the evidence behind each guide grows, its claims
  firm up or fall away, and readers' corrections are folded back in.
- **The same evidence trains players.** The review of your own games tells *you* what to change
  (for the Disc Priest on 26 Sep: the Medallion was spent before the enemy's kill go); the
  guides tell *everyone* what works at a given level.

## The loop

```
  play games
      │
      ▼
  wow:sync ─────────────► archive (your games, never committed)
      │
      ▼
  review tools (tools/match-review/) ──► review table + measures
      │                                   (method: match-review-operations.md)
      ▼
  analysis (match-review-analysis.md) ── the evidence: every claim with its games and numbers
      │
      ├──► level of play (below) ── decides which guide the evidence can feed
      │
      ▼
  guide draft (data/machine-guides/*.json) ── written per guide-writing.md, from the Brain
      │
      ▼
  guides:author --dry-run, then publish ── on MindCollector
      │
      ▼
  readers correct it ── guides:export-feedback (on the server)
      │
      └──► corrections fold back into the analysis, the Brain and the next draft
```

Where each level of detail goes:

| Detail | Home | Reader |
|---|---|---|
| **The comp** | **guides**, drafted from `match-review-analysis.md` | players on that comp |
| **The role** and **any comp** | `arena-structure.md` (the Brain, published at `/brain`), as observations with their sample sizes | every player, and every guide, because every guide is written from the Brain |
| **The evidence itself** | `match-review-analysis.md` | whoever drafts or corrects a guide |

**The evidence never lives in the guide.** A guide is a plan in about 2,500 characters. The
games and numbers behind each claim stay in the analysis, so any correction can be traced to
the game it rests on.

## Level of play

A guide's level comes from **the games it is drawn from, and from all six players in each**,
not from our team alone.

- **Gladiator level:** every player in the game has at least one Gladiator season (3v3).
  Read from the Blizzard profile API by `tools/match-review/experience.php`.
- **Below that**, the same rule with the best rank all six share (Elite, Duelist, Rival).
- **Unknown players** (no public profile) make a game **borderline**: it is counted separately
  and never silently promoted.

Two cautions:

- **Gladiator titles are lifetime and account-wide.** "5x Glad" can include seasons from years
  ago. The API gives each title's season, so the level should eventually count **recent**
  seasons only.
- **The game's MMR is recorded beside the level.** Early in a season MMR is deflated, which is
  exactly why experience is the better measure, but both are shown.

On 26 Sep, **7 of the 15 games** were Gladiator level with every player known (19:26, 19:47,
19:51, 19:57, 20:13, 20:19, 20:22: 4 won, 3 lost), and 2 were borderline (19:54, 20:08).

## Rules for a guide drawn from play

1. **It states its evidence** in the draft's `"evidence"` object: `level`, `games`, and a one-line
   `note` (date range, record). The page shows it under the byline: "Gladiator level · drawn
   from 7 observed games · 26 Sep 2026 · 4 won, 3 lost".
2. **A claim resting on a handful of games is reasoning, not settled fact.** It is written as
   a proposal with its reason (the Brain's Part 0), never asserted. As games accumulate, it
   can firm up.
3. **Only what was measured.** A guide from play says what happened in those games and what
   worked; it does not invent a sequence nobody played. Where the games do not show something,
   the guide does not claim it.
4. **Everything in `guide-writing.md` still applies**: the length budget, one kit a character
   can actually have (`--dry-run`), headings as claims.
5. **Export feedback before every update.** Re-authoring replaces a guide's sections, which
   deletes every reader comment anchored to them. `guides:export-feedback` first, fold the
   corrections in, then re-author.
6. **Opponents stay anonymous in the guide.** A guide names specs, never characters.

## Public reading

**Guides stay public to read. Acting on one needs an account** (2026-09-29). A sign-up wall
across the machine guides was built and reversed the same day, on Gemini's recommendation (a
language model's product advice, adopted by Chriso): a cold visitor will not create an account for
content they have not sampled, so a wall trades reach for nothing. Instead:

- **"Accurate for 12.1?"** at the top of every guide: a one-second yes/no vote, no account needed,
  one per reader per patch (`user_guide_accuracy_votes`). It breaks the passive read, and a guide
  voted inaccurate for the current patch is one for this loop to re-check.
- **"Make this plan yours"** after the guide: copy it into the reader's planner as a private
  draft (`UserGuideDuplicator`). This is where an account is asked for; a guest is sent to sign up
  and brought back to the guide. A guest can still try a blank plan with no account.

Gemini's other suggestions, **not built yet**: gating the interactive layer (quizzes, matchup
decision trees, a deep-dive section per matchup) rather than the reading; saving a guide to a
personal or guild collection; a Discord sign-in.

## What exists and what does not yet

| Piece | State |
|---|---|
| Capture and ingest (`wow:sync`) | done, committed, tested |
| Review measures and review table | draft scripts in `tools/match-review/`, run by hand |
| Experience lookup | draft script; uses the site's own Blizzard parsers |
| Raw output on the site | `/wow/game-review/analysis`, uploaded by its owner |
| Level of play on a guide | `user_guides.evidence_level` / `evidence_games` / `evidence_note`, set from a draft's `"evidence"` object by `guides:author` and shown under the byline (2026-09-28) |
| Class guides from play | `unholy-dk-gladiator.json` and `windwalker-monk-gladiator.json`, drafted 2026-09-28 from `rotation.php` on the 7 Gladiator-level games. `guides:author` writes class guides when a draft says `"type": "class"` |
| The build behind the why | a draft's `"builds"` attaches each roster spec's build (talents by name with `:rank` / `#node`, PvP by name) to the guide's slot; the page shows it under "Talents this guide is written for" (2026-09-29) |
| First guide from play | `data/machine-guides/walking-dead-gladiator.json`, drafted 2026-09-28 from the 7 Gladiator-level games of 26 Sep. Authored as a **draft** for the site admin to read and publish |
| The loop as one command | not yet: the scripts need the team and dates as arguments, JSON output, tests |
| Folding role and any-comp findings into the Brain | not yet done for 26 Sep |
