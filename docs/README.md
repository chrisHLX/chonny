# The doc map

Every doc in the repo, by area and status. **Current**: true now, keep it true. **Reference**: true,
consulted for a task. **Dormant**: describes code still there but not the direction. **History**: a
record; never edit it to change the past. **Which docs to read for a task** is `CLAUDE.md`'s "Before
you start" table.

**Keeping it clean.** `CLAUDE.md` is loaded every session, so it stays a library: a fact goes there
only if most sessions need it, and everything else goes in the doc for its area with a pointer. A doc
over about 30KB opens with what to read for which question, and is read by section. A new doc gets a
row here the day it is written. Moved text goes to `docs/history/` rather than being deleted.

**A doc or comment citing "CLAUDE.md"** means `docs/rules.md` when it gives a rule number. When it names
a section ("Canonical Context Module Template", "AI-Assisted Game Data", "Synergies tab", "the patch
row is relabelled in place"), that is the old 790KB CLAUDE.md: search
`docs/history/engineering-log-2026.md` for the heading.

## Working in the repo

| File | Status | What it is |
|---|---|---|
| `CLAUDE.md` | current | The library: vision, task routing, commands, environment, one-line rule and trap indexes. |
| `docs/rules.md` | current | The full text of rules 1–39, the traps, the test baseline, working practice. |
| `docs/architecture.md` | current | How the pieces fit, security, and every smaller service, command and page. |
| `docs/design-system.md` | current | Tokens, component classes, icons, pickers. |

## The current work: the match review and the desktop app

| File | Status | What it is |
|---|---|---|
| `match-review.md` | current | **Start here.** The pipeline, what is measured where, what to re-run after a change, the data files that decide what is seen, the reading rules, the traps. |
| `match-review-operations.md` (77KB) | current | The method: the log's field offsets, what a go is, every measure, the three questions for defensives, the cooldown ledger, the tag audit. By section. |
| `match-review-analysis.md` (120KB) | current | The findings, dated, with their numbers and corrections. By section. |
| `match-review-tools.md` | current | Every tool in `tools/match-review/`. Read before writing a new analysis script. |
| `tools/desktop-app/README.md` | current | The downloadable app, on Chriso's PC since 2026-10-25: what it does, recording, YouTube, updates, publishing a version, the Store build. |
| `tools/log-manager/README.md` | dormant | The PowerShell app, retired from the PC on 2026-10-25, kept in the repo. |
| `tools/wow-addon/MindCollectorArenaLog/README.md` | reference | The addon that turns combat logging on in arena. |
| `tools/app-pictures/README.md` | current | The pictures of the app on the front page and `/wow/app`, and the two checks before committing new ones. |
| `guides-from-play.md` | current | How played games become guides; the level-of-play rule. |
| `docs/class-mechanics.md` | current | How a spec's buffs and talents combine (empowered casts), how to measure every spec, and what to redo after a patch. Live on PvP Guides for 25 specs. |
| `docs/combat-log-ingest.md` | reference | How a combat log becomes archive games (rule 12 in full). |
| `docs/international.md` | current | Players from other regions, China first: why their games showed Chinese, the English rule, the name table, reaching the site from China. |
| `docs/testers/how-to-test.md` | current | How a tester runs the app. |
| `docs/training.md` | current | Training (`/training`): lessons, notes that come back, the reasoning check, claims; how to write a lesson. |
| `.claude/skills/review-games/SKILL.md` | current | The project skill for "look at my games". |
| `docs/vision-experiment.md` | open experiment | Reading a game from the screen, and the [READ]/[INFER]/[PRIOR]/[UNKNOWN] protocol. n=1. |
| `docs/achievements.md` | proposal | Achievements for improving, not grinding. Not built. |
| `docs/ai/ask-about-this-game.md` | proposal | A model answering from stored measurements. Not built. |
| `docs/plan-overlay.md` | proposal | A player's plan held against a played game, each teammate seeing their own part, the cooldown feedback loop, and where AI fits: the research, the design and the build order. Prototypes only (`tools/match-review/planread.php`, `cdaudit.php`). |
| `docs/sc2/plan.md` | research | StarCraft II: where its knowledge comes from, WoW's pieces mapped across. Built so far: `data/sc2/` and its quizzes. |
| `docs/reviews/` | history | One-off session reviews. |
| `addon-upgrades.md` | history | The capture-side wants of Sep 2026; all shipped. |

## What the whole archive shows

Read before writing advice, a tip, or a "how the top plays" line.

| File | Status | What it is |
|---|---|---|
| `docs/learning/population-findings-2026-10-06.md` | current | Which basics kill and which habits go with winning, across 795 games, with confound checks; how tips re-test themselves (`wow:population`). |
| `docs/learning/population-findings-2026-10-07-trading.md` | current | Defensive trading: what wins is an answer back for the next go. Stacked reductions multiply. |
| `docs/learning/population-findings-2026-10-07-top-tier.md` | current | The best games: how the top plays differently, and that what decides its games is the same as below. |
| `docs/learning/population-findings-2026-10-10-decisions.md` | current | Defensive choices judged within the same situation; "pressed nothing" is a choice. |
| `docs/learning/fear-windows-2026-10-14.md` | current | Exploration: positions in the log and Psychic Scream windows. |
| `data/arena-logs/GCD and Go Analysis.md` | reference | Reconstructing what is pressed in a go from raw casts, correcting for off-GCD abilities (2026-08-17). |
| `data/arena-logs/spec-cooldowns/` | reference | Per-spec cooldown tables from the archive. |

## The arena model

| File | Status | What it is |
|---|---|---|
| `docs/arena/structure-summary.md` | current | **Start here.** The model on one page, each line naming its Part. |
| `arena-structure.md` (75KB) | current | The model (v2): the go/anti-go cycle, the answer pool, the rating ladder, tagged [OBS]/[DER]/[HYP]. A [HYP] is never asserted. By Part. |
| `data/brain/brain.md` | current | The public statement at `/brain`; every machine guide is written from it. Section ids are comment anchors: never change one. |
| `arena-open-questions.md` | current | What the model still guesses at, and who could settle each. |
| `docs/arena/synthesis-process.md` | current | How to fold a new prose source into the model. |
| `docs/arena/sources/chriso-scope-correction-2026-09-23.md` | reference | Why the model proposes rather than refuses. Read before adding any "we can't do X" line. |
| `docs/arena/sources/` | history | Verbatim sources, each with a distilled note. Never edit a raw file. The Gemini source had the model in its context, so its agreement is not corroboration. |

## Guides and the site

| File | Status | What it is |
|---|---|---|
| `docs/site-pages.md` | reference | Every main page, what it does and the decisions behind it. |
| `docs/machine-guides.md` | reference | Machine-drafted guides: authoring, feedback export, talent feasibility, bylines. |
| `guide-writing.md` | current | How to draft one: the length budget, the shape, past errors. |
| `data/claudes-guides/README.md` | reference | The hand-authored JSON guides at `/wow/claudes-guides`. |
| `data/claudes-counters/README.md` | reference | The spell counters behind `/spell-counters`. |
| `data/matchup-profiles/README.md` | reference | The Matchup Lab's per-spec artifact, and what it cannot say. |
| `data/kick-priorities/README.md` | reference | Which casts are worth a kick, and how the archive checks them. |
| `docs/guides/` | history | Reader feedback on machine guides, exported before re-authoring. |
| `monetisation-read.md` | history | Usage against the case for a paywall (Sep 2026): mostly crawlers, distribution is the problem. |

## The spell data

| File | Status | What it is |
|---|---|---|
| `spell-acquisition-model.md` | reference | Every acquisition script, command and service. Start here. |
| `game-data.md` (75KB) | reference | The import pipeline, folder by folder, with dated findings. By section. |
| `wow-spells.md`, `wow-spell-data-model.md` | reference | How spell data works and is modelled. |
| `knowledge-gaps.md` | current | Append-only ledger of module prose against spell data. |
| `spellbook-verifier.md` | reference | Addon export → snapshot → diff. |
| `tools/wow-addon/MindCollectorExport/README.md`, `data/spellbook-exports/README.md` | reference | The spellbook export addon, and where its files go. |
| `dr-categories-reference.md` | reference, **stale** | A 2022 community DR guide, confirmed wrong twice. A hint, never authority. |
| `arena-log-api.md` | history | The WoWArenaLogs API. Closed to us (rule 12). |
| `data/spelldata/filtered/*/INDEX.md` | generated | Per-class spell indexes, 60–95KB each. Grep them; never read one whole. |

## Production

| File | Status | What it is |
|---|---|---|
| `DEPLOY.md` | current | The box, connecting, permissions, what `deploy.sh` does, env vars, nginx. |
| `docs/youtube-verification.md` | current | YouTube uploads: adding a tester, and what Google needs to open it to everyone. |
| `docs/history/deploy-incidents-2026.md` | history | The 2026-08-28 incident in full, and the password method that ended on 2026-10-29. |

## The dormant learning platform

| File | Status | What it is |
|---|---|---|
| `system-integration.md` | reference | How the learning platform could join the spell data and the model. Read before touching modules, diagnostics or mastery. |
| `docs/learning/question-audit-2026-09-24.md` | history | The authored question bank checked against the model and the spell data. |
| `module-upload-format.md` | dormant | Shape for module content. |
| `app/Http/Services/playstyle-analysis.md` | reference | The per-player talent-usage read. |
| `docs/archive/` | dormant | Prompts, the research feature, the next-step loop, the quiz runner, a seeder audit, a pricing audit. How the code works; not the direction. |

## Ideas and history

| File | Status | What it is |
|---|---|---|
| `VISION.md` | current | The vision and its history. |
| `docs/vision/feedback-loops-2026-10-23.md` | history | Chriso's account, verbatim, of what building MindCollector taught. Condensed in `VISION.md`. |
| `docs/future-plan.md` | current | Ideas flagged for later, deliberately not built. |
| `docs/forever/market-research-2026-11-07.md` | research | WoW: Forever: which materials later content will use (from the client's recipe data), the content calendar, and a design for a price-and-forecast addon. Scripts in `tools/forever-market/`. |
| `tools/wow-addon/MindCollectorObserver/README.md` | current | Forever addon that remembers what you look at (items, auction prices, talents), guesses why, and reports what changed later. Not yet shipped by the desktop app. |
| `tools/forever-ask/README.md` | current | `/ask` from inside Forever: the addon sends a question at a reload, `ask_bridge.py` answers with Claude (web, Wowhead in your Chrome, game-file item facts) into the addon's `Answers.lua`, and the next reload shows it, with map pins. |
| `docs/history/engineering-log-2026.md` | history | Every dated write-up, bug trace and reversal through 2026-09-18 (790KB, the old CLAUDE.md). Search it for the *why*. |
