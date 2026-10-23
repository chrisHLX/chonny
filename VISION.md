# Vision

A website that gives a player everything they need to reach Gladiator or higher in WoW arena.

There are no certainties in the path to Gladiator each season. But the game itself is knowable, and that is what the site captures.

It combines comprehensive class and spell data derived directly from the game with in-depth insight from real players and real match data. Together, an aspiring Gladiator can find what they need to model and simulate what happens in arena.

## The website

- **Speed** — the technology prioritises speed for the user.
- **Optimisation** — the architecture stays simple: the minimum number of parts needed to do the job.
- **Clean formatting** — information is presented with resolved, finished formatting. No broken icons or asset links, no placeholder symbols, no `(varies)` or raw formula calculations, no duplicate spells.
- **Clean data** — an accurate, up-to-date representation of spells, abilities, talents, and mechanics.

## The direction (2026-10-02)

**The product is specific to each player.** The player and the AI are a team, and the AI's job is
to move that player's rating up, session by session. It reads their own arena games: their class
and talents, the teammates they queue with, the comps they face. It measures them against the
players they actually meet, names one thing to work on, and measures it again next session.
Guides, spec reads and the arena model are the baseline a player is measured against, not the
product itself. The map of this work: `match-review.md`.

The spell data below stays the foundation: every coaching read sees a button only through it.

## The platform (2026-10-17)

**MindCollector is a platform, one game at a time.** The same idea (read a player's own games against
the players they meet, name one thing to work on, measure it next session) applies to any competitive
game with a record of what happened. World of Warcraft arena is the first and the only one open.
StarCraft II is next: its replays hold the whole game, and its unit and upgrade data is public.
League of Legends is shown as coming.

The site says so: `/` is MindCollector's home with a card per game, each game has its own home (`/wow`,
`/sc2`, `/lol`) and its own menu, and a game that is not open yet has a page that says what is
coming and lets a player ask to be told. The games are listed once, in `config/platform.php`. A new
game gets its own route group and its own pages, never a parameter on WoW's (see the `/wow` route
group's comment in `routes/web.php`). What SC2 starts with is still to be chosen: replay reviews or a
unit and build reference (`docs/sc2/plan.md`). Achievements and credit rewards for improving are
proposed in `docs/achievements.md`.

## What building it has taught (2026-10-23)

Chriso's account of how building MindCollector changed his view of improvement. Full text, verbatim:
`docs/vision/feedback-loops-2026-10-23.md` (written with ChatGPT from his conversation; a view, not a
measurement).

**Improvement is a feedback loop, not information.** Goal, sensor, comparison, controller, action,
feedback: a thermostat, an arena player and a business run the same loop. Data improves nothing until
it is used to adjust behaviour and is then measured again.

What arena taught, each true beyond it:

- **The goal decides how feedback is read.** The same 150-rating loss is information, a failing
  strategy or a reason to quit, depending on the goal. Competing goals can look like a lack of
  direction. Hold goals steady; revise them deliberately.
- **Feedback is late.** A decision right for the moment observed can be wrong by the moment it lands.
  Experts look faster because they read earlier signals and anticipate: feedforward on top of feedback.
- **Actions change the system, not only the score.** A go forces answers, which changes what both
  sides have for the next go, and reveals how the other side responds. Pressure is worth the state it
  leaves, not the damage it does. The population findings point the same way: in every tier, what
  decides games is healer CC per go, defensives drawn and answers held
  (`docs/learning/population-findings-2026-10-07-top-tier.md`).
- **A measure can predict without explaining.** Losing with fewer than two defensives may be the
  consequence of losing control earlier, not the cause. Base rates, confounding, reverse causality,
  selection and timing all stand between a pattern and a recommendation.
- **Second-order effects feed back.** A cooldown that forces an answer can leave you open; a cut in
  labour cost can raise repeat jobs.
- **Loops compound both ways.** Confidence and practice reinforce each other; so do frustration and
  avoidance.
- **People are not thermostats.** They change their own goals and readings; attention, emotion and
  conflicting goals sit inside the loop.

**The missing piece is validation.** Three separate questions:

1. **Analytical validity:** is the reading of the data right?
2. **Behavioural validity:** does the recommendation change what the player does?
3. **Outcome validity:** does that change improve results?

A rating gain after using MindCollector proves none of them. Measure the specific behaviour, compare
like with like, and rule out other explanations. The same holds for business analytics: a dashboard is
not an improvement. **The product is the improvement process, with evidence that it works.**

The full cycle: **Observe → Model → Interpret → Decide → Act → Measure → Validate → Revise.** When a
recommendation fails, the system reconsiders its assumptions instead of producing another convincing
explanation.

**The broader question:** how do you understand a complex system well enough to make it perform better,
and show that your intervention caused the improvement? It applies to games, education, business
operations, personal development and possibly autonomous AI. Arena is where it is built and tested
first: a microcosm with a complete record of what happened.

> A system is not intelligent because it can explain what happened or recommend what to do next. Its
> practical intelligence is how well it uses feedback from reality to improve the next decision.

### Training (built 2026-10-23)

The reasoning behind this (cybernetics, causal reasoning, statistics, systems thinking, decisions,
incentives, AI literacy) is **Training** (`/training`), a section inside MindCollector, built here
first rather than as a separate product: if the ideas work in competitive games they work in
business, and a player's own games are both the examples and the test. How it works:
`docs/training.md`.

- Training sits **outside the game switcher**, beside Home, always visible, because it spans every
  game.
- **One notebook across the site.** Notes on a lesson passage and on a match finding come back for a
  revisit, where the player records whether their reading changed. Marking a finding "disagree" or
  "confusing" is also the coach's own feedback: which reads players keep disputing.
- **Lessons open on a problem**, ask for the player's reading before teaching, and count mastery only on
  a new situation in a different domain, first attempt. A before-and-after check on different problems
  measures transfer.
- **Concepts stay universal; a declared context (games, business) chooses the examples**, as in the
  player model (CLAUDE.md). Lessons are titled by the question a player asks ("Does a full go beat half
  a go?"), with the discipline as a label.
- Built: all 19 lessons across ten subjects from the `steersman` prototype (now superseded), notes
  that come back, the reasoning check, claims with calibration, and the course's own feedback page.
  The burst lesson sets its teaching model against what the archive measured (the population
  findings of 2026-10-07).
- Not built yet: notes on match findings in the same notebook (`ArenaNote` holds notes on games), a
  games context that opens a lesson on the player's own round, and a business context.

## The current focus

Getting the spell data confidently correct — accurate cooldowns, charges, talent modifiers, CC durations, and mechanics, presented cleanly. Every `(varies)`, every unresolved `$s1`/`${...}` token, every duplicate spell row, every missing icon is a defect to close, not a limitation to accept. Where a real value genuinely cannot be computed yet, the answer is to get the data — never to ship a placeholder, and never to fabricate a number.

## The future

Undecided. Once the spell data is confidently correct, the project moves on to whatever comes next.

## On the `.md` files

The Markdown files in this repo are reference and history, not gates. Follow the vision; implementation is Claude's domain. Where a note says something "can break," treat it as a warning to check before relying on it — not a rule that stops the work.

Sections in `CLAUDE.md` and elsewhere describing the older learning-platform direction (modules, quizzes, diagnostics, credits, AI content generation, the Next Step / Reflection loop) are kept as a record of how the code got here. They are not the current direction, and the systems they describe are dormant.

## Vision history

1. **Content-first** — the value was the size and quality of an AI-generated question/module bank.
2. **Diagnostics-first** — the value shifted to profiling the player and routing them to content.
3. **Expertise-capture** — the value was capturing how experts think, with content/diagnostics/recommendations as downstream representations.
4. **WoW arena spell data** — a fast, clean, accurate reference for class/spell/talent/mechanics data plus player and match insight, aimed squarely at players pushing for Gladiator.
5. **A coach for one player (2026-10-02)** — the player's own games read against the players they meet, through the spell data, the arena model and the desktop app. Diagnostics return (stage 2), now with evidence from the combat log rather than self-report.
6. **A platform for more than one game (2026-10-17)** — the same coach, game by game: WoW arena open, StarCraft II next, League of Legends shown as coming.
7. **Improvement as a validated feedback loop (current, 2026-10-23)** — the coach is judged by whether its reads are right, change what a player does, and improve results, not by how good its explanations sound. The reasoning behind it becomes Training, a section across every game.
