# The doc map

Every doc in the repo, by area, with its status. **Current**: true now, keep it true. **Reference**:
true, consulted for a task. **Dormant**: describes code that is still there but not the current
direction. **History**: a record; never edit it to change the past. Start with `CLAUDE.md` (rules
and orientation), then the area you are working in.

**Which docs to read for a task** is CLAUDE.md's "Before you start" table. **An older doc that
cites "CLAUDE.md's ... section"** by a name CLAUDE.md no longer has ("Canonical Context Module
Template", "AI-Assisted Game Data", "Synergies tab", "the patch row is relabelled in place") is
pointing at the old 790KB CLAUDE.md: search `docs/history/engineering-log-2026.md` for that
heading. Rules are still cited by number and still mean the same rule.

## The current work: the coach, the match review, the desktop app

| File | Status | What it is |
|---|---|---|
| `match-review.md` | current | **Start here.** The whole area on one page: the pipeline, what is measured where, what to re-run after a change, the data files that decide what is seen, the reading rules, the traps. |
| `match-review-operations.md` | current | The method: the combat log's measured field offsets, what a go is, every measure and how it is taken, the three questions for defensives, the cooldown ledger, the tag audit. Read before measuring anything from a log. |
| `match-review-analysis.md` | current | The findings, dated, with the numbers each rests on: what reviews of the user's games found, and the corrections to them. Read before claiming how an ability is used or why games were won or lost. |
| `match-review-tools.md` | current | Every research tool in `tools/match-review/`, what it answers, and the one-off scripts worth rewriting. Read before writing a new analysis script. |
| `tools/log-manager/README.md` | current | The desktop app (MindCollector Logs): every page, where its files live, how it syncs. |
| `guides-from-play.md` | current | How played games become guides, and the level-of-play rule. Read before drafting a guide from match data. |
| `addon-upgrades.md` | current | What Chriso wants next from the capture side. Wants, not design. |
| `docs/reviews/` | history | One-off reviews of a session (2026-10-01: Disc with LFG partners). |
| `docs/ai/ask-about-this-game.md` | proposal | "Ask about this game": a model answering from stored measurements. Not built. |
| `docs/testers/how-to-test.md` | current | How a tester runs the app. |
| `docs/combat-log-ingest.md` | reference | How a combat log becomes archive games: team ids, Solo Shuffle, Feign Death, the checks behind each (rule 12 in full). |
| `.claude/skills/review-games/SKILL.md` | current | The project skill for reviewing games: loads itself on a "look at my games" request. |

## The arena model

| File | Status | What it is |
|---|---|---|
| `arena-structure.md` | current | The arena model (v2): the go/anti-go cycle, the answer pool, the rating ladder. Every claim tagged [OBS]/[DER]/[HYP]; a [HYP] may be written, never asserted as settled. Read before building anything that scores a plan. |
| `data/brain/brain.md` | current | The model's public statement, rendered at `/brain`. Every machine guide is written from it. Section ids are comment anchors: never change one. |
| `docs/arena/structure-summary.md` | current | The arena model on one page: the objective (a relative resource state, Part 20), the vocabulary, the match as setup → go → answer → reset, and what the analysis measures at each stage. Each line names its Part of `arena-structure.md`. |
| `arena-open-questions.md` | current | What the model still guesses at, and who could settle each. |
| `docs/arena/synthesis-process.md` | current | How to fold a new prose source (a transcript) into the model. |
| `docs/arena/sources/` | history | Verbatim sources with a distilled note each. Never edit a raw file. The Gemini source had the model in its context, so its agreement is not corroboration. |
| `docs/arena/sources/chriso-scope-correction-2026-09-23.md` | reference | Why the model proposes rather than refuses. Read before adding any "we can't do X" line. |

## Guides and the site

| File | Status | What it is |
|---|---|---|
| `docs/site-pages.md` | reference | Every page of the site, what it does and the decisions behind it. |
| `docs/machine-guides.md` | reference | Machine-drafted guides: authoring, feedback export, talent feasibility, bylines. |
| `guide-writing.md` | current | How to draft a machine guide: the length budget, the shape, the errors past drafts made. |
| `docs/guides/` | history | Reader feedback on machine guides, exported before re-authoring. |
| `monetisation-read.md` | history | Usage against the case for a paywall (2026-09-24, with a 09-29 update): mostly crawlers, distribution is the problem. |
| `data/matchup-profiles/README.md` | reference | The Matchup Lab's per-spec artifact, and what it cannot say. |

## The spell data

| File | Status | What it is |
|---|---|---|
| `game-data.md` | reference | The import pipeline, folder by folder, with dated findings. |
| `spell-acquisition-model.md` | reference | Every acquisition script, command and service. |
| `wow-spells.md`, `wow-spell-data-model.md` | reference | How spell data works and is modelled. |
| `knowledge-gaps.md` | current | Append-only ledger of module prose against spell data. |
| `spellbook-verifier.md` | reference | Addon export → snapshot → diff. |
| `dr-categories-reference.md` | reference, **stale** | A 2022 community DR guide, confirmed wrong twice. A hint, never authority. |
| `arena-log-api.md` | history | The WoWArenaLogs API shape. That API is closed to us (rule 12). |

## Production

| File | Status | What it is |
|---|---|---|
| `DEPLOY.md` | current | The deploy runbook, and how to work on production from Claude Code. |

## The dormant learning platform

| File | Status | What it is |
|---|---|---|
| `system-integration.md` | reference | How the learning platform could join the spell data, guides and the model. Read before touching modules, diagnostics or mastery. |
| `docs/learning/question-audit-2026-09-24.md` | history | The authored question bank checked against the model and the spell data. |
| `docs/learning/population-findings-2026-10-06.md` | current | Learning from every player in every game: which basics kill and which habits go with winning across 795 games and 1,193 players, with confound checks; macro and positioning signals from failed casts; how the tips re-test themselves (`wow:population`). Read before writing advice or a new tip. |
| `docs/learning/population-findings-2026-10-07-trading.md` | current | Defensive trading across the archive: higher-rated sides spend more answers per go, but within a band spending more loses; what wins is an answer back for the next go. Stacked reductions multiply. Behind the Matchup Lab's trading plan and the Basics tab's answers line. |
| `docs/learning/population-findings-2026-10-07-top-tier.md` | current | The best games in the archive: rounds tiered by team MMR and by players' arena history (Blizzard profiles). How the top plays differently (opens sooner, goes together, kicks and CCs more, gives no free exchanges), and that what decides its games is the same as below: healer CC per go, defensives drawn, answers held. Read before writing a "how the top plays" line. |
| `module-upload-format.md` | dormant | Shape for module content. |
| `app/Http/Services/playstyle-analysis.md` | reference | The per-player talent-usage read. |
| `docs/archive/` | dormant | Prompt inventory, the research feature, the next-step loop, the quiz runner, a seeder audit and a pricing audit: the earlier learning-platform direction. Kept for how the code works; not the direction. |

## Ideas and history

| File | Status | What it is |
|---|---|---|
| `VISION.md` | current | The vision and its history (stage 5, 2026-10-02: a coach for one player). |
| `docs/future-plan.md` | current | Ideas flagged for later, deliberately not built. |
| `docs/history/engineering-log-2026.md` | history | The archive: every dated write-up, bug trace and reversal through 2026-09-18 (790KB, the old CLAUDE.md). Search it for the *why*. |
