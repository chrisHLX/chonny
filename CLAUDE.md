# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

**Start full dev environment (server + queue + logs + vite in parallel):**
```bash
composer run dev
```

**Individual processes:**
```bash
php artisan serve
php artisan queue:listen --tries=1
npm run dev
php artisan pail --timeout=0   # log tail
```

**Build frontend assets:**
```bash
npm run build
```

**Run all tests:**
```bash
composer test
# or
php artisan test
```

**Run a single test file or test:**
```bash
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter=test_name
```

**Code formatting (Laravel Pint):**
```bash
./vendor/bin/pint
```

**Database:**
```bash
php artisan migrate
php artisan migrate:fresh --seed
php artisan db:seed --class=DatabaseSeeder
```

## Architecture Overview

**Chonny** is an AI-powered adaptive learning platform. Users enroll in Modules, take quizzes that adapt to their performance, and earn AI-generated review content when they struggle.

### Core Domain Flow

```
User → enrolls in Module → takes Quiz (QuizRunner) → 
  → answers tracked in user_question pivot (attempts, consecutive_fails, etc.)
  → on completion: Pipeline dispatched → SuggestionJob + GenerateCardJob queued
  → on consecutive fails: GenerateReviewContentJob queued → review content shown before re-quiz
```

### Key Models & Relationships

- **Module** has many Questions (many-to-many), many Proficiencies (many-to-many), many ModulePages, belongs to Subject. Supports parent-child versioning via `parent_module` + `version` fields.
- **User** has many Modules (pivot: `status`, `score`, `difficulty`, `last_activity_at`), has many answeredQuestions (pivot: `attempts`, `correct_count`, `consecutive_fails`, `last_answer`, `last_time_spent`).
- **Question** has `type` (mcq, true_false, open, ordering, matching_pairs, diagnostic_mcq, survey_mcq) and `answer` (JSON, structure varies by type — see below). Has many Concepts (many-to-many).
- **Pipeline** / **PipelineStep** — orchestrates async workflows. After module completion, a `quiz_completion` pipeline is created and steps dispatched as jobs.

### Content Hierarchy: Categories → Subjects → Modules → Questions

The platform organises all learning content in a strict hierarchy:

```
Category
  ├── Axes (belong to Category — fixed skill dimensions, e.g. "Critical Thinking")
  ├── Archetypes (many-to-many via archetype_category pivot — player profile types)
  └── Subject (belongs to Category)
        ├── Concepts (belong to Subject — many-to-many with Axes via concept_axis pivot)
        ├── Proficiencies (belong to Subject — difficulty tiers with an index integer)
        └── Module (belongs to Subject)
              ├── ModulePages (ordered pages of Markdown/HTML content)
              ├── Questions (many-to-many via module_question pivot)
              └── Tags (many-to-many — freeform labels)
```

**Category** (`categories` table) — top-level grouping (e.g. "Science", "Finance"). Has many Subjects, many Axes, and many Archetypes (via pivot).

**Archetype** (`archetypes` table) — universal player profile types used by the diagnostic system. Many-to-many with Categories via `archetype_category` pivot. Key fields: `key` (unique slug, e.g. `strategic_controller`), `label` (display name), `description`, `signals` (JSON array of trait/axis signal names used to describe when this archetype fits). Seeded by `ArchetypeSeeder` — 8 universal archetypes currently attached to the "Games" category. The `DiagnosticProfileService` loads archetypes from the module's category and passes them to the AI so it can choose the best fit and return the key. Adding new archetypes for a category requires no code changes — only a seeder or admin action.

**Axis** (`axes` table) — Axes are defined per Category and should reflect the fundamental dimensions of skill in that domain (e.g. Games use Mechanics, Strategy, etc., while other categories may use different dimensions). Axes are used as a scaffold for AI to generate and organise Concepts consistently across Subjects. 

Many-to-many with Concepts via `concept_axis` pivot. `UserAxisMastery` tracks each user's mastery percentage per Axis, calculated as the average concept mastery across all Concepts mapped to that Axis. The `weight` column on the `concept_axis` pivot is nullable and not yet used in calculations.

**Mastery flow:** `Question answered → UserConceptMastery updated → for each Axis linked to that Concept → UserAxisMastery updated (average of all concept masteries in the Axis)`

**`user_axis_mastery`** — stores one row per (user, axis). Written by `MasteryService`. **`user_concept_skill_mastery`** — stores one row per (user, concept, skill_type) triplet; tracks `correct_count`, `total_count`, `mastery_percentage` per thinking mode. Unique key on (user_id, concept_id, skill_type).

**Subject** (`subjects` table) — belongs to a Category. Has many Modules, Concepts, and Proficiencies. Every Module must belong to a Subject — the Subject determines which Concepts are available to tag questions and which Proficiency tiers govern difficulty.

**Concept** (`concepts` table) a subject-specific idea that represents one or more skill dimensions (Axes) and is used to tag questions and drive mastery tracking. Many-to-many with Questions via `concept_question` pivot, and many-to-many with Axes via `concept_axis` pivot (with nullable `weight` column). When the AI generates questions it is given the full list of Concept names for the module's Subject and must tag each question with one or more matching concepts. `UserConceptMastery` tracks each user's mastery percentage per Concept over time.

**Proficiency** (`proficiencies` table) — ordered tiers on a Subject (index 0 = beginner, higher = advanced). Many-to-many with Modules via `module_proficiency`. The `index` value drives how many questions the AI generates per batch (index < 2 → 2 complex questions, 2–4 → 5, ≥4 → 7) and the difficulty mix (easy/medium/hard split). `outcomes` (JSON) — optional array of expected learning outcomes for this proficiency tier; passed to the AI during question generation to anchor difficulty and scope.

**Module** (`modules` table) — the primary learning unit. Key fields:
- `subject_id` — determines available Concepts and Proficiencies
- `status` — `draft | preparing | ready` (set to `preparing` while jobs run, `ready` once complete)
- `content_source` — short descriptor of where content originated
- `published` (bool) — controls public visibility
- `parent_id` + `version` — versioning chain when AI generates follow-up modules via `VersioningService`
- `art_spec` (JSON) + `art_path` — collectible card art generated after quiz completion

**ModulePage** (`module_pages` table) — ordered pages of educational content for a Module. Key fields:
- `module_id` — parent module
- `page_number` — ordering (page 1 = landing/intro page)
- `title` + `slug` (auto-generated, unique) — page identity
- `content` (longText) — Markdown or HTML written by the module creator; this is the primary source material for AI question generation
- `created_by` / `updated_by` — user attribution

**SubjectContent** (`subject_content` table) — persists AI-generated research fetched by `ResearchService`. Key fields:
- `subject_id` — the Subject this research belongs to
- `module_id` (nullable) — the Module it was fetched in context of (null if research is done outside a module)
- `ai_request_id` (nullable) — links back to the `AiRequest` log entry for cost tracking
- `created_by` (nullable) — user who triggered the research
- `title` — auto-generated as `"Research: {topic} — {date}"`
- `content` (longText) — raw Gemini summary text
- `source_urls` (JSON array) — URLs extracted from Gemini grounding metadata

`ResearchService::fetchLatestMaterial()` uses `updateOrCreate(['module_id' => $moduleId])` when a module ID is provided — each module therefore has **at most one** `SubjectContent` row. Multiple rows per `module_id` is a bug; investigate `ResearchService` if duplicates appear. Rows with `module_id = null` (subject-level research, no module context) are still created normally.

### How Module Content Pages Feed Question Generation

**Step 1 — Author writes content pages.**
A module creator opens the module edit view and writes one or more `ModulePage` records via `ModuleController::createLandingPage()`. Page 1 is typically the introductory/landing page. Content is stored as Markdown or HTML in the `content` column.

**Step 2 — Creator triggers generation.**
Clicking "Generate Questions" calls `ModuleController::generateQuestions()`:
1. Fetches all page content: `$module->modulePages()->pluck('content')->implode("\n\n")`
2. Creates a `Pipeline` record (type: `question_generation`, status: `running`)
3. Creates one `PipelineStep` per question type (mcq, true_false, matching_pairs, ordering)
4. Dispatches a `GenerateQuestions` job for each step with `mode="edit"`

**Step 3 — `GenerateQuestions` job runs (per type).**
In `app/Jobs/GenerateQuestions.php`:
- `mode="edit"` → reads `$module->modulePages()->first()->content` (first page) as the context string
- `mode="suggestions"` → `GenerateModuleContentJob` runs **first**: it calls the AI to generate ModulePage content for the new module (using module name/description/subject via `PromptBuilder`), writes the resulting `ModulePage` records, then **chains** to `GenerateQuestions` jobs for each question type. Question generation in this mode reads the newly written page content, not pre-existing pages.
- Calls `AiService::generateQuestions($type, $contextString, $module, $userID)`
- Updates `PipelineStep` status (`running` → `completed` / `failed`)
- When all steps complete, sets Module `status = 'ready'`

**Step 4 — `AiService::generateQuestions()` calls OpenAI.**
The prompt is built with:
- The raw page content as the knowledge base
- The full list of `Concept` names for the module's Subject (AI must tag each question)
- The module's Proficiency `index` (controls question count and difficulty mix)
- All existing question texts for the module (to avoid duplication)
- Strict JSON output schema for the question type (see shapes below)

Model selection: `gpt-4o-mini` for mcq/true_false/open; `gpt-4.1-mini` for ordering/matching_pairs.

**Step 5 — Questions validated and saved.**
The AI now returns `{"questions": [...]}` (wrapped object, not a bare array). After receiving the response:
1. Each entry is run through `normalizeQuestion()` (fixes ordering flat-array edge cases) then `isValidQuestion()` (checks required fields and concept validity)
2. If fewer than 50% of requested questions pass validation, the entire batch is **aborted** with a `RuntimeException` — nothing is written to the DB
3. The valid batch is written inside a single `DB::transaction()`: `Question::create()`, `$question->concepts()->sync()`, `$module->questions()->syncWithoutDetaching()`
4. Credits deducted from the triggering user via `CreditService`

### Question Answer JSON Shapes

Each question type stores `answer` differently:
```json
mcq:             { "correct": "Answer text", "options": ["A", "B", "C", "D"] }
true_false:      { "correct": true }
open:            { "correct_keywords": ["keyword1", "keyword2"], "ideal_answer": "..." }
ordering:        { "steps": ["Step 1", "Step 2", "Step 3"] }
matching_pairs:  { "correct": {"Key1": "Val1", "Key2": "Val2"}, "pairs": { "keys": [...], "values": [...] } }
diagnostic_mcq:  { "options": [{ "text": "Option label", "diagnostic_payload": { "traits": { "trait_key": 2 } } }, ...] }
survey_mcq:      { "question_key": "current_rating", "options": [{ "text": "Under 1400", "value": 1 }, ...] }
```
`prepareQuestionsForQuiz()` in `QuizRunner` shuffles options/steps/values before serving.

`diagnostic_mcq` options carry a `diagnostic_payload.traits` map — each key is a trait key from the `player_traits` table and the value is the points to add to that trait's running score. No correct/incorrect concept — every answer is accepted.

`survey_mcq` options carry a `text` label and a `value` integer (ordinal scale). The `question_key` on the answer object (e.g. `"current_rating"`, `"primary_role"`) identifies the self-reported context dimension in `user_profile_evidence`.

### Skill Types

Skill Types define *how* a question tests understanding, not just *what* it tests.
Every question has one `skill_type` (enum stored on the `questions` table).

**The three types:**
- `recall` — tests memory and recognition of facts
- `analysis` — tests interpretation of situations and information
- `application` — tests decision-making and use of knowledge in context

**Mental model:**
- Concept → what is being learned
- Skill Type → how it is being understood
- Axis → where it fits in overall mastery

**Purpose:**
1. Improves learning depth — same concept tested across different thinking modes
2. Enables better diagnostics — identifies HOW a user struggles not just WHAT they got wrong
3. Drives adaptive progression — future questions target weakest skill type
4. Increases question diversity — forces AI to generate fundamentally different questions

**Relation to Bloom's Taxonomy:**
- Recall ≈ Remember
- Analysis ≈ Understand + Analyze
- Application ≈ Apply

**Current state:**
- `skill_type` stored on `questions` table (enum, default: `recall`)
- AI assigns `skill_type` during question generation
- Displayed as a badge in admin question management
- NOT yet used in mastery calculations or adaptive logic — planned for future

### Adaptive Quiz Logic (`app/Livewire/QuizRunner.php`)

`calculateNextDifficulty()` iterates easy → medium → hard. A level is "mastered" at ≥80% correct. If all three levels are mastered, `handleMasteryCompletion()` serves the least-accurate questions as a `final` round. If `consecutive_fails >= 1` on a question, `GenerateReviewContentJob` is dispatched to generate AI review content.

### Services (`app/Http/Services/`)

- **AiService** — all OpenAI calls. Uses `gpt-4o-mini` by default, `gpt-4.1-mini` for complex types (ordering, matching_pairs), `gpt-4.1-nano` for HTML generation. Always deducts credits after each call via `CreditService`. `answerQuestion(string $question, string $context, int $userId)` handles Content Q&A — grounded prompt, uses `callOpenAiString()`, logged under purpose `content_qa`. `sendPromptToAi(string $prompt, string $model, int $userId, string $purpose)` is a public pass-through used by other services (e.g. `DiagnosticProfileService`) that need a raw JSON response without going through question-generation scaffolding.

  All four private call methods now share a single `makeOpenAiRequest(array $payload): array` helper (connectTimeout 10s, timeout 60s, retry 2× with 1s delay, throws on HTTP failure). `callOpenAiJson()` additionally passes a `response_format` structured-output schema — used by `generateQuestions()` for mcq, true_false, and ordering types. `callOpenAi()` JSON decode now uses `JSON_THROW_ON_ERROR` and logs a structured error on failure instead of silently returning `{}`.
- **DiagnosticProfileService** (`app/Http/Services/DiagnosticProfileService.php`) — generates the AI player profile shown at the end of a diagnostic quiz. `generateProfile(array $traitScores, array $axisScores, array $conceptScores, int $userId, bool $isGuest, array $surveyAnswers, ?Module $module)` builds a game-agnostic prompt by loading archetypes from the module's category and available modules from the subject, then calls `AiService::sendPromptToAi()` for auth users or `generateForGuest()` for guests. Guest path uses a direct Gemini `gemini-2.5-flash-lite` HTTP call (no credit deduction — diagnostic is the conversion moment for guests). The prompt is fully universal — no hardcoded game language; all context comes from the DB. Output is normalised by `normaliseResponse()` which writes both new field names and backward-compat aliases so old stored profiles continue to render. New output fields: `profile_title`, `archetype_key`, `confidence_level`, `summary`, `evidence[]`, `self_report_check`, `likely_in_game_pattern`, `primary_strength`, `primary_growth_area`, `recommended_module` (object with `module_id`, `title`, `reason`), `next_practice_goal`. Backward-compat aliases written alongside: `player_type`, `narrative`, `top_traits`, `growth_area`, `next_module_suggestion`.
- **CreditService** — manages `UserCredit` balance. New users receive 50 welcome credits. The quiz requires >5 credits to start.
- **MasteryService** — updates `UserConceptMastery` percentages after each question answer, then propagates to `UserAxisMastery` for every Axis linked to the answered Concept.
- **VersioningService** — handles module versioning when AI generates follow-up modules.
- **TokenService** — estimates token counts (chars / 4) and calculates credit cost per model.
- **ResearchService** — Gemini web-search integration. `fetchLatestMaterial()` sends a prompt to `gemini-2.5-flash-lite` with Google Search grounding enabled, then writes both an `AiRequest` log record and a `SubjectContent` record, and deducts credits via `CreditService`. Unlike AiService, ResearchService writes `AiRequest` directly — do NOT add a second write in calling code. Requires `GEMINI_API_KEY`. Called from `ModuleController::research()` (module edit panel) and `ExploreModuleJob` (explore flow).

  **Source URL and YouTube modes** — `fetchLatestMaterial()` accepts an optional `$sourceUrl` (param 9) and `$youtubeMode` (param 10, `'transcript'|'video'`, default `'transcript'`). Behaviour by URL type:
  - **No URL** — `google_search` tool only.
  - **Non-YouTube URL** — prepends `PRIMARY SOURCE URL` instruction to prompt, enables both `url_context` and `google_search` tools so Gemini fetches the page as authoritative source.
  - **YouTube URL + transcript mode** — extracts the video ID, fetches captions via `mrmysql/youtube-transcript` (`extractYouTubeTranscript()`), and appends the full transcript text to the prompt. Falls back to full-video `file_data` part if captions are unavailable (private video, no CC). HTTP timeout: 30 s.
  - **YouTube URL + video mode** — sends the URL as a `file_data` multimodal part so Gemini analyses on-screen action and commentary directly. HTTP timeout: 90 s.

  Both the module edit research panel (`resources/views/components/modules/research-panel.blade.php`) and the explore form (`resources/views/livewire/modules/index.blade.php`) show a YouTube processing mode toggle (transcript / full video) that appears only when a YouTube URL is detected. The toggle is hidden for non-YouTube URLs. `ExploreModuleJob` carries `$youtubeMode` as a serialised property and passes it through to `fetchLatestMaterial()`.

### Model Selection (`config/ai_models.php`)

The `config/ai_models.php` file defines the user-selectable generation models:

| Key | Label | Multiplier | Use |
|---|---|---|---|
| `gpt-4o-mini` | Standard | 1.0× | Default for mcq / true_false / open |
| `gpt-4.1-mini` | Enhanced | 1.8× | Default for ordering / matching_pairs |
| `gpt-4o` | Advanced | 4.0× | Optional upgrade for any type |

The `ai-model-selector` Blade component iterates this config. `ModuleController::generateQuestions()` validates the incoming `model` parameter against the config keys before dispatching jobs.

**Complex-type guard** in `AiService::generateQuestions()`: if `$type` is `ordering` or `matching_pairs`, the model is silently upgraded to `gpt-4.1-mini` even when a cheaper model is passed as `$modelOverride`. This guard always wins — `gpt-4o-mini` cannot reliably handle structured ordering and matching output.

### AI Request Tracking (`app/Models/AiRequest.php`)

Every AI call is logged to the `ai_requests` table. The write happens **inside** the four private methods in `AiService`:

| Method | Used for |
|---|---|
| `callOpenAi()` | JSON-returning calls (explore content, tags) |
| `callOpenAiJson()` | JSON with structured-output schema (question generation — mcq, true_false, ordering) |
| `callOpenAiString()` | Plain-text calls (review explanations, module content) |
| `callOpenAiHTML()` | HTML generation (`gpt-4.1-nano`) |
| `callOpenAiRaw()` | Single-value calls (proficiency inference) |

`ResearchService::fetchLatestMaterial()` also writes `AiRequest` directly (Gemini calls bypass AiService entirely).

**Do NOT write an `AiRequest` record in calling code** — each of the methods above already does it. Double-logging inflates the `Admin\ApiUsage` spend dashboard and charges credits twice.

Key columns:
- `purpose` — the `$description` argument passed to the call method. Keep it descriptive — it is the label shown in the `Admin\ApiUsage` purpose breakdown.
- `template_prompt` — always stored as `''` currently; field exists for future structured prompt auditing.
- `metadata` (JSON) — model, input_tokens, output_tokens, cost_usd, credits_charged.
- `duration_ms` — wall-clock time of the HTTP call in milliseconds.

### Livewire Components (`app/Livewire/`)

- **`Modules\Index`** — module browser with URL-synced query string filters (category, subject, status, proficiency, tags).
- **`QuizPage`** — state machine wrapper: selection → running → review-feedback.
- **`QuizRunner`** — the active quiz engine (adaptive difficulty, pivot tracking, job dispatch on completion).
- **`QuizSelection`** — picks module/subject before starting.
- **`Collection`** — user's enrolled modules.
- **`Admin\ContentManager`** — single admin interface for all content management: Axes, Categories, Subjects (with inline proficiency management), and Concepts (with axis mapping). Route: `admin.content` → `/admin/content`.
- **`Admin\ApiUsage`** — AI spend dashboard. Reads from `ai_requests`. Has three computed properties: `summary` (this-month totals by provider), `chartData` (30-day daily spend), `purposeBreakdown` (grouped by `purpose` column). **Known gotcha:** do NOT use `fn($group, $purpose)` in the `map()` call after `groupBy()` — Laravel's `Collection::map` does not reliably pass the key as a second arg. Always derive the purpose from `$group->first()?->purpose` instead.
- **`Admin\WeakAreas`** — mastery gap dashboard. Route: `admin.weak-areas` → `/admin/weak-areas`. Shows which concepts, axes, and users are underperforming. Has a live `$threshold` filter (30/50/70%) that all computed properties react to. Four computed properties: `summary` (platform-wide totals + per-skill-type averages from `user_concept_skill_mastery`), `weakConcepts` (paginated — concepts with avg mastery below threshold, ordered worst-first), `weakUsers` (paginated — users with at least one weak concept, ordered by avg mastery), `weakAxes` (non-paginated — axes below threshold). Tabs switch between "By Concept" and "By User" views. Colour coding: red < 30%, yellow < 50%, accent otherwise.
- **`DiagnosticQuizRunner`** — Livewire component for diagnostic modules. Presents `diagnostic_mcq` and `survey_mcq` questions in sequence (shuffled). `diagnostic_mcq` answers accumulate trait scores in `$traitScores`; `survey_mcq` answers populate `$surveyAnswers` (keyed by `question_key`). On completion: calls `DiagnosticProfileService::generateProfile()` with both, stores the result as `diagnostic_profile` JSON on the `user_module` pivot for auth users, or in `session('guest_quiz_results.{moduleId}')` for guests. Auth users also persist `UserTraitEvidence` per trait and `UserProfileEvidence` per survey question. Retake resets all accumulated state and clears the pivot/session.
- **`GenerationProgress`** — real-time progress overlay displayed while a module's question generation pipeline is running; polls pipeline step statuses.
- **`Modules\Show`** — public-facing module detail page; displays module metadata, proficiency tier, and enrollment call-to-action. `getAllPagesHtmlProperty()` returns an array with `id`, `title`, `page_number`, `html` — the `id` field is required by the embedded `ContentQa` components.
- **`ContentQa`** — embeddable Q&A widget. Receives `promptableType` (allowlisted to `ModulePage` or `SubjectContent`) and `promptableId`. Public `$selectedModel` (`'gpt'` | `'gemini'`) drives the model toggle. On submit: snapshots `$model->content`, creates a `Prompt` record (with `model`), dispatches `AnswerPromptJob`. Uses `wire:poll.3s` on the root element only while prompts have `status = pending|processing`. Embedded inline in `modules/show.blade.php` beneath each research accordion body and each module page panel.

### Jobs (`app/Jobs/`)

All jobs run via Redis queue (`QUEUE_CONNECTION=redis`):
- `GenerateQuestions` — bulk question generation for a module (dispatched from `ModuleController`)
- `GenerateReviewContentJob` — generates AI explanation for a question a user keeps failing
- `SuggestionJob` — generates next-module suggestions after quiz completion
- `GenerateCardJob` — generates collectible card art spec after quiz completion
- `GenerateModuleContentJob` — generates AI-written ModulePage content for suggestion-mode modules; once content is written, chains directly to `GenerateQuestions` jobs for each question type. Uses `ModulePage::updateOrCreate(['module_id', 'page_number' => 1])` — page 1 is **overwritten** on every run, not appended. Multiple page-1 rows per module is a bug.
- `AnswerPromptJob` — resolves a `Prompt` record. Marks `status = processing`, then routes by `$prompt->model`: `'gemini'` → `ResearchService::answerQuestion()` (Gemini + Google Search grounding, returns answer + sources); `'gpt'` → `AiService::answerQuestion()` (content-only). Writes `answer`, `sources`, `status = completed`. On exception sets `status = failed` + `error_message`.

### Content Q&A (`app/Models/Prompt.php`)

Users can ask questions about any `ModulePage` or `SubjectContent` record directly from the module show page. Answers are generated by AI and stored alongside the question.

**`prompts` table:**
- `user_id` — question author
- `promptable_type` / `promptable_id` — polymorphic link to the content item (currently `App\Models\ModulePage` or `App\Models\SubjectContent`)
- `model` (string, default `'gpt'`) — `'gpt'` routes to `AiService::answerQuestion()`; `'gemini'` routes to `ResearchService::answerQuestion()` (web search enabled)
- `question` (text) — what the user asked
- `context_snapshot` (longText) — copy of `$model->content` at submission time; used as AI context so answers remain valid even if the source content is later edited
- `status` — `pending | processing | completed | failed`
- `answer` (longText, nullable) — AI response once complete
- `sources` (JSON, nullable) — web sources returned by Gemini; empty for GPT answers
- `error_message` (text, nullable) — failure reason if status = failed

**Flow:**
1. User submits question via `ContentQa` Livewire component
2. Component validates, snapshots `$model->content`, creates `Prompt` record (`status = pending`), dispatches `AnswerPromptJob`
3. Job calls `AiService::answerQuestion($question, $context_snapshot, $userId)` — grounded prompt: *"Using ONLY the following content…"*
4. Answer written to `prompts.answer`, `status = completed`
5. `ContentQa` polls (`wire:poll.3s`) while pending/processing prompts exist; stops once all resolved

**Important rules:**
- `promptableType` is allowlisted inside `ContentQa` — only `ModulePage` and `SubjectContent` are accepted. Never remove this guard.
- `context_snapshot` is the AI's source of truth, not the live `content` column — do not change either `answerQuestion()` to re-fetch the model.
- `selectedModel` is validated to `['gpt', 'gemini']` in `ContentQa::submit()` before being stored — never trust the raw Livewire property directly.
- Gemini answers include `sources` (web URLs); GPT answers always store an empty array. The `Prompt` model casts `sources` as `array`.
- `ResearchService::answerQuestion()` writes `AiRequest` with purpose `content_qa_gemini` and deducts credits — do NOT add a second write in `AnswerPromptJob`.
- Prompts are user-scoped on the page (each user sees only their own). Admin can query all via `Prompt::all()` or filter by `promptable_type`.
- The `prompts` table uses standard Laravel pluralisation — no `$table` override needed.

### Diagnostic Modules & Player Profiling

Diagnostic modules are a special module type that builds a player personality profile instead of testing knowledge correctness. They contain two question types:

- **`diagnostic_mcq`** — personality/behavioural questions. Each option carries `diagnostic_payload.traits` — a map of trait keys to point values. Selecting an option adds those points to `$traitScores` in `DiagnosticQuizRunner`. Persisted to `user_trait_evidence` per trait per question.
- **`survey_mcq`** — self-reported context questions (rating, role, goal, weakness). Each option has `text` + `value` (ordinal integer). The `answer.question_key` identifies the dimension. Persisted to `user_profile_evidence` for auth users; guest answers go into `$guestEvidenceLog`.

**Distinction: traits vs profile evidence**
- `user_trait_evidence` — behavioural tendencies derived from `diagnostic_mcq` answers. Input signal for the trait scoring system.
- `user_profile_evidence` — explicit self-reported context from `survey_mcq` answers. Passed to `DiagnosticProfileService` as an interpreter layer, not as trait signal. Unique per `(user_id, question_id)` — retakes `updateOrCreate`.

**Profile generation flow:**
1. `DiagnosticQuizRunner::completeModule()` gathers `$traitScores`, `$surveyAnswers`, plus `UserAxisMastery` + top-5 weak `UserConceptMastery` for auth users
2. Loads `Module::with(['subject.category'])` and passes it to `DiagnosticProfileService::generateProfile()` — the service uses it to load archetypes and available modules from the DB
3. Auth users pay credits via `AiService::sendPromptToAi()`; guests get a free direct Gemini call
4. Profile stored as JSON with new rich structure (see DiagnosticProfileService docs above). Old profiles stored before this change have the old shape — the blade view handles both via fallback aliases
5. Auth: saved to `user_module.diagnostic_profile` pivot column. Guest: saved to `session('guest_quiz_results.{moduleId}')`
6. Page refresh reloads the stored profile without re-running AI (guard checks `pivot->status === 'completed'` / session key exists)

**WoW Diagnostic seeder:** `WoWDiagnosticModuleSeeder` seeds both `diagnostic_mcq` questions (via `questions()`) and `survey_mcq` questions (via `surveyQuestions()` — current_rating, primary_role, primary_goal, self_assessed_weakness). Re-running the seeder is idempotent (`firstOrCreate` on `question` text).

**Survey question UI:** `survey_mcq` cards render with violet theming (border, ornament corners, "About You" badge, radio dots) instead of gold. Submit button shows "Next" instead of "Submit Answer".

### Model Table Name Overrides

Some models declare an explicit `$table` property to avoid Laravel's default pluralisation:

| Model | Table |
|---|---|
| `UserAxisMastery` | `user_axis_mastery` |
| `UserConceptSkillMastery` | `user_concept_skill_mastery` |
| `SubjectContent` | `subject_content` |
| `UserProfileEvidence` | `user_profile_evidence` |

Always use the explicit table name in raw queries or `DB::table()` calls — never the auto-pluralised form.

### Route Naming Conventions

Module routes use both controller-based and Livewire routes:
- `modules.index` → `App\Livewire\Modules\Index` (Livewire)
- `modules.quiz` → `ModuleQuizController@show` (blade + QuizRunner embedded)
- `questions.quiz.index` → `QuizPage` (Livewire)

Avoid creating routes with overlapping segment patterns (e.g. `/modules/destroy/{id}` vs `/modules/{module}`) — the route file has a comment about this.

### Module Route Binding

`Module` uses `slug` as its route key — always pass the model or `$model->slug` to route helpers, never `$model->id` (causes 404).

### Helper

`app/Helpers/routeWithContext.php` — autoloaded helper for building URLs with context parameters preserved.

## Critical Rules for Claude Code

### NEVER touch without explicit permission:
- `app/Livewire/QuizRunner.php` — complex pipeline logic, easy to break silently
- `app/Http/Services/AiService.php` — credit deduction on every call
- `app/Jobs/` — async pipeline, failures are hard to detect
- Any migration that touches pivot tables

### Known working solutions:
- Ordering question type: use `x-on:sorted` to update `$wire.answer` reactively
- Do NOT query DOM at submit time for ordering answers — causes rehydration bugs with Livewire + SortableJS

### Silent failure patterns to watch for:
- Mastery not updating = question not tagged to concepts (check `$question->concepts`)
- Quiz completion pipeline not firing = pipeline step status stuck on pending
- Review content not showing = cache key mismatch in GenerateReviewContentJob

### Architectural invariant: subject-scoped queries must use the selected subject, not "most recent"
The dashboard (and any future feature) supports multiple Subjects per Category, switchable via the context-bar pills (`$currentSubjectId`, carried through navigation via `route_with_context()`). Any query that reads Subject-scoped, user-specific data — diagnostic profile, mastery, concepts, modules, or anything else keyed to a `subject_id` — **must filter by `$currentSubjectId`**, never by "most recently completed/active across all subjects." A query that ignores the selected subject and falls back to global recency will silently show the wrong subject's data once a user has activity in more than one subject — this is a correctness bug, not a UX nit, and it will not surface in testing with only one subject seeded. When adding a new subject-aware dashboard panel, cross-check it against `$currentSubjectId` the same way `$hasContentActivity` and `$completedDiagnostic` already do in `DashboardController::index()`.

## Known UX Gaps

- **Research panel auto-reload** — after a successful research call, the panel fires `window.location.reload()` after a 2-second delay. Any unsaved content in the module editor will be lost. There is no warning.
- **Research "Add to content" uses a hardcoded DOM ID** — `append()` targets `document.getElementById('descriptionC')`. If the textarea is renamed the button will silently do nothing.
- **Explore flow generates only MCQ + true_false** — `ExploreModuleJob` dispatches `GenerateQuestions` for `mcq` and `true_false` only. Explore-created modules never receive `ordering` or `matching_pairs` questions.
- **`GEMINI_API_KEY` not in env docs** — ResearchService (and therefore the research panel and explore flow) silently returns an error object when this key is absent. It is now listed under Required Environment Variables.

## Content Model (Intended Direction)

The platform is moving toward a curated content model:
- Users do NOT create questions directly
- Content creators define: Subject → Concepts → Questions (with forced concept tagging)
- Users create Modules by selecting from existing question bank
- AI generates Modules from existing content via suggestion pipeline

### Not yet implemented cleanly:
- Forced concept tagging on question creation (currently optional = mastery breaks)
- Multi-subject onboarding flow for new categories
- Weight-based axis mastery calculation (weight column exists on concept_axis but is unused)

## Content Limits
- Research block: 500 words max (enforced in ResearchService prompt)
- Content block: 500 words max (enforced in AiService::generateModuleContent prompt)
- Both limits are hardcoded. Future: tie to user credit tier or module setting.
- Research overwrites existing SubjectContent row for the module (updateOrCreate)
- Content generation overwrites existing ModulePage page 1 (updateOrCreate)

## Credit System
- New users receive 50 welcome credits on registration
- Quiz requires >5 credits to start
- Every OpenAI call deducts credits via CreditService
- TokenService estimates tokens at chars/4 (rough estimate)
- Credit costs vary by model — gpt-4.1-mini costs more than gpt-4o-mini

## Environment
- Runs on Laravel Herd (Windows)
- Redis via Herd for queues, sessions, cache
- Shell commands may fail on Windows — do not assume bash/curl syntax

## Required Environment Variables

```
OPENAI_API_KEY=       # AI question/content generation
GEMINI_API_KEY=       # Gemini web-search grounding (ResearchService)
STRIPE_KEY=           # Stripe publishable key
STRIPE_SECRET=        # Stripe secret key
DB_CONNECTION=mysql
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis
```

## Security
- reCAPTCHA v3 on login and registration
- Keys in .env as RECAPTCHA_SITE_KEY and RECAPTCHA_SECRET_KEY
- Score threshold: 0.5 (adjust in RegisteredUserController and LoginRequest)
- Uses direct Google siteverify API call — no package dependency

## Windows / Herd Environment
- Do NOT attempt to run php artisan commands directly
- Instruct the user to run them manually in PowerShell instead
- PHP is available in Windows PATH but not accessible via bash
- All shell commands should be provided for the user to run manually

## Production Environment
- Linux server (Nginx + PHP-FPM) — Windows-specific assumptions do not apply
- 2 Supervisor workers both listening on the **default queue** running `php artisan queue:work`:
  - Worker 1: timeout 90s
  - Worker 2: timeout 90s
  *(No supervisor `.conf` is committed to the repo — verify against `/etc/supervisor/conf.d/` on the production server if these values change.)*
- Long-running jobs (e.g. `GenerateModuleContentJob`) must complete within 90s or they will be killed and retried — a dedicated long queue with a higher timeout is a **future improvement**
- Same Redis, MySQL, and `.env` variable requirements as local dev

## Implementation Roadmap
Gap Analysis: Axes and Skill Types
File-by-file findings
AiService.php
Axes: No reference at all. generateQuestions() fetches concepts for the subject and passes their names to the AI, but never fetches the subject's Axes, never passes the Axis structure, and the AI therefore has no awareness of what dimensional framework the concepts belong to.

Skill Types: Partially integrated. The generateQuestions() prompt includes skill_type in the example JSON schema and defines all three values. skill_type is read from the response and saved to the Question model. This part works. However:

generateContentForQuestion() (review explanations) receives no skill_type — the AI explanation is generic for all thinking modes.
tagConcepts() has a hardcoded "StarCraft 2 coach" system prompt — it is a dead legacy function that would tag wrong for any other domain.
What needs to change:

Fetch the category's Axes and pass their names/descriptions into the generateQuestions() prompt so the AI can see which Axes each concept contributes to.
Pass the question's skill_type into generateContentForQuestion() so review explanations target the right cognitive mode (recall = what it is, analysis = how to interpret it, application = how to use it).
MasteryService.php
Axes: Fully implemented. The updateMasteryForUserQuestion() method iterates $concept->axes, sums UserConceptMastery records within the axis, and writes a UserAxisMastery record. This is working correctly.

Skill Types: Zero reference. All mastery is aggregated into a single mastery_percentage per concept and per axis. There is no record of whether the user struggles with recall vs analysis vs application for any concept.

What needs to change:

After updating UserConceptMastery, also write to a new user_concept_skill_mastery table — one row per (user_id, concept_id, skill_type) triplet — tracking correct_count and total_count per thinking mode. This is a purely additive extension with no change to existing columns.
QuizRunner.php
Axes: Not referenced anywhere. calculateNextDifficulty(), chooseQuestions(), getLeastAccurateQuestions(), and handleMasteryCompletion() are all axis-blind.

Skill Types: Not referenced anywhere. Question selection is based on difficulty level and last-answer correctness only. The skill_type stored on questions is never read during quiz operation.

What needs to change (high risk — see warnings):

A new private getWeakestSkillType($user, $module) helper could read from user_concept_skill_mastery and return the skill_type the user scores lowest on for the module's concepts.
chooseQuestions() could accept a $preferSkillType argument and add a soft preference for that type when all other targeting conditions are equal.
getLeastAccurateQuestions() (final round) could weight toward the weakest skill_type in its sort logic.
These must be additive — new methods only, no changes to the existing adaptive flow's core path.
GenerateQuestions.php
Axes: Not referenced.

Skill Types: Not directly referenced — it passes everything to AiService::generateQuestions() which does handle skill_type. The issue is in the PromptBuilder() method used in suggestions mode: it builds a bare-minimum prompt with no concept list and no axis context. Questions generated in suggestions mode are likely to have poorly assigned skill_types and will miss valid concepts entirely.

What needs to change:

The PromptBuilder() method should load the module's subject concepts (as AiService::generateQuestions() does) and include them in the prompt.
Both modes should eventually pass Axes so the AI's tagging is dimensionally aware.
GenerateReviewContentJob.php
Axes: Not referenced.

Skill Types: Not referenced. The job calls ReviewQuestionService::getReviewContent() → AiService::generateContentForQuestion(), and neither pass $question->skill_type.

What needs to change:

The job already has the $question object. Pass $question->skill_type into the chain so the review explanation in generateContentForQuestion() can tailor its instruction to the relevant thinking mode.
SuggestionJob.php
Axes: Not referenced. Delegates entirely to UserModuleService::nextModuleResponse() (not in scope of this analysis).

Skill Types: Not referenced.

What needs to change:

Read UserAxisMastery for the user and identify their weakest axes. Pass this information into nextModuleResponse() so it can bias suggestions toward modules that strengthen weak skill dimensions.
Question.php
Axes: Not directly related — axes are accessed via question → concepts → axes. No change needed here.

Skill Types: skill_type is in $fillable and is saved correctly. However there is no enum cast, no validation, and no database-level constraint — any string value can be stored silently.

What needs to change:

Add 'skill_type' => \App\Enums\SkillType::class to $casts once a SkillType enum is created (or use a simple cast with validation at the boundary).
Concept.php
Axes: Fully modelled. axes() relationship exists with weight pivot. Correct.

Skill Types: Not referenced. The mastery_for_user appended attribute returns a single percentage with no skill-type breakdown.

What needs to change: No urgent changes. A future skillMasteryForUser($skillType) helper could be added but is not required now.

Axis.php
Axes: It is the Axis model. Relationships are correct: category() and concepts().

Skill Types: Not referenced.

What needs to change: Could add userMasteries() → hasMany(UserAxisMastery::class) for convenience querying, but this is optional.

UserAxisMastery.php
Axes: Correctly references Axis via axis().

Skill Types: Not referenced. Only tracks a single mastery_percentage with no skill-type breakdown.

What needs to change: No immediate changes — adding a user_axis_skill_mastery table later (derived from concept-skill mastery) is a Phase 2 concern.

Prioritised Implementation Plan
Ordered from lowest to highest risk. Each phase is independently deployable.

Phase 1 — Data layer foundations ✓ COMPLETE
1a. Create user_concept_skill_mastery table
Columns: user_id, concept_id, skill_type (enum: recall/analysis/application), correct_count (int), total_count (int), mastery_percentage (decimal), timestamps. Unique key on (user_id, concept_id, skill_type).

1b. Create UserConceptSkillMastery model
With user(), concept() relationships and the three fillable columns.

1c. Create SkillType PHP enum
recall | analysis | application. Add 'skill_type' => SkillType::class cast to Question.php.

Nothing writes to these yet — migration + model + enum only. Zero breakage risk.

Phase 2 — MasteryService extension ✓ COMPLETE
2a. Extend updateMasteryForUserQuestion()
After the existing UserConceptMastery::updateOrCreate() block, add a parallel UserConceptSkillMastery::updateOrCreate() call scoped to the answered question's skill_type. Reads concept->questions()->where('skill_type', $skillType) and counts correct user answers of that skill type.

Existing mastery columns are untouched. New records written to the new table only.

Phase 3 — AI prompt enrichment ✓ COMPLETE
3a. Pass Axes into generateQuestions() in AiService
After fetching $conceptMap, fetch the category's axes: $module->subject->category->axes()->with('concepts')->get(). Build a short string like "Axes: [Strategy (concepts: Build Orders, Map Control), Mechanics (concepts: APM, Micro)]" and insert it into the prompt before the concepts line. Tell the AI: "Tag each question's concepts from the list above — the axes show which skill dimensions each concept belongs to."

3b. Pass skill_type into generateContentForQuestion()
Add a string $skillType parameter. Append to the prompt: "This question tests {$skillType} thinking. Tailor the explanation accordingly: recall = reinforce the fact; analysis = clarify the interpretation; application = demonstrate the decision." This requires the callers in ReviewQuestionService and GenerateReviewContentJob to pass $question->skill_type through the call chain.

3c. PromptBuilder() in GenerateQuestions job
AiService::generateQuestions() already fetches the subject concept list from the database internally and injects it into every prompt regardless of mode. PromptBuilder() was dead code and has been removed. No further action needed.

Phase 4 — SuggestionJob axis awareness (moderate risk, isolated)
4a. Extend SuggestionJob::handle()
After loading $user, query: $user->userAxisMasteries()->with('axis')->orderBy('mastery_percentage')->take(3)->get(). Build a string of weak axis names. Pass this into UserModuleService::nextModuleResponse() as an additional parameter (or cache key) so the suggestion AI can factor in the user's weakest skill dimensions when recommending next modules. This requires a small change to UserModuleService interface — verify it doesn't break other callers.

Phase 5 — QuizRunner skill-type adaptation (HIGHEST RISK — approach with caution)
5a. Add getWeakestSkillType($user, $module) as a new private method
Reads UserConceptSkillMastery for all concepts in the module, averages mastery by skill_type, returns the skill_type with the lowest average. Pure computation — no side effects. Safe to add.

5b. Pass weakest skill_type into chooseQuestions()
Add an optional ?string $preferSkillType = null argument. When set, prefer questions matching that skill_type using orderByRaw("CASE WHEN skill_type = '{$type}' THEN 0 ELSE 1 END") or a PHP sort after fetching. Fall back to existing logic if no preference is available. Only touch the internals of chooseQuestions() — do not change any of the caller paths.

5c. Weight getLeastAccurateQuestions() by skill_type in final round
In the sort closure in getLeastAccurateQuestions(), after the four existing criteria, add a fifth: questions whose skill_type matches the weakest skill_type from Phase 5a are preferred. Purely additive to the existing sort logic.

New tables / models required
What	Why
user_concept_skill_mastery table	Core foundation for skill-type tracking per concept
UserConceptSkillMastery model	Eloquent access to the above
SkillType PHP enum	Enforce valid values, enable casting
user_axis_skill_mastery (Phase 2 optional)	Axis-level skill-type rollup — lower priority
Warnings
QuizRunner.php — CLAUDE.md explicitly flags this file. Changes in Phase 5 must be surgical: new private methods only, arguments added as optionals with defaults, no changes to any method signatures that Livewire calls or that other methods depend on. Test each sub-step independently before combining.

MasteryService — The existing mastery_percentage calculation uses count(all questions for concept) / count(user correct answers). If you accidentally change this denominator to be skill-type-scoped, existing mastery scores for all users will drift silently. Write only to the new table in Phase 2 — never touch the existing UserConceptMastery calculation.

AiService credit deduction — Adding Axes to the prompt increases token length. Small increase (~5–10%), but every generation call deducts credits. Worth flagging so it doesn't surprise users.

Review content caching — GenerateReviewContentJob caches content at key review_content:{question_id} for one hour. After Phase 3b, if a user hits a cached entry, they'll get the old non-skill-type-aware explanation until the cache expires. This is acceptable as a transient inconsistency during rollout.

SuggestionJob → UserModuleService interface — Phase 4 requires passing weak axes into nextModuleResponse(). Verify how many places call that method before changing its signature. An optional parameter with a default null is the safest approach.

## Diagnostic Profile Rework ✓ COMPLETE

### Archetypes system
`archetypes` table + `archetype_category` pivot. `Archetype` model with `categories()` relationship; `Category` now has `archetypes()`. `ArchetypeSeeder` seeds 8 universal archetypes (`strategic_controller`, `reactive_survivor`, `aggressive_forcer`, `mechanical_grinder`, `adaptive_opportunist`, `theory_heavy_underperformer`, `uncertain_beginner`, `patient_setup_artist`) and attaches all to the "Games" category. Adding archetypes for other categories requires only a seeder or DB insert — no code changes.

### Universal prompt (DiagnosticProfileService)
Prompt is now fully game-agnostic. The service loads game context, archetypes, and available modules from the DB at generation time using the `?Module $module` parameter added to `generateProfile()`. No game-specific language is hardcoded. The AI is instructed to choose one archetype from the category's list and one module from the subject's published/ready non-diagnostic modules.

### Richer output shape
New fields: `profile_title`, `archetype_key`, `confidence_level`, `summary`, `evidence[]` (signal + source + interpretation per item), `self_report_check` (alignment enum + comment), `likely_in_game_pattern`, `primary_strength` (name + concepts[]), `primary_growth_area` (name + concepts[]), `recommended_module` (module_id + title + reason), `next_practice_goal`. `normaliseResponse()` also writes backward-compat aliases so profiles stored before the rework continue to render.

### Evidence-based philosophy
Survey answers are treated as self-reported context (weaker signal). Trait scores and concept/axis scores are stronger evidence. The prompt explicitly instructs the AI to surface contradictions between self-report and diagnostic behaviour — this is the most valuable output the system can produce. Every claim in `evidence[]` must trace back to a named input field.

### Guest → auth evidence transfer (fixed)
`RegisteredUserController::claimGuestQuizResults()` previously only wrote `UserTraitEvidence` for a guest's diagnostic session — `survey_mcq` answers logged in `$guestEvidenceLog` (tagged `'type' => 'survey'`) were silently dropped on signup, so `UserProfileEvidence` never got backfilled for guests. Fixed: survey-tagged evidence entries now also write to `UserProfileEvidence::updateOrCreate()` (same `(user_id, question_id)` key as the authenticated path in `DiagnosticQuizRunner`), resolving `category_id`/`subject_id` from the module. Each module's claim (diagnostic and regular-quiz branches) now runs inside `DB::transaction()`, and `session()->forget("guest_quiz_results.{$moduleId}")` only fires after that module's transaction commits — a failed claim no longer wipes the guest's session data, so it survives for a retry instead of being lost. Tests: `tests/Feature/Auth/GuestDiagnosticClaimTest.php`.

### Concept grounding for strength/growth-area concepts (fixed)
`primary_strength.concepts` and `primary_growth_area.concepts` used to be pure LLM invention — free-text labels (e.g. "Direct Pressure", "Converting Advantages") never checked against the subject's real `concepts` table, even though the dashboard renders them as chips that look like real ontology entities. Fixed in `DiagnosticProfileService`:
- `generateProfile()` builds `Concept::where('subject_id', $subject->id)->pluck('id', 'name')` and passes the concept names into `buildPrompt()`.
- `buildPrompt()` gained an optional `?string $validConceptNames` param and a new "VALID CONCEPTS FOR THIS SUBJECT" prompt block + Rule 11 instructing the AI to use only exact names from that list (or return fewer/none — never invent).
- New private `groundConcepts(array $response, $conceptMap): array` runs on the **raw AI response, before `normaliseResponse()`** — filters both `concepts[]` arrays down to names that exist in the map, drops anything else, logs a warning per field when something's dropped, never touches the `.name` labels, never fails the whole profile (empty array is a valid outcome). Applied identically to both the guest (direct Gemini) and authenticated (`AiService`) paths.
- **Decision: stores validated names (flat strings), not `{name, id}` objects** — keeps the JSON shape unchanged so `resources/views/components/dashboard/profile-hero.blade.php` needed no changes. A future feature needing concept IDs can re-resolve them from validated names via the same one-line `pluck('id', 'name')` query.
- `primary_strength.name` / `primary_growth_area.name` remain free personalised text — only the `concepts[]` arrays are ontology-constrained.
- Does NOT touch `AiService.php` — `DiagnosticProfileService` loads/validates concepts entirely on its own.
- Tests: `tests/Feature/Services/DiagnosticProfileServiceTest.php` (all-valid / mixed-valid / all-invalid / missing-concepts-key / zero-concepts-subject / null-module / guest-path / warning-logged).

### Known gap: recommended_module is still not concept-grounded
`DiagnosticProfileService::buildAvailableModules()` still hands the AI the **entire** catalog of published/ready non-diagnostic modules for the subject (id, name, description — no concept tags), and the AI picks one in the same generation call by free-text similarity between its own profile prose and each module's static `description` field — not a structured recommendation. Grounding `primary_strength`/`primary_growth_area` concepts (above) does not by itself fix this: there is still no code path checking whether a module's tagged concepts (via `concept_question`) actually cover the now-validated `primary_growth_area.concepts`, and no distinction between a knowledge gap (module appropriate) vs. a behavioural/execution gap (module may not help). `$conceptScores` passed into the prompt comes from `UserConceptMastery`, which is empty for a user who has only completed the diagnostic and no content module — so there's no real mastery evidence to base a module recommendation on yet either. This is the concrete gap a future targeted-knowledge-check / next-step selector (a proposed lightweight `KnowledgeCheck`-style assessment, not yet built) needs to close — do not treat `recommended_module` as validated against the ontology today.

## Next Step + Reflection Loop ✓ COMPLETE

Adds a second, faster cadence on top of the diagnostic profile: where the diagnostic produces a profile roughly once per subject (slow cadence), this system gives the user one concrete practice task at a time and reinterprets it after they report back (fast cadence, re-triggered by reflection or by expiry). No changes to `QuizRunner.php` or `AiService.php`.

**Tables/models:** `user_profile_insights` (`UserProfileInsight`) — a durable, queryable snapshot of a diagnostic profile at generation time (subject-scoped, one row per diagnostic completion), separate from the `user_module.diagnostic_profile` JSON blob so next-steps can reference a stable `insight_id`. `user_profile_insight_concepts` — pivot marking which `Concept`s are the profile's growth-area concepts (`UserProfileInsight::growthAreaConcepts()`). `user_next_steps` (`UserNextStep`) — one row per generated task; carries `subject_id`, `insight_id`, `concept_id`, `previous_step_id` (chains regenerated steps), `step_type`, `status`, `generated_reason`. `user_next_step_reflections` (`UserNextStepReflection`) — the user's self-report on a task (`did_try`, `how_it_went`, `why_reasoning`). `user_reflection_evidence` (`UserReflectionEvidence`) — AI-interpreted evidence items derived from a reflection, each with a `confidence` score.

**Enums:** `StepType` (`task | module | knowledge_check` — only `task` is implemented today), `NextStepStatus` (`pending | attempted | completed | superseded | expired`), `GeneratedReason` (`initial | reflection | expired | insight_change`), `DidTry` (`yes | no | partially`).

**Flow:**
1. `DiagnosticQuizRunner::completeModule()` creates a `UserProfileInsight` scoped to `$moduleModel->subject_id`, then — inside the same DB transaction — calls `NextStepService::supersedePendingStepsForNewInsight()` to flip any still-`pending` `UserNextStep` rows for that **same user + same subject** to `superseded` (never a cross-subject or "most recent regardless of subject" query — this repeats the subject-scoping invariant documented above for `$completedDiagnostic`/`$heroModule`). Outside the transaction, `NextStepService::generateInitial()` makes the AI call (`gpt-4o-mini`, purpose `next_step_generation_initial`) and writes the first `UserNextStep` (`status = pending`, `generated_reason = initial`).
2. Dashboard shows the active step via `next-experiment.blade.php`, fed by `DashboardController`'s `$activeNextStep` (subject-scoped the same way, `step_type = task`, status in `pending|attempted`).
3. User submits a reflection (`NextStepReflection` Livewire component) → creates `UserNextStepReflection`, flips the step to `attempted`, dispatches `InterpretReflectionJob`.
4. `InterpretReflectionJob` calls the AI (purpose `reflection_interpretation`) to turn the reflection into `UserReflectionEvidence` rows, marks the step `completed`, and calls `NextStepService::regenerateAfterReflection()` to generate the next task (`generated_reason = reflection`, chained via `previous_step_id`).
5. `next-steps:expire` console command flags any `pending`/`attempted` step older than 14 days as `expired` and calls `NextStepService::regenerateAfterExpiry()` so the dashboard always has a live task rather than going silent. Registered via `Schedule::command('next-steps:expire')->daily()` in `routes/console.php` — requires the standard Laravel scheduler (`schedule:run` cron entry) to be active on the server for this to actually fire.

**Verified invariants (do not regress):**
- **Subject-scoped superseding** — `supersedePendingStepsForNewInsight()` filters by `user_id` AND `subject_id`; every `UserNextStep::create()` call site (`generateInitial`, `regenerateAfterReflection`, `regenerateAfterExpiry`) propagates `subject_id` from the originating insight/step rather than re-deriving it. `subject_id` is a NOT-NULL FK on both `user_profile_insights` and `user_next_steps`, so it can't be silently omitted.
- **Confidence is code-clamped, not prompt-clamped** — `InterpretReflectionJob::MAX_CONFIDENCE = 0.4`; every evidence item is passed through `min((float)($item['confidence'] ?? 0), self::MAX_CONFIDENCE)` then `max($confidence, 0)` before being written. The "never higher than 0.4" prompt rule is a backup, not the actual guarantee — self-reported reflection evidence can never outweigh diagnostic-derived evidence in downstream scoring.
- **Expiry always regenerates** — `ExpireNextSteps` calls `regenerateAfterExpiry()` for every step it expires (wrapped per-step in try/catch so one AI failure doesn't block the rest of the batch); the dashboard never goes empty after a successful sweep.
- **`current-focus.blade.php` title is bound to the growth area, not the archetype** — `$growthAreaName` comes from `primary_growth_area.name` (fallback: legacy `growth_area` string), and the body (`$displayPattern`) prefers the new `growth_area_pattern` field over the archetype-level `likely_in_game_pattern`, only falling back to the latter for profiles generated before this field existed. `DiagnosticProfileService`'s prompt explicitly forbids the AI from reusing `likely_in_game_pattern` text for `growth_area_pattern` — it must describe how the specific growth area manifests, not restate general archetype flavor.

**Concept grounding:** both `NextStepService::groundConceptName()` and `InterpretReflectionJob::groundConceptName()` validate any AI-returned concept name against `Concept::where('subject_id', ...)->where('name', ...)` before storing an ID — an unrecognised name is dropped to `null` and logged, never invented into the DB, following the same pattern as `DiagnosticProfileService::groundConcepts()`.

**Known gaps:**
- `step_type` values `module` and `knowledge_check` exist in the enum but have no generation or rendering path — only `task` is implemented.
- `GeneratedReason::InsightChange` exists in the enum but nothing currently sets it — a new insight supersedes old steps but the replacement step this creates is generated on-demand later with `generated_reason = initial`/`reflection`, not `insight_change`. Confirm intended usage before relying on it.

## Profile-First Dashboard (V1) ✓ COMPLETE

The authenticated dashboard (`DashboardController` → `resources/views/dashboard.blade.php`) is profile-first for any user with a completed diagnostic, rather than module-first. Uses only data already generated at diagnostic completion — no schema changes, no AI calls on dashboard load.

`DashboardController::index()` additionally loads:
- `$completedDiagnostic` — the user's most recently completed diagnostic module **for the currently selected subject only**: `$user->modules()->where('modules.type', 'diagnostic')->where('subject_id', $currentSubjectId ?? 0)->wherePivot('status', 'completed')->orderByPivot('completed_at', 'desc')->first()`. Must stay subject-scoped — see the "subject-scoped queries" invariant under Critical Rules.
- `$diagnosticProfile` — that module's `pivot->diagnostic_profile`, `json_decode`d
- `$subjectDiagnosticModule` — when the selected subject has no completed diagnostic, the published/ready diagnostic module for that subject (if one exists), used to render a subject-specific "Complete the {Subject} diagnostic" CTA in place of the profile-first sections instead of showing nothing or another subject's profile

New Blade components under `resources/views/components/dashboard/`, rendered in this order at the top of `dashboard.blade.php` (only when `$diagnosticProfile` exists):
- `profile-hero.blade.php` — `profile_title` / `confidence_level` / `summary` / `primary_strength` / `primary_growth_area`
- `current-focus.blade.php` — `primary_growth_area.name` + `likely_in_game_pattern`, framed as a hypothesis ("Mindcollector currently thinks this is worth investigating"), not a verdict
- `next-experiment.blade.php` — promotes `next_practice_goal` into a persistent action card
- `evidence-panel.blade.php` — collapsible (`x-collapse`, matches the pattern already used in `diagnostic-quiz-runner.blade.php`); renders `evidence[]` (signal/interpretation/score, self-reported items filtered out) plus `self_report_check`. Never renders the raw `source` field (internal path like `trait_scores.reactivity`) in production UI.
- `recommended-next-step.blade.php` — `@props(['type' => 'module', 'data' => null, 'reason' => null])`. `type='module'` is the only implemented type; `$data` accepts either a `Module` model (new-shape profile, resolved via `recommended_module.module_id`) or a plain string (legacy-shape fallback via `next_module_suggestion`, rendered without a CTA since there's no reliable module id). Add future step types as additional `$type` branches — do not restructure the props contract.

All components read both the new normalised fields and the legacy backward-compat aliases (`player_type`, `narrative`, `top_traits`, `growth_area`, `next_module_suggestion`) so old stored profiles still render. `top_traits` (flat array of trait keys) is used as a synthetic `primary_strength` (`name = 'Key Traits'`) when the new-shape field is absent. The no-completed-diagnostic path (`$diagnosticNudge`, category-scoped — separate from the newer subject-scoped `$subjectDiagnosticModule` CTA above) is untouched.

**Not yet implemented** (intentionally deferred — see the "recommended_module is not concept-grounded" gap above): no stored "current focus" object, no progress/completion tracking on the next-practice-goal, no next-step selector beyond the single `recommended_module` passthrough.

### Lower dashboard reframed around "Knowledge Profile" (renamed, not new)
The pre-existing lower half of `dashboard.blade.php` (predating the profile-first work) has been renamed and reframed to stop contradicting the profile-first sections above it — no new backend systems, no schema changes, same underlying data:
- **"Overall Mastery" → "Knowledge Profile"**. Dropped the `{{ $overallMastery }}/100` score entirely — now shows `$assessedConceptCount of $totalConceptCount concepts assessed` (`$concepts->filter(fn($c) => $c->userConceptMasteries->isNotEmpty())->count()`). A concept only counts as "assessed" if a real `UserConceptMastery` row exists for it — absence of evidence is visually distinct from a 0% score (per-concept rows show "Not yet assessed" instead of "0%"; the radar chart withholds its filled polygon entirely — showing only the neutral hex grid — when `$assessedConceptCount === 0`, rather than rendering a polygon that reads as "scored zero everywhere").
- **"Topic Mastery" → "Concept Knowledge"**; radar caption → **"Concept Knowledge Map"**.
- **"My Guides" → "Learning"** (contents/functionality unchanged — renamed so it can later host non-module activity types, e.g. a future `KnowledgeCheck`, without another rename).
- **Leaderboard demoted** — moved to the 3rd/last grid column, `opacity-80` + muted heading, so Concept Knowledge and Learning read as visually dominant over it.
- **Redundant diagnostic hero card removed.** `$heroModule` (used only in this lower Knowledge Profile section) is now scoped to `where('subject_id', $currentSubjectId)->where('modules.type', '!=', 'diagnostic')` — it can no longer surface a diagnostic module at all, since the diagnostic profile already has its own dedicated section at the top of the page. This also fixed a subject-scoping bug: `$heroModule` previously ignored `$currentSubjectId` entirely.
- Page title is now `{{ $currentSubjectName }} Profile` (was the static "Knowledge Atlas"); subtitle is "Your current profile, focus, and learning evidence." (replaced "Welcome back, {name}").

**Known residual inconsistency**: the "Alchemist" badge (top-right of the header) is still driven by the old `$overallMastery` percentage banding (I–V levels), which no longer has a visible home elsewhere on the page now that the 0–100 score is gone from Knowledge Profile. Left as-is since it wasn't in scope for this pass — a future decision is needed on whether to keep it as an independent gamification badge or retire/rework it.

## Module Route Binding
Module uses `slug` as its route key — always pass the model 
or `$module->slug` to route helpers, NEVER `$module->id`.
Known intentional integer exceptions:
- modules.next-module uses {moduleId} as plain integer — correct by design

## Design System

### Design Tokens (`tailwind.config.js`)

**Fonts:**
- `font-sans` → Inter (weights 300–800)
- `font-display` → Playfair Display (italic + 400–900)

**Color palette:**

| Token | Value | Use |
|---|---|---|
| `surface-0` | `#09090D` | Page background |
| `surface-1` | `#111116` | Cards, panels |
| `surface-2` | `#18181E` | Elevated elements |
| `surface-3` | `#1E1E26` | Modals, overlays |
| `ink` | `#F0F0F2` | Primary text |
| `ink-muted` | `#8A8A9A` | Secondary text |
| `ink-subtle` | `#52525F` | Disabled/placeholder text |
| `gold` | `#C8952C` | Brand/CTA primary |
| `gold-light` | `#E8B84B` | Hover gold |
| `gold-dark` | `#8B6420` | |
| `gold-muted` | `#6B4E1A` | Subtle gold fills |
| `gold-subtle` | `#1E150A` | Very faint gold tint |
| `violet` | `#7B6EE8` | Secondary/quiz elements |
| `violet-hover` | `#8B7EF8` | |
| `violet-muted` | `#4A3FA8` | |
| `violet-subtle` | `#18163A` | Very faint violet tint |
| `accent` | `#C8952C` | Alias for `gold` — backwards compat |
| `line` | `#1E1E26` | Default border |
| `line-strong` | `#2C2C38` | Stronger border |
| `line-gold` | `#6B4E1A` | Gold-tinted border |

**Gradients:** `bg-gold-gradient`, `bg-gold-gradient-v`, `bg-violet-gradient`

**Shadows:** `shadow-gold-sm`, `shadow-gold`, `shadow-gold-lg`, `shadow-violet-sm`, `shadow-violet`

### Component Utility Classes (`resources/css/app.css`)

| Class | Description |
|---|---|
| `.linear-card` | Standard card: `surface-1` bg, `line` border, gold hover |
| `.sidebar-item` / `.sidebar-item.active` | Nav items (active = gold) |
| `.form-input`, `.form-select`, `.form-textarea`, `.form-checkbox` | Form controls with gold focus ring |
| `.page-section`, `.page-section-title`, `.page-section-desc` | Section layout (used in edit pages) |
| `.badge-green`, `.badge-amber`, `.badge-gold`, `.badge-blue`, `.badge-gray` | 10px status pills |
| `.tab-btn`, `.tab-active`, `.tab-inactive` | Tab navigation |
| `.btn-primary` | Gold gradient button |
| `.btn-secondary` | Violet-bordered button |
| `.btn-ghost` | Subtle outline button |
| `.btn-danger` | Red destructive action |
| `.ordering-list`, `.ordering-item` | Drag-to-order quiz list (SortableJS) |

### SVG Icon Component

**Usage:** `<x-mc-icon name="icon-compass" class="w-6 h-6 text-gold"/>`

- Component: `resources/views/components/mc-icon.blade.php`
- Inlines SVG from `public/images/icons/{name}.svg` directly into HTML — `currentColor` works, so Tailwind `text-*` controls the color
- `w-*`/`h-*` Tailwind classes set the dimensions

**Important:** `<x-icon>` is **taken** by the `blade-ui-kit/blade-icons` package (^1.8). Always use `<x-mc-icon>` instead. Using `<x-icon>` will throw "Svg by name … not found."

**Available icons** (`public/images/icons/`):

| Name | Use |
|---|---|
| `icon-complete` | Quiz completion |
| `icon-compass` | Score / navigation |
| `icon-scroll` | Question count |
| `icon-hourglass` | Time elapsed |
| `icon-leaf` | Strengths |
| `icon-starburst` | Weaknesses / needs improvement |
| `icon-lightning-circle` | Guest CTA / energy |
| `icon-flask` | Development / alpha |
| `icon-axis-hex` | Concept/axis progress bars |
| `icon-diamond` | Diamond bullet point |
| `icon-delta` | Change / progression |
| `badge-wow` | World of Warcraft game badge |
| `badge-sc2` | StarCraft 2 game badge |
| `badge-lol` | League of Legends game badge |
| `gem-mastered` | Mastery gem (full) |
| `gem-strong` | Mastery gem (strong) |
| `gem-developing` | Mastery gem (developing) |
| `gem-weak` | Mastery gem (weak) |
| `gem-unknown` | Mastery gem (unknown) |
| `bg-arch` | Decorative arch background |
| `bg-constellation` | Decorative constellation background |

### Ornament Components

**`<x-ornament.corner position="tl|tr|bl|br" class="..."/>`**

- File: `resources/views/components/ornament/corner.blade.php`
- Inline SVG corner bracket ornament; rotate handled via `position` prop
- Use `absolute` positioning, `text-gold/20` or similar for subtle decoration
- Example: `<x-ornament.corner position="tl" class="absolute top-2 left-2 w-10 h-10 text-gold/20"/>`

### Guest Quiz Score Accumulation

Guest quiz uses `$allQuestionResults` (separate from the per-round `$questionResults`) to track answers across all difficulty rounds. `guestScore()` and `buildGuestCompletionStats()` read from `$allQuestionResults`. `$questionResults` is still round-scoped for the wrong-answer display between rounds. `$allQuestionResults` is reset on `retake()`.