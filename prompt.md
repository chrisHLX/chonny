# AI Prompt Inventory

A reference for every prompt sent to AI in this codebase — what triggers it, what data goes in, and what comes back.

---

## 1. Question Generation
**Method:** `AiService::generateQuestions()` — [AiService.php:1101](app/Http/Services/AiService.php#L1101)
**Purpose label:** `"Generate {type} questions for module {id}"`
**Model:** `gpt-4o-mini` (mcq / true_false / open), `gpt-4.1-mini` (ordering / matching_pairs)
**Input:**
- Module page content (knowledge base)
- Proficiency level name + description
- Existing question list (for deduplication)
- Available concept names (must tag each question)
- Axis / skill-dimension list (measurement context only)
- Question count + difficulty mix (derived from proficiency index)
- Optional: `difficultyFocus` override, `enforceAllSkillTypes` flag

**Output:** JSON array — each item has `question`, `answer`, `type`, `skill_type`, `difficulty`, `concepts`
**Schema enforcement:** structured output (`json_schema`) for mcq / true_false / ordering; `json_object` for matching_pairs
**Triggered by:** `GenerateQuestions` job (edit mode) or chained from `GenerateModuleContentJob` (suggestion mode)

---

## 2. Review Explanation
**Method:** `AiService::generateContentForQuestion()` — [AiService.php:788](app/Http/Services/AiService.php#L788)
**Purpose label:** `"AI explanation generation"`
**Model:** `gemini-2.5-flash`
**Input:**
- Question text + correctly formatted answer
- Module name (context label)
- Optional `skill_type` — recall / analysis / application (tailors explanation style)

**Output:** Plain text ≤ 120 words — corrects misconception, explains correct answer, ends with a memory hook
**Triggered by:** `GenerateReviewContentJob` when `consecutive_fails >= 1` on a question
**Cache:** keyed `review_content:{question_id}`, TTL 1 hour

---

## 3. Next Module Suggestion — REMOVED 2026-07-09
`UserModuleService::nextModuleResponse()` and `SuggestionJob` were retired entirely (single-module-blind, ungrounded free-text output, superseded by `NextStepService::findBestModuleForConcepts()` — see CLAUDE.md's Next Step + Reflection Loop section). No AI call happens here anymore; `buildModuleUserStats()` (the stats builder this prompt used to consume) survives only for the quiz-completion screen's Strengths/Needs-Improvement lists.

---

## 4. Diagnostic Player Profile
**Method:** `DiagnosticProfileService::generateProfile()` — [DiagnosticProfileService.php:17](app/Http/Services/DiagnosticProfileService.php#L17)
**Purpose label:** `"diagnostic_profile_generation"` (auth) / free direct Gemini call (guest)
**Model:** `gpt-4o-mini` (auth users), `gemini-2.5-flash-lite` (guests — no credit deduction)
**Input:**
- Self-reported survey answers: `current_rating`, `primary_role`, `primary_goal`, `self_assessed_weakness`
- Accumulated trait scores from `diagnostic_mcq` answers (key → cumulative points)
- Axis mastery % per skill dimension
- Top 5 weakest concept masteries

**Output:** `{ player_type, narrative, top_traits[3], growth_area, next_module_suggestion }`
**Triggered by:** `DiagnosticQuizRunner::completeModule()`
**Storage:** `user_module.diagnostic_profile` pivot column (auth) or `session('guest_quiz_results.{moduleId}')` (guest)

---

## 5. Research
**Method:** `ResearchService::fetchLatestMaterial()` — [ResearchService.php:22](app/Http/Services/ResearchService.php#L22)
**Purpose label:** `"research"`
**Model:** `gemini-2.5-flash-lite` with `google_search` grounding
**Input:**
- Topic string + subject name
- Optional user prompt (treated as untrusted — sanitised)
- Optional attached existing research / module pages
- Source URL handling:
  - No URL → google_search only
  - Non-YouTube URL → raw page content injected (stripped HTML, ≤ 50k chars); falls back to `url_context` if fetch fails
  - YouTube + transcript mode → transcript text appended; falls back to full-video multimodal if captions unavailable
  - YouTube + video mode → multimodal `file_data` part sent directly

**Output:** 1,000–1,500 word summary + grounding source URLs
**Saves to:** `SubjectContent` (one row per module via `updateOrCreate`), `AiRequest`
**Triggered by:** module edit research panel, `ExploreModuleJob`

---

## 6. Content Q&A — GPT
**Method:** `AiService::answerQuestion()` — [AiService.php:72](app/Http/Services/AiService.php#L72)
**Purpose label:** `"content_qa"`
**Model:** `gpt-4o-mini`
**Input:**
- `context_snapshot` of the page at submission time (not the live `content` column)
- User's question

**Output:** Plain text answer grounded only to the provided content — explicitly told not to go beyond it
**Triggered by:** `AnswerPromptJob` when `$prompt->model === 'gpt'`

---

## 7. Content Q&A — Gemini (web-grounded)
**Method:** `ResearchService::answerQuestion()` — [ResearchService.php:281](app/Http/Services/ResearchService.php#L281)
**Purpose label:** `"content_qa_gemini"`
**Model:** `gemini-2.5-flash-lite` with `google_search`
**Input:**
- Same page content snapshot + user question
- Gemini instructed to use content as primary source but may web-search to verify / correct outdated facts

**Output:** Answer text + source URL array
**Triggered by:** `AnswerPromptJob` when `$prompt->model === 'gemini'`

---

## 8. Module Content Generation
**Method:** `AiService::generateModuleContent()` — [AiService.php:895](app/Http/Services/AiService.php#L895)
**Purpose label:** `"generate_module_content"`
**Model:** `gemini-2.5-flash`
**Input:**
- Module name + description
- Subject name, category name
- Proficiency level name + description + depth guide (beginner / intermediate / advanced word targets)
- Axis list with concepts per axis
- Full concept list for the subject
- Optional focus context (weak area description for AI-suggested modules)

**Output:** Markdown ≤ 500 words — `##` sections, concrete examples, `## Key Takeaways` at end
**Triggered by:** `GenerateModuleContentJob` (suggestion / explore flow), which then chains to `GenerateQuestions` jobs

---

## 9. Synthesise Content from Research
**Method:** `AiService::synthesiseContent()` — [AiService.php:954](app/Http/Services/AiService.php#L954)
**Purpose label:** `"synthesise_content"`
**Model:** `gpt-4o-mini`
**Input:**
- Module name, subject, proficiency name + description
- Research summary text (from `SubjectContent`)
- User's custom instruction (falls back to a default if blank)

**Output:** Markdown ≤ 500 words — same structure as module content generation
**Triggered by:** module edit panel "Synthesise" action

---

## 10. Explore Module Content
**Method:** `AiService::generateExploreContent()` — [AiService.php:1372](app/Http/Services/AiService.php#L1372)
**Purpose label:** `"Explore module content: {userIntent}"`
**Model:** `gemini-2.5-flash`
**Input:**
- User's stated learning intent string
- Subject name + proficiency level + learning outcomes
- Axis block (skill dimensions)
- Available concept names
- Optional research context (if research was fetched first — must be used as the only factual source)
- Optional prior knowledge string

**Output:** `{ title, description, pages: [{ title, content }] }` — 2–3 Markdown pages
**Triggered by:** `ExploreModuleJob`

---

## 11. Tag Generation
**Method:** `AiService::generateTags()` — [AiService.php:742](app/Http/Services/AiService.php#L742)
**Purpose label:** `"generate_tags"`
**Model:** `gpt-4.1-mini`
**Input:**
- Module subject name, module name, module description
- List of existing tags for the subject with their types

**Output:** JSON array of `{ name, type }` objects — 2–5 picks from the existing tag list
**Triggered by:** module edit panel tag auto-generation

---

## 12. Auto-Tag Concepts
**Method:** `AiService::tagConcepts()` — [AiService.php:718](app/Http/Services/AiService.php#L718)
**Purpose label:** `"tag_concepts"`
**Model:** `gpt-4o-mini`
**Input:** Question text, answer text, list of all concept names for the module's subject
**Output:** `{ "concepts": ["Concept A", "Concept B"] }` — 1–3 concept names from the provided list
**Triggered by:** `QuestionController::store()` only when no concepts were manually selected by the creator

---

## 13. Keyword Extraction
**Method:** `AiService::getKeywords()` — [AiService.php:777](app/Http/Services/AiService.php#L777)
**Purpose label:** `"get_keywords"`
**Model:** `gpt-4o-mini`
**Input:** Raw answer text for an `open`-type question
**Output:** JSON array of keyword strings — used to evaluate user free-text answers
**Triggered by:** question creation flow when type is `open`

---

## 14. Proficiency Inference
**Method:** `AiService::inferProficiencyLevel()` — [AiService.php:1360](app/Http/Services/AiService.php#L1360)
**Purpose label:** `"infer_proficiency_level"`
**Model:** `gpt-4o-mini`, temperature 0.0
**System prompt:** `"You are a proficiency assessment assistant. Reply only with a single integer."`
**Input:** Prompt built by the caller (not inside AiService)
**Output:** A single integer — proficiency index

---

## 15. Landing Page Generation (HTML)
**Method:** `AiService::createLandingPage()` — [AiService.php:834](app/Http/Services/AiService.php#L834)
**Purpose label:** `"generate_landing_page"`
**Model:** `gpt-4.1-nano` (via `callOpenAiHTML`)
**Input:**
- Module name + category name
- Proficiency tier name + description

**Output:** JSON `{ title, summary, sections: [{ heading, content }] }` → run through `HtmlFormatter` → stored as `ModulePage` page 1
**Triggered by:** module edit panel "Create Landing Page" action
