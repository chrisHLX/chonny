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
- **Discipline Priest (Oracle)** has not gone through that step at all — its docblock says explicitly "not AI-generated, not verified against guides." It's pure expert dictation, organised but never cross-checked against any raw data source.
- So: on completeness of content, Disc Priest reads closer to "done" (per the earlier note); on **process** — the actual definition above — Feral is the one closer to what a canonical module is supposed to be, and Disc Priest is the one missing a step. Both observations are true and describe different gaps — don't collapse them into a single "which pilot is better" ranking.

**What's still open:** no repeatable pipeline exists yet for the Raw Data + AI Calibration steps — the SimulationCraft cross-check for Feral was done ad hoc by hand, not via any tool/service. That needs designing (what raw data source per subject, what the AI gap-detection/follow-up-question step actually looks like as a feature) before authoring the next canonical module, so each new one doesn't repeat that ad hoc process manually. Do not revise the Feral or Disc Priest pilot content until that pipeline exists — running Disc Priest through it retroactively is the natural next step once it does.

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