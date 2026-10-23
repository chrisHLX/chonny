# Training

Reasoning lessons inside MindCollector (2026-10-23): cybernetics and feedback loops, causal
reasoning, statistics, systems thinking, decisions, epistemology, experiments, incentives, AI
literacy and human factors. Why it exists: `VISION.md`, "What building it has taught". It began as
the separate `steersman` prototype (`C:\Users\chris\Herd\steersman`), which is now superseded by
this; don't develop both.

**Where it sits.** `/training`, linked from the sidebar beside Home, not in the game switcher: it
spans every game. `/training` itself is public (it says what Training is); everything under it needs
an account and is the player's own work. The dormant learning profile that used to be at `/training`
is `/learning-profile`.

## The two loops

**The player's loop.** Select any passage in a lesson to highlight it or attach a note (Note,
Question, Confusing, Disagree, Apply it). Notes come back for a revisit on a schedule set by their
kind (`App\Enums\Training\NoteKind`): Confusing and Question after a day, Disagree after three days,
Note and Apply after a week; a plain highlight never comes back. At a revisit the player records
what they think now (`RevisitOutcome`): still unclear (back in two days), now understand or changed
my mind (resolved), still holds (back at widening intervals by revisit count, 7, 14, 30 days, then
retired). The sidebar's number and Today show what is due. The notebook shows how often revisiting
moved understanding.

**The course's loop.** `/training/course-feedback` (admin gate): passages players marked confusing,
questioned or disagreed with, grouped by passage, and the wrong answers chosen first on every
question. Read it to fix lessons.

## How a lesson runs

1. **The problem**, before any teaching. The player writes their reading of it (or skips).
2. **More evidence** that may challenge that reading, and a question. Feedback on every answer
   explains the reasoning; it never praises.
3. **The principle**, with interactive models where they help.
4. **A new situation in a different domain.** The first answer here is what mastery counts.
5. **What would settle it?** The player names the evidence needed, then compares with a model answer.
6. **Look back:** the first reading beside what they think now.

Correctness and feedback live on the server and are returned per answer, never shipped in the page
(a test checks this). Options are shown in a stable shuffled order per question (`StableShuffle`), so
lesson files can list them in any order and the right answer moves around.

## Measuring whether it works

The three validity questions in `VISION.md` apply to Training as much as to the coach.

- **Mastery** counts only transfer questions answered right first time; the in-lesson question is
  shown beside it for comparison. Training's concepts are its own (`resources/training/concepts.php`)
  and never write `UserConceptMastery` (rule 35).
- **The reasoning check:** form A before the lessons, form B on different problems testing the same
  six concepts, opening after 6 complete lessons or 14 days (`Assessment::B_AFTER_*`). One player and
  six items is a small sample; the pattern across players is the evidence.
- **Claims:** something read, heard or generated, worked through the four questions with a
  confidence before and after, and a date to record what turned out to be true. Progress shows
  calibration from the outcomes.

## Where things are

| Path | What it is |
|---|---|
| `resources/training/subjects.php` | The ten subjects, in order |
| `resources/training/lessons/{subject}.php` | The lessons for each subject |
| `resources/training/concepts.php` | The concepts mastery is tracked against |
| `resources/training/assessment.php` | Reasoning check items, forms A and B |
| `app/Training/` | `Curriculum` (loads the files), `Progress`, `Assessment`, `Prose`, `StableShuffle` |
| `app/Http/Controllers/Training/` | Pages, answers, notes and revisits, claims, the check |
| `app/Models/Training/` | `Note`, `NoteRevisit`, `Response`, `Claim`, `CheckAnswer` (tables `training_*`) |
| `resources/views/training/` | The pages, the content blocks, the five models (`sims/`) |
| `public/css/training.css` | Marks, the selection toolbar, the notes rail, questions, the models. A plain file: lesson and style changes need no front-end build |
| `public/js/training/lesson.js` | Steps, questions, selecting text and notes |
| `public/js/training/sims.js` | The models: control loop, stock and flow, loop polarity, thermostat, burst commitment |
| `tests/Feature/Training/` | Every page renders through its real route, the loops, the check, claims |

**Page usage** (rule 28): every Training page logs a `training_*` view and has an entry in
`Admin\PageUsage::PAGES` (a test checks the list).

## Writing a lesson

Add an entry to the subject's file. Each lesson has:

- `case`: `domain`, `scenario` (paragraphs), `prompt`, `reveal` (paragraphs), `question`, `options`
- `principle`: blocks: a string (paragraph), `['h' => …]`, `['list' => […]]`, `['note' => …]`,
  `['table' => ['head' => […], 'rows' => [[…]]]]`, or `['sim' => 'thermostat']`
- `transfer`: a new situation in a different domain, with `question` and `options`
- `evidence`: `prompt`, and `model` (the points a careful answer includes)
- `concepts`, `minutes`, `summary`, `sources`

Options are `$o(text, correct, feedback)`; exactly one is correct (a test checks). Text allows
`**bold**` and `*italic*` only; everything else is escaped.

**Numbers.** An invented figure in a case is labelled as illustrative ("Suppose…"). A claim about
the game rests on the archive and names its source, the way the burst lesson's "What MindCollector's
archive says" cites the population findings of 2026-10-07. The burst simulation is a teaching model
with invented numbers and says so on its face (rule 33's spirit: a model is a hypothesis, not a
verdict).

**Notes survive edits.** A note anchors to a passage by its block id (`case-0`, `principle-3`) plus
the quoted text and a little context either side. If a passage moves, the note finds it; if the
text is gone, the note stays in the rail marked as changed. Reordering blocks is safe; rewriting a
sentence someone noted orphans that note's mark.

## Open next

- **Notes on match findings.** The vision has one notebook across the site: marking a finding in a
  game review "disagree" or "confusing" should land in the same revisit queue. `ArenaNote` already
  holds notes on games; the join is not built.
- **Lessons from the player's own games.** Lessons use written cases; the plan is a games context
  that opens each lesson on the player's own round where the data exists.
- **A business context.** Concepts stay universal; a declared context would choose the opening case.
- **The phone tab bar** (Home, Comps, Build, Guides, Menu) does not carry Training; it is one tap
  further, under Menu.
