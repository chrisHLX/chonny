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
- **Question** has `type` (mcq, true_false, open, ordering, matching_pairs) and `answer` (JSON, structure varies by type — see below). Has many Concepts (many-to-many).
- **Pipeline** / **PipelineStep** — orchestrates async workflows. After module completion, a `quiz_completion` pipeline is created and steps dispatched as jobs.

### Content Hierarchy: Categories → Subjects → Modules → Questions

The platform organises all learning content in a strict hierarchy:

```
Category
  ├── Axes (belong to Category — fixed skill dimensions, e.g. "Critical Thinking")
  └── Subject (belongs to Category)
        ├── Concepts (belong to Subject — many-to-many with Axes via concept_axis pivot)
        ├── Proficiencies (belong to Subject — difficulty tiers with an index integer)
        └── Module (belongs to Subject)
              ├── ModulePages (ordered pages of Markdown/HTML content)
              ├── Questions (many-to-many via module_question pivot)
              └── Tags (many-to-many — freeform labels)
```

**Category** (`categories` table) — top-level grouping (e.g. "Science", "Finance"). Has many Subjects and many Axes.

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

**Step 5 — Questions saved and linked.**
For each AI-returned question:
1. `Question` record created with `type`, `answer` (JSON), `difficulty`, `created_by`
2. AI-returned concept names resolved to Concept IDs → attached via `$question->concepts()->sync()`
3. Linked to module via `$module->questions()->syncWithoutDetaching($question->id)`
4. Credits deducted from the triggering user via `CreditService`

### Question Answer JSON Shapes

Each question type stores `answer` differently:
```json
mcq:             { "correct": "Answer text", "options": ["A", "B", "C", "D"] }
true_false:      { "correct": true }
open:            { "correct_keywords": ["keyword1", "keyword2"], "ideal_answer": "..." }
ordering:        { "steps": ["Step 1", "Step 2", "Step 3"] }
matching_pairs:  { "correct": {"Key1": "Val1", "Key2": "Val2"}, "pairs": { "keys": [...], "values": [...] } }
```
`prepareQuestionsForQuiz()` in `QuizRunner` shuffles options/steps/values before serving.

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

- **AiService** — all OpenAI calls. Uses `gpt-4o-mini` by default, `gpt-4.1-mini` for complex types (ordering, matching_pairs), `gpt-4.1-nano` for HTML generation. Always deducts credits after each call via `CreditService`. `answerQuestion(string $question, string $context, int $userId)` handles Content Q&A — grounded prompt, uses `callOpenAiString()`, logged under purpose `content_qa`.
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
| `callOpenAi()` | JSON-returning calls (question generation, explore content, tags) |
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

### Model Table Name Overrides

Some models declare an explicit `$table` property to avoid Laravel's default pluralisation:

| Model | Table |
|---|---|
| `UserAxisMastery` | `user_axis_mastery` |
| `UserConceptSkillMastery` | `user_concept_skill_mastery` |
| `SubjectContent` | `subject_content` |

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