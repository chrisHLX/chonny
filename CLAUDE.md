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
  └── Subject (belongs to Category)
        ├── Concepts (belong to Subject — knowledge tags)
        ├── Proficiencies (belong to Subject — difficulty tiers with an index integer)
        └── Module (belongs to Subject)
              ├── ModulePages (ordered pages of Markdown/HTML content)
              ├── Questions (many-to-many via module_question pivot)
              └── Tags (many-to-many — freeform labels)
```

**Category** (`categories` table) — top-level grouping (e.g. "Science", "Finance"). Has many Subjects.

**Subject** (`subjects` table) — belongs to a Category. Has many Modules, Concepts, and Proficiencies. Every Module must belong to a Subject — the Subject determines which Concepts are available to tag questions and which Proficiency tiers govern difficulty.

**Concept** (`concepts` table) — a knowledge unit that belongs to a Subject. Many-to-many with Questions via `concept_question` pivot. When the AI generates questions it is given the full list of Concept names for the module's Subject and must tag each question with one or more matching concepts. `UserConceptMastery` tracks each user's mastery percentage per Concept over time.

**Proficiency** (`proficiencies` table) — ordered tiers on a Subject (index 0 = beginner, higher = advanced). Many-to-many with Modules via `module_proficiency`. The `index` value drives how many questions the AI generates per batch (index < 2 → 2 complex questions, 2–4 → 5, ≥4 → 7) and the difficulty mix (easy/medium/hard split).

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
- `mode="suggestions"` → builds context from a `PromptBuilder` using module name/description/subject instead of page content
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

### Adaptive Quiz Logic (`app/Livewire/QuizRunner.php`)

`calculateNextDifficulty()` iterates easy → medium → hard. A level is "mastered" at ≥80% correct. If all three levels are mastered, `handleMasteryCompletion()` serves the least-accurate questions as a `final` round. If `consecutive_fails >= 1` on a question, `GenerateReviewContentJob` is dispatched to generate AI review content.

### Services (`app/Http/Services/`)

- **AiService** — all OpenAI calls. Uses `gpt-4o-mini` by default, `gpt-4.1-mini` for complex types (ordering, matching_pairs), `gpt-4.1-nano` for HTML generation. Always deducts credits after each call via `CreditService`.
- **CreditService** — manages `UserCredit` balance. New users receive 50 welcome credits. The quiz requires >5 credits to start.
- **MasteryService** — updates `UserConceptMastery` percentages after each question answer.
- **VersioningService** — handles module versioning when AI generates follow-up modules.
- **TokenService** — estimates token counts (chars / 4) and calculates credit cost per model.

### Livewire Components (`app/Livewire/`)

- **`Modules\Index`** — module browser with URL-synced query string filters (category, subject, status, proficiency, tags).
- **`QuizPage`** — state machine wrapper: selection → running → review-feedback.
- **`QuizRunner`** — the active quiz engine (adaptive difficulty, pivot tracking, job dispatch on completion).
- **`QuizSelection`** — picks module/subject before starting.
- **`Collection`** — user's enrolled modules.

### Jobs (`app/Jobs/`)

All jobs run via Redis queue (`QUEUE_CONNECTION=redis`):
- `GenerateQuestions` — bulk question generation for a module (dispatched from `ModuleController`)
- `GenerateReviewContentJob` — generates AI explanation for a question a user keeps failing
- `SuggestionJob` — generates next-module suggestions after quiz completion
- `GenerateCardJob` — generates collectible card art spec after quiz completion

### Route Naming Conventions

Module routes use both controller-based and Livewire routes:
- `modules.index` → `App\Livewire\Modules\Index` (Livewire)
- `modules.quiz` → `ModuleQuizController@show` (blade + QuizRunner embedded)
- `questions.quiz.index` → `QuizPage` (Livewire)

Avoid creating routes with overlapping segment patterns (e.g. `/modules/destroy/{id}` vs `/modules/{module}`) — the route file has a comment about this.

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

## Content Model (Intended Direction)

The platform is moving toward a curated content model:
- Users do NOT create questions directly
- Content creators define: Subject → Concepts → Questions (with forced concept tagging)
- Users create Modules by selecting from existing question bank
- AI generates Modules from existing content via suggestion pipeline

### Not yet implemented cleanly:
- Admin/creator interface for Subject → Category → Concept hierarchy
- Forced concept tagging on question creation (currently optional = mastery breaks)
- Multi-subject onboarding flow for new categories

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