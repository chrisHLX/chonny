# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Product Vision (updated 2026-07-21 — read `VISION.md` for the full document)

MindCollector's center of gravity has shifted twice: **content** (AI-generated question/module bank) → **diagnostics** (profiling the player) → now **capturing expertise** — structuring what real experts already know into canonical modules, rather than asking AI to invent or verify domain knowledge from scratch. A canonical module (see the "Canonical Context Module Template" section below) is increasingly treated as *structured player expertise*, not AI-generated-then-verified content — the AI's job is to organize a practitioner's own brain dump, not to know the class itself. This does not change any architectural boundary already documented in this file (diagnostics stay universal/class-blind; context still only filters post-diagnosis recommendations, per the Player Model Design Principle and Subject Context Dimensions sections below) — it changes how canonical modules get authored and what confidence the platform can place in them. See `VISION.md` for the full vision, the "what this validates before automating" framing, and pattern findings from the first two practitioner brain dumps (Discipline Priest, Feral Druid).

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
  → on completion: credits rewarded (no pipeline/async jobs dispatched anymore — SuggestionJob and
    GenerateCardJob were both retired; see their respective retirement notes)
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

### ⚠️ NEVER include spec_id=NULL baseline spells in any spell display list ⚠️
See "Baseline ability display — DO NOT re-add" further down this file for the full story. Short version: `spell_class_availability` rows with `spec_id = NULL` are ambiguous by construction — some are genuinely class-wide (Leg Sweep), some are actually spec-restricted but Blizzard's own export never says so (Mind Sear, on Discipline Priest). There is no reliable way to derive which is which from data alone — confirmed by multiple failed attempts (see that section: a SimC GitHub search, a spell-relationships pattern check, a cooldown/CC heuristic — all disproven under testing). This was tried once as a bulk heuristic (`TalentSelectionService::alwaysAvailableAbilityIds()`) and reverted the same day, 2026-08-06, after it put Shadow-only spells on Discipline's kit. That method still exists, unused, marked "DO NOT WIRE IN" in its own docblock. **The actual working fix is a hand-curated file, not a heuristic** — `data/spelldata/baseline-spec-overrides.txt` + `TalentSelectionService::verifiedBaselineAbilityIds()` — add one manually-verified line at a time, never bulk-derive. Read the full section before touching any of this.

### NEVER touch without explicit permission:
- `app/Http/Services/AiService.php` — credit deduction on every call
- `app/Jobs/` — async pipeline, failures are hard to detect
- Any migration that touches pivot tables

### `app/Livewire/QuizRunner.php` — care required, narrowed 2026-07-09
No longer a blanket no-touch (the user explicitly authorized editing it and clarified the actual fragile pattern). The one genuinely fragile surface is the **ordering-question answer**, split across two files that must stay in sync:
- `resources/views/livewire/quiz-runner.blade.php`'s `@case('ordering')` block: `wire:ignore` on the `<ul>` (stops Livewire from re-diffing/fighting SortableJS), plus `x-init`/`x-on:sorted` pushing the live DOM order into `$wire.answer` reactively via Alpine.
- `QuizRunner::submit()`'s `case 'ordering':` branch reads `$this->answer` as already-correct data — **never** query the DOM at submit time; that's what caused the original rehydration bugs this rule was written to prevent.

Everything else in the file (completion flow, credit rewards, retake handling, question flagging) is regular Livewire logic — no special fragility, just the general care due to any actively-used component. **No pipeline dispatch happens on quiz completion anymore** (removed 2026-07-10 alongside the card system — see the `Card` retirement note below); `completeModule()` now only rewards credits.

**A second, previously-latent rehydration bug fixed 2026-07-10:** `prepareQuestionsForQuiz()`/`shuffleCurrentQuestionAnswers()` used to shuffle MCQ options / ordering steps / matching-pair values by cloning the `Question` model and mutating the clone's `answer` attribute in memory (`$q->answer = $answer;`). This does **not** survive Livewire's request cycle — when `$this->questions` (a Collection of Eloquent models) gets rehydrated on the next request, Livewire re-fetches fresh models from the DB, silently discarding the in-memory-only mutation. The bug was latent because nothing triggered a same-question round-trip without also advancing past the question (`submit()` always calls `nextQuestion()`) — until question flagging added the first action (`toggleFlag()`) that round-trips the server while staying on the same question, which is what actually surfaced it (visually: clicking the flag button reset MCQ options / matching-pair values back to their unshuffled DB order — the ordering question type was accidentally shielded from the visible symptom by its own `wire:ignore`, though the same underlying data loss was happening there too).

**Fix:** shuffled order now lives in `public array $shuffledOptions` — a plain array keyed by question ID (`['options' => [...]]` / `['steps' => [...]]` / `['values' => [...]]`), never baked into the question model itself. Plain arrays don't have Eloquent's rehydration problem. `quiz-runner.blade.php` reads `$shuffledOptions[$question->id][...] ?? $question->answer[...]` (falls back to the canonical unshuffled order if a question type was never shuffled). `submit()`'s correctness checks (`case 'ordering'`/`case 'matching_pairs'`) already read the canonical `$question->answer['steps']`/`['correct']` directly — untouched by this fix, and now *reliably* correct (rather than accidentally correct via the old bug happening to reset the mutation back to canonical before `submit()` ran). Do not go back to mutating cloned question models for shuffle order — that's the exact rehydration trap this fix exists to avoid.

### Known working solutions:
- Ordering question type: use `x-on:sorted` to update `$wire.answer` reactively
- Do NOT query DOM at submit time for ordering answers — causes rehydration bugs with Livewire + SortableJS

### Silent failure patterns to watch for:
- Mastery not updating = question not tagged to concepts (check `$question->concepts`)
- Review content not showing = cache key mismatch in GenerateReviewContentJob

### Architectural invariant: subject-scoped queries must use the selected subject, not "most recent"
The dashboard (and any future feature) supports multiple Subjects per Category, switchable via the context-bar pills (`$currentSubjectId`, carried through navigation via `route_with_context()`). Any query that reads Subject-scoped, user-specific data — diagnostic profile, mastery, concepts, modules, or anything else keyed to a `subject_id` — **must filter by `$currentSubjectId`**, never by "most recently completed/active across all subjects." A query that ignores the selected subject and falls back to global recency will silently show the wrong subject's data once a user has activity in more than one subject — this is a correctness bug, not a UX nit, and it will not surface in testing with only one subject seeded. When adding a new subject-aware dashboard panel, cross-check it against `$currentSubjectId` the same way `$hasContentActivity` and `$completedDiagnostic` already do in `DashboardController::index()`.

### Category/subject selection is remembered in session, 2026-07-09
Category/subject context historically only ever traveled through URL query params (`?category_id=&subject_id=`), read fresh on every request with no persistence — any link that omitted them (a plain `route('dashboard')`, the nav sidebar, a bookmark, `route_with_context()` itself when the current request had no params) silently fell back to `Category::first()`/`Subject::first()`, i.e. whatever happens to be first in the seeded data (in this app: Games → StarCraft 2) — regardless of what subject the user was actually just working in. Confirmed as a real, reported UX bug (surfaced via the "Continue Learning" button on the quiz completion screen, which redirects to a bare `route('dashboard')`).

**Fix:** every place that used to default straight to `Category::first()`/`Subject::first()` now checks `session('context.category_id')`/`session('context.subject_id')` first, and writes back to those same two session keys whenever an explicit selection is made (URL param present, or an interactive Livewire property change). Shared, app-wide session keys — not one per page — so switching subject on any one of these pages carries through to all the others:
- `DashboardController::index()`
- `route_with_context()` helper (`app/Helpers/routeWithContext.php`) — read-only fallback, deliberately does **not** write to session itself (it's called many times per render just to build `href`s; a side effect there would be the wrong architectural layer)
- `Collection.php`, `QuizSelection.php`, `Modules\Index.php` (Livewire `mount()`, plus `updatedCategoryId()`/`updatedCurrentSubjectId()` on `Modules\Index` for interactive switches)
- `ConceptController::index()`

**Stale cross-category subject guard:** a remembered `subject_id` can belong to a *different* category than the one just resolved (e.g. session still holds a subject from before the user switched categories). Every site above validates the resolved subject actually belongs to the resolved category's subject list before trusting it (`Modules\Index` reuses its existing `syncSubject()` method for this; the others use `$subjects->contains('id', $currentSubjectId)`), falling back to that category's first subject rather than silently scoping every subsequent query to a subject that isn't even in the current category.

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

### Platform framing: routing layer, not just content
The core product bet is not the size of the question bank — it's the loop that maps a user to a diagnosed gap (via Concepts/Axes) and finds or generates the content that closes it, then tracks whether it worked. Concepts and Axes exist to shrink an effectively infinite domain-knowledge space (e.g. every possible WoW cooldown/comp interaction, every LoL item/power-spike timing, every SC2 build-order weakness) down to the handful of areas that matter for a given user, so the diagnostic's job is search-space reduction, not final judgment. Practically this means: prioritise the matching/routing infrastructure (concept-to-module coverage, mastery-aware next-step selection) over raw content volume — a large content library with no routing layer doesn't deliver on this, and a thin content library with good routing already partially does.

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
BLIZZARD_CLIENT_ID=     # Blizzard Game Data API OAuth — data/talenttrees/fetch-talent-trees.php, data/spelldata/fetch-spell-icons.php
BLIZZARD_CLIENT_SECRET= # ditto
DB_CONNECTION=mysql
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis
```

Found missing from this list 2026-08-05 while answering a deployment question about spell icons — was already required by `fetch-talent-trees.php` before that, just never documented here. Confirm these are actually set in production's `.env` before relying on either of these two scripts running there.

## Security
- reCAPTCHA v3 on login and registration
- Keys in .env as RECAPTCHA_SITE_KEY and RECAPTCHA_SECRET_KEY
- Score threshold: 0.5 (adjust in RegisteredUserController and LoginRequest)
- Uses direct Google siteverify API call — no package dependency

## Windows / Herd Environment
- `php artisan` commands may be run directly via the PowerShell tool — PHP is on the Windows PATH and confirmed working there (verified 2026-08-02: `php artisan --version` succeeds via PowerShell).
- The Bash tool is git-bash and does NOT have PHP on its PATH (`php: command not found`) — never attempt `php`/`artisan` commands via the Bash tool; use the PowerShell tool for anything PHP/artisan-related, and Bash only for non-PHP shell work (file listing, git, etc.) if preferred.
- Care still applies as with any command execution: prefer read/idempotent commands freely, but treat destructive or state-changing artisan commands (migrate:fresh, db:seed on a real DB, queue:restart, etc.) with the same confirm-first judgment as any other risky action.

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

Phase 4 — SuggestionJob axis awareness (moderate risk, isolated) — **MOOT, 2026-07-09: `SuggestionJob`/`UserModuleService::nextModuleResponse()` were retired entirely, see the "'what's next' systems — down to one" note under Next Step + Reflection Loop. This phase describes extending code that no longer exists.**
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
New fields: `profile_title`, `archetype_key`, `confidence_level`, `summary`, `evidence[]` (signal + source + interpretation per item), `self_report_check` (alignment enum + comment), `likely_in_game_pattern`, `primary_strength` (name + concepts[]), `primary_growth_area` (name + concepts[]), `next_practice_goal`. `normaliseResponse()` also writes backward-compat aliases so profiles stored before the rework continue to render. (`recommended_module` was part of this new shape originally — removed 2026-07-10, see below.)

### Evidence-based philosophy
Survey answers are treated as self-reported context (weaker signal). Trait scores and concept/axis scores are stronger evidence. The prompt explicitly instructs the AI to surface contradictions between self-report and diagnostic behaviour — this is the most valuable output the system can produce. Every claim in `evidence[]` must trace back to a named input field.

### Guest → auth evidence transfer (fixed)
`RegisteredUserController::claimGuestQuizResults()` previously only wrote `UserTraitEvidence` for a guest's diagnostic session — `survey_mcq` answers logged in `$guestEvidenceLog` (tagged `'type' => 'survey'`) were silently dropped on signup, so `UserProfileEvidence` never got backfilled for guests. Fixed: survey-tagged evidence entries now also write to `UserProfileEvidence::updateOrCreate()` (same `(user_id, question_id)` key as the authenticated path in `DiagnosticQuizRunner`), resolving `category_id`/`subject_id` from the module. Each module's claim (diagnostic and regular-quiz branches) now runs inside `DB::transaction()`, and `session()->forget("guest_quiz_results.{$moduleId}")` only fires after that module's transaction commits — a failed claim no longer wipes the guest's session data, so it survives for a retry instead of being lost. Tests: `tests/Feature/Auth/GuestDiagnosticClaimTest.php`.

### Concept grounding for strength/growth-area concepts (fixed)
`primary_strength.concepts` and `primary_growth_area.concepts` used to be pure LLM invention — free-text labels (e.g. "Direct Pressure", "Converting Advantages") never checked against the subject's real `concepts` table, even though the dashboard renders them as chips that look like real ontology entities. Fixed in `DiagnosticProfileService`:
- `generateProfile()` builds `Concept::where('subject_id', $subject->id)->pluck('id', 'name')` and passes the concept names into `buildPrompt()`.
- `buildPrompt()` gained an optional `?string $validConceptNames` param and a new "VALID CONCEPTS FOR THIS SUBJECT" prompt block + a rule (renumbered to Rule 10 after the `recommended_module` rule was deleted 2026-07-10 — see below) instructing the AI to use only exact names from that list (or return fewer/none — never invent).
- New private `groundConcepts(array $response, $conceptMap): array` runs on the **raw AI response, before `normaliseResponse()`** — filters both `concepts[]` arrays down to names that exist in the map, drops anything else, logs a warning per field when something's dropped, never touches the `.name` labels, never fails the whole profile (empty array is a valid outcome). Applied identically to both the guest (direct Gemini) and authenticated (`AiService`) paths.
- **Decision: stores validated names (flat strings), not `{name, id}` objects** — keeps the JSON shape unchanged so `resources/views/components/dashboard/profile-hero.blade.php` needed no changes. A future feature needing concept IDs can re-resolve them from validated names via the same one-line `pluck('id', 'name')` query.
- `primary_strength.name` / `primary_growth_area.name` remain free personalised text — only the `concepts[]` arrays are ontology-constrained.
- Does NOT touch `AiService.php` — `DiagnosticProfileService` loads/validates concepts entirely on its own.
- Tests: `tests/Feature/Services/DiagnosticProfileServiceTest.php` (all-valid / mixed-valid / all-invalid / missing-concepts-key / zero-concepts-subject / null-module / guest-path / warning-logged).

### `recommended_module` removed entirely (2026-07-10) — same precedent as the `SuggestionJob` retirement
This field used to be a one-shot AI free-text module pick made at diagnostic completion — never concept-grounded, and confirmed dead weight: `resources/views/livewire/diagnostic-quiz-runner.blade.php` (the only remaining reader anywhere in the codebase) computed `$recModule`/`$recTitle`/`$recReason` from it and never referenced those variables again. The dashboard card that used to render it (`recommended-next-step.blade.php`) had already been deleted when the two-uncoordinated-recommendations bug was fixed (see "Profile-First Dashboard" below) — so every diagnostic completion was paying real AI credits for a value with no UI surface at all. `NextStepService::findBestModuleForConcepts()` already covers the same need correctly and continuously (grounded, concept-coverage-ranked, re-evaluated on every regeneration), making this field a strict, worse subset of functionality that system already provides. Removed rather than fixed, matching the `SuggestionJob` precedent (superseded by a better system, confirmed dead by tracing actual usage, not just "looks unused").

**What was removed, in `DiagnosticProfileService.php`:**
- The `buildAvailableModules()` private method (queried published/ready non-diagnostic modules for the subject) and its call site.
- The "AVAILABLE MODULES" prompt block and the rule instructing the AI to pick from it (remaining rules renumbered sequentially — the concept-grounding rule mentioned above is now Rule 10).
- The `"recommended_module": {...}` entry from the OUTPUT SHAPE JSON schema.
- The `'recommended_module' => ...` line in `normaliseResponse()`.
- The `next_module_suggestion` backward-compat alias **key was kept** (still set as a fallback default in `DiagnosticQuizRunner.php`'s AI-failure-fallback profile shape) but its derivation no longer depends on the now-gone `recommended_module` — it just reads `$response['next_module_suggestion'] ?? ''` directly.
- The two dead `$recTitle`/`$recReason`-computing lines (plus `$recModule`) in `diagnostic-quiz-runner.blade.php`.

**Verified no hidden breakage:** `groundConcepts()` never referenced `recommended_module`. `tests/Feature/Services/DiagnosticProfileServiceTest.php` has 8 fixture arrays with a `'recommended_module' => [...]` key, but only as unused mock-AI-response input data — no test assertion checks for it in the output, so those tests kept passing unmodified with no edits needed.

## Next Step + Reflection Loop ✓ COMPLETE

Adds a second, faster cadence on top of the diagnostic profile: where the diagnostic produces a profile roughly once per subject (slow cadence), this system gives the user one concrete practice task at a time and reinterprets it after they report back (fast cadence, re-triggered by reflection or by expiry). No changes to `QuizRunner.php` or `AiService.php`.

**Tables/models:** `user_profile_insights` (`UserProfileInsight`) — a durable, queryable snapshot of a diagnostic profile at generation time (subject-scoped, one row per diagnostic completion), separate from the `user_module.diagnostic_profile` JSON blob so next-steps can reference a stable `insight_id`. `user_profile_insight_concepts` — pivot marking which `Concept`s are the profile's growth-area concepts (`UserProfileInsight::growthAreaConcepts()`). `user_next_steps` (`UserNextStep`) — one row per generated task; carries `subject_id`, `insight_id`, `concept_id`, `previous_step_id` (chains regenerated steps), `step_type`, `status`, `generated_reason`. `user_next_step_reflections` (`UserNextStepReflection`) — the user's self-report on a task (`did_try`, `how_it_went`, `why_reasoning`). `user_reflection_evidence` (`UserReflectionEvidence`) — AI-interpreted evidence items derived from a reflection, each with a `confidence` score.

**Enums:** `StepType` (`task | module | knowledge_check` — `task` and `module` are implemented, `knowledge_check` is not), `NextStepStatus` (`pending | attempted | completed | superseded | expired`), `GeneratedReason` (`initial | reflection | expired | insight_change | module_completed`), `DidTry` (`yes | no | partially`).

**Flow:**
1. `DiagnosticQuizRunner::completeModule()` creates a `UserProfileInsight` scoped to `$moduleModel->subject_id`, then — inside the same DB transaction — calls `NextStepService::supersedePendingStepsForNewInsight()` to flip any still-`pending` `UserNextStep` rows for that **same user + same subject** to `superseded` (never a cross-subject or "most recent regardless of subject" query — this repeats the subject-scoping invariant documented above for `$completedDiagnostic`). Outside the transaction, `NextStepService::generateInitial()` makes the AI call (`gpt-4.1-mini` — switched from `gpt-4o-mini` 2026-07-09 for better instruction-following on the "don't repeat a failed task style" rule; already registered in `TokenService`'s pricing table so no credit-calculation risk, purpose `next_step_generation_initial`) and writes the first `UserNextStep` (`status = pending`, `generated_reason = initial`).
2. **Before generating a free-text task, every generation entry point first tries to route to real content.** `NextStepService::findBestModuleForConcepts($userId, $subjectId, $conceptIds)` looks for an already-published, `ready`, non-diagnostic, non-child (`parent_id IS NULL`) module in the same subject — not already completed by the user — whose questions' concepts (`module → questions → concepts`, joining `module_question`/`concept_question`) cover at least one of the insight's growth-area concepts, ranked in PHP by distinct-concept coverage count (same style as `QuizSelection.php`'s coverage counting, `orderBy('id')` first for a deterministic tie-break). On a match, `createModuleStep()` writes a `step_type = module` `UserNextStep` pointing at that real `Module` — **no AI call**, since the title/instructions are deterministic ("This module covers X — one of your current growth areas"). Only when no module matches does the existing free-text AI path run. This closes the loop described in the "three disconnected systems" gap below (item 2) — completing that module runs through the existing, untouched `MasteryService`, so the next generation call sees the improved mastery number.
3. Dashboard shows the active step via `next-experiment.blade.php`, fed by `DashboardController`'s `$activeNextStep` (subject-scoped the same way, `step_type IN (task, module)`, status in `pending|attempted`). Before rendering, `DashboardController` calls `NextStepService::checkAndCompleteModuleStep($activeNextStep)` — synchronous, on every dashboard load, **deliberately not a queued job** (explicit product decision, to avoid touching the protected `app/Jobs/` directory). It no-ops instantly for `task`-type steps or a `module`-type step whose module isn't complete yet; when the module *is* complete, it uses an **atomic conditional `UPDATE ... WHERE status IN (pending, attempted)`** (not read-then-write) to claim the transition — only the request that wins the update proceeds to call `regenerateAfterModuleCompletion()`, which tries another module match first (`generated_reason = module_completed`) and falls back to a free-text task otherwise. This guards against a double-regeneration (and double AI-cost) race from concurrent dashboard loads (multi-tab, prefetch). The Module branch of `next-experiment.blade.php` links to `route('modules.show', $module)`, not `modules.quiz` — a not-yet-enrolled user hitting `modules.quiz` is silently redirected to `modules.show` anyway (`ModuleQuizController::show()`), so linking there directly skips a pointless redirect hop.
4. User submits a reflection on a `task`-type step (`NextStepReflection` Livewire component, which already guards to `step_type = task` only — a `module`-type step's "reflection" is completing the real module, auto-detected per step 3, not self-reported) → creates `UserNextStepReflection`, flips the step to `attempted`, dispatches `InterpretReflectionJob`.
5. `InterpretReflectionJob` calls the AI (purpose `reflection_interpretation`) to turn the reflection into `UserReflectionEvidence` rows, marks the step `completed`, and calls `NextStepService::regenerateAfterReflection()` to generate the next step (`generated_reason = reflection`, chained via `previous_step_id`) — this also tries a module match first, same as step 2.
6. `next-steps:expire` console command flags any `pending`/`attempted` step older than 14 days as `expired` and calls `NextStepService::regenerateAfterExpiry()` so the dashboard always has a live task rather than going silent. Registered via `Schedule::command('next-steps:expire')->daily()` in `routes/console.php` — requires the standard Laravel scheduler (`schedule:run` cron entry) to be active on the server for this to actually fire.

**Verified invariants (do not regress):**
- **Subject-scoped superseding** — `supersedePendingStepsForNewInsight()` filters by `user_id` AND `subject_id`; every `UserNextStep::create()` call site (`generateInitial`, `regenerateAfterReflection`, `regenerateAfterExpiry`) propagates `subject_id` from the originating insight/step rather than re-deriving it. `subject_id` is a NOT-NULL FK on both `user_profile_insights` and `user_next_steps`, so it can't be silently omitted.
- **Confidence is code-clamped, not prompt-clamped** — `InterpretReflectionJob::MAX_CONFIDENCE = 0.4`; every evidence item is passed through `min((float)($item['confidence'] ?? 0), self::MAX_CONFIDENCE)` then `max($confidence, 0)` before being written. The "never higher than 0.4" prompt rule is a backup, not the actual guarantee — self-reported reflection evidence can never outweigh diagnostic-derived evidence in downstream scoring.
- **Expiry always regenerates** — `ExpireNextSteps` calls `regenerateAfterExpiry()` for every step it expires (wrapped per-step in try/catch so one AI failure doesn't block the rest of the batch); the dashboard never goes empty after a successful sweep.
- **`current-focus.blade.php` title is bound to the growth area, not the archetype** — `$growthAreaName` comes from `primary_growth_area.name` (fallback: legacy `growth_area` string), and the body (`$displayPattern`) prefers the new `growth_area_pattern` field over the archetype-level `likely_in_game_pattern`, only falling back to the latter for profiles generated before this field existed. `DiagnosticProfileService`'s prompt explicitly forbids the AI from reusing `likely_in_game_pattern` text for `growth_area_pattern` — it must describe how the specific growth area manifests, not restate general archetype flavor.
- **Module-step completion is claimed atomically, not read-then-write** — `checkAndCompleteModuleStep()`'s `UserNextStep::where('id', ...)->whereIn('status', [pending, attempted])->update(...)` only transitions a row still in one of those statuses; a `$claimed === 0` result means another concurrent request already handled it, so the caller bails without regenerating a second time. Do not "simplify" this back to a plain `$step->update(...)` after a separate existence check — that reintroduces the double-regeneration (and double AI-cost) race it exists to prevent.
- **Regeneration prompts include multi-step history, not just the immediately previous one** — `buildHistoryContext()` (fixed 2026-07-09) walks back the `previous_step_id` chain (default depth 4) collecting prior tasks' reflections and completed modules, formatted as a "RECENT HISTORY (oldest to newest)" block, fed into `regenerateAfterReflection()`, `regenerateAfterExpiry()`, and `regenerateAfterModuleCompletion()`'s prompts, with an explicit rule telling the AI not to repeat a task style that already failed. Before this fix, each generation call only ever saw the single most recent reflection in isolation, so a repeating failure pattern across two+ tasks (confirmed in production: two reflections in a row reporting "narrowing focus makes me miss everything else," met with three consecutive narrow-focus-observation tasks) was invisible to the AI — it kept proposing cosmetically different versions of an approach the user had already reported didn't work. Do not remove `buildHistoryContext()`'s call sites when touching these methods; that regresses to the isolated-reflection bug.
- **`NextStepReflection::checkCompletion()` polls for the successor's existence, not the old step's status** — fixed 2026-07-09 after a confirmed production race: `InterpretReflectionJob` marks the reflected-on step `Completed` *before* calling `regenerateAfterReflection()` (a separate AI call, can take a couple more seconds to actually create the replacement row). The original implementation polled `wire:poll.3s` checking only `$step->status === Completed` and redirected to the dashboard the instant that was true — landing in the multi-second gap before the replacement existed meant `$activeNextStep` resolved to nothing (or a stale legacy `next_practice_goal` fallback), which read as "the feedback boxes just disappeared." Now checks `UserNextStep::where('previous_step_id', $this->nextStepId)->exists()` instead, which is only ever true once regeneration has actually finished — race-free by construction. Do not revert to checking the old step's status alone.

**Concept grounding:** both `NextStepService::groundConceptName()` and `InterpretReflectionJob::groundConceptName()` validate any AI-returned concept name against `Concept::where('subject_id', ...)->where('name', ...)` before storing an ID — an unrecognised name is dropped to `null` and logged, never invented into the DB, following the same pattern as `DiagnosticProfileService::groundConcepts()`.

**Growth-area concept grounding hardened (2026-07-10)**, after a confirmed real case (~1 in 15 diagnostics) where a `UserProfileInsight` was created with zero `growth_area` concepts:
- **Root cause traced to a specific prompt mechanism, not just "two vocabularies sit near each other" in `DiagnosticProfileService::buildPrompt()`:** Rule 9 tells the AI to render trait signals in Title Case for `evidence[].signal` (e.g. "Low Kill Instinct", "Low Pressure Orientation" — both `player_traits` keys). The very next rule (10, governing `primary_strength`/`primary_growth_area.concepts`) didn't say those two fields are a different vocabulary — so the AI's own immediately-preceding output style bled into the next field, producing concept name candidates that were actually reworded trait keys. `groundConcepts()` correctly caught and dropped them (working as designed) — but that left the insight with no growth-area concepts at all. Fixed by rewriting Rule 10 to explicitly name TRAIT SCORES/AXIS MASTERY/VALID CONCEPTS as separate vocabularies with the actual offending examples as counter-examples, plus a recency-positioned reminder immediately after the VALID CONCEPTS block, right before OUTPUT SHAPE. `groundConcepts()` itself was not touched — it doesn't need to be, it already does its job correctly.
- **Visibility added:** `recordInsightAndGenerateInitialStep()` now logs `'NextStepService: insight created with zero growth-area concepts...'` (tagged `insight_id`/`user_id`/`subject_id`) whenever zero `role = growth_area` pivot rows get attached — distinct from `DiagnosticProfileService`'s per-field "Filtered invalid concept names" warning (which fires on the raw AI response, before grounding). The new one fires on the post-grounding outcome that actually matters downstream: zero growth-area concepts means `findBestModuleForConcepts()` returns `null` before ever querying modules, so every next-step generated from that insight falls back to a free-text task for the insight's entire lifetime, with no other visible signal it degraded. No retry/repair logic was added — visibility only, so the rate can be watched going forward.
- **Known duplication, not fixed (deliberately deferred):** grounding for growth-area concepts now happens in two independent places — `DiagnosticProfileService::groundConcepts()` (validates the raw AI response, logs per-field, documented contract: "empty array is a valid outcome") and, separately, `NextStepService::recordInsightAndGenerateInitialStep()`'s attach loop (re-checks `$conceptMap->has($name)` against the same subject's concepts a second time before attaching `user_profile_insight_concepts` rows). Currently harmless — the second check only ever sees input `DiagnosticProfileService` already grounded, since that's the only path that currently reaches it. But nothing enforces that as an invariant: if `recordInsightAndGenerateInitialStep()` ever gains a second caller that skips `DiagnosticProfileService` (plausible given the shape of a possible future "re-derive growth concepts from accumulated reflection evidence" feature), this redundant check silently becomes load-bearing, with its own behavior on an empty/invalid result that doesn't share `groundConcepts()`'s documented contract — no per-field warning, nothing distinguishing "AI gave nothing" from "AI gave names this second, independent check happened to reject." Flagged here as known debt, same category as the `recommended_module` field before it was traced and removed — not fixed now because it isn't causing anything today, only worth revisiting if/when a second call path actually appears.

**Known gaps:**
- `step_type = knowledge_check` exists in the enum but has no generation or rendering path.
- `GeneratedReason::InsightChange` exists in the enum but nothing currently sets it — a new insight supersedes old steps but the replacement step this creates is generated on-demand later with `generated_reason = initial`/`reflection`, not `insight_change`. Confirm intended usage before relying on it.
- `findBestModuleForConcepts()` only ever considers the insight's `growth_area` concepts, never `primary_strength` concepts — intentional (the loop is scoped to closing gaps, not reinforcing strengths), but worth knowing if strength-reinforcing suggestions are ever wanted.

### Known gap: "what's next" systems — down to one, 2026-07-09 (and the last dangling field removed 2026-07-10)
There used to be three separate places that told a user what to do next. As of 2026-07-09, `SuggestionJob` and its whole surrounding apparatus have been **retired**; as of 2026-07-10, `diagnostic_profile.recommended_module` has been **deleted entirely** (see "`recommended_module` removed entirely" under Diagnostic Profile Rework above) — `NextStepService` is now the only system:

1. ~~`diagnostic_profile.recommended_module`~~ — **removed 2026-07-10.** Was a one-shot AI free-text pick made once at diagnostic completion, never concept-grounded, and confirmed fully unrendered (nothing displayed it). Superseded by item 2 below, which already did the same job correctly.
2. **`UserNextStep`** (this Next Step + Reflection Loop) — the sole "what's next" system now. `NextStepService::findBestModuleForConcepts()` routes to a real, already-published module covering a growth-area concept when one exists, instead of always generating a free-text task (see the Flow section above).
3. ~~`SuggestionJob` → `UserModuleService::nextModuleResponse()`~~ — **retired.** Previously fired after *any* regular module quiz completion, single-module-blind, freeform AI text that might not correspond to a real module — see prior history in git for what this looked like. Removed entirely:
   - `app/Jobs/SuggestionJob.php` deleted; the dispatch call and its `'Generate Suggestions'` `PipelineStep` removed from `QuizRunner::completeModule()` (the `'Generate Card'` step + `GenerateCardJob` were untouched at the time — unrelated, still fired. Both were separately retired 2026-07-10 alongside the whole card system — see the "Card system retired" section below — after which `completeModule()` dispatches nothing async at all).
   - `UserModuleService::nextModuleResponse()`, `prepareModuleStatsForAI()`, `getHash()` deleted. **`buildModuleUserStats()` survives** — `QuizRunner::buildCompletionStats()` also depends on it for the completion screen's Strengths/Needs-Improvement lists, unrelated to the suggestion system.
   - `app/Http/Services/SuggestionsService.php` deleted (only callers were the above, all removed).
   - `app/Models/ModuleSuggestions.php` and the `module_suggestions` table **deliberately left in place**, now inert — dropping a data table is a separate, more destructive decision than removing dead application code.
   - Also removed: the `/modules/next-module` browse page (`ModuleController::nextModule()`/`createSuggested()`, the `modules.next-module`/`modules.create-suggested` routes, `resources/views/modules/next-module.blade.php` + `pending.blade.php`), the Collection page's suggestion card (`Collection::getLatestSuggestionProperty()` + its blade block), the "Next" button on both module-browse views, and dead/already-broken `QuizRunner.php` state (`$suggestions`, `$suggestionsStatus`, `checkSuggestions()`, and `getNextSuggestionProperty()` — the last one queried a `Module.user_id` column that never existed, a pre-existing bug unrelated to this cleanup). All of these matched modules by fragile name-string comparison and/or could silently `Module::create()` a brand-new module from AI text — none of that risk exists anymore.
   - **Known dangling reference, deliberately not fixed:** `app/Livewire/TimedQuiz.php` has its own hand-duplicated `SuggestionJob::dispatch(...)` call and is confirmed dead/unreachable code (no live route mounts it — `routes/web.php`'s route to it is commented out). Left untouched since it's outside this cleanup's verified scope; flagged here so it isn't mistaken for an oversight.

## Profile-First Dashboard (V1) ✓ COMPLETE

The authenticated dashboard (`DashboardController` → `resources/views/dashboard.blade.php`) is profile-first for any user with a completed diagnostic, rather than module-first. Uses only data already generated at diagnostic completion — no schema changes, no AI calls on dashboard load.

`DashboardController::index()` additionally loads:
- `$completedDiagnostic` — the user's most recently completed diagnostic module **for the currently selected subject only**: `$user->modules()->where('modules.type', 'diagnostic')->where('subject_id', $currentSubjectId ?? 0)->wherePivot('status', 'completed')->orderByPivot('completed_at', 'desc')->first()`. Must stay subject-scoped — see the "subject-scoped queries" invariant under Critical Rules.
- `$diagnosticProfile` — that module's `pivot->diagnostic_profile`, `json_decode`d
- `$subjectDiagnosticModule` — when the selected subject has no completed diagnostic, the published/ready diagnostic module for that subject (if one exists), used to render a subject-specific "Complete the {Subject} diagnostic" CTA in place of the profile-first sections instead of showing nothing or another subject's profile

New Blade components under `resources/views/components/dashboard/`, rendered in this order at the top of `dashboard.blade.php` (only when `$diagnosticProfile` exists):
- `profile-hero.blade.php` — `profile_title` / `confidence_level` / `summary` / `primary_strength` / `primary_growth_area`
- `current-focus.blade.php` — `primary_growth_area.name` + `likely_in_game_pattern`, framed as a hypothesis ("Mindcollector currently thinks this is worth investigating"), not a verdict
- `next-experiment.blade.php` — the single "what's next" card, backed by `$activeNextStep` (see Next Step + Reflection Loop above), falling back to `next_practice_goal` (static profile text) only when no `UserNextStep` exists at all. **Consolidated 2026-07-09**: this card's label/icon now switch on `$activeNextStep->step_type` — "Your Next Experiment" / `icon-lightning-circle` for a `task` (self-reported, exploratory), "Recommended Next Step" / `icon-compass` for a `module` (a bigger, structured commitment) — so a module recommendation only ever appears once on the dashboard. This **replaced** the old `recommended-next-step.blade.php` card, which independently rendered the ungrounded `diagnostic_profile.recommended_module`/`next_module_suggestion` fields right below this one — the two could show two different, uncoordinated module recommendations simultaneously (confirmed by the user seeing "Arena Positioning" next to "Arena Fundamentals"). That component is deleted; `recommended_module`/`next_module_suggestion` are still generated and stored on the profile (see the "recommended_module is not concept-grounded" gap above, still open) but are **no longer rendered anywhere** — only `NextStepService`'s grounded `StepType::Module` steps are shown.
- `evidence-panel.blade.php` — collapsible (`x-collapse`, matches the pattern already used in `diagnostic-quiz-runner.blade.php`); renders `evidence[]` (signal/interpretation/score, self-reported items filtered out) plus `self_report_check`. Never renders the raw `source` field (internal path like `trait_scores.reactivity`) in production UI.

All components read both the new normalised fields and the legacy backward-compat aliases (`player_type`, `narrative`, `top_traits`, `growth_area`, `next_module_suggestion`) so old stored profiles still render. `top_traits` (flat array of trait keys) is used as a synthetic `primary_strength` (`name = 'Key Traits'`) when the new-shape field is absent. The no-completed-diagnostic path (`$diagnosticNudge`, category-scoped — separate from the newer subject-scoped `$subjectDiagnosticModule` CTA above) is untouched.

**Not yet implemented** (intentionally deferred): no stored "current focus" object, no progress/completion tracking beyond `UserNextStep.status`. The next-step selector gap this note used to describe (a single ungrounded `recommended_module` passthrough) is closed — see `findBestModuleForConcepts()` under Next Step + Reflection Loop above. Still open: using performance on a *completed* module (score, struggle patterns — already computed by `UserModuleService::buildModuleUserStats()`, currently only consumed by the still-disconnected `SuggestionJob`) to decide whether the next recommendation should escalate to a more advanced/specific module (via `Module::proficiencies()`/`Proficiency.index`) or reinforce with something at the same tier — proposed direction, not built.

### Lower dashboard reframed around "Knowledge Profile" (renamed, not new)
The pre-existing lower half of `dashboard.blade.php` (predating the profile-first work) has been renamed and reframed to stop contradicting the profile-first sections above it — no new backend systems, no schema changes, same underlying data:
- **"Overall Mastery" → "Knowledge Profile"**. Dropped the `{{ $overallMastery }}/100` score entirely — now shows `$assessedConceptCount of $totalConceptCount concepts assessed` (`$concepts->filter(fn($c) => $c->userConceptMasteries->isNotEmpty())->count()`). A concept only counts as "assessed" if a real `UserConceptMastery` row exists for it — absence of evidence is visually distinct from a 0% score (per-concept rows show "Not yet assessed" instead of "0%"; the radar chart withholds its filled polygon entirely — showing only the neutral hex grid — when `$assessedConceptCount === 0`, rather than rendering a polygon that reads as "scored zero everywhere").
- **"Topic Mastery" → "Concept Knowledge"**; radar caption → **"Concept Knowledge Map"**.
- **"My Guides" → "Learning"** (contents/functionality unchanged — renamed so it can later host non-module activity types, e.g. a future `KnowledgeCheck`, without another rename).
- **Leaderboard demoted** — moved to the 3rd/last grid column, `opacity-80` + muted heading, so Concept Knowledge and Learning read as visually dominant over it.
- Page title is now `{{ $currentSubjectName }} Profile` (was the static "Knowledge Atlas"); subtitle is "Your current profile, focus, and learning evidence." (replaced "Welcome back, {name}").
- **`$heroModule` and its "Continue Learning"/"Explore the Map" CTA removed entirely, 2026-07-09.** This was the last remaining pre-diagnostic-era "here's *a* module, go do something" pattern on the dashboard — both its branches (a hero card for an in-progress module, or an "Explore the Map" fallback linking to `modules.index`) duplicated what `next-experiment.blade.php` already does properly at the top of the page (a specific, grounded next action from `NextStepService`). Removed from both `DashboardController::index()` (the two-query hero-module lookup) and the Knowledge Profile card. **Known gap this creates:** the app does not actually enforce "complete the diagnostic before anything else" anywhere — there's no route-level gating, a user can still navigate directly to `/modules` and enroll in a regular module without ever taking a diagnostic. Removing this CTA was a deliberate product decision on the assumption that gap doesn't matter / will be closed separately, not evidence that it's already closed. If a user reaches the dashboard with no diagnostic profile, no available subject diagnostic, and no active next step, there is currently no CTA anywhere on the page — only direct navigation (e.g. the nav sidebar) reaches `modules.index`.

**Known residual inconsistency**: the "Alchemist" badge (top-right of the header) is still driven by the old `$overallMastery` percentage banding (I–V levels), which no longer has a visible home elsewhere on the page now that the 0–100 score is gone from Knowledge Profile. Left as-is since it wasn't in scope for this pass — a future decision is needed on whether to keep it as an independent gamification badge or retire/rework it.

## Card system retired; question flagging + Progress page redesign ✓ COMPLETE (2026-07-10)

The collectible-card mechanic (mint numbers, "First Edition"/"Limited"/"Common" editions) was a derivation of Pokémon-card collecting bolted onto AI-generated modules from an earlier product direction — confirmed by the user to have "nothing to do with adaptive learning," removed entirely. Prompted by the same session in which the user flagged wanting to bookmark specific quiz questions that "stood out" for later reference — both changes land on the same page (`app/Livewire/Collection.php` / `resources/views/livewire/collection.blade.php`, "Progress"), so they were built together.

**Removed:** `app/Models/Card.php`, `app/Jobs/GenerateCardJob.php`, `app/Http/Services/CardGenerationService.php`, `resources/views/components/card.blade.php`, the `cards` table (migration `drop_cards_table`, 4 dev rows lost — user explicitly approved), `resources/views/collection/pending.blade.php` + `empty.blade.php`, `resources/views/livewire/collection-pending.blade.php`, `app/Livewire/CollectionPending.php`, and the already-orphaned `resources/views/dashboard/progress.blade.php` (dead view, no route, still rendered `<x-card>`). `User::cards()`/`Module::cards()` relations removed.

**`QuizRunner::completeModule()`** — the `Pipeline`/`PipelineStep::create(['name' => 'Generate Card', ...])` + `GenerateCardJob::dispatch(...)->afterCommit()` + `session(['completion_pipeline_id' => ...])` block is gone entirely, not replaced — nothing async is left to track for quiz completion (the `'Generate Suggestions'` step was already removed in the `SuggestionJob` retirement earlier this session, so `'Generate Card'` was the pipeline's only remaining step). `completeModule()` now only rewards credits on first completion. **`CollectionController::index()`** simplified to `return view('collection.index');` — no more gating on a pipeline step's completion status; `Collection`'s own `@empty` states already handle "nothing yet" gracefully, so the page-level pending/empty branch was redundant.

**Bug caught during removal, not obviously card-related:** `app/Http/Controllers/AiController.php` constructor-injected `CardGenerationService` but never called it (dead injection, same pattern as other services found earlier this session) — this controller is live at `/credits`, `/creditsTest2`, `/ai_requests`, so deleting `CardGenerationService` without fixing the constructor would have 500'd all three routes. Fixed as part of this pass; the regression test (`GET /ai_requests` returns 200) exists specifically because this class of bug is easy to reintroduce silently.

**Deliberately left untouched:** `app/Livewire/TimedQuiz.php` and its `.backup` — confirmed dead/unreachable (same status as when `SuggestionJob` was retired), still references `Card`/`GenerateCardJob`/`quiz_completion`. Same precedent, not fixed.

### New: question flagging
A user can flag a question as personally important while taking a quiz (star icon in the question card, `resources/views/livewire/quiz-runner.blade.php`, positioned `absolute` before the `<form>` so it never touches `wire:model="answer"` or the submit handler — hidden entirely in guest mode, since guests have no account to persist a flag against). New pivot table `question_user_flags` (`user_id`, `question_id`, timestamps, unique pair) — no dedicated pivot model, matching `Question::concepts()`/`contents()`'s existing bare-`belongsToMany` convention. New relations: `Question::flaggedByUsers()`, `User::flaggedQuestions()`. `QuizRunner::toggleFlag($questionId)` calls `auth()->user()->flaggedQuestions()->toggle($questionId)`; `getFlaggedQuestionIdsProperty()` exposes current-question flagged state to the blade.

**Progress page redesign:** the whole page used to navigate via card selection (`$selectedCardId` drove which module's data displayed). Replaced with direct module selection — the existing "Active Modules" tray rows are now clickable (`selectModule($moduleId)`), and `getAnsweredQuestionsProperty()`/`getSelectedModuleProperty()` key off `$selectedModuleId` directly. New **"Your Library"** section is deliberately subject-scoped, not module-scoped — always visible regardless of which module is selected in the tray, following the same architectural invariant `getEnrolledModulesProperty()` already uses on this page (filter by `$currentSubjectId`, never show cross-subject data). `Question` has no direct `subject_id`, so scoping goes through `whereHas('modules', fn($q) => $q->where('modules.subject_id', $this->currentSubjectId))`.

**"Explain" reuses existing infrastructure, not new AI plumbing:** `Collection::explainQuestion($questionId)` properly implements what was previously a dead stub (`regenerateExplanation()`, `dd("currently not implemented")`, unwired to any button) by calling `ReviewQuestionService::getReviewContent($question, $module, auth()->id())` — the same cache-or-generate-and-persist path `Question::contents()` already used elsewhere. One correctness detail: a flagged question can belong to modules in more than one subject, and the explanation prompt includes the module's name/subject context — `explainQuestion()` resolves to the module matching the *currently selected* subject (`$question->modules->firstWhere('subject_id', $this->currentSubjectId)`), not an arbitrary first match, so the explanation shown never references a subject the user isn't currently looking at.

**Known gap, explicitly deferred:** the user separately floated an idea where AI periodically synthesizes the whole flagged-question library back into the profile (a fourth evidence source alongside diagnostic/reflection evidence, but explicit user-curated signal rather than inferred or self-reported). Deliberately not built in this pass — ship flagging, see if it gets used, design synthesis properly later. `Collection::$flaggedExplanations` is in-memory only (not persisted) — repeated visits to the Progress page re-fetch from the cached `Content` row via `ReviewQuestionService`, not from component state.

## Guest Roadmap + Persisted Learning Path (Signup Funnel) ✓ COMPLETE (2026-07-19)

**Problem:** guests completing the diagnostic were asked to sign up immediately after seeing their profile, with nothing communicating what the product would actually *do* for them if they did — reported as a real bounce cause ("saw the form, didn't want to enter my details"). This adds a personalised, click-gated roadmap between the profile and the sign-up CTA, plus a persisted authenticated counterpart on the Progress page.

### Guest flow
**Flow change:** diagnostic quiz → profile → **[View your learning path]** click → roadmap reveals (milestones + bridge text) → sign-up CTA at the bottom. The click is deliberately required before the roadmap builds — this both frames the plan as something earned/revealed rather than upfront, and gates the (cheap, DB-only, no-AI) computation behind actual interest, which funnel events can then measure.

- **`RoadmapService`** (`app/Http/Services/RoadmapService.php`) — `buildGuestRoadmap(array $profile, array $surveyAnswers, Module $module): array`. Deliberately AI-free: milestone copy is static, subject-scoped config (`config/roadmap.php`, keyed by `Subject.name` — Subject has no slug column — with a `default` fallback for subjects without bespoke copy). The one dynamic slot is which real module comes right after the diagnostic, resolved by `findFirstModule()` — the same deterministic, coverage-ranked, no-AI concept-to-module matching `NextStepService::findBestModuleForConcepts()` already uses. Bridge text uses `survey_answers.current_rating.text` / `.primary_goal.text` **verbatim** — both are `survey_mcq` option **labels**, not numbers (`current_rating` is a bucketed range like "1800–2099"; `primary_goal` is a categorical intent like "Push Gladiator or higher", consistent across all four diagnostic seeders — WoW/LoL/SC2/Poker all use the same two `question_key`s). There is no numeric target anywhere in this data for any subject — don't try to render "1800 → 2100"-style arithmetic from it.
- **`GuestRoadmap`** Livewire component (`app/Livewire/GuestRoadmap.php`) — nested inside `diagnostic-quiz-runner.blade.php`'s existing `@if ($guestMode)` branch (a view-only change; `DiagnosticQuizRunner.php` itself is untouched by the guest path). Reads `session('guest_quiz_results.{moduleId}')` directly (`survey_answers` is a flat, already-parsed array there — not `$guestEvidenceLog`, which is a different, evidence-shaped structure used only for post-signup `UserProfileEvidence` writes). Collapsed → button; `reveal()` builds the roadmap and logs `roadmap_clicked`. Hidden entirely for auth users or when guest session data is missing/expired. The relocated sign-up CTA (former always-visible "Unlock my path — free" card) now lives inside this component's *revealed* state only — two sibling Livewire components can't toggle each other's visibility, so the CTA had to move into the same component as the reveal, not stay a separate block.
- **`<x-milestone-path>`** (`resources/views/components/milestone-path.blade.php`) — new standalone stepper component (`['title', 'detail', 'status' => 'complete'|'next'|'future']` per item). Deliberately **not** extracted from the Progress page's "Your Path" timeline despite visual similarity — that timeline is chronological event-history with no status concept; forcing one shared component over two differently-shaped call sites was judged the wrong trade.

### Funnel events
`funnel_events` table + `FunnelEvent` model (`FunnelEvent::log($event, $guestSessionId, $moduleId, $userId)` — wrapped in try/catch, swallows its own failures, same "never break the flow it's observing" contract as `NextStepService`). No `updated_at` (events are immutable). Events logged: `profile_viewed` (guarded by a session flag so a refresh doesn't re-log), `roadmap_clicked`, `signup_started` (`RegisteredUserController::create()`), `signup_completed` (inside `claimGuestQuizResults()`, after the per-module transaction commits, joined via `session()->getId()`). That join is reliable because `RegisteredUserController::store()` has no `session()->regenerate()` call anywhere in it — unlike `AuthenticatedSessionController`'s login flow, which does — so a real guest's session id survives from diagnostic completion through to registration.

### Persisted, authenticated version (Progress page)
- **`user_learning_path_stages` table + `UserLearningPathStage` model** — one row per milestone, per (user, subject). Deliberately has **no status column**: whether a stage is complete/next/future is always computed live at render time (`Collection::getLearningPathProperty()`), never stored — same "don't create a second source of truth" discipline `NextStepService::checkAndCompleteModuleStep()` already follows.
- **`RoadmapService::persistStagesForUser(int $userId, Module $module, array $profile, array $surveyAnswers, ?int $insightId)`** — replaces (not appends) a user's stages per subject, so a retake shows the new plan rather than accumulating old ones. Called from the same two places `NextStepService::recordInsightAndGenerateInitialStep()` already is, for the same reason (guest and auth users get identical behavior): `DiagnosticQuizRunner::recordProfileInsight()` and `RegisteredUserController::claimGuestQuizResults()`. Never throws.
- **`roadmap:backfill`** console command (`app/Console/Commands/BackfillLearningPaths.php`) — one-time backfill for users who completed a diagnostic before this feature existed (persistence only ever fires from the two live hooks above, never retroactively). Sources from `module_user.diagnostic_profile` directly rather than `UserProfileInsight` — that table is a later addition (2026-07-08) and some older completions may predate it entirely. Safe to re-run (same replace-not-append behavior).
- **Progress page** (`resources/views/livewire/collection.blade.php`) — "Learning Path" renders in a `grid md:grid-cols-2` parallel to "Your Path": forward-looking plan on one side, backward-looking history on the other, both subject-scoped the same way the rest of that page is.

**Critical fix, same session — do not regress:** the "Module" stage does **not** trust the `module_id` frozen onto it at persist time. `Collection::getLearningPathProperty()` always overrides that stage's title/detail with the user's live `UserNextStep` for the subject (identical query + `checkAndCompleteModuleStep()` sync-check to `DashboardController`'s `$activeNextStep`). The frozen guess (`RoadmapService::findFirstModule()`'s deterministic, growth-area-concept-coverage pick) and the live recommendation (`RecommendationService::generateRecommendation()`'s AI-chosen pick from a *broader* candidate pool) are computed by genuinely different algorithms and **will** disagree — confirmed in production during this same session (roadmap showed "Basic PvP Tips", dashboard's Next Experiment card showed "Arena Positioning", for the same user). Freezing the roadmap's guess forever was a structural repeat of the exact "two disconnected what's-next opinions shown at once" bug this codebase already retired twice (`SuggestionJob`, `recommended_module` — see the "'what's next' systems — down to one" note earlier in this file). The fix makes the roadmap a **view** over `NextStepService`'s single decision, not a second one: `NextStepService`/`RecommendationService` remains the only system that decides what real content is next; everything else (dashboard card, roadmap "Module" stage) must read from it, never compute its own independent guess once a live `UserNextStep` exists. The frozen `module_id` on the stage is now only a fallback for the rare case no `UserNextStep` exists yet — and it's still the best-available approximation for the guest preview specifically, which has no `UserNextStep` at all pre-signup.

Stages after "Module" (the static config copy — "Personalised Win Conditions", "In-Game Practice Drills", "Comp & Matchup Preparation", etc.) always render `status = 'future'` and never light up as next/complete, regardless of what the tracked module/task does. This is intentional, not a bug: there is currently no real module, task, or generation pipeline behind any of them. (The one exception, "Class/Race/Role Breakdown," stopped being one of these placeholder stages on 2026-07-19 — see "Subject Context Dimensions" below, which made it functional.)

### Funnel reporting (`Admin\DiagnosticStats`, 2026-07-19)
Raw `funnel_events` logging is not visible anywhere on its own — `Admin\DiagnosticStats::getFunnelProperty()` (`/admin/diagnostic-stats`, existing page, extended rather than adding a new one) surfaces it: profile-viewed → roadmap-clicked → signup-started → signed-up counts, click-through rate, and — the number this whole feature exists to answer — signup rate for guests who clicked the roadmap vs. those who didn't (joined via `guest_session_id`). `signup_completed` is logged once per claimed diagnostic module (`claimGuestQuizResults()` loops per module), so signup totals here are deduplicated by distinct `user_id`, not raw row count, to avoid a guest with multiple pending diagnostics inflating the number.

**Known gap — next planned work, partially closed 2026-07-19:** the roadmap beyond the diagnostic + first module was pure aspirational preview copy with nothing tracking it. The breakdown stage (`context_dimensions`) is no longer one of these — see "Subject Context Dimensions" below. Still open: "Win Conditions," "Practice Drills," "Comp & Matchup Preparation," and "Reassessment" have no real content or tracking behind them. Candidates for closing this remainder: generating a `Module` per stage via the existing question-generation pipeline (`GenerateQuestions`/`GenerateModuleContentJob`), extending `RecommendationService`/`NextStepService` to progress through a themed sequence of concepts rather than always picking the single globally-best one, or hand-authoring modules per subject that map onto the remaining static stage keys in `config/roadmap.php`. Not designed yet.

**Known separate gap, not caused by this work but surfaced while testing it:** 8 pre-existing Auth tests (`AuthenticationTest`, `EmailVerificationTest`, `PasswordResetTest`, `RegistrationTest`) fail under `composer test` — the recaptcha-affected ones (`AuthenticationTest`'s login test, `RegistrationTest`) because the stock Breeze-generated tests never send `g-recaptcha-response`, and `phpunit.xml` sets `APP_ENV=testing` (not `local`), so the `app()->environment('local')` skip in both `RegisteredUserController::store()` and `LoginRequest` never triggers during the test suite. Not a production issue — real users get a real token from the recaptcha widget. `EmailVerificationTest`/`PasswordResetTest`'s failures are unrelated to recaptcha and not yet root-caused. Not fixed in this pass (out of scope, offered and declined) — flagged so it isn't mistaken for a regression from this feature.

## Player Model Design Principle

MindCollector models **the player**, never the game. All player data is one of three kinds:

- **Behaviour** — inferred from diagnostic and learning interactions (traits, archetype)
- **Context** — declared by the player (class, race, role, rating, goals). Declared ≠ stable: class rarely changes, rating/goals go stale and will need refresh mechanics later.
- **Evidence** — observed performance (answers, completed modules, reflections, future telemetry)

Context **filters and flavours content; it never partitions mastery**. Concepts stay universal (one "Cooldown Management," not a Mage version and a Rogue version); context changes how a concept is taught, never what it means. Deliberate trade-off: mastery earned through Rogue-flavoured modules persists if the player rerolls — accept this; do not "fix" it by splitting concepts or mastery per class.

Sanity check for any future field or feature touching the player model: is it behaviour, context, or evidence? If none, it probably doesn't belong.

## Subject Context Dimensions ✓ COMPLETE (2026-07-19)

Users want class/race/spec-specific training ("tell me what to do as Zerg / as a Rogue"). The core infrastructure (Concept, Proficiency, Difficulty) has no notion of class — modeled as a generic, per-subject structure rather than hardcoding "class" (WoW-specific) or using freeform tags (no referential integrity, AI could invent labels). See the Player Model Design Principle above — this whole feature is "context."

**Core invariant:** the closed option list (`subject_context_options`) is the single vocabulary shared by user declarations (`user_subject_context`) and module tagging (`module_context_option`) — because both reference the same FK-constrained table, neither the AI nor an admin can invent a label outside the ontology. This *replaces* any use of the freeform Tags system for class/race specificity; Tags stays untouched for editorial labels only (confirmed nothing else routes on Tags — `AiService::tagConcepts()` is dead legacy code, `TagJob`/`TagSeeder` are editorial-only).

### Schema
- **`subject_context_dimensions`** (`SubjectContextDimension`) — the *shape* of specificity per subject: `subject_id`, `name` (e.g. "Race", "Class", "Spec"), `slug`, `order`, `required` (bool), `parent_dimension_id` (nullable self-FK — Spec's parent is Class). Unique `(subject_id, slug)`.
- **`subject_context_options`** (`SubjectContextOption`) — closed list per dimension: `dimension_id`, `name` (e.g. "Zerg", "Rogue", "Assassination"), `slug`, `parent_option_id` (nullable self-FK — Assassination's parent is Rogue), `order`. Unique `(dimension_id, slug)`. `depth()` walks the `parent_option_id` chain on the fly (0 = root, 1 = child) rather than a stored column — hierarchy is shallow (2 levels) and options rarely change.
- **`user_subject_context`** (`UserSubjectContext`) — one declared option per (user, dimension). **Unique `(user_id, dimension_id)` at the DB level** — it is structurally impossible for a user to be both Rogue and Mage, not just application-guarded.
- **`module_context_option`** (pivot, no dedicated model — same bare-`belongsToMany` convention as `Module::tags()`) — `module_id` + `subject_context_option_id`. A module with zero rows here is context-free (universal), no separate flag needed.

New relations: `Subject::contextDimensions()`, `User::subjectContext()`, `Module::contextOptions()`, `SubjectContextOption::modules()`.

### Seeder (`SubjectContextSeeder`, idempotent — `firstOrCreate`, matches `ConceptSeeder`'s convention)
- **SC2**: "Race" → Terran, Zerg, Protoss.
- **WoW**: "Class" (13 classes) → "Spec" (parent: Class), specs seeded for Rogue/Druid/Warrior only — enough to prove the hierarchy, not exhaustive.
- **LoL**: "Role" → Top, Jungle, Mid, ADC, Support. Champion dimension deliberately deferred (160+ options).
- **Poker**: zero dimensions — deliberate, proves that path works cleanly, not an oversight.

### Declaration service (`SubjectContextService`) — the only writer to `user_subject_context`
- `declare(userId, dimensionId, optionId)` — validates the option actually belongs to the dimension (the DB unique constraint alone can't catch a mismatched pair), `updateOrCreate`s the declaration, then **cascades**: clears every *descendant* dimension's declaration (walks the full `parent_dimension_id` chain, not just one level) — changing Class clears Spec, since a stale Spec from a different Class would be meaningless at best.
- `hasDeclaredAllRequiredDimensions(userId, subjectId)` — used by the Learning Path milestone (below). Returns `false` for a zero-dimension subject (that subject never shows the milestone at all, so this is never actually asked the question there).
- `declaredOptionIds(userId, subjectId)` / `declarationsForSubject(userId, subjectId)` — read helpers used by both the declaration form and the routing preference.
- `isContextEligible(module, declaredOptionIds)` / `contextSpecificity(module, declaredOptionIds)` — the shared routing rule, see below. Take a pre-fetched `$declaredOptionIds` rather than re-querying per call, since they run inside sort comparators.

### Declaration UI (`SubjectContextForm` Livewire component)
Renders one `<select>` per dimension in `order`; a child dimension's (Spec) options are filtered to the currently-selected parent option and empty (effectively disabled) until one is chosen — `updatedSelections()` also clears any in-memory child selection when its parent changes, mirroring the service's cascade rule client-side before Save is even clicked. Renders nothing for a zero-dimension subject. Always visible on the Progress page (not gated behind a click) — a declaration can go stale (a reroll, a role swap) unlike a one-time diagnostic, so it needs to stay editable, not just "declare once."

### Learning Path integration
The former per-subject hardcoded `class_breakdown`/`role_breakdown`/`race_breakdown`/`style_breakdown` stage keys in `config/roadmap.php` are now one canonical `context_dimensions` key everywhere (the old per-subject copy in that file is now only a defensive fallback, effectively unused in normal operation). `RoadmapService::resolveStages()`'s `buildContextDimensionsStage()` builds the real title/detail from the subject's actual `SubjectContextDimension` rows: a single dimension reads **"{Name} Breakdown"** (e.g. "Race Breakdown"), two or more join with **" & "** (e.g. "Class & Spec") — and **omits the stage entirely** when the subject has zero dimensions (Poker). Detail copy deliberately describes *declaration*, not inference ("Tell us which race you play so every future recommendation is personalised to it.") — built from the dimension names, never hardcoded per subject, and never implying the system discovered this on its own.

`Collection::getLearningPathProperty()` computes this stage's live status independently of "Module" (a user can declare their class before ever touching a module): `complete` when `hasDeclaredAllRequiredDimensions()` is true, otherwise it competes for the single "next" badge the same left-to-right pass every other dynamic stage does. Only `first_module` and `context_dimensions` ever carry real status — every other stage (the still-unimplemented "Win Conditions" etc.) unconditionally stays `future`, since there's nothing behind them a user could act on yet; a generalized "any incomplete stage can become next" rule was tried and reverted for this reason (see the code comment in `Collection.php` if touching this again).

### Routing preference — the actual point of the feature
Applied identically in `NextStepService::findBestModuleForConcepts()` and `RecommendationService::findModuleForConcept()` (the two places module recommendations get resolved), via `SubjectContextService`:
1. **Exclude** any candidate module tagged with an option the user did **not** declare. A module with zero context tags is always eligible (context-free/universal) for every user, declared or not. An undeclared user can therefore only ever receive context-free modules — an empty declared set can't intersect a non-empty tag set.
2. **Order by specificity, most specific first**: `contextSpecificity()` = depth of the deepest *declared* option a module is tagged with (Assassination-tagged = 1 beats Rogue-tagged = 0 beats context-free = −1). A module tagged with several options uses its deepest match.
3. Existing exclusion rules (completed modules, wrong subject, unpublished, diagnostic type, child/`parent_id` modules) still apply underneath this — untouched.
4. Fully deterministic — no AI involvement in the filtering/ordering itself (`RecommendationService`'s AI call still only picks *which concept*, same as before; this governs which *module* that concept resolves to).

`RecommendationService::candidateConcepts()` (the broader "does any module exist for this concept at all" check that feeds the AI's concept choice) is deliberately left context-blind — enforcement happens only at the final module-resolution step. This means the AI can occasionally pick a concept that, after context filtering, resolves to no eligible module (a minor wasted call, handled gracefully via the existing `?? null` fallback) — accepted rather than adding a second, more complex context-aware filter to the concept-candidate query for a shallow, low-content-volume ontology.

### Locked files
`QuizRunner.php` and `AiService.php` — zero changes, as required. Generation-time context (passing declared class into AI module-generation prompts, auto-tagging AI-generated modules with context options) is explicitly out of scope for this phase; it would touch `AiService.php` and needs its own explicit approval first.

### Out of scope (not built)
Champion dimension for LoL; any migration of the existing Tags system; admin UI for managing dimensions/options (seeder only); re-profiling or diagnostic changes (a declaration is a fact, not evidence — it never feeds the diagnostic AI).

## Canonical Context Module Template (pilot: Arms Warrior, 2026-07-21)

Separate from AI-generated learning modules, this is a **human/Haiku-authored, offline reference** module type — one per class/spec/race/role context option (e.g. "Arms Warrior," "Zerg," "Jungle") — intended to become the canonical educational representation of that context and to ground future content authoring (diagnostic variants, recall questions, explanations). It is explicitly **not** queried at runtime by the diagnostic or recommendation AI, and this boundary is enforced structurally, not just by convention: `NextStepService::findBestModuleForConcepts()` / `RecommendationService`'s module matching both require `whereHas('questions.concepts', ...)` — a module with zero `Question` rows is automatically unselectable, so a context module stays inert to the runtime system for as long as it has no recall questions attached. No new `Module.type` value is needed (`type` stays `content`, the default) — keep `published = false` until recall questions exist, since `Modules\Index` browsing does not filter on question count and would otherwise let a user enroll into an empty quiz.

**Page structure — 6 pages, not 8.** Piloted at 8 (adding "Win Condition" and "Typical Arena Patterns"), both removed after the Arms Warrior draft made the overlap concrete: "how does this thing create pressure" was being answered at two different zoom levels by two different pages, and "Typical Arena Patterns" in particular read as largely generic across specs/games once compared side by side (the same sentence shape — "long quiet stretches punctuated by burst" — fit an SC2 timing attack or a LoL assassin with only nouns swapped). Locked-in structure:
1. **Identity** — role, class fantasy, arena role, general combat approach (this already carries a condensed version of what "Win Condition" would have said — no content was lost, just the redundant second page)
2. **Resources** — primary resource(s), generation, spending, resource philosophy
3. **Major Offensive Cooldowns**
4. **Major Defensive Cooldowns**
5. **Utility** — interrupts, CC, mobility, peels, support tools
6. **Weaknesses** — vulnerabilities, windows of weakness, counterplay

**Ability-name volatility is isolated into a per-page callout, not left embedded in prose.** Pages 3–5 (the ones that reference specific abilities) each end with a small **"Current Ability Names (verify each patch)"** table — role/purpose → current ability name — and the surrounding prose describes abilities only by role ("the armor-debuff burst cooldown," "the healing-reduction signature strike"), never by name. This means a patch/rework only ever requires editing the table row, never the educational paragraph around it — directly satisfies the "update only affected sections" maintenance goal without needing a structured-content schema change. Locked in now, while there is only one module, specifically because retrofitting this split across dozens of already-drafted modules later would be expensive.

**Schema:** no changes needed to author these. A future per-page `last_checked_at` (or similar verification-status column) on `module_pages` is anticipated for the patch-verification workflow described above, but is deliberately deferred until that workflow is actually built — see the module's own review notes for the reasoning.

**Status:** template locked, three pilot modules drafted via the expertise-capture path (Discipline Priest (Oracle) and Feral Druid (Wildstalker), both dictated by a Gladiator-rated player; Arms Warrior — AI-researched draft, not yet seeded to DB). Discipline Priest is seeded via `DiscPriestOracleModuleSeeder` and **is** registered in `DatabaseSeeder`. Feral Druid is seeded via `FeralDruidWildstalkerModuleSeeder` but is **not** currently called from `DatabaseSeeder` — the file exists but nothing invokes it, so it's likely not actually in the DB unless someone ran it manually (`php artisan db:seed --class=FeralDruidWildstalkerModuleSeeder`). Confirmed 2026-07-22 while investigating why a Feral-declared user wasn't routing to it — flagged here rather than silently fixed, since whether to wire it up is entangled with the open question below. Both dictated modules are structured directly from the player's own dictation rather than AI/guide-researched, with page structures adapted to what the dictation actually contained rather than forced into the original 6-page/8-page template shape. Ambiguous or hedged facts from the dictation are preserved and flagged in the page content ("stated with uncertainty — verify") rather than resolved by AI guesswork. Both are seeded `published = false` (no recall questions yet — a separate, later authoring stage) and tagged via `module_context_option` to their Spec option. Scaling to the remaining WoW specs, SC2 races, LoL roles, and Poker archetypes is future work.

### Definition (resolved 2026-07-22): what a canonical module actually is

A canonical module is **the trusted, teachable representation of a subject** — produced by reconciling objective reference data with expert judgment — so it can power every feature that needs that domain's knowledge, not just serve as a one-off lesson: diagnostic question variants, recall questions, flashcards, review-content/explanation generation, validating AI answers against ground truth, diffing across game patches, and grounding future expert interviews.

It has three inputs, not one:
```
Raw Game Data                Expert Mental Model
(spells, talents,            (win conditions, priorities,
 cooldowns, hero talents,  +  burst windows, matchups,
 resource generation,         common mistakes, decision
 mechanics — what             rules — what actually
 objectively exists)          matters)
                 \            /
                  AI Calibration
        (reconciles the two: detects omissions,
         detects factual inaccuracies in the expert's
         account, asks follow-up questions, produces
         one coherent teaching model)
                       ↓
              Canonical Module
```

"Canonical" doesn't mean perfect or final — it means grounded in objective data, reviewed by an expert, and internally consistent, so a *later* expert reviewing it is correcting an existing trusted baseline ("I'd change the opener") rather than rebuilding from a blank page.

**Process shift this formalises:** originally a canonical module was just "expert writes the module." The intended pipeline is fuller: raw structured data (e.g. SimulationCraft) → knowledge extraction → expert interview → gap/inaccuracy detection → expert review → canonical module. The structured data is a safety net so the expert isn't expected to recall every exact number — they supply judgment, the reference data supplies facts, AI reconciles the two.

**Why this exists for MindCollector specifically:** the diagnostic identifies a gap ("weak at cooldown trading") and the platform needs somewhere real to point the player — not "go watch a YouTube guide" but a specific, trusted module ("Cooldown Trading for Discipline Priest") that exists because the expert knowledge behind it has already been captured and calibrated against real data.

**Re-reading the two pilots against this definition** (corrects the completeness-only read from the earlier version of this note): the two aren't equally short of the goal, and not in the way "which one has more content" suggests.
- **Feral Druid (Wildstalker)** already went through part of the real pipeline — its seeder docblock records that SimulationCraft data (`data/spelldata/filtered/druid/...`) was used to *correct* several of the player's dictated cooldown numbers (Feral Frenzy, Skull Bash, Wild Charge, Ursol's Vortex, Berserk) before seeding. That's the raw-data-reconciliation step actually happening, even done informally/by hand rather than via a repeatable process.
- **Discipline Priest (Oracle)** went through the same kind of ad hoc cross-check retroactively (2026-07-25) — every numeric claim in the module's prose (cooldowns, talent modifiers, proc values) was checked by hand against the imported spelldata. Confirmed correct almost across the board (Pain Suppression's charges/cooldown-reduction text, Fade+Improved Fade, Desperate Prayer+Angel's Mercy, Psychic Scream+Psychic Voice, Angelic Bulwark's four numbers, Harsh Discipline, Dark Indulgence — all matched exactly); found one real discrepancy (Ultimate Penitence: module says "5 minute CD," data says 240s/4 minutes) still unresolved, flagged for the original dictating player to check.
- So: both pilots have now had this step done, informally/by hand rather than via a repeatable process — the thing "still open" below remains open for both.

**What's still open:** no repeatable pipeline exists yet for the Raw Data + AI Calibration steps — both cross-checks above were done ad hoc by hand, not via any tool/service. That needs designing (what raw data source per subject, what the AI gap-detection/follow-up-question step actually looks like as a feature) before authoring the next canonical module, so each new one doesn't repeat that ad hoc process manually. Do not revise the Feral or Disc Priest pilot content until that pipeline exists — Ultimate Penitence's cooldown discrepancy is the one flagged exception, pending the original player's input, not a decision made unilaterally here.

**A step beyond ad hoc cross-checking now exists, though, and it's structural rather than one-time: the "Spells" reference section (2026-07-25).** Canonical modules can declare a `ModuleGameBuild` (class/spec/hero-tree — see that model's docblock) and a curated `module_spell_references` list (the abilities actually named in the module's prose, resolved to real `spell_id`s once at seed time via `ModuleSpellReferenceService::resolveSpellByName()`). The module's Show page then renders full detail for each — name, description, cooldown/charges, and what modifies or enhances it — computed **live** from whatever's currently in `spells`/`spell_relationships` on every page load, never frozen into prose. This is deliberately *not* a `ModulePage`: prose goes stale the moment a patch changes a number with nothing to catch it (exactly what the Ultimate Penitence discrepancy above would have looked like if written into the guide text itself, undetectably, instead of being cross-checked against live data), so the Spells section is its own always-current mechanism instead, kept out of `ModulePage` specifically so `ContentQa`'s "snapshot real prose" contract stays untouched. Modifier lookup itself needed two mechanisms, confirmed against real data: `spell_relationships` graph walk (catches e.g. Weal and Woe on Power Word: Shield) and a description-text scan bounded to the build's own kit (catches proc-relationships with no structural spell_id link at all, e.g. Borrowed Time's haste proc on Power Word: Shield) — neither alone covers both known cases. Generic always-on class-wide passive modifiers (e.g. "Priest", "Discipline Priest") are deliberately pulled out of each spell's own modifier list and shown once, deduplicated, at the bottom of the section, rather than repeated under nearly every row.

## Talent-Aware Spell Data ✓ COMPLETE (2026-07-30)

The Spells reference section (above) used to show every possible modifier for a spec unconditionally — no concept of which talents a viewer actually has. Motivating example: the Discipline PvP talent Ultimate Radiance reduces Evangelism's cooldown by 45s, but PvP talents carry only a free-text `description` (no structured spell_id references at all, unlike spelldata's Affecting Spells/Category fields) — this relationship wasn't captured anywhere, and even `ModuleSpellReferenceService::buildKitSpellIdsFor()` excluded `pvp_talent`-sourced spells from its candidate pool entirely (fixed as part of this change — see below).

**New relationship type + magnitude columns.** `spell_relationships` gained `modifier_value`/`modifier_unit` (nullable — populated only where confidently derivable, never guessed) and a fourth `relationship_type`: `modifies_cooldown`, sourced from regex-parsing PvP talent descriptions for the one confirmed phrasing shape (`"<Spell> cooldown is reduced/increased by N sec/%"`) — `ImportSpellData::importPvpTalentRelationships()`, a new global pass alongside the existing three. Anything that doesn't match the known phrasing is logged and skipped, not guessed. Separately, `importCategoryRelationships()` now threads magnitude through for the one `modifies_charges` effect type with a verified conversion (`Modify Recharge Time (Category)` → flat seconds, per the Mind Blast worked example in `game-data.md`) — the other 7 effect types in that "coarser label" gap stay descriptive-only, unchanged. **Superseded 2026-08-01** — see the note below the "Deliberately not built" line: the 8 effect types are now split across 5 correctly-labeled `relationship_type` values instead of all sharing `modifies_charges`.

**Selection state.** `talent_builds`/`talent_build_choices` (PvE tree picks) existed with zero UI before this — extended with a new `talent_build_pvp_choices` table (PvP talents have no tree/node structure, just 4 flat slots with no per-slot restriction in the data, so a build's whole PvP selection is replaced-not-appended via `TalentSelectionService::syncPvpChoices()`, not tracked per-slot). `talent_builds` gained `is_default` (an admin-curated "meta" build per spec+patch, `user_id = null`) and a real DB unique constraint on `(user_id, spec_id)` — one saved build per user per spec, same "structurally impossible" precedent as `UserSubjectContext`'s `(user_id, dimension_id)` constraint.

**`TalentSelectionService`** (`app/Http/Services/TalentSelectionService.php`) is the single place that resolves "what's selected": `resolveActiveBuild()` — user's saved build → spec's default build → an unsaved shell (nothing selected, falls back to base data, same as before this feature existed) — and reads/writes choices. `ModuleSpellReferenceService::modifiersFor()` gained a `$selectedSpellIds` parameter (the 'named' bucket now requires selection, not just kit membership — 'baseline' always-on passives stay ungated) and a new `effectiveCooldown()` (base cooldown + selected modifiers, flat seconds before percent, same layering order as the Mind Blast worked example).

**`TalentSelector`** (`app/Livewire/TalentSelector.php` + `talent-selector.blade.php`) — the talent picker. Authenticated users auto-save on every click (no Save button, same pattern as `SubjectContextForm`); guests get component-state-only selection (nothing persisted, resets on reload). `Admin\TalentBuildEditor` (`/admin/talent-builds`) reuses the exact same component in `isDefaultEditor` mode to author each spec's default build — actually sourcing "top players' meta" picks is a manual curation task, not automated here. **Removed from `Modules\Show` 2026-08-01** — see the note below the "Deliberately not built" line; as of that date `Admin\TalentBuildEditor` is the only place this component is mounted.

**Deliberately not built:** talent-tree spend-order/point-budget validation (any entry in any node is selectable regardless of prerequisites); magnitude for the plain `modifies` relationship pass (needs `SpellDataFileParser::parseSpellRefs()` extended to retain effect-number annotations it currently discards by design). Tests: `tests/Feature/Services/TalentSelectionServiceTest.php`, `tests/Feature/Services/ModuleSpellReferenceServiceTalentGatingTest.php`.

**`modifies_charges`/charges display fixed 2026-08-01** — the charge-count path described above (`ModuleSpellReferenceService::effectiveCooldown()`) had no charges counterpart until this date, so a talent granting a spell an extra charge (e.g. Protector of the Frail → Pain Suppression) was detected and shown as a badge but never changed the displayed charge count. Also traced a systemic, previously-unknown bug in `data/spelldata/split-by-tree.php` that silently truncated multi-paragraph spell descriptions across every class (several hundred records) before they ever reached the parser, and a separate pre-existing parser bug where `SpellDataFileParser` extracted the wrong pipe-segment of an effect line (kept the generic "Apply Aura (N)" wrapper instead of the specific AuraType name like "Modify Cooldown Charge (Category)") — which meant `categoryRelationshipMapping()` (and the original check it replaced) never actually matched anything until fixed. See `game-data.md`'s "`split-by-tree.php` was silently truncating multi-paragraph descriptions" and "`modifies_charges` split into correctly-typed relationships" sections (2026-08-01) for the full detail — new `ModuleSpellReferenceService::effectiveCharges()`, `categoryRelationshipMapping()` splitting the 8 conflated Category effect types into 5 correctly-labeled `relationship_type` values, and the `split-by-tree.php`/`SpellDataFileParser` continuation-text and effect-type-extraction fixes. **Re-import required for existing DBs** (`migrate:fresh` + re-run `import:spelldata`, not an in-place re-import — see game-data.md for why) before the corrected charges/relationship labels take effect. Verified end-to-end against the real DB: `modifies_charges` went from 576 (mislabeled) to 169 rows, `modifies_cooldown` from 1 to 227, and Pain Suppression's effective charges correctly compute to 2 when Protector of the Frail is selected.

**Talent picker removed from module pages entirely, 2026-08-01.** Two changes landed together, both prompted by a real bug report (selecting a hero talent on the Discipline Priest Oracle module made the "Build/Path Identity" tab content visually vanish — traced to a client-side Alpine/Livewire DOM-morph issue: server-rendered HTML was confirmed correct both before and after the change via `Livewire::test()`, so the content wasn't actually missing, just mis-painted):
1. `TalentSelector` gained a `$moduleHeroTreeId` param (passed from `Modules\Show`'s `ModuleGameBuild.hero_talent_tree_id`) — a canonical module's prose only ever covers one hero tree, so forcing a manual dropdown pick before the page's own content was accurate was pure friction with no real choice behind it. Defaults `heroTreeId` in-memory on mount only (never persisted, never overwrites a viewer's own saved cross-module preference) and hides the dropdown when locked this way.
2. User decision, when asked where the picker should live instead of on the module page (options were: the Progress page next to `SubjectContextForm`, a new dedicated page, or admin-default-only): **admin-default-only** — no per-user picker UI exists anywhere right now. `<livewire:talent-selector>` was removed from `show.blade.php` entirely (replaced with a static one-line note when a build is active); `Modules\Show::onTalentsChanged()`/`#[On('talents-changed')]` was deleted as dead code (nothing on that page dispatches the event anymore). `Admin\TalentBuildEditor` is now the *only* mounting point for `TalentSelector`.

**Practical consequence, not yet resolved:** `TalentSelectionService::resolveActiveBuild()`'s fallback chain (user's saved build → spec's admin default → empty shell) means the Spells section on every module now shows fully unmodified base data for every viewer until an admin default `TalentBuild` (`is_default = true`) is actually created for that spec via `/admin/talent-builds` — confirmed zero `TalentBuild` rows exist for Discipline as of this date. Setting one (e.g. picking Protector of the Frail for Discipline) is a manual curation step, not automated by this change.

**Class-availability gaps found by cross-checking the real in-game Spellbook, fixed 2026-08-01.** Two distinct bugs, both traced by pulling real Priest Spellbook screenshots and checking every ability against `spell_class_availability`: (1) Mind Blast was importing as class-wide when its own data (`free=(Discipline, Shadow)` on its Talent Entry line) says it should be Discipline+Shadow only — nothing parsed `free=(...)` at all before this fix. (2) Penance and Ultimate Penitence are each several `spells` rows sharing one display name (internal damage-bolt/heal-bolt/visual-effect sub-spells, most flagged `Not In Spellbook` in their own Attributes) — `resolveSpellByName()` could previously resolve to the wrong one. See `game-data.md`'s "Class-availability gaps found by cross-checking the real in-game Spellbook" section and `wow-spells.md` §2/§4 for full detail — new `SpellDataFileParser` capture of `free_specs`/`not_in_spellbook`, `ImportSpellData::resolveFreeSpecIds()`, and `ModuleSpellReferenceService::preferVisible()`. Same re-import requirement as every other classification fix in this file (`migrate:fresh`, not in-place). Verified end-to-end after the rebuild, including that the Discipline Priest Oracle module's `180s · 2 charges` fix survived it.

## Blizzard Talent String Import ✓ COMPLETE (2026-07-31)

`TalentSelector` can now populate itself from a real Blizzard "Export" loadout string (the same one Wowhead/Raidbots/the in-game copy button produce) instead of only click-through selection. `BlizzardTalentStringCodec` (`app/Http/Services/BlizzardTalentStringCodec.php`) decodes it — a literal port of Blizzard's own shipped client Lua (`Blizzard_ClassTalentImportExport.lua` + `ExportUtil.lua`, pulled from the `Gethe/wow-ui-source` mirror), not a reverse-engineering guess, and verified against a real string during development (decodes to spec ID 256/Discipline Priest correctly).

**Format, in brief:** standard base64 alphabet packed as a custom LSB-first bit-stream (not `base64_decode`-compatible) — 8-bit version + 16-bit spec ID + 128-bit tree hash (read and discarded; we can't compute Blizzard's native-code hash, and Blizzard's own client explicitly sanctions third-party tools zero-filling/skipping it), then one bit per talent node across the class's *entire* trait tree (every spec + every hero tree, not just the target spec), ascending by Blizzard's internal node ID. PvP talents are not part of this format at all — import only ever affects PvE tree picks.

**Two assumptions the codec makes that couldn't be fully verified without real imported data on hand while building it** (documented in the class's own docblock, not silently trusted): (1) choice-node index maps to our `talent_node_entries` sorted by `id` ASC, assuming that matches Blizzard's live `entryIDs` order — likely true (same underlying Game Data API source) but unproven; (2) hero-tree-selection needs no special-casing at the bit level (confirmed by reading the source — the choice flag is self-describing in the stream) but unconfirmed against real decoded output.

**Mitigation, not a hidden risk:** `TalentSelector::previewImport()` decodes into a human-readable list of resolved spell names — nothing is written to `talent_builds` until `applyImport()` is explicitly clicked after reviewing the preview. Same "match a trusted source, flag discrepancies, never silently trust" posture as `ModuleSpellReferenceService::resolveDescription()` and the whole `game-data.md` philosophy. Warnings (truncated string, unresolvable node) surface in the preview rather than failing silently or crashing.

**Not built:** export (encode() exists internally for test fixtures only, not exposed in the UI — cheap follow-up if wanted, the codec is symmetric). Tests: `tests/Feature/Services/BlizzardTalentStringCodecTest.php` (real-string header decode + a synthetic encode→decode round-trip covering simple/ranked/choice node types).

**Real node-ordering bug found and fixed, 2026-08-01** — a real Discipline Priest export string decoded to an impossible result (talents selected from Holy AND Shadow AND Discipline simultaneously, which can never happen in a genuine export). Root cause: `external_node_id` is only unique *within one tree*, not globally — confirmed directly in `data/talenttrees/priest.json`, where node id `94691` appears at four separate byte offsets (Priest's class tree, Holy tree, Discipline tree, and the Oracle hero tree). The original `decode()` queried every node across the whole class and sorted them in one global pass by that column — meaningless once IDs collide across trees, and the actual cause of the garbage output. Fixed via new `orderedNodesForSpec()`: nodes are now read in three separate ordered blocks — class tree, then only the target spec's own tree, then hero trees valid for that spec (never any other spec's tree). All existing tests still pass; verified against the real string, which now correctly resolves Protector of the Frail (previously absent) and 85 total selections, all real Discipline/Oracle talents.

## Module-linked talent builds ✓ COMPLETE (2026-08-01)

A `TalentBuild` can now be scoped to a specific **module**, not just to a user or the spec-wide admin default — added because per-module content sometimes has (or was authored assuming) a specific known talent setup, and re-deriving that from a user's own picks or a generic spec-wide default doesn't fit. `talent_builds.module_id` (nullable, unique — MySQL allows unlimited NULLs so this coexists with the existing `(user_id, spec_id)` unique constraint) is the third scope, alongside `user_id` (personal) and `is_default` (spec-wide meta build).

`TalentSelectionService::resolveBuildForModule(Module $module): ?TalentBuild` returns the module's own linked build if one exists **and has at least one choice** (an empty linked build is treated the same as "not linked," same as the existing unsaved-shell fallback elsewhere in this service) — else null. `getOrCreateModuleBuild()` lazily creates one when curating a module's talents (e.g. importing a real Blizzard string into it via `TalentSelector`'s existing import flow, pointed at this build instead of a user/default one).

**Resolution order, per `Modules\Show::initSelectedSpellIds()`:** module's own linked build (if any, non-empty) → the viewer's own saved build → the spec's admin default → empty/base data. This sits on top of the 2026-08-01 removal of the interactive picker from module pages (see the "Talent picker removed from module pages entirely" note above) — a module's own linked build is now the *primary* way any viewer sees personalized cooldowns/charges on that page, not a fallback.

**Verified end-to-end for the Discipline Priest (Oracle) module**, using a real Blizzard "Export" talent string decoded through the fixed codec above: 85 selections saved as that module's linked `TalentBuild` (including Protector of the Frail), and the module's Spells table now renders `180s · 2 charges` for Pain Suppression automatically, on a completely fresh page load, with zero interaction required — the original motivating bug this whole investigation started from.

## `ModuleSpellReferenceService` computation bugs found via the spellbook verifier, fixed 2026-08-02

Surfaced by the very first real diff run of the spellbook verifier above: the player reported Mind Blast showing `24s` cooldown in-game on their real Discipline Priest, `9s` on the Discipline Priest (Oracle) module page. Traced to **two stacked bugs in `ModuleSpellReferenceService.php`**, both now fixed — this corrects the "Talent-Aware Spell Data" section's earlier claim (line above, 2026-07-30) that the Mind Blast worked example was already correctly layered; it wasn't, for a different reason than that section describes.

**Bug 1 — `modifiersFor()`'s structural-relationship loop deduped by source spell, not by (source, relationship_type).** `$seenIds->contains($source->id)` gated the loop itself, so once a source spell's *first* `spell_relationships` row to the target was processed, every other row from that same source — a different `relationship_type` — was silently skipped. Confirmed concretely: Discipline Priest's identity passive has **two separate rows** targeting Mind Blast (`id 30352, type=modifies, no magnitude` from the Affecting-Spells pass; `id 46366, type=modifies_cooldown, +19s` from the Category pass) — only the first was ever classified. `$seenIds` was intended to stop the *later* description-text-scan pass from re-detecting a source already found structurally, not to dedupe within the structural pass itself, where a source having multiple distinct relationship types to the same target is a normal, expected pattern (e.g. modifying both a spell's damage and its cooldown). Fixed: the loop no longer skips on `$seenIds`; it still populates `$seenIds` afterward so the text-scan pass's exclusion behavior is unchanged.

**Bug 2 — `effectiveScalarValue()` (backing both `effectiveCooldown()`/`effectiveCharges()`) only summed the `'named'` bucket, never `'baseline'`.** `'baseline'` is `modifiersFor()`'s own bucket for "generic always-on class-wide passive auras" (e.g. "Priest", "Discipline Priest") — by definition these always apply, no talent selection needed, which makes them the *safest* category to include in a numeric total, not one to exclude. Confirmed: even after Bug 1 is fixed and the `+19s` entry reaches classification, it lands in `'baseline'` (since its source spell literally *is* the Discipline Priest identity passive) and was still being dropped from the sum. Fixed: `effectiveScalarValue()` now merges `'named'` and `'baseline'` before applying the same `relationship_type`/`modifier_unit` filter as before — the filter itself is unchanged, so this doesn't relax which modifiers can contribute, only which bucket they're allowed to come from.

**Root data, for reference:** Mind Blast's own record declares `Charges: 1 (9 seconds cooldown)` (its generic/Shadow-context base, correctly stored as `spells.cooldown_seconds`). The Discipline Priest identity passive (`spell_id 137032`) separately carries `Modify Recharge Time (Category): Base Value 19000` (→ 19s) targeting Mind Blast — Blizzard's actual mechanism for "this shared spell has a different real cooldown under this spec." `9 + 19 = 28s` unhasted; the player's `24s` live in-game reading, confirmed by the player to be haste-shortened from that unhasted value (haste isn't modeled anywhere in this system — `28s` is the correct number for this system to show, not `24s`).

**Audited before merging, per the project's "trace to root cause, don't shotgun-patch" convention** (see [[feedback_trace_dont_shotgun_patch]] in memory) — every spell across both real `ModuleGameBuild` rows in the DB (Discipline Priest Oracle, Discipline Priest Fade & Death Matchup Timing) whose `modifiersFor()` `'baseline'` bucket carries a magnitude-bearing `modifies_cooldown`/`modifies_charges` entry: exactly two, **Mind Blast** and **Void Blast**, both from the same single relationship (the same Discipline-identity `+19s` — matches that passive's own `Affected Spells (Category): Mind Blast (8092), Void Blast (450983)` line exactly). No other spell, no other effect type, no cross-contamination. `php artisan test --filter=ModuleSpellReferenceService` and the full suite both confirmed zero regressions (the only failures present were 12 pre-existing, unrelated ones — recaptcha-affected Auth tests already documented above, plus one `LearningPathTest` case about subject-context milestones).

## `data/talenttrees/{class}.json` class-tree bloat, found and fixed 2026-08-02

Found while debugging why a real Blizzard talent-string import into the Discipline Priest Oracle module's linked build only ever resolved talents to the "Priest Class" tree, never to Discipline's own spec tree or the Oracle hero tree — surfaced by the player reporting the admin talent-build editor "only picks the disc[?] talents, not the actual priest ones" after using it for the first time.

**Root cause: Blizzard's own `/data/wow/talent-tree/{treeId}` (no spec) endpoint returns far more than the class-wide baseline.** `fetch-talent-trees.php`'s docblock assumed this endpoint returns only the class's shared baseline nodes (`talent_nodes`, "shared across every spec of that class"). Confirmed via live re-fetch (not a stale artifact — re-ran it and got the identical shape) that it also echoes nearly every one of the class's own spec nodes, with identical embedded `spell_name`/`talent_name` content, not a coincidental id collision. Checked across all 13 classes: every one showed 90-100%+ overlap between `class_talents.nodes` and the union of that class's spec nodes (e.g. Priest: 226 class nodes returned vs. 208 real spec nodes, all 208 duplicated inside the 226). This inflated every class's real ~45-75-node baseline tree up to 200-280 nodes.

**Why this broke the Blizzard talent-string codec specifically:** `BlizzardTalentStringCodec::orderedNodesForSpec()` reads the bitstream in three fixed blocks — class tree, then the target spec's tree, then hero trees. Because the "class" block was ~4-5x its real size, the bit reader consumed far more bits than Blizzard's real client did for that same logical position, misaligning everything after it. All of a real character's actual selections — spanning class + spec + hero in a genuine build — decoded as landing entirely inside the (artificially huge) class block, several resolving to clearly wrong content (e.g. "Voidheart", "Touch of the Void" — Shadow/Voidweaver talents — decoded as if they were Discipline class-tree picks). The stream also ran out early (`"Talent string ended early"` warning), meaning real spec/hero selections were never reached at all.

**This also invalidates the "verified end-to-end... 85 selections (including Protector of the Frail)" claim** in the "Module-linked talent builds" section above — that verification only checked total selection count and that one specific talent resolved, never checked tree attribution. Re-checked after this fix: the same real loadout string now correctly decodes to 54 selections (24 class + 13 Discipline spec + 17 Oracle hero — a properly distributed real build, not 85 all miscategorized as "class"). Protector of the Frail is genuinely *not* selected in the character's current real build — its earlier "presence" was decoded from corrupted, misaligned bits and was coincidental, not a trustworthy confirmation. `Mind Blast`'s `effectiveCooldown()` fix (see the section above this one) still correctly computes `28s` against the corrected, rebuilt selection set.

**Fixed in `fetch-talent-trees.php`:** after fetching both the class tree and every spec's tree, `class_talents.nodes` is now filtered to exclude any node whose id also appears in that class's own spec node lists — spec-list membership is treated as authoritative over the bloated class-tree response. Applied to all 13 classes; re-fetched live and confirmed zero overlap remains, with plausible class-tree sizes (45-75 nodes) across every class. Whether this is a Midnight-expansion API/data-model change or the endpoint always behaved this way is unconfirmed (no way to check historical API behavior) — the filter is defensive regardless.

**Re-import required and performed**: `migrate:fresh` (wipes the whole local DB — deliberate, player confirmed acceptable) + `import:spelldata wow 12.0.7.68887 --current` + `db:seed`, using the corrected JSON. Both `TalentBuild`s and both `spellbook_snapshots` had to be recreated afterward: the spellbook snapshot was re-imported directly (`wow:import-spellbook`, same export file); the Discipline Priest Oracle module's linked `TalentBuild` was rebuilt programmatically by decoding the *same* real loadout string already captured in that fresh spellbook snapshot (`SpellbookSnapshot.loadout_string`) through `TalentSelectionService::getOrCreateModuleBuild()` + `BlizzardTalentStringCodec::decode()` — no UI re-entry needed for that one, since the raw string was already on hand.

**A second, separate gap found along the way, not fixed:** `TalentSelectionService::getOrCreateModuleBuild()` exists but has zero callers anywhere in the Livewire/views layer — there is currently no UI to import a Blizzard string directly into a *module-linked* build (as opposed to the spec-wide admin default, which `Admin\TalentBuildEditor` does support). The original build_id=1 must have been set up through a one-off manual/tinker action in an earlier session, not a repeatable feature. Flagged here, not built — out of scope for this investigation.

**Still needs manual redo, not done as part of this fix:** the admin default `TalentBuild`'s PvP talent selections (3 choices, lost in the `migrate:fresh`) — unlike the module-linked build, there's no stored source string for these (the Blizzard talent-string format carries no PvP data at all), so they can only be re-entered by hand via `/admin/talent-builds`.

## `not_in_spellbook` systemic false-positive + cross-class name-resolution gap, fixed 2026-08-02

Found immediately after the talent-tree fix above, investigating a *different* module: the Discipline Priest "Fade & Death Matchup Timing" module showed no cooldown for Hunter's Intimidation/Freezing Trap. Two stacked bugs, both fixed same day, full trace in `game-data.md`:

1. **`SpellDataFileParser`'s `not_in_spellbook` check had a real false-positive bug** — `str_contains($m[1], 'Not In Spellbook')` also matched the unrelated, common `Not In Spellbook Until Learned (269)` attribute (true of nearly every normal talent), wrongly hiding 646 real spells dataset-wide from `ModuleSpellReferenceService::preferVisible()`. Fixed to match the exact `Not In Spellbook (143)` string. Bundled fix: also now matches `Do Not Display (Spellbook, ...)` (see game-data.md's Penance-197419 entry — was flagged earlier today, fixed here).
2. **`resolveSpellByNameAnyClass()`** (the opponent-ability fallback, used for cross-class spell references like a Hunter ability mentioned on a Priest module) had no equivalent to `resolveSpellByName()`'s own-class `$withCooldown` disambiguation tier — added.

**Required a third `migrate:fresh` + re-import + re-seed cycle today** (talent-tree bloat fix this morning, this fix this afternoon) — same routine each time: re-import spellbook snapshot, rebuild the module-linked `TalentBuild` from the same real loadout string + PvP selections already captured. Verified nothing regressed: Mind Blast still `28s`, Evangelism still `45s` on the Oracle module; Intimidation now `60s`, Freezing Trap now `30s` on the Fade & Death module.

## Description resolver: SP Coefficient, sibling effect recovery, formula modifiers ✓ COMPLETE (2026-08-02)

Three related fixes to `ModuleSpellReferenceService::resolveDescription()`, built together after investigating why Penance/Angelic Bulwark/Roar of Sacrifice all showed broken or unresolved description text. Full technical trace in `game-data.md`'s "Description resolver gaps" section — summary here.

**1. SP/PvP Coefficient capture.** `spell_effects` gained `sp_coefficient`/`pvp_coefficient` (nullable decimal) — confirmed via SimC's own upstream source (`spelleffect_data_t.sp_coefficient` is a first-class field there too, not something specific to this project's text-dump format) that this is legitimate structured data, not a hack. `SpellDataFileParser` now captures `SP Coefficient: X` / `PvP Coefficient: X` from each effect's detail line (previously discarded). When an effect's `base_value`/`scaled_value` are both 0 — the signature of a purely SP-scaled effect (Mind Blast, etc.) — `resolveDescription()`'s Pass 3 (bare `$sN` tokens only, deliberately not Pass 2's compound arithmetic — see the method's docblock for why blending an estimate into multi-variable formulas risks a confidently-wrong result) now shows `"≈78.3% of Spell Power"` instead of an unresolved formula or `(varies)`.

**2. Sibling effect-index recovery (`ModuleSpellReferenceService::findEffectByIndex()`).** Confirmed two real, distinct root causes for a description referencing an effect index that doesn't exist on the spell carrying it: Angelic Bulwark (`spell_id 114214`, the real visible spellbook entry) has its description inherited via a `$@spelldesc` pointer from `108945` (a hidden internal data-carrier) — the `$sN` tokens in that inherited text were written relative to `108945`'s effects, not `114214`'s own (nearly empty) ones. Roar of Sacrifice (`67481`) is a *different* shape of the same problem — no pointer involved, literal own description text, but SimC's dump itself splits the ability's effects across multiple same-named non-hidden spell_id records, only one of which (`53480`, the real Talent Entry) has the complete effect list. `findEffectByIndex()` now falls back to a same-named sibling (same patch) when the spell's own effect at that index is missing or zero-valued, used by both `resolveValueToken()`'s `sN` branches and the new `coefficientDisplay()`.

Quantified before building this: **1,015** non-hidden spells dataset-wide reference a `$sN` index missing from their own effects (after excluding hidden-duplicate noise that would never actually be selected in the first place). **694 (68%)** have a same-named sibling that actually carries the real data — recovered by this fix. **321 (32%)** don't exist anywhere in the imported dataset at all — genuinely absent from the underlying SimC dump, not a resolution bug, and stay exactly as unresolved as before (no fabricated numbers).

**3. Variables-block-derived "Scales with" list (`ModuleSpellReferenceService::variablesModifiers()`).** For formulas too complex to reduce to one number (Penance's damage/healing — a coefficient multiplied by several conditional talent factors, some percentage multipliers, some additive bolt-counts, deliberately never blended into arithmetic), the raw "Variables" block (previously not parsed at all — see below) is scanned for `$?a<id>`/`$?s<id>` conditionals, each resolved to a real talent name by spell_id (a real unique key per patch, no duplicate-name ambiguity to resolve here, unlike name-based lookups elsewhere in this file). Shown as a plain name list under the description ("Scales with: Power of the Dark Side, Twilight Equilibrium, Castigation, Harsh Discipline") — deliberately not asserting the exact math (some factors are conditional % multipliers, others are conditional additions), just surfacing which real talents matter. Rendered by `<x-spells.table>`, wired from both `Modules\Show` and `SpellExplorer`.

**Bundled parsing fix, found investigating case 3: the "Variables" block was leaking into `description`.** `SpellDataFileParser`'s description-continuation logic didn't recognize "Variables" as a field boundary, so the entire raw formula block (`$castigation=$?a193134[...]...`) was getting silently appended onto the end of the description text — confirmed on Penance's own pre-fix stored description, which ended with the full unreadable Variables dump glued on after "Castable while moving." Fixed: "Variables" is now a recognized field (same continuation-then-stop pattern as Description), captured into a new `spells.variables` column instead.

**A fourth, smaller fix found during verification:** bare `$<varname>` tokens (angle-bracket references to a *named* Variables-block formula, e.g. Penance's `$<penancedamage>`) matched neither Pass 2 nor Pass 3's regex at all — they passed straight through as raw, broken-looking text in otherwise-clean prose (pre-existing behavior, not a regression from this session's other fixes). Actually evaluating the referenced formula is out of scope (same reasoning as not blending coefficients into Pass 2's arithmetic), so these now resolve to the same `(varies)` placeholder every other unresolvable case already uses — `variablesModifiers()` fills the informational gap instead.

**Re-imported and re-verified end-to-end** (same `migrate:fresh` + re-import + re-seed + re-link-builds routine as every other classification fix today): Penance now reads *"...causing (varies) Holy damage... or (varies) healing... healed for ≈18.7% of Spell Power"* with "Scales with: Castigation, Harsh Discipline, Power of the Dark Side, Twilight Equilibrium" — coherent and honest, not the old raw-formula leak. Angelic Bulwark and Roar of Sacrifice both fully resolve to real numbers via sibling recovery. Mind Blast shows the coefficient percentage. Full test suite: zero regressions (same 12 pre-existing, unrelated failures as every prior check today).

## Spells table: category grouping + public Spell Explorer page ✓ COMPLETE (2026-08-02)

**`ModuleSpellReferenceService::categorize(Spell $spell): string`** — a best-effort display heuristic (Crowd Control / Defensive / Utility / Offensive / Other), computed purely from each spell's already-captured `spell_effects.type` strings (`$spell->effects` must be eager-loaded). No new data, no parser changes. Checked in priority order (CC first — a Stun/Fear/Root effect is the least ambiguous signal available; Other last as the catch-all). **Deliberately not authoritative** — spot-checked against real spells before shipping: clean cases work well (Pain Suppression → Defensive, Kidney Shot → Defensive... e.g. Stun → Crowd Control, Dispel Magic → Utility), but genuinely multi-purpose spells get misfiled (Fade lands in Defensive due to its damage-taken% component, even though it reads as Utility to a player; Avatar mixes offense+defense). The `Spells` table in both `Modules\Show` and the new Spell Explorer page (below) groups rows under these category headings — purely a display grouping, nothing is written anywhere.

**`<x-spells.table>`** (`resources/views/components/spells/table.blade.php`) — the Spells table markup (category-grouped rows, cooldown/charges display, "what modifies it" badges, baseline-passives footer) extracted out of `modules/show.blade.php` into a shared, reusable Blade component (`@props(['entries', 'title', 'description'])`) so it renders identically wherever it's used. `Modules\Show`'s `getBaselineModifierNamesProperty()` was removed — the component now computes that internally from `$entries`, so callers only need to pass the entries array.

**Spell Explorer** (`app/Livewire/SpellExplorer.php` + `resources/views/livewire/spell-explorer.blade.php`, route `spells.explore` → `/spells`, public, no auth) — a class/spec-only counterpart to a canonical module's Spells section, with no module involved. Picker mirrors `Admin\TalentBuildEditor` exactly (class dropdown → spec dropdown). Below it, the same `<x-spells.table>` component renders driven by **the spec's admin-curated default `TalentBuild`** (`is_default = true`, set via `/admin/talent-builds`) — read-only, never creates one if missing (a spec with no default configured shows a message linking to the admin page instead of an empty table).

**Spell list is intentionally just the default build's own selections (`TalentSelectionService::selectedSpellIds()`), not "the class's whole baseline kit."** A `source = 'baseline'` merge was tried first and reverted — that data mixes real class abilities with generic system/item spells (procs, test spells, artifact/covenant items) with no reliable column at the view layer to tell them apart: for Discipline Priest it pulled in 591 rows, including things like "Aberrant Spellforge" and "9.0 Hearthstone Test." Restricting to just the selected talents is also a more literal match for what was actually asked ("pulls the talents from the system default") and gives a correctly-scoped, honest result instead of a noisy one. Same known limitation as `buildKitSpellIdsFor()`'s existing `source='baseline'` usage elsewhere in this service — not a new bug, just newly load-bearing here since this page uses baseline-availability as the primary list instead of only a modifier-candidate pool.

**Verified**: both pages render correctly (`Livewire::test()`), the Oracle module's Mind Blast `28s` fix and category headings survived the component extraction unchanged, no regressions in `ModuleSpellReferenceServiceTalentGatingTest`.

## In-Game Spellbook Verifier — Phase 1 ✓ COMPLETE (2026-08-02)

The trusted-source verification layer described in `spellbook-verifier.md` (repo root — read that file for the full plan, including what Phase 2 will add). Existing verification matched imported spell data by **name**, which is ambiguous (multiple "Penance" rows, only some real) — this pipeline cross-checks by **spell id** against what a real character's client actually shows, using an addon export as ground truth rather than another offline guess.

**Three parts, all built and confirmed live** (2026-08-02, Discipline Priest, patch 12.0.7.68887):
1. **`tools/wow-addon/MindCollectorExport/`** — a WoW addon (`/mcexport` slash command, `.toc` + `main.lua` + `README.md`). Exports the logged-in character's spellbook, selected talents, and known PvP talents to `MindCollectorExportDB` SavedVariables, including resolved (spec/talent-conditional-evaluated) description text and the character's official Blizzard talent loadout export string. Every API call in `main.lua` has been live-verified — two real bugs found and fixed in the process: the loadout-string call is `C_Traits.GenerateImportString(configID)`, not `GenerateInspectImportString` (that one's for inspecting *other* players — silently returns `""` on your own configID, no error); PvP talent enumeration needed a fixed 4-slot loop checking each slot's own `enabled` field (no `GetNumPvpTalentSlots` function exists) plus the global `GetPvpTalentInfoByID(id)` (11 positional returns, not a `C_SpecializationInfo`-namespaced table call). See the addon's README for the full trace.
2. **`spellbook_snapshots` / `spellbook_snapshot_entries`** tables + models — append-only (a new export is always a new snapshot, never an update), imported via `php artisan wow:import-spellbook {path}` (idempotent by SHA-256 content hash of the source file). Class/spec are stored as the addon's **raw** export (string token, Blizzard numeric spec id) rather than resolved to local `classes`/`specializations` FKs at import time — resolution happens at diff time instead, so an import never fails just because local reference data hasn't caught up to a new patch. `SavedVariablesLuaParser` (`app/Http/Services/`) is a small dedicated recursive-descent parser for the restricted Lua-table subset SavedVariables files use — no general Lua parser exists in the repo/vendor, and none was needed.
3. **`php artisan wow:diff-spellbook {snapshot_id?} {--json}`** — resolves the snapshot's class/spec against `classes`/`specializations` and diffs in both directions against `spell_class_availability` for the resolved patch: Direction A (`MISSING_SPELL` / `MISSING_AVAILABILITY` — spellbook entries not correctly represented in the DB, the real alarm) and Direction B (`NOT_IN_SPELLBOOK_CANDIDATE` — DB rows claiming availability that the export didn't see, informational only, since passives/auras/procs legitimately aren't spellbook entries). Fully deterministic, no AI calls, writes nothing to any existing table — print/flag only, same posture as every other "flag, don't guess" pass in `game-data.md`.

**Descriptions are captured, not diffed.** `resolved_description` on each snapshot entry is build-specific ground truth (spec conditionals resolved, talent modifications baked in). Never copied onto `spells` or any general table. **Phase 2 redefined 2026-08-02** (corrects the original plan text in `spellbook-verifier.md`, which described a website-side description-template resolver — that idea is **cut**, not deferred, unless arbitrary-build tooltips become a real need): what Phase 2 actually is now is wiring the site's existing talent-build resolver (`TalentSelectionService::resolveActiveBuild()`/`resolveBuildForModule()`, see "Talent-Aware Spell Data"/"Module-linked talent builds" above) to look up `resolved_description` from a matching snapshot when one exists for that exact build, instead of computing text from a template engine that was never built. **Built same day** — see "Snapshot-backed descriptions (Phase 2)" below.

## Snapshot-backed descriptions (Phase 2 of the spellbook verifier) ✓ COMPLETE (2026-08-02)

Surfaced by a real comparison: Penance's own `spells.description` reads `Launches a volley of holy light... causing $<penancedamage> Holy damage...` — an unresolved SimC formula, because the real number depends on the caster's own Spell Power, which nothing on this site has or can compute. `ModuleSpellReferenceService::resolveDescription()`'s existing template resolver (conditional-branch substitution, `${...}` arithmetic evaluation) was already built and already handles what it structurally can — this is a different, harder gap it can never close on its own. The real, in-game export for the same character already had it fully resolved: `"...causing 20,630 Holy damage to an enemy or 33,275 healing to an ally over 1.7 sec... healed for 437."`

**`talent_builds.spellbook_snapshot_id`** (nullable FK, migration `2026_08_02_000003`) — which real character export (if any) a build was decoded from. Nullable and unset for the overwhelming majority of builds (admin defaults hand-picked via `/admin/talent-builds`, personal builds assembled by clicking through the picker) — those keep using the template resolver exactly as before, no behavior change. Set explicitly, not inferred — `TalentBuild::find(1)->update(['spellbook_snapshot_id' => 1])` for the Discipline Priest Oracle module's linked build, matching the same real snapshot it was already decoded from earlier the same day (see "Module-linked talent builds" above). No UI to set this yet (same gap as `getOrCreateModuleBuild()` having no import UI, noted in the spellbook verifier section) — a manual, one-time link for now.

**`TalentSelectionService::resolvedDescriptionsFor(TalentBuild $build): Collection`** — `spell_id => resolved_description` map from `spellbook_snapshot_entries` for the build's linked snapshot, empty collection when unset. `Modules\Show::getModuleSpellReferencesProperty()` checks this map first per spell and only falls back to `ModuleSpellReferenceService::resolveDescription()`'s template resolver when the snapshot has nothing for that spell_id — snapshot-backed text always wins when available, since it's strictly more accurate (a real number beats "varies by condition").

**Verified**: Penance now renders the real resolved text (confirmed via `Livewire::test()`), zero regressions in `ModuleSpellReferenceServiceTalentGatingTest`. Only affects modules whose linked build has a snapshot set — everything else renders identically to before.

**Verified end-to-end** against the real DB (patch `12.0.7.68887`, Discipline Priest): migration + rollback + re-migrate clean; import creates a snapshot with correct entry counts; re-import of the same file skips as a duplicate with zero new rows; diff against real spell data correctly flagged two real fixture gaps (`MISSING_SPELL`/`MISSING_AVAILABILITY` on spell ids that don't have vetted-accurate real-world numbers in the hand-written test fixture — expected, not a tool bug) and 70 legitimate `NOT_IN_SPELLBOOK_CANDIDATE` hits (passives/procs/hero-tree entries). `tests/Feature/SpellbookImportAndDiffTest.php` — all green.

**Real export run (snapshot #2, same character/patch) surfaced two genuine findings**, both worth knowing before reading future diff output:

- **`MISSING_AVAILABILITY` includes false positives from spec history, not just real DB gaps.** The real diff flagged ~24 Holy/Shadow Priest spells (Holy Words, Prayer of Healing/Mending, Mind Flay, Shadowform, Voidform, etc.) as available in-game but untagged for Discipline in `spell_class_availability`. Confirmed with the player: this character has respecced into/browsed Shadow and Holy before — WoW's spellbook enumeration (`C_SpellBook.*`) includes spells the character has ever known, not just the active spec's kit. **This is not a DB bug.** Anyone reading `wow:diff-spellbook` output for a character with any respec history should expect this kind of noise in `MISSING_AVAILABILITY` and cross-check against the character's actual spec history before treating a hit as a real gap — the tool has no way to distinguish "never tagged" from "known from a past spec" on its own (out of scope for Phase 1; a future improvement could special-case this if it becomes a recurring nuisance).
- **`not_in_spellbook` misses a second phrasing** (`Do Not Display (Spellbook, ...)` vs. the already-handled `Not In Spellbook (143)`) — a real Penance internal-duplicate `spell_id` (`197419`) slipped through and showed up as a `NOT_IN_SPELLBOOK_CANDIDATE`. The real, player-facing Penance (`47540`) is unaffected and correctly resolves. See `game-data.md`'s "`not_in_spellbook` misses a second phrasing" section for the full trace. **Not fixed, but not permanently blocked either** — `SpellDataFileParser`/`ImportSpellData` were scoped *out of this plan* (`spellbook-verifier.md`'s Out of Scope list), the same way every other completed feature in this file lists files it deliberately didn't touch. That is a different, weaker restriction than the two genuinely permanent no-touch files (`QuizRunner.php`, `AiService.php`, see Critical Rules above) — this Penance fix is a legitimate small standalone task, safe to hand to a future session whenever wanted, not blocked by anything this plan established.

**Baseline snapshot #2 is the pre-fix reference — do not treat it as disposable.** It captures a clean Discipline kit plus this one precisely-diagnosed `not_in_spellbook` gap, from before that fix exists. Snapshots are already append-only/immutable by design (a new `/mcexport` + import always creates a new row, never overwrites), so it's safe from being clobbered by a future export — but once the `Do Not Display (Spellbook, ...)` fix above does land, re-diff *this exact snapshot* to prove it: `php artisan wow:diff-spellbook 2` (not the argument-less default, which diffs the *latest* snapshot instead). Confirms the fix worked with zero new in-game steps.

**Locked files respected:** zero changes to `QuizRunner.php` or `AiService.php`. Zero changes to `ImportSpellData`/`SpellDataFileParser`/`resolveBaselineSpecIds()` — this plan only reads what they produced.

## murlok.io default-build importer ✓ COMPLETE (2026-08-03)

Populates a spec's admin-curated default `TalentBuild` (`is_default = true`, same row `Admin\TalentBuildEditor` writes to by hand) from murlok.io's public per-spec/bracket guide page, instead of requiring an admin to click through every talent one at a time.

**Why not the obvious approaches — both real dead ends, not just inconvenient:**
- Murlok's per-character "Copy talents" button (the actual Blizzard export string) is generated client-side by a **WebAssembly module** (`wasm_exec.js`) — nothing in the raw HTTP response contains it. Getting it would require driving a real headless browser (not available in this environment) or reverse-engineering the compiled WASM.
- Going straight to **Blizzard's own Character Specializations API** (read a top-rated character's talents directly, official/licensed data) is blocked at the source: that endpoint's `loadouts` field has been confirmed broken/missing since patch 11.2, unresolved as of the most recent Blizzard forum activity found. This is the actual reason murlok itself needs a player-run addon (`MurlokExport`) for character-specific data — even they can't pull it from Blizzard's API anymore.

**What is scrapable, and what this reads instead:** murlok's per-spec/bracket **guide** page (e.g. `murlok.io/death-knight/unholy/3v3` — not a character page) is plain server-rendered HTML: a heatmap of pick counts (0–50, "top 50 players in this spec/bracket") per talent, plus a labeled hero-tree section and a flat PvP-talents section. No WASM involved, confirmed via direct `curl` before any code was written.

**`MurlokTalentImportService`** (`app/Http/Services/MurlokTalentImportService.php`) — rather than trying to infer murlok's own grid layout or choice-node pairing from its markup, it reads OUR OWN `talent_trees`/`talent_nodes`/`talent_node_entries` structure (already correctly imported from Blizzard's Game Data API) and asks, per node, "what pick count did murlok report for each of this node's possible spells?" — matched purely by spell name. This sidesteps needing to parse murlok's DOM grid/choice structure at all.
- `preview(Specialization, string $bracket = '3v3')` — fetches (`Http::withHeaders(['User-Agent' => ...])->timeout(15)->retry(2, 1000)`), parses via `DOMDocument`/`DOMXPath`, resolves against our own class/spec/hero trees + `pvp_talents`, and returns a full report (selected/total per tree, resolved hero tree name, top-4 PvP picks, and every name that couldn't be matched — never silently dropped). Writes nothing.
- `apply(array $preview, TalentSelectionService)` — only called explicitly, after reviewing a preview. Replaces (not appends to) the build's existing choices, same "replace not append" precedent as `RoadmapService::persistStagesForUser()`/`TalentSelectionService::syncPvpChoices()`.
- murlok URL slugs are derived as `Str::slug($class->name)`/`Str::slug($spec->name)` at call time, not our own stored `classes.slug` column — confirmed those two disagree (`classes.slug` for Death Knight is `deathknight`, no hyphen; murlok's URL is `death-knight`). A wrong derived slug fails loudly (HTTP error from `fetch()`), it doesn't silently 404 into an empty page.

**Real bug found and fixed while verifying against live data, before ever writing to the DB — do not regress:** murlok's guide page renders **every hero tree available to the spec on the same page**, not just "the" meta pick — confirmed live: Unholy DK 3v3 shows both San'layn (0 picks across all 14 talents in that bracket) and Rider of the Apocalypse (50/50 picks across all 14) as two separate `div.hero` blocks, each with its own `<h3>`. An earlier version of `parseHeroBlocks()` (then just `parse()`) read only the *first* `<h3>` under `#talents-hero`, which would have silently resolved to whichever tree happens to render first in the markup — San'layn, the *wrong* one, 0 real players — regardless of which tree top players actually use. Fixed: the service now finds every `div.hero` block, and `preview()` picks the one with the highest aggregate pick count across its own entries as the meta tree.

**A second, separate finding surfaced by this work — flagged, not fixed, per [[feedback_trace_dont_shotgun_patch]]:** cross-referencing real per-talent ground truth (murlok's per-section pick data) against our own `talent_node_entries` for the first time exposed a pre-existing data-quality issue in the talent-tree import, unrelated to this scraper: several Rider of the Apocalypse hero-tree talents (Rider's Champion, Mograine's Might, Nazgrim's Conquest, and others) and San'layn's Desecrate are **also** attributed to the Unholy/Frost/Blood *spec* trees in `talent_node_entries` — confirmed directly via DB query, present in multiple `talent_trees` rows simultaneously (spec AND hero) for the same spell. This does not corrupt anything `apply()` writes — a talent only ever gets selected when murlok's page shows it in *that specific tree's own section*, so the spuriously-duplicated spec-tree copies never get chosen (murlok's spec-tree section correctly never lists them) — it only shows up as extra noise in `preview()`'s unmatched-names log. Root cause not investigated (likely the same category of bug as the already-fixed "class-tree bloat" issue, but in the opposite direction — spec trees absorbing hero-tree nodes rather than class trees absorbing spec nodes) — worth a future pass through `fetch-talent-trees.php`/`ImportSpellData`'s tree-node import if it turns out to matter beyond log noise.

**`ImportMurlokDefaults` console command** (`wow:import-murlok-defaults {spec} {bracket=3v3} {--class=} {--apply}`) — always previews first; `--apply` is required to actually write, same preview-before-trust posture as `TalentSelector`'s Blizzard-string import flow. One spec per invocation, manual/on-demand only — nothing scheduled or automatic, out of respect for hitting a third-party site (murlok's `robots.txt` allows crawling, but that's not the same as license to run this unattended or in bulk).

**Verified end-to-end** (Unholy Death Knight, bracket `3v3`): class 38/47, spec 32/65, hero 14/14 (correctly resolved to Rider of the Apocalypse after the fix above), PvP talents Necrotic Wounds/Spellwarden/Strangulate/Bloodforged Armor (all real top-tier picks) — applied to `TalentBuild #2`, confirmed via direct DB query: 84 PvE choices, 4 PvP choices, correct spell names. Full test suite: zero new regressions, same 12 pre-existing unrelated failures as every check earlier this session.

**Not built:** a loop over all ~39 WoW specs in one command (deliberately scoped to one spec per invocation — bulk-running this unattended against a live third-party site wasn't something to default to without checking in first); an admin UI button wiring this into `Admin\TalentBuildEditor` directly (console-only for this first pass, but `MurlokTalentImportService::preview()`/`apply()` are already UI-ready — a future admin button is a small addition on top, not a redesign).

**This policy is now load-bearing for `wow:patch-update` too (added 2026-08-07, see below) — that orchestrator deliberately does NOT loop this command across specs, for the exact reason stated above.**

## Patch update orchestration — `wow:patch-update` (added 2026-08-07)

Compresses the mechanical half of a WoW patch data update (see this file's earlier "Patch 12.1 Readiness" analysis, delivered as an artifact, for the full reasoning) into one command: `php artisan wow:patch-update {build} --branch=... --current`. Runs, in order — `fetch-talent-trees.php` → `fetch-simc-dumps.php` (new, see below) → `regenerate-filtered.php` → `import:spelldata` → `fetch-spell-icons.php` → `wow:diff-spellbook` (report only, against every snapshot on file). Each step shells out via `Illuminate\Support\Facades\Process` and streams output live; a failure at the filtered-dump-regeneration step halts before the database import, everything else just reports and continues.

**`data/spelldata/fetch-simc-dumps.php`** — new script, closes the one gap in the pipeline that had no automation at all: downloading SimC's per-class raw dumps. Pulls `SpellDataDump/{class}.txt` from a given branch of `github.com/simulationcraft/simc` via `raw.githubusercontent.com`. `--branch=midnight` for the current expansion's default branch; `--auto-detect-live` queries GitHub's branches API and picks the highest-numbered `data-update-live-*` branch, failing loudly (not guessing) if none exists — relevant when a patch hasn't shipped yet, since only `data-update-test-*` (PTR) branches exist at that point and this deliberately won't silently fall back to treating PTR data as final.

**Deliberately NOT folded into this orchestrator, and not planned to be:**
- `wow:import-murlok-defaults` — see the policy directly above this section. Looping it across every spec here would silently reverse a decision already made once for a documented reason (hitting a live third-party site unattended, in bulk). The command prints this as a manual checklist item instead, one spec at a time, same as always.
- Module-linked talent-build re-decoding — bounded to 1-2 modules, needs a human judgment call about whether the affected spec's tree actually moved enough to warrant it.
- git deploy and actually looking at the live site — outside any script's reach.

**Verified 2026-08-07** via a real, non-destructive end-to-end run (`--only=priest`, all fetch steps skipped, no `--current`): produced a correctly isolated new `patches` row without touching or demoting the real current patch, `baseline-spec-overrides.txt` entries for classes outside the `--only` scope skipped cleanly with warnings (never errored), and the `wow:diff-spellbook` step ran automatically against the one existing snapshot. Test patch row deleted afterward (cascade-deletes its child spell/talent rows via `patch_id`'s `cascadeOnDelete()`) — confirmed the real `12.0.7.68887` patch was undisturbed and still `is_current`. Full test suite: same 12 pre-existing failures, zero new regressions.

**One incidental finding surfaced by this test run, not yet investigated:** the current `midnight` branch's raw Paladin dump now includes a `havoc.txt` file with exactly one spell record — possibly an early, inert tease of a fourth Paladin spec, possibly leftover/placeholder noise in SimC's own dump. Not acted on; flagged here in case it's a real signal once actual patch notes confirm one way or the other.

## Export-string generation attempted, confirmed broken — pulled from UI, 2026-08-03

A same-day follow-up to the murlok importer above: the user wanted a way to load a murlok-derived default build into the real game client for verification, so `BlizzardTalentStringCodec::encodeBuild(TalentBuild, Specialization, patchId)` was added — the first attempt in this codebase at the *inverse* direction of the already-verified `decode()` (structured DB choices → a real Blizzard "Export" string, rather than string → DB). This is now confirmed broken and has been removed from the one place it was wired up (Spell Explorer's export-string display) — the method itself is left in place, docblock rewritten to say plainly that it's broken, as a starting point for whoever picks this up rather than a working feature.

**How it was "verified" before shipping, and why that verification was worthless:** the only check run was encode → decode the result with this class's own `decode()` → compare selections. It passed cleanly (95/95 choices matched for the Arms Warrior build) and shipped on that basis. **This was a self-referential test, not real verification** — `encodeBuild()` and `decode()` both walk the same `orderedNodesForSpec()` node list, so a bug in that shared list is invisible to a round-trip through both of them; the test could only ever catch bugs in `encodeBuild()`'s own logic, not bugs it inherits from something `decode()` already relies on.

**What actually caught it: the user imported the generated string in-game and screenshotted the result.** The hero tree showed as completely unresolved (neither of the two options selected, despite the build having a specific one chosen) and both class and spec trees showed far fewer points spent than the build actually has. Real, concrete, non-self-referential evidence the string was wrong.

**A stronger check that should have been run BEFORE shipping, run after the fact instead:** `TalentBuild #1` (the Discipline Priest Oracle module's linked build) was originally decoded from a real, addon-captured loadout string, still on hand in `spellbook_snapshots.loadout_string`. Re-encoding that same build's choices with `encodeBuild()` and comparing byte-for-byte against the real original: real string = 109 characters, `encodeBuild()`'s output = 81 characters — **28 characters (~168 bits) of real content missing**, despite both strings still decoding to the identical 54 selections through this class's own `decode()`. This is the same failure mode the user's screenshot showed, just reproducible in seconds with no in-game round trip needed — and it was available the entire time `encodeBuild()` existed, just not used until after the feature had already been shipped to the user once.

**Most likely root cause, not yet confirmed further:** our own `talent_nodes` data for a spec is probably incomplete relative to the real game client's total node count for that spec — `orderedNodesForSpec()`'s node sequence would then run shorter than what a real client expects, and everything after the shortfall reads as garbage/unselected. This is consistent with the "class-tree bloat" and "spec tree polluted with hero-tree entries" data-quality issues already found and documented elsewhere in this file — another sign the underlying `talent_trees`/`talent_nodes`/`talent_node_entries` import has more gaps than previously known. Not investigated further — whoever resumes this should start by comparing `orderedNodesForSpec()`'s node count against a real total (e.g. walking the raw Game Data API talent-tree response node-by-node) rather than guessing at the bitstream format again.

**What's still true and unaffected by this bug, so it doesn't get lost in the noise:** the murlok importer's own data (`talent_build_choices`/`talent_build_pvp_choices`, what `<x-spells.table>` actually renders) was independently validated by the user's own in-game comparison and holds up — Sharpen Blade present, Duel absent, Disarm present, Dragon Charge absent, Safeguard present, all matching. The bug is specifically in the export-string round trip, not in the imported talent selections themselves. Also worth remembering for next time: **PvP talent choices are never part of the export string format at all** (Blizzard's own client limitation, correctly respected by `encodeBuild()` and unrelated to this bug) — any PvP talents visible after an import were already on the character beforehand, not something the string set, so they can't be used to validate anything about this feature specifically.

**Removed:** the export-string `<input>` block and `SpellExplorer::getExportStringProperty()`. **Left in place, marked broken:** `BlizzardTalentStringCodec::encodeBuild()` itself, `orderedNodesForSpec()` (shared with the still-trusted `decode()`, untouched).

## Spell icons: self-hosted, filename-keyed ✓ COMPLETE (2026-08-05)

`spells.icon_name` (nullable string, migration `2026_08_05_000001`) stores just the icon **filename** (e.g. `spell_shadow_unholyfrenzy.jpg`), never a remote URL — deliberate, so nothing in this codebase can hotlink Blizzard's live CDN. The actual image files are self-hosted at `storage/app/public/spell-icons/`, fetched once via `data/spelldata/fetch-spell-icons.php` (a plain non-Laravel-bootstrapped CLI script, same convention as `data/talenttrees/fetch-talent-trees.php` — reuses that script's exact OAuth/retry pattern against the same already-configured `BLIZZARD_CLIENT_ID`/`BLIZZARD_CLIENT_SECRET`). Source: Blizzard's Game Data API `GET /data/wow/media/spell/{spellId}` — confirmed live before building anything (not assumed from docs): returns `{"assets":[{"key":"icon","value":"https://render.worldofwarcraft.com/us/icons/56/{filename}"}]}`; the filename is `basename()` of that URL. SimC's spelldata dump was checked and confirmed to carry no real icon filenames — only occasional `$@spellicon<id>` inheritance-pointer tokens in description text (same syntax family as `$@spelldesc<id>`), never parsed by `SpellDataFileParser` and out of scope here.

Target set is every spell_id referenced by `talent_node_entries` + `pvp_talents` (3,406 distinct spells.id) — the full ceiling, not an incremental subset, fetched in one pass. Idempotent: re-running skips any spell that already has `icon_name` set and any file already on disk, so a later top-up (a patch adds new talents) only fetches what's new.

**A real bug was found and fixed the same day it was built, via the same "don't trust a self-reported pass, verify against real numbers" discipline already established throughout this file.** The task was delegated to a Haiku-tier subagent (per user instruction — this project's workflow runs analysis/verification on a stronger model and routine, fully-specified builds on a cheaper one). Its first pass self-reported success — but its own numbers were internally contradictory (claimed 3,357 spells "already processed" on what should have been a first run, yet only 39 files existed on disk) and its own verification check technically "passed" while comparing two numbers nowhere near the expected scale (39 vs the ~1,500–2,000 specified). Caught on review, not trusted at face value.

**Root cause:** `talent_node_entries.spell_id` and `pvp_talents.spell_id` are foreign keys to `spells.id` (the internal auto-increment PK) — that's how Laravel's `foreignId('spell_id')->constrained()` resolves in this schema — **not** to `spells.spell_id` (Blizzard's numeric ID), despite the column name suggesting otherwise. The agent's script queried `spells.spell_id IN (...)` using values that were actually `spells.id`s. Since `spells.id` is a small sequential range (1..~12,527) and `spells.spell_id` is Blizzard's large arbitrary numeric ID space, only a handful of rows spuriously matched by coincidence — explaining both the tiny real count and the agent's own confused "already processed" tally (it was quietly re-running against the same accidental ~50-row subset each time, not the real ~3,400). Fixed by querying `spells.id IN (...)` directly — the values collected from those two tables already *are* the primary keys, no secondary lookup needed at all.

**Verified end-to-end after the fix**, via a direct full-table query (not the script's own last-run-only in-memory stats, which have a separate, milder flaw — they only ever compare *the current invocation's* unique filenames against the *total* directory file count, so they're only meaningful on a single one-shot full run and give a false-negative "mismatch" on any subsequent incremental/retry run; not fixed, since the authoritative check belongs outside the script anyway): **3,406 / 3,406** target spells have `icon_name` set (100% coverage, zero remaining), collapsing to **2,185** unique icon files — `SELECT COUNT(DISTINCT icon_name) FROM spells WHERE icon_name IS NOT NULL` and the actual file count in `storage/app/public/spell-icons/` match exactly. (Higher than the ~1,500–2,000 originally estimated — dedup collapsed less than guessed; the measured number is what matters, not the estimate.) A first full run hit 215 transient DNS resolution failures partway through (`Could not resolve host: us.api.blizzard.com`, not a systematic bug) — a second idempotent run cleanly picked up exactly those 215 and all succeeded.

**Display layer built same day, immediately after the data pass above** — `<x-spell-icon :spell="$spell" size="w-8 h-8"/>` (`resources/views/components/spell-icon.blade.php`) renders an `<img>` from `Storage::disk('public')->url("spell-icons/{$spell->icon_name}")` when `icon_name` is set, or a plain placeholder `<div>` (never a broken `<img>`) when it isn't — covers the real "hidden/internal spell with no Blizzard-side icon" case, not just a hypothetical one. Wired into `<x-spells.table>`'s Spell column, to the left of the name/spell_id block (icon sits *before* the Description column, matching what was actually asked for). Required `php artisan storage:link` (hadn't been run before — the symlink from `public/storage` to `storage/app/public` didn't exist, so nothing under that disk was web-reachable at all until now). No changes to `ModuleSpellReferenceService` or any other service — `icon_name` is a plain column already present on every eager-loaded `Spell` model passed into the table, so the component reads it directly, no new query/service plumbing needed.

Verified against real data, not just "it compiles": rendered the actual Arms Warrior default build (94 spells) and confirmed all 94 produced real `<img src>` URLs that resolve to files that actually exist on disk (checked every one, not a sample) — plus separately confirmed the placeholder `<div>` path (no `<img>` tag at all) for a real spell with `icon_name IS NULL`. `$@spellicon<id>` token rendering remains out of scope, as originally planned — this only builds the icon-per-spell primitive, not description-text token substitution.

**Real bug reported and fixed the same day: images were broken in the actual browser despite every automated check above passing.** Root cause — the component originally built the `<img src>` via `Storage::disk('public')->url(...)`, which builds an **absolute** URL from the static `APP_URL` config value (`http://localhost` in `.env`). Herd almost certainly serves this project on a different local domain in the browser (it auto-parks every subfolder of `C:\Users\chris\Herd` under its `.test` TLD, per Herd's own config — so `chonny.test`, not `localhost`), so every generated image URL pointed at the wrong origin entirely, even though the page itself loaded fine (server-rendered HTML has no such host dependency). This is exactly why the earlier verification passed cleanly — it checked that URLs resolved to real files *on disk*, which they did; it never checked that the URL's *host* matched the page's actual serving domain, because that can only surface in a real browser, not a CLI render. **Fixed** by switching to a host-relative path (`/storage/spell-icons/{name}`, no scheme or host at all) — resolves against whatever domain actually loaded the page, immune to any `APP_URL`/real-serving-domain mismatch regardless of local dev environment setup. Worth remembering for any future asset URL in this codebase: prefer host-relative paths over `Storage::disk()->url()`/`asset()`-style absolute URLs unless there's a specific reason (e.g. a different asset CDN) to need an absolute one.

**Deploy checklist for production, confirmed by direct inspection (2026-08-05) — the host-relative URL fix above needs no further code changes to work on live, but three things are NOT carried by `git push` and must happen on the server itself:**
1. `php artisan migrate` — adds the `icon_name` column (standard, additive, safe).
2. `php artisan storage:link` — the `public/storage` symlink is explicitly gitignored (`.gitignore:19`) and is environment-specific; without running this on the live server too, every `/storage/...` URL 404s even though the code is correct.
3. Run `data/spelldata/fetch-spell-icons.php` directly on the production server — the 2,185 actual icon files are also gitignored (`storage/app/public/.gitignore` excludes everything but itself, standard Laravel default) and the `icon_name` column will be NULL for every row on a fresh production DB until this runs there. Same pattern as every other raw-data-fetch script in this codebase (`fetch-talent-trees.php` etc.) — each environment populates its own copy independently rather than one environment's output being copied to another.

**Icon-fetch target set extended, 2026-08-06** — the script originally only ever looked at `talent_node_entries` + `pvp_talents` for its target spell list, which meant Leg Sweep/Freezing Trap/Hammer of Justice (the new `verified_override` baseline abilities, see "Baseline ability display" above) had no icon at all — confirmed live: `icon_name` was `NULL` for all three despite them now rendering correctly everywhere else. Fixed by adding a third source query (`spell_class_availability WHERE source = 'verified_override'`) to the target-set union. Re-running the script after this fix is safe/idempotent (skips anything already fetched) — but note it needs re-running again on **every environment, including production**, each time a new line is added to `baseline-spec-overrides.txt`, same "each environment populates its own copy" rule as above. The script's own end-of-run "counts DO NOT MATCH" check is a known false negative on any incremental run (only compares this run's file count against the *total* directory count) — not a real failure, already documented above.

Also requires `BLIZZARD_CLIENT_ID`/`BLIZZARD_CLIENT_SECRET` to be set in production's `.env` — found missing from the "Required Environment Variables" list while answering this same deployment question; added there. Confirm they're actually set on the live server before relying on this.

## Multi-rank talent magnitude scaling ✓ COMPLETE (2026-08-06)

Reported by the player: Discipline Priest's **Improved Fade** talent (2 ranks, "reduces Fade's cooldown by 5 sec" per rank) always showed a flat -5s modifier on the Spells section regardless of whether 1 or 2 points were invested — should be -10s at rank 2. Traced across all four layers involved, not patched at the symptom:

1. **Selection layer already worked.** `talent_build_choices` has both `chosen_entry_id` (the exact rank's `TalentNodeEntry`) and `rank`, and `TalentSelector`'s blade template already renders one clickable button per rank (`@foreach ($node->entries->groupBy('rank') as $rank => $entries)`) — picking a specific rank was already a real, reachable UI state, correctly persisted by `TalentSelectionService::saveChoice()`.
2. **The flattening step threw rank away.** `TalentSelectionService::selectedSpellIds()` reduced every pick to a bare `spell_id` set. Blizzard's own talent tree data gives Improved Fade's rank 1 and rank 2 the *same* `spell_id` (390670) — confirmed directly in `data/talenttrees/priest.json`, where rank 1's description says "...by 5 sec" and rank 2's says "...by 10 sec," both under identical `spell_id: 390670` — so picking either rank collapsed to an indistinguishable "390670 selected."
3. **`spell_effects` only ever stored one magnitude per spell_id** — whichever rank SimC's dump's single `Effects:` block happened to show (rank 1's -5000, in this case).
4. **The computation layer had no rank parameter at all.** `ImportSpellData`'s relationship-magnitude mapping and `ModuleSpellReferenceService`'s cooldown/charge resolution both worked purely off `spell_id`, with no way to know which rank was active even if one had been.

**The real per-rank data exists in SimC's dump, previously discarded.** A multi-rank talent's own `Talent Entry` line carries a continuation annotation SimC generates for exactly this case — e.g. Improved Fade: `Talent Entry : Generic [tree=class, ..., max_rank=2, ...]` followed by `Effect#1 [op=set, values=(-5000, -10000)]`. Two operators confirmed to occur across the dataset (162 spells use `op=set`, 250 use `op=mul`) — `set` means the listed value at that rank *is* the effect's value (replaces `base_value`); `mul` means `base_value` is multiplied by the listed value at that rank (e.g. `[op=mul, values=(1, 2)]`, a rank-2-doubles-the-base shape). `SpellDataFileParser::parseSpellRefs()` and the new `Effect#N [op=...]` regex now capture this, folded onto each effect record as `rank_op`/`rank_values` (new nullable `spell_effects` columns) at flush time — matched independent of line prefix, same trick as the existing `replace=`/`free=` handlers.

**Why magnitude had to move from import-time to render-time.** The obvious-looking fix — compute the "final" magnitude once at import time — is structurally wrong: which rank a build has selected is a per-build, per-viewer runtime fact, never a static one. `ImportSpellData::modifiesRelationshipMapping()`/`categoryRelationshipMapping()` now recognize a rank-scaled effect and still correctly classify the relationship's `relationship_type` (the type is knowable from the effect alone), but deliberately leave `modifier_value`/`modifier_unit` **null** — deferred, not guessed. A new `spell_relationships.effect_index` column (always persisted when known, populated by both import passes) is what lets the render-time layer re-find the specific effect later. `TalentSelectionService::selectedRanks(TalentBuild $build): Collection` (spell_id => rank, PvE picks only — PvP talents have no rank concept) is the new sibling to `selectedSpellIds()` that preserves what the flattening method throws away. `ModuleSpellReferenceService::resolveRankAwareMagnitude()` is the actual resolution: if a relationship already has a magnitude (the common, non-rank-scaled case), return it as-is with no extra query; otherwise look up the source's specific effect via `effect_index`, and if it's rank-scaled, compute the number for whatever rank `selectedRanks` says the current build has (falling back to the highest available rank if a confirmed-selected spell's rank can't be found, rather than showing nothing for a talent that *is* selected — flagged in the method's own docblock as a documented fallback, not a silent guess). `effectiveCooldown()`/`effectiveCharges()`/`modifiersFor()` all gained an optional trailing `$selectedRanks` parameter; both call sites (`Modules\Show`, `SpellExplorer`) now compute and thread it through alongside the existing `$selectedSpellIds`.

**A real bug caught during verification, not shipped:** the first version of the import-side fix classified the relationship type correctly but only *added* `modifier_value`/`modifier_unit` to the write when non-null — never explicitly clearing them. Every relationship pair already carrying a stale magnitude from before this fix existed (baked in by the earlier, rank-blind Hammer of Justice fix session) kept that stale number forever, since `upsertTrack()`'s `fill()` only touches keys actually present in the values array. Fixed by always writing both keys explicitly, even as `null` — confirmed by observing Improved Fade→Fade's relationship row actually flip from `-5.00` to `null` on re-import, not just staying unchanged.

**Verified end-to-end, not just unit-level:** built a throwaway `TalentBuild`, selected Improved Fade's rank-1 entry, confirmed `effectiveCooldown()` → 25s (30 base − 5); switched to rank-2, confirmed → 20s (30 base − 10). Independently, the *live* "Discipline Priest Fade & Death Matchup Timing" module (a real, already-existing linked build, not a test fixture) now renders Fade at **20s** — meaning that build has Improved Fade at rank 2, and the fix produces the correct number on real production data with zero test scaffolding involved. Every previously-documented worked example re-checked and still correct: Hammer of Justice 30s (Fist of Justice, from the prior session), Mind Blast 28s, Evangelism 45s, Intimidation 60s, Freezing Trap 30s. Full test suite: same 12 pre-existing, unrelated failures as every check this session — zero new regressions.

**Re-import performed without `migrate:fresh`.** Unlike the Hammer of Justice fix (which needed a `spell_relationships`-only truncate because the relationship_type itself was changing, colliding with the table's unique key), this fix only changes column *values* on rows whose identifying key (`source_spell_id`, `target_spell_id`, `relationship_type`) is unchanged — a plain `php artisan import:spelldata wow {patch}` re-run updates everything in place via `upsertTrack()`. No admin-curated talent build data was touched.

**Not built:** validating that a selected rank doesn't exceed a node's own `max_ranks` (same "no spend-order/budget validation" scope already documented for `TalentSelector`); a general rank-aware pass for effect types outside the two that already carry a magnitude (`Add Flat Modifier (107): Spell Cooldown` / the two Category effect types) — `rank_op`/`rank_values` are captured for every effect that has them regardless of type, but only consumed where a magnitude is already computed at all, same "flag, don't guess" scoping as everywhere else in this importer.

## `categorize()` accuracy pass: Mechanic field + sibling recovery ✓ COMPLETE (2026-08-06)

Prompted by two player-reported miscategorizations: Priest's **Mind Control** showed as Offensive (should be Crowd Control), and DK's **Anti-Magic Zone** wasn't showing as Defensive at all. Traced both to real, distinct root causes rather than patched by adding a keyword to the existing regex blind:

**Mind Control** — its core mechanic effect type is literally `Possess` in the Effects block, which `categorize()`'s old regex had no way to recognize; its incidental `Modify Damage Done%` side effect happened to match the Offensive pattern instead, winning by accident. The real fix: Blizzard's own raw data carries a `Mechanic : Charm` field on this spell's record — a single-value, authoritative classification (Stun/Root/Silence/Charm/Shield/Bleed/etc.) that `SpellDataFileParser` never captured before. Surveyed every distinct `Mechanic` value across the whole dataset (24 found, real counts from 60 down to 1) before mapping any of them, rather than guessing — see `ModuleSpellReferenceService::MECHANIC_CATEGORY_MAP`. New nullable `spells.mechanic` column; `categorize()` now checks it first, before falling back to the effect-type regex.

**Anti-Magic Zone** — a different bug, same *family* as every prior "spell split across multiple same-named `spell_id` records" finding in this file (Penance, Ultimate Penitence, Angelic Bulwark). Confirmed directly in the raw data: the talent-tree entry (`spell_id 51052`, the one Blood DK's default build actually has selected) has exactly one effect, `Create Area Trigger` — no categorizable signal at all — while a *separate* baseline record (`spell_id 50461`) carries the real `Absorb Damage` effect, and even points its own `Description` back at 51052 via `$@spelldesc`. Neither record has a `Mechanic` tag, so the Mechanic fix alone doesn't close this one. `categorize()` now falls back to the same same-named-sibling recovery `findEffectByIndex()` already uses for description resolution, but only when its own effects come back `'Other'` (avoids pulling in an unrelated same-named spell's effects for the common case where a spell's own data already categorizes fine).

**A third case checked and confirmed correctly `Other`, not a bug:** Anti-Magic *Barrier* — the player also flagged this as missing. It genuinely has no defensive effect of its own (`Add Flat Modifier: Spell Cooldown` / `Spell Duration` — it's a passive that boosts Anti-Magic Shell's cooldown/duration, not a shield in its own right). `categorize()` correctly has nothing to categorize here; the real gap is that a talent modifying another Defensive spell has no way to inherit that context, a relationship-aware categorization feature not built (see below).

**Quantified the remaining gap before declaring this "done," not just fixing the two reported cases and stopping:** surveyed every spell currently selected by any admin default build (2,485 distinct spells) and split `'Other'` by whether the spell is an *active* ability (has a cooldown/charges — shows under "Active Abilities," not "Buffs & Passives," the section that actually matters for this heuristic's usefulness) — 86 of 1,792 `'Other'` spells qualified. Inspecting all 86 by hand surfaced two more clean, high-confidence clusters, added to the regex: **`Interrupt Cast`** (7 confirmed real interrupts — Mind Freeze, Quell, Counter Shot, Muzzle, Spear Hand Strike, Rebuke, Wind Shear — none previously recognized, now Utility) and **healing/armor effect types** (`Direct Heal`/`Periodic Heal`/`Heal Max Health%`/`Modify Armor` — Swiftmend, Wild Growth, Lay on Hands, Riptide, Power Word: Radiance, Ironfur, etc. — filed under Defensive for the same reason `MECHANIC_CATEGORY_MAP`'s `Heal` entry already is: this dataset has no distinct "Healing" bucket, and every one of these in practice is a self/ally-preservation cooldown). Active-ability `'Other'` dropped from 86 to 60 after this pass.

**The remaining ~60 active-ability `'Other'` cases are genuinely ambiguous, not under-investigated — left alone rather than force a confident-looking label onto them:** overwhelmingly `Summon Guardian`/`Summon Pet` (a totem or pet could be offensive, defensive, or utility depending entirely on which one) and generic stat/haste/cooldown-modifier cooldowns (Trueshot, Power Infusion, Nature's Swiftness, Combustion) whose own effect types don't say enough to safely infer a bucket. Consistent with this heuristic's own documented posture (`categorize()`'s docblock) — "Other" is the honest answer for these, not a placeholder for more regex.

**Judgment calls, flagged rather than hidden** (per [[feedback_verify_before_flagging_gap]] discipline — investigate first, but some classification choices are inherently a judgment call even after full investigation, and those get documented, not silently decided): `Snare`/`Knockback` mechanics filed under Utility, not Crowd Control — movement-control tools used both offensively and defensively, not "hard" CC the way a stun/root/fear is; lumping every slow into the CC bucket would dilute it. `Heal`-flavored effects filed under Defensive, not a dedicated bucket that doesn't exist in this 5-category system.

**Verified:** direct spot-checks (Mind Control → Crowd Control, Anti-Magic Zone's talent-tree entry → Defensive via sibling recovery, Anti-Magic Barrier still correctly `Other`, all 7 interrupts → Utility, Swiftmend/Lay on Hands/Ironfur → Defensive) confirmed both via direct service calls and rendered live on the actual Spell Explorer pages (Shadow Priest, Blood DK). Category distribution across all 2,485 currently-selected spells shifted from `Other: 1792` to `Other: 1680` (112 fewer) with the two active-ability-focused additions layered on top of the Mechanic-field fix. Full test suite: same 12 pre-existing, unrelated failures as every check this session, zero new regressions. Re-import was additive only (`spells.mechanic` populated in place via `upsertTrack()`, no `spell_relationships` changes this time) — no admin-curated talent build data touched.

**Not built:** relationship-aware categorization (a talent that only *modifies* another spell inheriting that spell's category — would close the Anti-Magic Barrier case, but is a materially bigger change than a regex addition, and no second real-world case has surfaced yet to justify it); a dedicated "Healing" or "Summon" bucket (would require expanding the 5-category system itself, a product decision, not a data-accuracy one).

### Follow-up, same day: "Active Abilities" vs "Buffs & Passives" split was never about the category badge at all

Player follow-up after the fixes above: "why is [Mind Control, now correctly tagged] Crowd Control under Buffs and Passives though as its something you cast?" — a real, separate bug in `<x-spells.table>`'s top-level grouping, not the category badge. That split was driven by `$e['cooldown']['seconds'] !== null` — the component's own comment already flagged this as "a simple, deterministic stand-in... for a real buff/passive classification we don't have yet." Mind Control has no cooldown at all in real WoW (balanced by its channel time and diminishing returns, not a cooldown timer), so it always fell into "Buffs & Passives" regardless of being something you actively press on an enemy.

**Fix:** captured Blizzard's own `Passive (6)` Attributes marker — new `spells.is_passive` boolean, parsed the same exact-code-match way `not_in_spellbook` already is (same false-positive discipline: match the precise coded attribute, not a bare substring). `<x-spells.table>` now groups by `$spell->is_passive` instead of cooldown presence.

**Quantified before shipping:** across the same 2,485 currently-selected-spell set, **187 spells move passive → active** under the new rule (Mind Control among them) and **3 move active → passive**. Checked all 3 by hand rather than assuming the smaller number was safe by default: Duplicate, Time of Need, and Frozen Touch all carry a `cooldown_seconds` value *and* Blizzard's own `Passive` tag — real internal-cooldown procs (an ICD gating a passive trigger), not player-cast abilities. Both directions of the reclassification are genuine corrections, not one intended fix plus accidental collateral damage.

**Verified:** Mind Control confirmed rendering under "Active Abilities" on the live Shadow Priest Spell Explorer page (not just a unit-level check). Full test suite: same 12 pre-existing failures, zero new regressions. One operational note: this re-import run hit PHP's default 128MB CLI memory limit (`php artisan import:spelldata` alone, no flag) — needed `php -d memory_limit=512M artisan import:spelldata wow {patch}` to complete. Likely cumulative growth from this session's several consecutive full re-imports (rank_scaling, mechanic, is_passive, effect_index all added the same day) rather than a regression in any single change — worth using the raised memory limit proactively for the next full re-import rather than rediscovering this.

## Choice-node siblings shown greyed out ✓ COMPLETE (2026-08-06)

Player observation: talent choice nodes ("pick one of two") are common (Ultimate Penitence vs. Power Word: Barrier, Convoke the Spirits vs. Incarnation: Avatar of Ashamane), and the one *not* taken simply never appeared anywhere on a module or Spell Explorer page — no way to see "the road not taken" alongside the pick that was actually made. Quantified before building: **695 CHOICE-type `talent_nodes`** in the current dataset, **655** of them a clean two-distinct-spell-option pair at one rank — a genuinely common shape, not a rare edge case.

**`TalentSelectionService::choiceSiblingSpellIds(Collection $selectedSpellIds): Collection`** — for every CHOICE node with a currently-selected entry, returns the spell_id(s) of the option(s) *not* taken. Deliberately returned as a **separate** collection, never merged into `$selectedSpellIds` itself — an unpicked sibling must never be treated as if it were actually selected for modifier-gating purposes (`ModuleSpellReferenceService::modifiersFor()` gates a modifier's real-world effect on that exact set; folding a sibling in would make its own outgoing modifiers look like they're actually applying). Both `SpellExplorer` and `Modules\Show` merge this sibling set into their *display* id list only, computing cooldowns/modifiers/categorization against the real, untouched `$selectedSpellIds` throughout. Each rendered entry now carries `isSelected: bool`; `<x-spells.table>` applies `opacity-50` plus a small "Not selected" badge when false.

**A real, separate finding surfaced while verifying this on the Discipline Priest Oracle module page, not a bug in the feature itself:** both Ultimate Penitence *and* Power Word: Barrier rendered with "Not selected" — i.e. neither showed as the pick, when the module's whole premise centers on Ultimate Penitence as its flagship capstone. Traced directly: the module's *linked* `TalentBuild` (id=1, the one decoded from a real Blizzard export string and used all session for the Mind Blast/Evangelism cooldown verification work) has **no `talent_build_choices` row at all** for that specific CHOICE node (`talent_node_id` 2710) — 54 total choices recorded, none of them here. The **admin default** Discipline build (a separate row, id=28) does have a real pick there (confirmed: Ultimate Penitence), and Spell Explorer — which reads that build — renders the pair exactly as expected (Ultimate Penitence normal, Power Word: Barrier greyed). So the sibling feature is working correctly in both cases; what it surfaced is a genuine mismatch between what the Oracle module's prose teaches and what its specifically-linked real character's actual current talent picks are at this one node. Not fixed here — flagged, per this file's standing rule that a module's authored content only changes on the original expert's confirmation, and per [[feedback_verify_before_flagging_gap]]'s discipline of tracing a surprising result to its real cause before either dismissing or "fixing" it.

**One wording bug caught by that same investigation:** the "Not selected" badge's tooltip originally read "...its sibling option was picked instead" — true for the common case, false for the Oracle-module case just described (neither option was picked there). Fixed to a neutral "Not selected in this talent profile," accurate regardless of which of the two cases actually applies.

**Verified:** Spell Explorer (Discipline Priest, admin default build) — Ultimate Penitence renders normally, Power Word: Barrier renders at `opacity-50` with the "Not selected" badge. Feral Druid Spell Explorer — Convoke the Spirits/Incarnation: Avatar of Ashamane pair correctly shows **neither** (confirmed via direct query: the Feral admin default build has no `talent_build_choices` row at that node either — a separate, real curation gap in that build, not a bug). Full test suite: same 12 pre-existing failures, zero new regressions.

**Not built:** surfacing a CHOICE node's options when *neither* side has been picked at all (the Feral case above) — this feature only ever shows "the other option," which requires one option to already be selected; showing an entirely-unpicked node's both options is a different, broader feature not asked for here.

## WoW Comps page + baseline ability display — tried, reverted, DO NOT re-add (2026-08-06)

New `/wow-comps` page (`app/Livewire/WowComps.php`): pick 3 specs (Healer/DPS/DPS slots, labels only, no role enforcement), see each one's spell kit side by side for comparison instead of stacked full tables — grouped Active Abilities/Buffs & Passives → category (same order as `<x-spells.table>`), one column per member. Description column removed from the compact rows in favor of a click-to-open modal (a shared `x-data="{ openSpellId }"` backdrop with one hidden content block per entry — simplest correct approach for a shape-check page, not meant to scale to hundreds of modals forever). New "Main Cooldowns" panel per member: active, non-passive, cooldown ≥ 20s, top 3 by cooldown length — deliberately not restricted to `categorize()`'s Offensive/Defensive buckets, since real "main cooldowns" like Bloodlust/Power Infusion fall into its documented 'Other' bucket. This page is Phase-1-shape-check only — no `comps`/`spell_functions` tables, no seeding (see the earlier "Plan: Comps Page" discussion for what that fuller system would look like; not built).

**Same-day performance fix, unrelated to the section below:** the first version of this page took 45.8s / ~5,000 queries for a 3-spec render. Root cause was `ModuleSpellReferenceService` recomputing several per-build-invariant lookups (`buildKitSpellIdsFor()`, `buildTreeIdsFor()`, the baseline-aura checker, `resolveKitContext()`, `isConfidentlyInBuild()`) from scratch for every single spell instead of once per spec — fixed via per-instance memoization keyed by the actual inputs. A second, larger factor: `spells.name` (12,527 rows) had **zero index** despite being queried constantly by the same-named-sibling recovery paths — a full table scan, confirmed at ~49ms per lookup. Added a `(name, patch_id)` composite index (migration `2026_08_06_000004`). Combined: **45.8s/~5,000 queries → 4.7s/~1,560 queries**, verified with identical output before/after. Also speeds up `Modules\Show` and Spell Explorer, which share this same service.

### The baseline-ability bug, in full — read this before touching spell display logic again

**The report that started it:** Windwalker Monk's Leg Sweep and Beast Mastery Hunter's Freezing Trap were missing from both `/wow-comps` and `/spells` (Spell Explorer). Root cause: both pages only ever pulled spells from `TalentSelectionService::selectedSpellIds()` — literally just `talent_build_choices` + `talent_build_pvp_choices`, i.e. things a player *picks*. Leg Sweep and Freezing Trap were never talents to begin with (`spell_class_availability.source = 'baseline'`, never gated by any choice), so they were structurally invisible regardless of which build was selected.

**The fix that was tried:** `TalentSelectionService::alwaysAvailableAbilityIds(int $classId, ?int $specId)` — merged in `source='baseline'` spells filtered by `not_in_spellbook = false`, `is_passive = false`, real cooldown/charges data, and (the one genuinely novel finding) excluding any spell whose `name` carries a `(desc=...)` suffix — a disambiguation marker present directly in SimC's raw dump (confirmed in `data/spelldata/filtered/hunter/baseline.txt`, e.g. `"Growl (desc=Basic Ability)"`, `"Windburst (desc=Artifact)"`) that turned out to reliably flag pet-family abilities, Legion artifact remnants, Shadowlands covenant abilities, and rank duplicates — the exact noise an earlier, unfiltered attempt at this same idea (documented in Spell Explorer's original docblock) had rejected. This filter alone turned Hunter's 99 noisy baseline candidates into a clean 14. Two duplicate-name bugs found and fixed the same day (`preferSelectedPerName()`, collapsing e.g. 3 "Freezing Trap" rows and a talent-pick/baseline-copy split on "Mindbender"/"Bestial Wrath" down to one each).

**Why it was reverted, same day:** once wired in, Mind Sear — a Shadow Priest signature spell — appeared on **Discipline** Priest's kit. Traced to the actual root cause, not patched blind: Mind Sear's only `spell_class_availability` row is `spec_id = NULL`. Checked whether this was the same class of bug as the already-fixed Mind Blast case (a `free=(Discipline, Shadow)` restriction that `ImportSpellData` wasn't parsing yet) — it is **not**: grepped Mind Sear's raw entry directly and confirmed there is no `free=(...)` tag or any other spec-qualifying field on it anywhere in the source data, just a bare `Class: Priest` line. `spec_id = NULL` is structurally ambiguous — it means "genuinely class-wide" for some spells (Leg Sweep) and "spec-restricted, but Blizzard's own export never says so" for others (Mind Sear), and nothing in the schema distinguishes the two cases.

**The ground-truth check that actually resolved it:** this project already has a real, in-game data source for exactly this — the Spellbook Verifier (`spellbook_snapshots`, captured via the `MindCollectorExport` addon, see "In-Game Spellbook Verifier — Phase 1" above). `wow:diff-spellbook`'s existing Direction B only ever compared `spec_id`-**explicit** rows against a snapshot, so it structurally could never see a `spec_id = NULL` row — Mind Sear sat undetected in snapshot #1 (a real Discipline Priest export) despite the ground truth already being on hand. Added **Direction C** (`DiffSpellbook::directionC()`): same "in DB, not in game" comparison, scoped to `spec_id = NULL` rows, filtered by the identical `alwaysAvailableAbilityIds()` criteria (an unfiltered first pass returned 615 hits, almost all already-known junk; filtered, 25) and restricted to `source = 'baseline'` only (a `source='talent'` row's absence from one snapshot just means "wasn't picked," a completely different, non-actionable signal — mixing it in produced false triggers like Halo and Mass Dispel).

**Applying the correction was not a blind "reassign all 25 to Shadow" pass — several would have been wrong.** Read every candidate's own description text before touching anything (per [[feedback_trace_dont_shotgun_patch]]): Spirit Shell and one "Mindbender" copy explicitly reference Discipline's own Atonement/Penance mechanic (Mana-generating, not Insanity) — genuinely Discipline's, just not currently talented by this one build, not a spec mismatch at all. "Inner Shadow" explicitly boosts *Atonement healing* despite its Shadow-sounding name. One "Divine Star" copy explicitly deals Holy damage in its own text. Mindgames and Premonition of Clairvoyance are historically multi-spec (Premonition ties to the Archon hero tree, already flagged ambiguous elsewhere in this file). Ascended Blast is an inert Shadowlands-Covenant-era leftover (Anima damage), not a current spec assignment at all. Confession, Guardian of the Forgotten Queen, and one "Leap of Faith" copy had no corroborating mechanic text either way. **14 of the 25 had explicit, textual Shadow confirmation** (Insanity generation, Voidform/Void Eruption references, "Shadow damage" stated directly) and were corrected via a one-off `UPDATE` on `spell_class_availability.spec_id`: Shadowcrawl, Void Shift, Divine Star (the Shadow-damage copy), Voidform, Hallucinations, Mindbender (the Insanity copy), Void Bolt, Dark Ascension, Mind Sear, Dark Reprimand, Void Blast, Voidwraith, Void Volley, Void Shield. The other 11 were left untouched. **This correction is a manual one-off, same category as other hand-applied corrections in this file (e.g. `TalentBuild` → `spellbook_snapshot_id` links) — it will be wiped by a future `migrate:fresh` + re-import**, since nothing in the importer derives it; there's no structural signal in the raw data to derive it from, only the cross-referenced snapshot.

**The revert, requested after seeing the result live:** even after the above cleanup, the user's judgment (confirmed live on `/wow-comps`) was that showing baseline spells at all made the page noticeably worse than the pre-fix state where only talent picks showed — the gap it closed (Leg Sweep/Freezing Trap missing) was smaller than the damage a `spec_id = NULL`-derived guess can still do for any class/spec combination not yet manually audited this way. **Fully reverted the same day:**
- `WowComps::spellReferencesFor()` and `SpellExplorer::getSpellReferencesProperty()` both back to talent-selections-only (`$selected->merge($siblingIds)`, no `alwaysAvailableAbilityIds()` call, `isSelected` back to `$selected->contains($spell->id)` alone).
- `TalentSelectionService::alwaysAvailableAbilityIds()` **left in the file, unused, marked with a "DO NOT WIRE IN" banner** in its own docblock — the `(desc=...)` suffix finding is real, documented, and may be useful groundwork later, but the method itself must not be called from anywhere until there's a reliable per-spec resolution mechanism.
- The 14 `spell_class_availability.spec_id` corrections were **kept** (they're independently correct, verified against real ground truth, and harmless now that nothing displays baseline spells) — only the *display* feature was reverted, not the data quality fix.
- `wow:diff-spellbook`'s Direction C was **kept** — it's a read-only diagnostic command, not display code, and it's the actual mechanism a future real fix would need (get spellbook snapshots for more specs, run Direction C per spec, manually confirm each candidate's own text before writing anything — never bulk-apply from absence alone).

**Do not re-attempt "just merge in baseline spells" as a quick fix.** The `(desc=...)` filter and duplicate-name dedup are good, keep-worthy pieces of infrastructure — the specific thing that doesn't work is trusting `spec_id = NULL` as "safe to show for every spec of this class." Until the safe path below existed, `alwaysAvailableAbilityIds()` stayed unused — it still does; the fix that shipped is a different, narrower mechanism, not a resurrection of this one.

### Dead ends investigated, same day, before landing on the actual fix

Several more attempts to *derive* spec-membership automatically were tried and disproven — recorded so they aren't retried:

- **SimulationCraft's own GitHub repo** was searched directly (not guessed at) for a pre-resolved per-spec spell list, on the theory that SimC's maintainers must have already solved this to build their simulator. Found and downloaded `engine/dbc/generated/specialization_spells.inc` — real, correctly structured (`{classID, specID, spellID, ..., "Name", 0}`), but grepping the actual downloaded file confirmed it's a small curated set (564 entries total across all 13 classes, ~10-12 per spec — Mastery abilities, spec-identity passives, one or two headline spells) with zero coverage of Mind Sear, Leg Sweep, or Freezing Trap. Not the resolved spellbook the theory predicted; likely used internally by SimC for something narrower like spec identification from a combat log.
- **`wow-spell-data-model.md`** (added to the repo by the user, read in full) reframed the whole problem correctly: WoW's own data has no "spec membership" table at all — only grant *rules* (`SkillLineAbility` classMask = class-wide, `SpecializationSpells` = spec-specific grants, trait-tree nodes, overrides). Availability is a computed reachability answer over that rule graph, not a stored fact, which is exactly why `spec_id = NULL` is structurally ambiguous and not a parsing gap.
- **Cross-referencing `spell_relationships` against each class's spec-identity passives** (e.g. does Leg Sweep have a real, already-imported relationship row from "Windwalker Monk"/"Mistweaver Monk"/"Brewmaster Monk"?) looked promising at first — Leg Sweep and Freezing Trap both do. But tested rigorously against known answers before trusting it, and it broke in both directions: **Mind Blast** (confirmed Discipline+Shadow only, NOT Holy) shows relationship links from all three Priest identity passives including Holy — the same pattern as genuinely-universal Power Word: Shield. **Voidform/Dark Ascension** (confirmed Shadow-exclusive) show *zero* identity links — same as **Spirit Shell** (confirmed Discipline-exclusive). No discrimination in either direction; abandoned. Root cause guess: SimC's "Affecting Spells" text field looks like it's generated from spell-family-flag matching, not true grant/availability semantics.
- **A `cooldown ≥ N seconds OR hard-CC mechanic` pre-filter** (proposed as a way to narrow "baseline spells worth resolving" down to arena-relevant ones) was quantified across all classes: shrinks Priest's pool from 290 "plausibly real" candidates to 14, and does coincidentally exclude Mind Sear (1s recast timer, no hard-CC mechanic tag). But it doesn't resolve spec — the spells that remain after filtering are exactly the same hard cases that still need per-spell reading (Spirit Shell, Mindbender, Divine Star, etc.), and it has real collateral cost: it would exclude **Mind Blast** (9s, one second under a `≥10` bar) and **Void Bolt** (6s), both genuinely important. Separately confirmed the `mechanic` column itself has real coverage gaps — **Hunter's Binding Shot** (a real, significant arena stun) has neither a cooldown value nor a `mechanic` tag at all, so it's invisible to this filter regardless of threshold. Useful as a volume-reduction tool for *manual* review, never adopted as an automated gate.

### The fix that actually shipped, 2026-08-06: a hand-curated override file, not a heuristic

Given every automated derivation attempt failed (see above) or was reverted (Mind Sear), the only remaining option consistent with this codebase's "flag, don't guess" discipline was to stop trying to derive spec-membership at all and instead **record verified facts one at a time**, the same way `TalentBuild.spellbook_snapshot_id` or `Admin\TalentBuildEditor`'s hand-picked defaults already work in this codebase.

- **`data/spelldata/baseline-spec-overrides.txt`** — a new, permanent, version-controlled file (see its own header for the full rationale) alongside the existing `data/spelldata/` tree. Format: `spell_id | class_slug | spec_slug | name` (name is a human-readable comment only). Leg Sweep → Windwalker Monk is deliberately scoped to exactly what was verified, not expanded to "all 3 Monk specs" even though Leg Sweep is very likely genuinely class-wide too (the `spell_relationships` check above showed it linked to all 3 Monk identity passives) — that dead end's disproof (Mind Blast showing the same pattern while NOT being Holy's) means that signal isn't trustworthy enough to act on even for a spell that's probably fine. **Hammer of Justice and Freezing Trap are both the exception to "one spec at a time"** — Hammer of Justice was added for all 3 Paladin specs at once from the start (2026-08-06 follow-up report), since its universal availability is uncontroversial general knowledge. Freezing Trap was initially scoped to Beast Mastery only (matching the one test case in the original report) but a follow-up report ("doesn't show for Survival Hunter") caught that under-scoping — its own `spell_relationships` link to the generic class-wide "Hunter" identity passive (not a spec-specific one) was already sitting there as evidence it's universal, just not acted on the first time. Now covers all 3 Hunter specs. Lesson: when a baseline spell's evidence points to "genuinely class-wide," add all of that class's specs up front rather than matching only the one spec in the bug report — the same gap will otherwise resurface spec-by-spec as separate reports. Still verify before adding; don't flip this into "add to all specs by default."
- **`ImportSpellData::importBaselineSpecOverrides()`** — a new pass at the end of `handle()`, runs unconditionally (independent of `--only`, since it's small and fast). Reads the file, resolves each line against the current patch's real `Spell`/`GameClass`/`Specialization` rows, and writes an **explicit**-`spec_id` `spell_class_availability` row with a new fourth `source` value, `verified_override` — added *alongside* the spell's existing `spec_id = NULL` row, never replacing or narrowing it. Malformed or unresolvable lines are skipped and warned about, never guessed.
- **`spell_class_availability.source` enum extended** to include `'verified_override'` (migration `2026_08_06_000005`). **A real bug was caught here, same day**: the first version of this migration ran a raw MySQL `ALTER TABLE ... MODIFY COLUMN` unconditionally, which is invalid syntax on SQLite — and `phpunit.xml` runs the entire test suite against an in-memory SQLite DB via `RefreshDatabase`, so that one statement broke migrations for literally every test (167 failures, not the usual 12). Fixed to branch on `DB::connection()->getDriverName()`: raw SQL on MySQL, `Schema::table(...)->enum(...)->change()` (Laravel's own cross-driver translation) on everything else. Confirms `php artisan test` after any migration touching an existing enum column, not just after touching display/service code — this class of bug is invisible until the suite actually runs.
- **`TalentSelectionService::verifiedBaselineAbilityIds(int $specId)`** — the new, safe display-read method. Deliberately separate from the abandoned `alwaysAvailableAbilityIds()`: this one only ever reads `source = 'verified_override'` rows, which always carry an explicit `spec_id` — it structurally cannot touch the ambiguous `spec_id = NULL` bucket that caused the Mind Sear leak, because that bucket has a different `source` value entirely. `WowComps`/`SpellExplorer` both merge this into their display id list (alongside talent selections and choice-node siblings), with `isSelected` always `true` for these entries (never talent-gated, same reasoning as the reverted method's `isSelected` handling).

**Verified end-to-end**: Windwalker Monk shows Leg Sweep, Mistweaver Monk correctly does *not* (not yet verified for that spec — scoping works), Beast Mastery Hunter shows Freezing Trap, Marksmanship Hunter correctly does not, and Discipline Priest still correctly does not show Mind Sear (completely unrelated code path, unaffected). Full test suite back to the standard 12 pre-existing failures after the migration fix.

**This is the pattern going forward for any future Leg Sweep/Freezing Trap-shaped report**: verify the one spell/spec pair by hand (real spellbook snapshot if available, description text otherwise, same rigor as the Priest 14/11 split above), add one line to `data/spelldata/baseline-spec-overrides.txt`, re-run `import:spelldata`. Never write a heuristic that tries to do this in bulk — every one tried so far has failed under testing.

### Known gap, quantified but deliberately not acted on: whole specs whose core kit is almost entirely baseline (2026-08-06)

Player report: "Devourer spells don't seem to be showing" on `/wow-comps` (Demon Hunter's third spec). Confirmed real and traced to this exact limitation, at a much larger scale than the Leg Sweep/Freezing Trap cases above: **635 baseline-tagged spells for Devourer are missing from the display**, including the entire signature Demon Hunter kit — Metamorphosis, Eye Beam, Chaos Strike, Blade Dance, Throw Glaive, Fel Rush, Vengeful Retreat, Disrupt, Blur — none of which are talent picks, all of them structurally invisible to `selectedSpellIds()`. Checked a second spec while investigating: **Devastation Evoker** has the identical problem — Living Flame, Pyre, Fire Breath, and Dragonrage are all baseline too, which is why that spec's WoW Comps column showed only 2 real active abilities (Rescue, Quell) out of 66 total entries, the rest passive talent modifiers.

This isn't a new bug — it's the same architectural boundary the section above already documents, just landing far harder here than the Leg Sweep/Freezing Trap cases that motivated the hand-curated-file design. Demon Hunter and Evoker's signature kits happen to be almost entirely baseline rather than talented (unlike e.g. Priest, where most of what defines the spec *is* a talent pick), so the "verify one spell, add one line" pattern that works fine for an occasional missing ability doesn't scale to a spec where *most* of the kit needs that treatment.

**Deliberately left as-is, not fixed** — presented to the player as a real, quantified finding with three options (leave as-is / hand-curate just these two specs' core kits / reconsider the whole approach for baseline-heavy specs) and the player chose to leave it as-is for now. Flagged here so a future session doesn't have to re-derive the root cause from scratch, and so "leave as-is" is understood as a deliberate decision, not an oversight.

### Partial fix shipped, 2026-08-07: `explicitBaselineCooldownAbilityIds()` — safe but narrow

Follow-up request: show DH/Evoker baseline cooldowns (10s+) and Sleep/Disorient CC regardless of cooldown, since those are worth tracking even for baseline-heavy specs. Before building this, checked whether it could reuse the ambiguous `spec_id = NULL` bucket the way a naive filter would need to — confirmed concretely it could not: Demon Hunter's Devourer has only **16** baseline spells with an explicit `spec_id` tag; the other **619**, including Eye Beam and the rest of the real signature kit, sit in the same `spec_id = NULL` bucket that leaked Mind Sear onto Discipline Priest. Grepped Eye Beam's own raw SimC entry directly to confirm it has no `free=(...)` or `Class:` qualifier disambiguating it — nothing in the data distinguishes it as Havoc-only, and the one candidate structural signal (its `spell_relationships` link to the "Havoc Demon Hunter" identity passive) is the exact signal already proven unreliable in both directions during the original Mind Sear investigation. A cooldown/mechanic filter over the NULL bucket would therefore have shown Eye Beam/Chaos Strike/Blade Dance identically on Havoc, Vengeance, *and* Devourer — reproducing the same leak, just within one class instead of across two.

Presented this tradeoff to the player before building anything; **explicit spec_id only** was chosen — safe, zero misattribution risk, but only a partial fix (leaves out Eye Beam and most of the real kit, same limitation as the section above).

**`TalentSelectionService::explicitBaselineCooldownAbilityIds(int $classId, int $specId): Collection`** — reads only `spell_class_availability` rows with `source = 'baseline'` **and** an explicit (non-null) `spec_id`, filtered to `cooldown_seconds >= 10` OR `mechanic IN ('Sleep', 'Disorient')`, excluding passives/`not_in_spellbook`/`(desc=...)`-suffixed junk (same hygiene as the abandoned `alwaysAvailableAbilityIds()`), deduped one-per-name. Wired into both `SpellExplorer::getSpellReferencesProperty()` and `WowComps::spellReferencesFor()`, merged into the display id list alongside talent selections/choice-siblings/verified overrides; these spells always render as `isSelected = true` (never talent-gated).

**Verified end-to-end (2026-08-07):** Devourer → Blur, Shift, Spectral Sight (3). Havoc → Blade Dance, Blur, Fel Rush (3). Vengeance → Demon Spikes, Infernal Strike, Metamorphosis, Sigil of Flame (4) — all real, correctly spec-scoped, zero cross-spec duplication. Evoker returns 0 across all three specs (28 explicit-spec baseline rows exist dataset-wide, none clear the cooldown/mechanic bar) — confirmed as the expected shape, not a bug. `Priest/Discipline` sanity-checked at 0 (no explicit-spec baseline rows there meet the bar), confirming the method is harmless to call for any class, not just the two named in the request. `tests/Feature/Services/TalentSelectionServiceTest.php` — new case asserts the NULL-bucket row and the `source='talent'` row are both correctly excluded even when they'd otherwise qualify. Full suite: same 12 pre-existing, unrelated failures, zero new regressions.

**Still open at that point:** headline abilities without an explicit spec tag (Eye Beam and most of Demon Hunter/Evoker's real kit) remained invisible on both pages. Closing that gap for real needed either hand-curating each one via `data/spelldata/baseline-spec-overrides.txt` (the sanctioned, verify-one-line-at-a-time path) or a genuinely new disambiguating signal in the source data — not a bulk heuristic over the NULL bucket.

### Hand-curated Havoc/Vengeance/Evoker kit pass, same day (2026-08-07)

Follow-up: asked whether other classes' already-computed `categorize()` output could help identify DH/Evoker's real kit. Answer given at the time: no — category (Offensive/Defensive/CC/Utility) and spec attribution are orthogonal; knowing a spell is "Offensive" says nothing about which of DH's three specs it belongs to. Where cross-class comparison *could* help is triage (which uncategorized candidates are worth checking first) — not verification itself. Asked to do the verification pass directly and add the results to `baseline-spec-overrides.txt`, the same file Leg Sweep/Freezing Trap/Hammer of Justice live in.

**First, tried to use `spell_relationships`-based identity-passive links (a spell showing up in "Affecting Spells" for e.g. "Havoc Demon Hunter") as supporting evidence — re-disproven immediately, on new spells this time.** Blade Dance and Death Sweep are 100% Havoc-exclusive by long-standing, stable game design (never on Vengeance in any expansion) — yet both showed spurious links to Havoc, Vengeance, *and* Devourer identity passives simultaneously. Same false-positive shape as Mind Blast linking to Holy. This signal was discarded entirely for this pass, same conclusion as the original Mind Sear investigation, now confirmed on a second, unrelated class.

**What was actually used instead: established, stable general game knowledge, cross-checked against each spell's own description text** — the same "AI calibration" role named in this file's "AI-Assisted Game Data Modeling" section. Deliberately scoped to **long-standing specs only** (Havoc, Vengeance, Devastation, Preservation, Augmentation) — **Devourer was excluded entirely**, on the grounds that it's new-enough content (Demon Hunter's third spec, first appearing in this dataset's "Midnight"/12.0 patch) that general knowledge isn't reliable for it, and no data signal survived the check above. Nothing about Devourer's kit was added on assumption.

23 candidate lines were drafted; **one was caught and reverted before shipping**: Fel Rush was going to be added for Havoc via a new spell_id (344865), but a duplicate-collision check (querying every candidate name for a second explicit-spec row under a different spell_id) found spell_id 195072 already had an explicit Havoc tag from *before* this session — adding a second, different spell_id under the same name would have rendered as a literal duplicate row. Removed from the file before import; a comment in the file itself explains why. Same class of bug `preferSelectedPerName()` exists to catch elsewhere in this service — worth checking for on every future batch addition, not just single-line ones.

**Final additions** (`data/spelldata/baseline-spec-overrides.txt`, all `source = 'verified_override'`):
- **Havoc-exclusive:** Eye Beam, Blade Dance, Death Sweep, Chaos Blades, Vengeful Retreat, Metamorphosis (Havoc's leap/stun copy, spell_id 200166 — distinct from Vengeance's own copy, already explicit), Throw Glaive (Havoc's copy, 185123 — distinct from Vengeance's own explicit copy, 204157)
- **Vengeance-exclusive:** Fel Devastation, Fiery Brand, Soul Carver
- **Shared Havoc + Vengeance** (both specs tagged to the same spell_id, same precedent as Hammer of Justice across Paladin specs): Immolation Aura, Sigil of Misery (the Disorient CC this request specifically asked to track), Sigil of Chains, Disrupt
- **Evoker:** Hover → all three specs (class-wide movement utility since launch); Time of Need → Preservation only (its signature healing cooldown)

**Evoker's other headline cooldowns (Dragonrage, Fire Breath, Living Flame, Pyre) were checked and NOT added** — every baseline copy of each has `cooldown_seconds` and `mechanic` both null in this dataset, so there was no number to verify against, not a spec-attribution question. Flagged rather than guessed; a data-quality gap in Evoker's cooldown capture specifically, separate from the DH spec-attribution problem this pass otherwise solved.

**Verified end-to-end after import** (`import:spelldata wow 12.0.7.68887 --only=demonhunter,evoker`, 30 overrides applied cleanly): Havoc 13 entries, Vengeance 11, Devourer unchanged at 3 (Blur/Shift/Spectral Sight — untouched by this pass), Evoker Devastation 1, Augmentation 1, Preservation 2 — checked programmatically for duplicate names across the merged `verifiedBaselineAbilityIds()` + `explicitBaselineCooldownAbilityIds()` result set, none found. `Livewire::test()` on `SpellExplorer` confirmed Eye Beam, Blade Dance, and Sigil of Misery all render. Full suite: same 12 pre-existing, unrelated failures, zero new regressions.

**Still open:** Devourer's real kit, and any DH/Evoker ability outside this hand-picked batch — same sanctioned one-line-at-a-time path applies to both.

## WoW Comps spec picker redesign + committed icon manifest ✓ COMPLETE (2026-08-08)

Two related changes, same day: `/wow-comps`'s slot picker was a two-step class-then-spec `<select>` pair with no icons; it's now a single searchable flyout per slot (type e.g. "disc", click "Discipline" — one action, no intermediate class selection), and class/spec icons were added following the same self-hosted, filename-keyed pattern `spells.icon_name` already established (2026-08-05).

**Schema:** `classes.icon_name` / `specializations.icon_name` (migration `2026_08_08_000002`), both nullable, both `$fillable` on their models.

**`data/spelldata/fetch-class-spec-icons.php`** — same OAuth/retry/self-host conventions as `fetch-spell-icons.php`. Class media via `/data/wow/media/playable-class/{id}`, using Blizzard's numeric playable-class IDs (1–13, stable since Legion, hardcoded `BLIZZARD_CLASS_IDS` keyed by our own `classes.slug`). Spec media via `/data/wow/media/playable-specialization/{id}`, using `specializations.external_spec_id` (already captured at import time — no separate ID-resolution step needed). Self-hosts to `storage/app/public/class-icons/` and `.../spec-icons/`.

**`WowComps` picker (`app/Livewire/WowComps.php` + `resources/views/livewire/wow-comps.blade.php`):** new `getClassSpecsProperty()` (every class with specs eager-loaded) and `selectSpec(int $index, int $classId, int $specId)` (sets both in one call, logs directly rather than relying on `updated()` to fire for a method-mutated property). The old `updated()` hook watching `slots.*.classId`/`slots.*.specId` and the old `specializationsFor()` helper are untouched/removed respectively — `updated()` stays because `tests/Feature/Admin/PageUsageTrackingTest.php` exercises it directly via `->set('slots.0.classId', ...)`, which still works identically. The flyout itself is pure Alpine (`x-data="{ open, search }"`, `data-search`/`data-search-group` attributes, no Livewire round-trip while typing) so filtering stays instant; clicking a spec button fires `wire:click="selectSpec(...)"` and closes the panel locally in the same click.

**`<x-class-icon>`/`<x-spec-icon>`** (`resources/views/components/`) — same host-relative-path convention as `<x-spell-icon>` (never `Storage::disk()->url()`, see that component's docblock for why). Fallback when `icon_name` is null: a colored initial badge using the class's real in-game color (`config/wow_classes.php` — Blizzard's own stable `RAID_CLASS_COLORS`, hardcoded since it's canonical unchanging reference data, not fetched), never a broken `<img>`.

### Icons committed to git, applied via manifest — no Blizzard credentials needed on new machines (2026-08-08)

Originally, every self-hosted icon set (spell/class/spec) followed the same "each environment fetches its own copy" pattern as the rest of `data/spelldata/` (see the spell-icons deploy checklist, 2026-08-05). In practice this meant every new dev machine — and every `migrate:fresh` on an existing one, which this codebase does routinely after nearly every patch-data fix — needed real `BLIZZARD_CLIENT_ID`/`SECRET` just to see icons at all. Since the icon set is small (a few MB) and stable, the files themselves are now **committed to git**:

- `storage/app/public/.gitignore` narrowed from a blanket `*` to explicitly un-ignore `spell-icons/`, `class-icons/`, `spec-icons/` (still ignores everything else under that disk — nothing else currently uses it, confirmed by grepping for other `Storage::disk('public')` callers, so there's no risk of accidentally tracking real user uploads).
- Committing the image files alone does **not** fully solve the problem — `spells.icon_name`/`classes.icon_name`/`specializations.icon_name` are DB columns, wiped by every `migrate:fresh`, and the files on disk don't tell the DB which spell/class/spec they belong to without another API call.
- **`data/spelldata/icon-manifest.json`** closes that gap — a small committed JSON file (`{"spells": {"<spell_id>": "<filename>", ...}, "classes": {...}, "specs": {...}}`) mapping each table's own stable external identifier (never `spells.id`, an internal PK not guaranteed stable across a re-import) to its icon filename. Both `fetch-spell-icons.php` and `fetch-class-spec-icons.php` now end by rewriting their own section of this file from the DB's full current `icon_name` state (a complete refresh each run, not an incremental in-loop track — self-healing regardless of where a previous run stopped or crashed).
- **`php artisan wow:apply-icon-manifest`** — new command, reads the manifest and fills `icon_name` wherever it's currently `NULL`. Zero Blizzard API calls. This is the actual fix for the "every new machine needs credentials" problem — run this (not the fetch scripts) after any fresh migrate on a machine that doesn't have `BLIZZARD_CLIENT_ID`/`SECRET` at all. The fetch scripts are still needed, but only occasionally, on one machine that does have credentials, whenever a new patch adds spells/specs the manifest doesn't cover yet — after which the updated manifest + any newly downloaded files get committed once, for everyone.
- Same precedent as `data/spelldata/baseline-spec-overrides.txt`: a small, hand/tool-generated, git-committed correction/reference file that a plain importer applies, rather than re-deriving the same result from a live API on every machine.

**Done same day, once real credentials were added to this machine's `.env`** (the user pasted them in after this section was originally written believing this dev sandbox already had them — it didn't) — `wow:apply-icon-manifest` was first verified against a throwaway fixture manifest (written and torn down, never committed) and a fixture-backed test (`tests/Feature/ApplyIconManifestTest.php`, using `--path` to avoid touching the real file), then both fetch scripts were actually run here: 3,420/3,440 spell icons, all 13 class icons, all 40 spec icons, `data/spelldata/icon-manifest.json` generated and committed along with the three `storage/app/public/*-icons/` directories (~10MB total). `php artisan storage:link` also had to be run on this machine — hadn't been, so `/storage/...` URLs 404'd even with correct data/files in place.

## Three real display bugs found and fixed via user reports, 2026-08-09

Same session, three independent reports, each traced to a genuinely different root cause rather than patched by the same fix three times — see [[feedback_trace_dont_shotgun_patch]].

**1. Anti-Magic Shell missing from Death Knight's kit.** Same "ambiguous `spec_id = NULL` baseline" bucket documented at length above (Leg Sweep/Mind Sear/etc.) — confirmed via the spell's own description text (5 name-sharing spell_id copies exist; 48707 is the real self-cast player-facing one, cd=60s). Added to `baseline-spec-overrides.txt` for all 3 DK specs — long-standing, uncontroversial general knowledge, same confidence tier as Hammer of Justice. A separate "cast on your target" variant (444740/444741) was found but deliberately not added — its provenance isn't confirmed the way the self-cast version's is.

**2. A source spell's bare `modifies` relationship rendering as a confusing, numberless duplicate row.** Reported as "Territorial Instincts doesn't give a number, but Intimidation's cooldown IS correctly reduced" (independently, same shape: Improved Traps -> Freezing Trap). Root cause: many sources have TWO separate `spell_relationships` rows to the same target — a generic `modifies` (no magnitude, from the Affecting-Spells text pass) alongside a specific `modifies_cooldown` (real magnitude, from the Category-effect pass). The 2026-08-02 "Bug 1" fix deliberately stopped deduping by source alone so a source with two genuinely different effects still shows both — correct — but this meant the literal-duplicate-signal case (same source, same target, one row just less specific) rendered as two rows instead of one. Fixed: `ModuleSpellReferenceService::dedupeGenericModifies()` drops a bare `modifies` entry when the same source has a more specific relationship type to the same target; keeps it when that's the only signal available. Tests: both cases covered in `ModuleSpellReferenceServiceTalentGatingTest.php`.

**3. Two Hunter "Intimidation" entries shown as active abilities simultaneously — investigated, confirmed real, not fixed.** Traced to a real double-pick in the Beast Mastery admin default `TalentBuild`: node 2655 (spell 19577, "Command your pet to intimidate") and node 2656 (spell 474421, "Spotting Eagle" hero-tree variant) are both independently selected. Confirmed directly in Blizzard's raw `data/talenttrees/hunter.json` that these two nodes occupy the **identical** `display_row`/`display_col`/`raw_position_x`/`raw_position_y` — the usual signature of a mutually-exclusive choice — but Blizzard's own API returned them as two separate `ACTIVE` nodes (no `choice_of_tooltips`) rather than a formal `CHOICE` node, so nothing in our schema or `TalentSelector`'s UI stops both being picked. The DB's own uniqueness constraint (`talent_build_id, talent_node_id`) doesn't catch it either, since they're different `node_id`s. **Not fixed** — flagged as a real gap. Quick fix available any time: un-pick one of the two in `/admin/talent-builds` for Beast Mastery. A structural fix (detecting same-position node pairs and greying one out when the other's picked, same UX as the existing CHOICE-node-sibling display) would prevent this for any spec, not just Hunter, but wasn't built this session.

## Evoker "hardly any spells" — two stacked root causes, both fixed, 2026-08-09

Follow-up report the same day: Evoker specs showed only 7-8 "Active Abilities" on WoW Comps/Spell Explorer (vs. ~23 for a normal spec like Discipline Priest) — Living Flame, Pyre, Fire Breath, Dragonrage, Disintegrate, Eternity Surge, Deep Breath all missing from Devastation specifically. Investigated as a suspected repeat of the already-documented "whole specs whose core kit is almost entirely baseline" gap — turned out to be **two separate bugs stacked together**, only one of which was that.

**Root cause A — a real matching bug in `MurlokTalentImportService`, not a curation gap.** Checking each "missing" spell's own raw data (`grep "Talent Entry"` in `data/spelldata/filtered/evoker/*.txt`) found that Pyre, Dragonrage, Eternity Surge (Devastation), Dream Breath (Preservation), and Ebon Might + Upheaval (Augmentation) are **real talents**, not baseline — and none were picked in their spec's admin default `TalentBuild`. Running `wow:import-murlok-defaults` to re-curate surfaced the actual cause: Evoker spell names routinely carry a legitimate `(desc=Color)` suffix as part of their raw name (e.g. `"Pyre (desc=Red)"`, `"Dragonrage (desc=Red)"`, `"Eternity Surge (desc=Blue)"`) — a per-dragonflight-color naming convention specific to this class (confirmed: Evoker's `Labels` field literally includes entries like `"1464: Red Evoker Spells"`). This is a *different* meaning of the same `(desc=...)` syntax already documented as "reliably flags noise" for other classes (pet-family/artifact/covenant duplicates, see `TalentSelectionService`'s docblock) — for Evoker specifically it's normal, load-bearing naming, present on nearly every real spell. murlok's page never shows this internal annotation to players, so exact-name matching failed for almost the entire class — confirmed via a live preview showing Class talents at only 31/219 and Spec at 30/67, with Pyre/Dragonrage/Eternity Surge all sitting in the `unmatchedNames` list despite being real, commonly-picked talents.

**Fixed:** `MurlokTalentImportService::normalizeSpellName()` strips a trailing `(desc=...)` before comparing names on both sides (our data and murlok's parsed text). Re-running the preview after the fix: Class 31→44, Spec 30→34, Hero 0→14 for Devastation, with Pyre/Dragonrage/Eternity Surge all correctly resolving. Applied via `wow:import-murlok-defaults {spec} 3v3 --class=evoker --apply` for all 3 specs. Tests: `tests/Feature/Services/MurlokTalentImportServiceTest.php` (matches with the suffix stripped; still correctly reports a genuine non-match when murlok has nothing for that node).

**Root cause B — Living Flame really was the already-documented baseline-visibility gap.** Confirmed genuinely baseline (no `Talent Entry` line) and genuinely shared by all 3 specs — its detailed record (`361469`, `"(desc=Red)"`) lists separate per-spec `Resource` costs for Preservation/Devastation/Augmentation explicitly, the strongest confirmation signal used anywhere in `baseline-spec-overrides.txt` so far. Added using spell_id `364664` (not `361469`) — the clean-named wrapper record whose own description is a `$@spelldesc361469` pointer (same structural pattern as Angelic Bulwark elsewhere in this file), so the spec's Spells table shows "Living Flame", not "Living Flame (desc=Red)". Fire Breath/Disintegrate/Deep Breath are confirmed genuinely baseline too (no `Talent Entry` line) but deliberately **not** added — none of their records carry Living Flame's explicit per-spec Resource confirmation, and general knowledge alone isn't a strong enough signal per this file's own standard. Flagged as a remaining gap, not guessed.

**Combined result, verified against the real DB:** Devastation active abilities 7→22, Preservation 7→25, Augmentation 8→28 (all now comparable to a normal spec). Full test suite: same 12 pre-existing, unrelated failures, zero new regressions (172 passing, up from 170).

**Cosmetic issue surfaced by this fix — fixed same day.** Now that Evoker's talent-derived kit actually renders, spells picked via the murlok import were displaying with their raw `"(desc=Color)"` suffix still attached (e.g. "Pyre (desc=Red)", not "Pyre") — invisible before this fix (the spells weren't showing at all), a real papercut across most of Evoker's UI once they did. Fixed via `Spell::getDisplayNameAttribute()` — a display-only accessor stripping the suffix; the raw `name` column is untouched everywhere else, since it's load-bearing for exact-name matching (murlok comparison, duplicate-copy disambiguation, sibling-effect recovery). Swapped into every player-facing spell name across WoW Comps, Spell Explorer/Modules\Show (`<x-spells.table>`), the admin talent build editor, and the Blizzard talent-string import preview. Tests: `tests/Unit/SpellDisplayNameTest.php`.

## WoW Comps: tabs (Active Abilities / Main Cooldowns / Buffs & Passives), 2026-08-09

Spell Explorer's tab pattern (`.tab-btn`/`.tab-active`, pure client-side Alpine, added the same day for that page's own new "Main Cooldowns" tab — filters to any spell with a real cooldown, plus Crowd Control regardless of cooldown) was extended to WoW Comps. The page used to always show both Active Abilities and Buffs & Passives stacked, plus a separate always-visible 300px sidebar panel duplicating a fixed top-3/20s+ "Main Cooldowns" view. Replaced with three tabs in the same 3-column comparison layout — Active Abilities, Main Cooldowns, Buffs & Passives — switching via an Alpine `tab` state on the page's outer `x-data`, no round-trip.

**Main Cooldowns tab redesigned the same day, per a follow-up request** — no longer `WowComps::mainCooldownsFor()`'s fixed top-3/20s+ curated list. It now uses the exact same category-grouped card-grid layout Active Abilities uses (same `$categoryOrder` loop, same headers, same per-member card grid), just filtered per entry: any active (non-passive) spell with a real cooldown value, plus any Crowd Control spell regardless of whether a cooldown is captured (some CC has no cooldown data but is still worth showing — e.g. Mind Control). Verified: Power Word: Shield (no cooldown, Defensive) correctly excluded; Mind Control (no cooldown, Crowd Control) correctly included. `mainCooldownsFor()` was removed entirely as dead code once the blade filtered entries directly the same way Active Abilities already did.

**A real stale-cache bug surfaced and fixed the same day, worth remembering for any future change to what `WowComps`/`SpellExplorer`'s cached spell-reference payload contains:** `WowComps::enrichModifiers()` (adds `description`/`category`/`cooldown` to each modifier entry, powering the modal's expandable "Modifies / Enhances" accordion) was added, but `spellReferencesFor()`'s Redis cache key only includes `TalentSelectionService::spellCacheVersion()` — a counter that bumps on **data** changes (admin build edits, spelldata re-imports), never on **code** changes. Any spec already cached before `enrichModifiers()` existed kept serving the old shape (no `cooldown` key on modifiers), which crashed with `Undefined array key "cooldown"` the instant a viewer opened a spec whose modifier list rendered that block — confirmed live via a real user report (Frost DK). Fixed operationally by manually calling `TalentSelectionService::bumpSpellCacheVersion()` to invalidate every cached entry, not a code change. **This class of bug will recur** for any future edit that changes the shape of what gets cached (new keys read by the blade, changed computation) without also being a "data" change — bump the spell cache version manually (`app(TalentSelectionService::class)->bumpSpellCacheVersion()`) after deploying any such change, on every environment, the same way a `migrate:fresh` needs a re-import elsewhere in this file. No automatic code-version-aware invalidation exists.

## Description resolver: color codes, spell name refs, compound conditionals, and a real safeEval bug ✓ COMPLETE (2026-08-10)

Reported by the user as raw, unresolved syntax visible in displayed spell descriptions (Breath of Sindragosa, Trueshot) — "I imagine this happens across the board." It did: four distinct issues in `ModuleSpellReferenceService::resolveDescription()`/`safeEval()`, three missing token types plus one real parser bug, confirmed dataset-wide (1,035 spells with `|c` color codes, 502 with `$@spell` references, 11 with the compound-conditional syntax).

**1. `|cAARRGGBB...|r` WoW chat/tooltip color codes** — pure client-side text-color formatting from the real tooltip, never meaningful data, previously passed straight through unhandled (e.g. `|cFFFFFFFFGrants a charge of Empower Rune Weapon...|r`). Stripped via a new early pass (keeps the wrapped text, drops the color markers), plus a defensive second pass mopping up any unpaired `|c`/`|r` left by a malformed source string.

**2. `$@spellname<id>` / `$@spellicon<id>`** — cross-spell name/icon reference tokens (Trueshot uses these to label which ability a bonus effect applies to: `$@spellicon19434 $@spellname19434`). Icon tokens can't render inline in plain text, so stripped (along with one adjacent whitespace character — without that, removing just the token left a stray double space in the real output, caught by an early version of the fix's own regression test). Name tokens resolve to that spell's own `display_name`, patch-scoped; an id not found in this patch's data resolves to `(unknown spell)` and flags `uncertain`, never guessed.

**3. Compound chained conditionals** — `$?(a<id>&a<id>)[branch]?(!a<id>&a<id>)[branch][fallback]`, a fundamentally different (and more complex) shape than the existing simple `$?a<id>[A][B]` Pass 1 already handled: a parenthesized boolean expression (terms like `a<id>`/`!a<id>`, joined by a single `&`/`|`) immediately after `$?`, and an arbitrary number of chained `?(cond)[branch]` segments before a final bare `[fallback]`. Confirmed on Trueshot, picking between "Applies Sentinel's Mark"/"Applies Spotter's Mark" based on which hero talent is known. PCRE can't capture a variable number of repeated groups, so the new pass (`resolveChainedConditional()`) only uses the outer regex to find the token's boundaries, then walks the segments in a PHP loop; each condition evaluates via `evaluateConditionExpression()`, which returns `null` (not `false`) for an unrecognized term shape so the caller can distinguish "confidently false" from "can't evaluate" — the latter immediately falls back to the same `(varies by condition — check in-game)` placeholder Pass 1 already uses for `$?c<n>` codes, never guessing which branch applies.

**4. The real bug, found while tracing why fix #3 alone didn't produce the right numbers: `safeEval()`'s recursive-descent parser was silently broken for every multi-term expression, dataset-wide, not just the two reported spells.** `$peek` was defined as an arrow function (`fn () => $tokens[$pos] ?? null`) — PHP arrow functions always capture used variables **by value** at the moment the closure is created, with no way to opt into by-reference capture (unlike a regular closure's `use (&$var)`, which `$next` correctly used). This froze `$pos` at 0 forever: every `peek()` call kept returning `$tokens[0]`, so the "is the next token an operator" checks in `parseTerm()`/`parseExpr()`'s while-loops were evaluated against the permanently-stale first token — which is never itself an operator for an expression starting with a number — so those loops silently never executed, truncating every ${...} expression with more than one term to just its first factor. Confirmed directly: `safeEval('800/1000')` returned `800.0`, not `0.8` — exactly matching Breath of Sindragosa's reported "increases the duration by 800.1 sec" (the literal ".1" immediately following in the raw text is Blizzard's own template, not something this resolver introduces — left as-is, flagged rather than "corrected" by guessing different math with no way to verify it against a real in-game tooltip). Fixed by changing `$peek` to a regular closure with `use (&$tokens, &$pos)`, matching `$next`'s existing pattern. Verified: `safeEval('800/1000')` → `0.8`, `safeEval('5+3')` → `8`, `safeEval('(5+3)/1000')` → `0.008`.

**Previously zero test coverage existed for `resolveDescription()`/`safeEval()`** despite the amount of documented work already built on this resolver (SP Coefficient, sibling effect recovery, formula modifiers, etc.) — `tests/Feature/Services/ModuleSpellReferenceServiceDescriptionTest.php` now covers all four fixes, including both the true-branch and fallback-to-second-branch cases of the compound conditional and the `800/1000` safeEval regression specifically.

**A separate, unrelated gap found while spot-checking the fix, not fixed — flagged only:** `$lWord:Words;` WoW pluralization/localization syntax (e.g. `$lRune:Runes;`, chooses singular/plural text based on a preceding number) is not handled by anything in this resolver and renders as broken trailing text (`"...summon 2 |cFFFFFFFFGrants a charge... $lRune:Runes;"`-shaped output). Outside the scope of what was reported this session (color codes, spell refs, compound conditionals, the arithmetic bug) — a real, separate token type needing its own pass whenever it's prioritized.

**Verified end-to-end against the real DB (not just synthetic test fixtures):** Breath of Sindragosa's color code and arithmetic both resolve correctly now (the residual ".1 sec" is Blizzard's own template, not a resolver bug — see #4 above); Trueshot's `$@spellname` references resolve to "Aimed Shot"/"Rapid Fire" with clean single-spacing, its simple conditional and its compound conditional both branch correctly against a real admin-default Marksmanship build (confirmed "Applies Sentinel's Mark." renders when that build's hero talent is actually selected). Full test suite: same 12 pre-existing, unrelated failures, zero new regressions (182 passing, up from 175). Spell cache version bumped (`bumpSpellCacheVersion()`) so every already-cached description across every spec picks up the fix immediately, same operational step as the stale-cache note above.

## Game Reference Data Import (updated 2026-07-24 — read `game-data.md` for the full document)

Raw WoW reference data (`data/spelldata`, `data/talenttrees`, `data/pvptalents`) pulled from SimulationCraft spell dumps and the Blizzard Game Data API, imported into a relational schema (`spells`, `talent_trees`, `pvp_talents`, etc.) via `php artisan import:spelldata {game} {patch}` (`app/Console/Commands/ImportSpellData.php` + `App\Http\Services\SpellDataFileParser`). The three source folders all join on Blizzard's external `spell_id` — spelldata is the primary source of spell records, talenttrees/pvptalents are structural overlays resolved against it, not independent sources. This is the "Raw Game Data" input referenced in the Canonical Context Module Template section below — it exists to ground/verify expert-dictated module content (e.g. the Feral Druid SimulationCraft cross-check), not to generate modules itself. See `game-data.md` for the full folder-by-folder breakdown, the downstream table mapping, the two global post-import passes (`spell_relationships`, description-reference resolution), and a growing set of dated findings from a deep investigative session (2026-07-24) that the "AI-Assisted Game Data Modeling" note right below this one generalizes into a standing principle — worth reading both together.

## AI-Assisted Game Data Modeling — realization, 2026-07-24

A full session spent extending `ImportSpellData`/`SpellDataFileParser` (charges/cooldown columns, a `modifies_charges` relationship type, spec-attribution from the `Class:` field — see `game-data.md`) surfaced a pattern worth naming explicitly, not just fixing case-by-case: every one of those fixes was pattern-matching against **SimC's text-dump format**, not the game's actual semantics, and each new Blizzard patch or system rework can introduce a structure none of those heuristics anticipate. Confirmed concretely: Premonition of Clairvoyance's actual gating mechanism (an `Override Triggered Action Spell` effect pointing at a spell id that exists *nowhere* in our dataset, not even the raw unfiltered dump) has zero data trail to follow — the only way to know it's Archon-only was recognizing "Premonition" as an Archon mechanic from general knowledge, not deriving it. No amount of additional parser cleverness would have found that one.

**The realization:** this project doesn't need to solve that by making the importer omniscient, because it now has a standing AI collaborator that can do the judgment work a purely mechanical importer structurally cannot — cross-referencing an actual in-game Spellbook screenshot against the data, recognizing a game system from general knowledge, spot-checking whether a heuristic's assumptions hold *before* it ships (see the Bladestorm/Execute duplicate-id spot-check in `game-data.md` — done by hand this session, not automated, and it's what caught that the disambiguation rule needed to be "prefer the talent_node_entries id," not "trust the name"). **This is the Canonical Context Module Template's philosophy (raw data + expert review + AI calibration, not raw data alone — see that section below) generalized from module-authoring specifically to the whole game-data pipeline.** Future work on this pipeline should be planned around a human/AI-reviewed correction layer sitting on top of an intentionally best-effort import — not around making the import itself self-sufficient forever. That's an outdated framing for a codebase that now has an AI collaborator as a standing part of its workflow, not an occasional tool invoked to fix bugs.

**Concretely, this means:** don't chase every SimC-dump edge case in the parser on the assumption that "the data must be perfect before the UI can trust it." Prefer matching a trusted external list (screenshots, expert dictation, existing canonical modules) against the data and *enriching*/*flagging discrepancies*, over trying to have the importer autonomously discover and filter a correct list from nothing — the former fails safely (visible, flaggable mismatches), the latter fails silently (confidently wrong output, as `Top Cooldowns` did for Voidwraith and Boon of the Ascended before this was named).

## Knowledge Gaps Ledger (added 2026-07-28 — read `knowledge-gaps.md` for the full document)

Every time a canonical module's dictated prose gets cross-referenced against imported spell data (the "AI Calibration" step named above) and turns up a real omission or discrepancy, it gets recorded in `knowledge-gaps.md` — a standalone, append-only ledger, deliberately kept out of this file so CLAUDE.md doesn't become the dumping ground for per-module findings. Each entry has a status (`CONFIRMED` / `FLAGGED` — pending expert confirmation / `AMBIGUOUS` — data itself unclear), what the module says, what the data shows, and why it matters. Nothing found this way gets silently folded back into a module's prose — same rule the Ultimate Penitence discrepancy already established (see above). The file's closing sections track open questions still pending the original expert, and an accumulating pattern note (so far: the highest-yield gaps are single-point, low-decision passives sitting under abilities the module already teaches in detail — not the big cooldowns, which experts reliably get right). This ledger is the raw material for the not-yet-built goal named in "AI-Assisted Game Data Modeling" above: eventually being able to proactively tell a player what's likely missing from their own understanding, not just answer when asked.

## Arena Structure Framework (added 2026-07-28 — read `arena-structure.md` for the full document)

A standing reference (not a findings log — different in kind from `knowledge-gaps.md` above) for authoring and auditing matchup-specific canonical modules. Combines two things: the player's own **go / anti-go cycle** (opening → a team commits a coordinated CC+burst "go" at the kill target → the other team's defensive "anti-go" response → reset → repeat until a kill or resource/mana attrition ends the match) and an externally-sourced **rating ladder** (which part of that cycle breaks down at each bracket, 1400 through 2300+ — see `knowledge-gaps.md`'s ArenaCoach.gg sourcing). The two combine into one practical claim worth knowing before authoring the next matchup module: below ~2100, arena skill is teachable in general terms (defend earlier, coordinate CC); past that, what's actually needed is matchup-specific go/anti-go knowledge that only exists in a real high-rated player's head — which is the whole reason the expertise-capture/dictation pattern exists as this project's answer, rather than trying to source it from guides. Flags one open Concept gap: "resource attrition" (mana as a win condition) has no home in WoW's current 7 seeded concepts.

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