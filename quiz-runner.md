# QuizRunner — How It Works

`app/Livewire/QuizRunner.php`

## Session flow

```
mount() → startQuizInternal()
  → calculateNextDifficulty()       picks the next level or returns completed
  → getQuestionIdsForDifficulty()   all question IDs at that level
  → getTargetQuestions()            filters to unanswered only
  → chooseQuestions()               picks up to 5 with diversity
  → prepareQuestionsForQuiz()       shuffles options/steps/values
  → initializeQuizState()           resets score/index/results

user answers each question → submit() → nextQuestion()
  → if last question: mark level done, check completion or show between-rounds screen

user clicks next → nextLevel() → startQuizInternal() (repeats for next level)
```

## Difficulty progression

Three levels in order: `easy → medium → hard`. Each is played exactly once per session. No going back, no repeating.

`calculateNextDifficulty()` iterates the levels and skips a level when either:
- it is already in `$completedDifficulties` (session flag set at end of each round), or
- the user has answered `min(5, totalQuestionsAtLevel)` questions at that level (full round already played)

If all three levels are skipped, returns `mode=completed` and the module finishes.

`$completedDifficulties[]` is appended in `nextQuestion()` as soon as the last question of a round is answered, before the completion check runs.

## Question selection

**`getTargetQuestions()`** — returns only unanswered questions. Questions the user got wrong in this round are not re-served; the pivot records their outcome but they do not come back in the same session.

**`chooseQuestions()`** — fetches the unanswered pool, shuffles it, then picks up to 5 using diversity rules:

1. Round-robin across `recall → analysis → application` skill types
2. Within each slot, prefer a question `type` (mcq, true_false, ordering, etc.) not yet picked
3. Falls back to any available question if no new type is available

If the pool has 5 or fewer questions, all are returned as-is. Returns a `Collection` (not a query builder).

## Answering a question

`submit()` evaluates correctness per question type:

| Type | Correct when |
|---|---|
| `mcq` | `$answer === correct` string |
| `true_false` | boolean cast matches `correct` |
| `open` | user answer contains ≥ half the `correct_keywords` |
| `matching_pairs` | every key maps to the expected value |
| `ordering` | array equals `steps` exactly |

After evaluating, `submit()`:
1. Records result in `$questionResults[id]`
2. Writes/updates the `answered_questions` pivot (`attempts`, `correct_count`, `last_answer_correct`, `consecutive_fails`, time fields)
3. Calls `MasteryService::updateMasteryForUserQuestions()` — updates `UserConceptMastery` and `UserConceptSkillMastery`
4. Calls `nextQuestion()`

## End of a round

`nextQuestion()` increments `$currentIndex`. When it exceeds the question count:

1. Appends `$this->difficulty` to `$completedDifficulties`
2. Calls `calculateNextDifficulty()` — if `completed`, calls `completeModule()` and returns
3. Otherwise sets `$this->completed = true`, saves `UserModuleHistory`, updates the module pivot to `in_progress`, dispatches `ModuleAttempted`

The between-rounds view shows `$wrongQuestions` (questions answered incorrectly this round) for review, but those questions will not be served again.

## Module completion

`completeModule()` is called when all three levels are done (or skipped because no questions exist at a level). It:

- Sets `status = completed` on the user–module pivot, records `completed_at`
- Creates a `UserModuleHistory` record with `status = completed`
- Creates a `quiz_completion` Pipeline with two steps
- Dispatches `SuggestionJob` and `GenerateCardJob`
- Stores `completion_pipeline_id` in session for the frontend progress overlay

There is no "final round" of weak questions. Hard finishes → module completes.

## Score calculation

`userScore()` reads the `answered_questions` pivot across all difficulty levels for the module and computes:

```
score = (sum of correct_count across all questions) / (sum of attempts across all questions) × 100
```

This is a historical accuracy rate, not a session score. It is saved to the user–module pivot after each round and on completion.

## Key state properties

| Property | Purpose |
|---|---|
| `$difficulty` | Current level being played (`easy`/`medium`/`hard`) |
| `$completedDifficulties` | Levels already finished this session |
| `$questionResults` | `[question_id => bool]` map for the current round |
| `$wrongQuestions` | Collection of questions answered incorrectly this round (display only) |
| `$completed` | `true` when a round ends and the between-rounds screen should show |
| `$status` | `in_progress` or `completed` — mirrors the module pivot |
