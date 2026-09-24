# System Integration

How the dormant learning platform (modules, diagnostics, concept mastery) connects to what this
project has built since: the spell data, the guides, and the Brain.

> **Status, 2026-09-24.** Stages 1–3 are built. Stage 1 is `App\Learning\ConceptCoverage`
> (concept → brain sections → generated question types, with the four unbacked concepts naming
> their own reason). Stage 2 is `docs/learning/question-audit-2026-09-24.md`. Stage 3 is concept
> drills — `/wow/quiz/{class}/{spec}/drill/{concept}`, generated questions scored against a
> concept with no `questions` row and no write to `UserConceptMastery`.
>
> Two things in the plan below turned out to be wrong, both in §2. The numeric-drift count of
> "only 7" was measured over the prompts; over the options as well it is 16, and **three of those
> answers are now wrong** — two of them state a crowd control duration in PvE seconds on a site
> about arena. And the "one real piece of work" in Layer 1 (letting a question exist without a
> `questions` row) needed no work at all: a quiz attempt already stores its questions as asked,
> and its `subject` column already carries whatever key the game wants. The real work was the
> accumulator, because `MasteryService` denominates a concept by the whole authored bank.

Written 2026-09-24 after reading the old system end to end. The short version: **the learning
platform was never the problem. Its inputs were.** It was built to teach from content that had
nothing under it, and the thing it needed — a body of verified, current, checkable facts about
the game plus a stated model of what arena *is* — is exactly what now exists.

---

## 1. What is actually there

Three systems, built at different times, currently unaware of each other.

### The learning platform — dormant, not broken

| | |
|---|---|
| Categories → Subjects → Concepts | 7 categories, 10 subjects, **91 concepts** (7 for WoW) |
| Axes | 42, of which **6 are the game axes**: Resources, Execution, Information, Decision, Control, Adaptation |
| Modules | 25 total, **9 for WoW**, 27 module pages |
| Questions | 349 total, **100 on WoW concepts** |
| Live usage | 0 diagnostic attempts, 0 concept mastery rows, 0 next steps |

The machinery is complete and considerable: `MasteryService` (per-concept and per-axis mastery
from answered questions), `DiagnosticProfileService` (trait/axis/concept scores → an AI-written
profile), `NextStepService` (815 lines — one next action, grounded against real concept ids),
`RecommendationService`, `RoadmapService`, `ReviewQuestionService`, `ModuleUploadService`.

The WoW concepts are: **Role Fundamentals, Crowd Control, Cooldown Management, Positioning,
Target Switching, Awareness & Tracking, Team Composition.**

### The class quizzes — live, and the architectural answer

`App\Quiz\*`. Three levels per spec, 8 questions an attempt, questions **generated at attempt
time from live data** rather than authored and stored. `WowAbilityFacts` reads only facts
something else already verifies:

> "Only facts that are verified somewhere else get used, because a quiz that marks a right answer
> wrong is worse than no quiz: crowd control — the hand-curated DR category… offensive and
> defensive cooldowns — the arena-log classification behind WoW Comps' cooldown tabs… interrupts —
> the curated `is_interrupt` flag. `categorize()`'s effect-based guess is deliberately not used."

Cached against `spellCacheVersion()`, so a spell import changes the next quiz.

### The game data and the model — built since, connected to neither

Spells with curated DR categories, durations, immunities and talent-resolved cooldowns; 40
matchup profiles; 16 machine guides; `arena-structure.md` and its public face `/brain`; the
Matchup Lab; `AbilityFinder`.

---

## 2. Why the old system went stale, precisely

The assumption going in was that the numbers rot. **That is not what the evidence shows.** Of
the 100 questions on WoW concepts, only **7** contain a hard number or duration anywhere in the
question or its answers (matching `\d+ *(s|sec|min|yd|%)`), and two of those match only
incidentally. The questions are overwhelmingly conceptual.

What has actually gone stale is **doctrine**. Two questions contradict the model outright:

| Question | Stored answer | What the model says |
|---|---|---|
| "What is the primary win condition in most arena matches?" | *"Killing the enemy healer to remove the team's source of sustain"* | Part 2: the kill target is **whoever's list is shortest, re-derived every go**. The reader was blunter — killing the Feral ends the game in most cases, and a guide arguing otherwise was rewritten for it. |
| "Your team has used all offensive cooldowns but the enemy healer is still at full health. What does this indicate?" | *"The kill attempt failed — your team must now play defensively and wait for cooldowns to reset"* | Part 1: **a go does not have to be a kill attempt to be correct.** Forcing their cooldowns is the win. And Part 3 says the next go is set up by what came back first, not by waiting for everything. |

Chriso: there is an answer in this question that was correct, answered by another gladiator but got it wrong because of what we defined as correct. He said swap to the healer not to waste offensives. Basically he meant the kill target has all their defensives up but we still have offensive cds rolling so we migth as well swap. The other is reste and wait for the next go but both are correct under different circumstances.

So the integration problem is not "wire the numbers in". It is that **the questions encode a
naive model of arena, and the project now has a better one written down** — one with provenance
on every claim. That is the thing to connect.

The reason it happened is structural and worth naming: those questions came from training data,
with nothing underneath to check them against. `knowledge-gaps.md` exists because that was
already noticed once.

One more number shapes the plan. By `skill_type` the 100 split **61 recall / 20 application /
19 analysis**. Recall is the majority, and recall is exactly the category that does not need to
be authored at all — see Layer 1.

---

## 3. The pattern that solves it already exists — three times

Independently, three parts of this codebase converged on the same idea:

**Reference by name, resolve once, render live.**

1. **Modules.** `ModuleSpellReferenceService`, in its own words:
   > `resolveSpellByName()` — **seed-time only**, used once when a module's curated spell list is
   > authored… `modifiersFor()` — **render-time**, called on every page load so the details
   > always reflect whatever is currently imported, **never a frozen snapshot**.

2. **Guides.** `guides:author` resolves ability names to external spell ids once, at publish, and
   fails loudly on an unknown name. Cooldowns and DR resolve live on every page load.

3. **Quizzes.** `WowQuestionBuilder` never stores a question at all — prompt, options, correct
   answer and explanation are all built from live data at attempt time.

The third is the strongest and is the template. An example of how far it goes — the generated
explanation hedges honestly on its own behalf:

> "{ability} has a {n}s cooldown with the talents most high-rated players take. **Talents can
> change it.**"

**Modules already have mechanism 1. What they lack is mechanism 3 applied to questions.**

---

## 4. The taxonomy already lines up

This was the surprise. The 7 WoW concepts and the 6 game axes were written for games in general,
before `arena-structure.md` existed. They map onto it almost cleanly:

| Concept | Part of the model |
|---|---|
| Cooldown Management | Part 2 (the answer pool), Part 3 (overlap) |
| Crowd Control | Part 6 (a chain is an economic trade), Part 7 (time in DRs) |
| Target Switching | Part 2 (kill target re-derived every go) |
| Team Composition | Part 12 (comp intent, which side the clock favours) |
| Awareness & Tracking | Part 10 (hidden state, forcing it) |
| Role Fundamentals | Part 5 (globals denied) |
| **Positioning** | Part 14.1 — richly *observed*, with no queryable field behind it |

| Axis | Part |
|---|---|
| Resources | Part 2 — the pool *is* a resource economy |
| Information | Part 10 — reconnaissance by fire |
| Decision | Parts 6, 8 — the trade, and patience inside the window |
| Control | Part 5 — globals denied |
| Adaptation | Part 12 — intent is matchup-selected |
| Execution | Part 0 — drill-shaped versus plan-shaped |

Two things follow. The mapping needs no new taxonomy — **it is a join table**.

And **Positioning** is the one that clarifies the design, because it is the odd one out in an
instructive way. Part 14.1 is tagged `[OBS]`, not `[HYP]`: the transcripts document it in more
concrete detail than almost anything else in the file — backing to a wall so Kidney cannot land,
baiting a movement read, where the bomb goes. What is missing is not the knowledge, it is a
**field**. Nothing in the data model represents position.

So Positioning cannot produce a generated question and never will. It can produce a very good
authored one, and Part 14.1 says as much: *"A guide can say this in prose today… without any map
data."* That is the distinction the three layers are built on, and Positioning is the clean case
of it: **queryable and observed are different things, and only the first can be generated.**

---

## 5. The design

Three layers of grounding, each with a different test and a different confidence.

### Layer 1 — Facts. Generated, never authored.

Anything with a curated field behind it becomes a **generated** question, the way the class
quizzes already work. Extend `WowQuestionBuilder`'s repertoire using `AbilityFinder`'s
vocabulary, which exists for exactly this and already refuses to answer what it cannot:

- DR categories, cooldowns, durations, charges — live
- "What can you press while feared?" — `usable_while_cc`
- "What stops a Fear?" — `grants_cc_immunity`, **with its conditional note**, since base Fade
  grants no immunity at all and only does with a PvP talent
- "Which of these opens without a cast?" — `cast_type`

These can never go stale, because nothing is stored. They are also the only questions with an
unambiguously right answer. They cover **61 of the 100** existing questions by skill type, which
is the bulk of the authored bank replaced by something that maintains itself.

The schema already allows this. A question is **not** owned by a module — `module_question`
(351 rows) and `concept_question` (390 rows) are both many-to-many pivots. So a generated
question can be scored against an existing concept without a migration; what it needs is a way
to exist without a `questions` row, which is the one real piece of work in this layer.

### Layer 2 — Doctrine. Authored, tiered, traceable.

Questions about *what arena is* come from the Brain, and **carry the tier of the claim they test**:

- `[OBS]`/`[DER]` → a normal question with a right answer.
- `[HYP]` → **not a graded question.** Either left out, or asked as a position to take with the
  uncertainty stated. Marking a player wrong for disagreeing with an untested hypothesis is the
  exact failure `arena-structure.md`'s tiers exist to prevent.

Every such question stores the brain section id it came from (`{#answer-pool}`, `{#overlap}` —
these are already stable comment anchors and the model says never to change them). That gives
three things for free: an "argue with this" link from any question to the claim behind it; a way
to find every question affected when a claim is corrected; and a check that no question outlives
the section it was drawn from.

### Layer 3 — Application. Drawn from guides and the Lab.

The 16 machine guides and 40 matchup profiles are a corpus of concrete situations. A question
can present a real matchup state and ask what to do — with the answer derivable rather than
asserted, because the Lab computes it:

> *Their Feral has Barkskin and Frenzied Regeneration left. Your Kidney is up, their trinket is
> not. Is this go a kill or a strip?*

That is Layer 3 and it is where the product actually differentiates. Nobody else can ask it,
because nobody else holds both the kit and a model of what to do with it.

### The diagnostic is a different instrument and should stay one

Worth stating because it is easy to confuse. Diagnostic questions have **no correct answer** —
`DiagnosticQuizRunner` accumulates `diagnostic_payload.traits` into trait scores, which become an
AI-written profile. It measures *how you play*, not *what you know*.

So: the diagnostic places you on the axes; Layers 1–3 test and teach. What changes is that the
diagnostic's output can finally point at something real — a spec's actual kit, a matchup in the
Lab, a named claim in the Brain — instead of a module written from nothing.

---

## 6. Where the AI belongs, and where it does not

`AiService` is 1,456 lines and works. The question is what to point it at.

**Not at facts.** Anything with a field behind it should be generated from the field. An AI asked
for a cooldown will produce a plausible number, which is the failure mode this whole project is
organised against.

**Yes at prose, second-pass.** Turning a verified fact into a readable explanation; drafting
module page copy around a resolved spell list; writing the distractor *rationale* once the
distractors are chosen by data.

**Authoring modules: use a committed file and a command, not the REPL and not live generation.**
This is settled precedent, from CLAUDE.md on machine guides:

> **Never author a guide through tinker:** a guide written in a REPL exists only in that database,
> can't be reviewed in a diff, can't be re-run after a patch changes an ability, and can't be
> reproduced on another environment.

`ModuleUploadService` and `module-upload-format.md` already define the file shape. A
`modules:author` command mirroring `guides:author` — idempotent by slug, resolving ability names
loudly, re-runnable after a patch — is the right way for me to write modules and push them live.

---

## 7. A staged path

Each stage is useful alone and nothing later depends on a stage being perfect.

**Stage 1 — Join the taxonomies.** A mapping from each WoW concept to the parts of the model that
back it, and mark Positioning as unbacked. Cheap, and it makes every later stage addressable.

**Stage 2 — Audit the 100 questions against the Brain.** Not a rewrite: a report of which stored
answers the model now contradicts. Two are already identified above. This is the cheapest way to
find out how bad the drift is, and it needs no new code.

**Stage 3 — Generated fact questions inside modules.** Let a module's quiz draw Layer-1 questions
from the live builder rather than only its stored bank. This is where module quizzes stop being
able to go stale.

**Stage 4 — `modules:author`.** Committed module files, resolved and pushed like guides, with the
same loud failure on an unknown ability.

**Stage 5 — Layer 3 matchup questions.** The differentiator, and worth doing last because it
depends on the Lab's engine being trusted.

---

## 8. What this does not fix

**Positioning will never have a field.** It can be taught from the transcripts, which are strong,
but it can never be generated or checked against data — so it stays authored, and every claim in
it is only as current as the source it came from.

CHRISO: Basic positioning questions are things like what is line of sight. does line of sight effect casts etc. 

**Layer 2 is only as good as the Brain.** If a claim there is wrong, every question drawn from it
is wrong together. That is an improvement on being wrong severally and untraceably, but it
concentrates the risk — which is an argument for the section-id link, not against the design.

**None of this creates demand.** The traffic analysis on 2026-09-24 found roughly 100–150 real
sessions a day and that about half of all recorded page views were crawlers. A better learning
platform does not by itself bring anybody. It is, however, the only part of the product that
plausibly earns a returning account — which is the thing the site does not currently have.

**Mastery is per-concept and concepts are universal by design.** CLAUDE.md is firm that context
flavours content and never partitions mastery: one "Cooldown Management", not a Rogue version.
Grounding questions in a specific spec's kit pushes against that, and the resolution is that the
*question* is spec-flavoured while the *concept it scores* stays universal. Worth deciding
explicitly before Stage 3 rather than discovering later.
