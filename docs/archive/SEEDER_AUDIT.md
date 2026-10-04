# Seeder Audit

Static analysis only — no `php artisan` was executed. All findings below are derived from
reading `database/seeders/*.php`, `database/data/*.json`, `database/migrations/*.php`, and
the relevant `app/Models/*.php` files.

---

## PHASE 1 — AUDIT

### 1. File map: every file in `database/seeders/`

| Seeder | Registered in `DatabaseSeeder`? | Writes | Pattern | Notes |
|---|---|---|---|---|
| `SystemUserSeeder` | Yes (1st) | `users` | `firstOrCreate` | Creates `engine@mindcollector.internal` (`User::SYSTEM_ENGINE_EMAIL`). |
| `CategorySeeder` | Yes | `categories` | `firstOrCreate` | 7 categories (Games, Arts, Science, Humanities, Trades, Technology, Commerce). |
| `SubjectSeeder` | Yes | `subjects` | `firstOrCreate` | 9 subjects. **Insertion order matters — see Bug #1.** |
| `ProficiencySeeder` | Yes | `proficiencies` | `updateOrInsert` | Looks up subject IDs by name (`pluck('id','name')`) — safe, order-independent. |
| `GameAxesSeeder` | Yes | `axes` | `updateOrCreate` | Only seeds axes for the **Games** category. |
| `ArchetypeSeeder` | Yes | `archetypes`, `archetype_category` | `firstOrCreate` + sync | Attaches all archetypes to Games category only. |
| `TagSeeder` | Yes | `tags` | `firstOrCreate` | **Hardcodes `subject_id` as literal integers 1–7 — see Bug #1 (confirmed broken).** |
| `ConceptSeeder` | Yes | `concepts`, `concept_axis` | `firstOrCreate` + `syncWithoutDetaching` | Reads `concepts.json`, `newconcepts.json`, `wowconcepts.json`. Resolves subject by name (safe). Also links SC2/LoL/WoW concepts to axes via 3 private methods. |
| `ModuleSeeder` | Yes | `modules`, `module_proficiency` | `firstOrCreate` | Reads `modules.json`. Resolves subject by name (safe). |
| `ModulePageSeeder` | Yes | `module_pages` | manual exists-check + `create` | Hardcoded page content for Zerg Basics / Terran Timing Attacks / Basic PvP Tips. |
| `QuestionSeeder` | Yes | `questions` | plain `create` (not idempotent by itself, but has no natural key check) | Merges `questions.json` + `lolquestions.json` + `medical_questions.json` + `wowquestions.json`. **Not idempotent** — relies on nothing re-running it twice in the same DB, or on `firstOrCreate` happening downstream. See Bug #3 re: `lolquestions.json`. |
| `UnitSeeder` | Yes | `units`, `unit_attributes`, `abilities` | `firstOrCreate` | SC2 unit data (protoss/terran/zerg). |
| `CountersSeeder` | Yes | `counters` | plain `create` | Depends on `UnitSeeder` having run first (Group 3 → Group 4, correct order). |
| `UserModuleSeeder` | Yes | `module_user` pivot | `syncWithoutDetaching` | Reads `user_module.json`. Resolves user/module by natural key (safe). |
| `ModuleContentSeeder` | Yes | `module_question`, `concept_question` | `syncWithoutDetaching` | Two passes: (1) links `module_questions.json` question text → module by name; (2) tags concepts on questions **that already have a module** (from `questions.json`/`lolquestions.json`/`medical_questions.json`/`wowquestions.json`). **Pass 2 silently no-ops for any question with zero linked modules — see Bug #2 and #3.** |
| `WowPvpFundamentalsSeeder` | Yes | `concepts`, `modules`, `module_pages`, `questions`, `concept_question`, `module_question` | fully idempotent (`firstOrCreate`/`updateOrCreate`/`syncWithoutDetaching`) | Self-contained: 5 WoW modules + dedicated question sets, tagged directly to `Concept::where('subject_id', ...)`. This is the pattern that fixed the "Team Composition zero-coverage" bug referenced in `CLAUDE.md`. |
| `LolLaunchSeeder` | Yes | same pattern as above, for LoL | fully idempotent | Self-contained: 5 LoL modules ("gap-fill" modules), covers all 10 LoL concepts directly. Does **not** depend on `lolquestions.json`/`module_questions.json` at all. |
| `TraitsSeeder` | Yes | `traits` (`player_traits`) | `updateOrCreate` | No FK dependencies. |
| `WoWDiagnosticModuleSeeder` | Yes | `modules`, `questions` (diagnostic_mcq/survey_mcq) | `firstOrCreate` | Self-contained, depends on Subject + Proficiency('Beginner') + SystemUserSeeder. |
| `LoLDiagnosticModuleSeeder` | Yes | same, for LoL | `firstOrCreate` | Same pattern. |
| `SC2DiagnosticModuleSeeder` | Yes | same, for SC2 | `firstOrCreate` | Same pattern. |
| `BackfillModuleAuthorsSeeder` | Yes (last) | `modules.created_by` | conditional `update` | Correctly placed last; correctly depends on `SystemUserSeeder`. |
| `CategoriesAndAxesSeeder` | **No — orphaned, never called** | `categories`, `subjects`, `axes` | `updateOrCreate` | Duplicates Category/Subject creation already done by `CategorySeeder`/`SubjectSeeder` (harmless overlap, since idempotent). Its **only unique value is Axes for the 6 non-Games categories** (Technology, Science, Commerce, Humanities, Arts, Trades) — since it never runs, those categories have **zero Axes**, ever. |
| `ProductionSeeder` | **No — orphaned, and broken** | `categories`, `subjects`, `proficiencies` | raw `DB::table()->insert()` | **Fatal bug**: references `Category::where(...)` and `Subject::firstOrCreate(...)` with no `use App\Models\Category;` / `use App\Models\Subject;` import — will throw `Class "Database\Seeders\Category" not found` if ever run. Fully superseded by `CategorySeeder` + `SubjectSeeder` + `ProficiencySeeder` (which cover a superset of the same data). Confirmed dead code. |

### 2. `database/data/*.json` — used vs. orphaned

| File | Loaded by | Status |
|---|---|---|
| `concepts.json` | `ConceptSeeder` | Used (SC2, LoL, Medicine, Global Macro concepts). |
| `newconcepts.json` | `ConceptSeeder` | Used (Music, Ancient History, Programming, Industrial Materials concepts). |
| `wowconcepts.json` | `ConceptSeeder` | Used (WoW concepts) — **duplicates** the same 7 WoW concepts that `WowPvpFundamentalsSeeder::seedConcepts()` also `firstOrCreate`s. Harmless (idempotent, identical names), but two sources of truth for the same 7 concepts. |
| `modules.json` | `ModuleSeeder` | Used — 5 modules (Zerg Basics, Terran Timing Attacks, Laning Fundamentals, Basic Medical Module, Basic PvP Tips). |
| `module_questions.json` | `ModuleContentSeeder` (Pass 1) | Used, but **only contains entries for 3 modules**: Zerg Basics, Terran Timing Attacks, Basic PvP Tips. No entries for "Laning Fundamentals" or "Basic Medical Module" despite both existing in `modules.json`. |
| `questions.json` | `QuestionSeeder` + `ModuleContentSeeder` Pass 2 | Used — SC2 questions, fully linked (via `module_questions.json`) and concept-tagged. |
| `lolquestions.json` | `QuestionSeeder` only | **Orphaned in practice** — see Bug #3. Loaded into `questions` table but never linked to any module and never concept-tagged, because `module_questions.json` has no LoL entries. |
| `medical_questions.json` | `QuestionSeeder` only | **Orphaned in practice** — see Bug #2. Same failure mode as `lolquestions.json`. |
| `wowquestions.json` | `QuestionSeeder` + `ModuleContentSeeder` | Used — linked to "Basic PvP Tips" via `module_questions.json`, concept-tagged in Pass 2. |
| `users.json` | **Nobody** | **Fully orphaned.** No seeder references `database/data/users.json` anywhere. Contains a stale/confusing "system" user (`ai@mindcollector.com` / "Ai_System") that is **not** the real system user (`engine@mindcollector.internal`, created by `SystemUserSeeder`). |
| `user_module.json` | `UserModuleSeeder` | Used. |
| `protoss_units.json` / `terran_units.json` / `zerg_units.json` | `UnitSeeder` + `CountersSeeder` | Used by both (units + their counter relationships). |

### 3. Confirmed bugs

**Bug #1 — `TagSeeder` mistags subjects due to hardcoded IDs (subject_id drift).**
`TagSeeder` hardcodes `subject_id` as literal integers 1–7, assuming a fixed `SubjectSeeder` insertion order. But `SubjectSeeder`'s actual array order is:
1. StarCraft 2 → 2. League of Legends → **3. World of Warcraft: The War Within** → 4. Medicine → 5. Music → 6. Ancient History → 7. Industrial Materials → 8. Programming → 9. Global Macro.

`TagSeeder`'s map assumes `3 = Medicine` (Cardiology/Neurology/Pediatrics tags), `4 = Music`, `5 = Ancient History`, `6 = Industrial Metals`, `7 = Programming`. Since World of Warcraft was inserted at position 3 (after `SubjectSeeder` was extended post-launch), **every subject from ID 3 onward gets the wrong category's tags**, and Programming (8) / Global Macro (9) get **no tags at all** (map only has keys 1–7). This is a genuine, confirmed data-correctness bug, not a hypothetical — on a fresh `migrate:fresh --seed`, WoW gets tagged "Cardiology/Neurology/Pediatrics", Medicine gets tagged "Guitar/Piano/Drums", etc.

**Bug #2 — Medicine's only module ("Basic Medical Module") receives ZERO questions.**
`medical_questions.json` is loaded by `QuestionSeeder` (creates bare `Question` rows with no module link). The only place that links questions to modules is `ModuleContentSeeder`'s Pass 1, which reads `module_questions.json` — and that file has **no entries referencing "Basic Medical Module"** (only Zerg Basics / Terran Timing Attacks / Basic PvP Tips are present). Consequently:
- "Basic Medical Module" ends up with 0 linked questions after a fresh seed (unplayable quiz).
- Pass 2 (concept-tagging) iterates `$question->modules` to find which subject's concepts to check against — since medical questions have zero modules, **zero concepts ever get attached to them either.**
- Net effect: all 10 Medicine concepts (Anatomy, Physiology, Pathophysiology, Pharmacology, Diagnosis, Clinical Skills, Microbiology & Immunology, Epidemiology & Public Health, Ethics & Professionalism, Emergency Medicine) have **zero question coverage**, and the module itself has no content to quiz on.

**Bug #3 — `lolquestions.json` is dead weight, same failure mode as Bug #2, but harmless because LoL is otherwise fully covered.**
Identical root cause: no `module_questions.json` entries name a League of Legends module, so all ~20+ `lolquestions.json` questions are created as bare, module-less, concept-less `Question` rows on every fresh seed. Unlike Medicine, this doesn't leave any concept uncovered, because `LolLaunchSeeder` independently and completely covers all 10 LoL concepts through its own dedicated, self-contained question set. `lolquestions.json`'s content is simply superseded/unused — safe to stop loading, but not a coverage bug in itself.

**Bug #4 (design, not data-correctness) — `CategoriesAndAxesSeeder` is written but never runs**, so the 6 non-Games categories (Technology, Science, Commerce, Humanities, Arts, Trades) have zero `Axis` rows. This means even if content existed for Medicine/Music/Ancient History/Programming/Industrial Materials/Global Macro, `concept_axis` links (and therefore `UserAxisMastery` rollups) could never be populated for those subjects — there's nothing to link to.

### 4. Non-bugs verified (things that looked risky but check out)

- **No seeder deletes or truncates another seeder's data.** No `->delete()`, `->truncate()`, or destructive raw queries found anywhere in `database/seeders/`. All writes are additive (`firstOrCreate`/`updateOrCreate`/`syncWithoutDetaching`/conditional `update`). The "append/take-away" failure mode described in the task brief was not found in this codebase's current state.
- **No references to retired systems** (cards/mint/collectibles, `SuggestionJob`, `recommended_module`, `ModuleSuggestions`) exist anywhere in `database/seeders/` or `database/data/`. Grepped for `Card`, `card_mint`, `mint_number`, `Suggestion`, `recommended_module`, `edition` — all matches were false positives (English words like "Common Mistake", "Cardiology"). This part of the codebase is already clean.
- **FK/run-order dependencies are all correctly satisfied** by `DatabaseSeeder`'s current group ordering (see Phase 2 trace below) — the *order* of registered seeders is correct throughout. The bugs found are semantic/data bugs (wrong hardcoded values, missing linkage rows), not sequencing bugs.
- **SC2 concept coverage: confirmed complete.** All 8 SC2 concepts (Economy, Army, Build Orders, Scouting, Strategy, Tactics, Map Control, Mechanics) have at least one tagged question via `questions.json` → `module_questions.json` → Zerg Basics/Terran Timing Attacks.
- **LoL concept coverage: confirmed complete.** All 10 concepts covered by `LolLaunchSeeder`'s 5 dedicated modules.
- **WoW concept coverage: confirmed complete**, but via two different mechanisms — `WowPvpFundamentalsSeeder`'s 5 modules cover Role Fundamentals, Crowd Control, Cooldown Management, Positioning, Awareness & Tracking, Team Composition; **Target Switching is covered only by the older, fragile `module_questions.json` text-matching pipeline** (one single question on "Basic PvP Tips"). This is a single point of coverage — worth reinforcing later with a dedicated question in `WowPvpFundamentalsSeeder`, but not currently broken.
- **Medicine coverage: confirmed broken (Bug #2 above).**
- **Music, Ancient History, Industrial Materials, Programming, Global Macro: zero modules exist for any of these subjects.** Concepts are seeded (from `newconcepts.json`) but nothing ever creates a Module, ModulePage, or Question for them. This is not a seeder-wiring bug — it's simply unbuilt content for subjects outside the Games category. Flagged here because the task asked for "all subjects" in the launch catalog; whether these subjects are in scope for this pass is a product decision, not a mechanical fix, so **no content was fabricated for them.**

---

## Run-order dependency trace (paper trace of current `DatabaseSeeder`)

1. Inline: creates `test@example.com`, `christian@mindcollector.com`.
2. `SystemUserSeeder` → creates `engine@mindcollector.internal`. No deps.
3. `CategorySeeder` → 7 categories, no deps.
4. `SubjectSeeder` → needs Categories (step 3) ✓. 9 subjects.
5. `ProficiencySeeder` → needs Subjects (step 4) ✓, resolves by name.
6. `GameAxesSeeder` → needs Games category (step 3) ✓.
7. `ArchetypeSeeder` → needs Games category (step 3) ✓.
8. `TagSeeder` → needs Subjects (step 4) ✓ to exist, but **assumes wrong IDs** (Bug #1 — sequencing is fine, values are wrong).
9. `ConceptSeeder` → needs Subjects (step 4) ✓, Games category + axes (steps 3, 6) ✓ for its axis-attach methods.
10. `ModuleSeeder` → needs Subjects (step 4) ✓, Proficiencies (step 5) ✓, SystemUserSeeder (step 2) ✓ for `created_by` fallback.
11. `ModulePageSeeder` → needs Modules (step 10) ✓.
12. `QuestionSeeder` → no deps (bare inserts).
13. `UnitSeeder` → no deps.
14. `CountersSeeder` → needs Units (step 13) ✓.
15. `UserModuleSeeder` → needs Users (steps 1) + Modules (step 10) ✓.
16. `ModuleContentSeeder` → needs Modules (step 10), Questions (step 12), Concepts (step 9) ✓.
17. `WowPvpFundamentalsSeeder` → needs Subject/Proficiency/Category+Axes/Concepts (steps 4,5,6,9) ✓.
18. `LolLaunchSeeder` → needs Subject/Proficiency/Concepts (steps 4,5,9) ✓.
19. `TraitsSeeder` → no deps.
20. `WoWDiagnosticModuleSeeder` / `LoLDiagnosticModuleSeeder` / `SC2DiagnosticModuleSeeder` → need Subject/Proficiency('Beginner')/SystemUserSeeder (steps 4,5,2) ✓.
21. `BackfillModuleAuthorsSeeder` → needs SystemUserSeeder (step 2) ✓, and runs last so it catches everything above.

**Conclusion: the registered run order has no FK/dependency violations.** All problems found are semantic (wrong hardcoded values, missing data rows, orphaned files/seeders), not ordering problems.

---

## PHASE 2 — CONSOLIDATION

### Changes made

1. **`TagSeeder.php` rewritten** — resolves `subject_id` via `Subject::where('name', ...)->first()` instead of hardcoded literal integers (1–7). This fixes Bug #1 (WoW/Medicine/Music/Ancient History/Industrial Materials were all getting each other's tags due to ID drift after WoW was inserted at subject position 3; Programming/Global Macro got none). Kept exactly the same tag data — only the subject-resolution mechanism changed.

2. **`QuestionSeeder.php`** — removed `data/lolquestions.json` from the merged data set (Bug #3: it was never linked to any module and produced ~20+ orphaned, concept-less `Question` rows on every fresh seed; LoL is already fully covered by `LolLaunchSeeder`'s own dedicated question set). Also changed `Question::create()` → `Question::firstOrCreate(['question' => ...], [...])` to match the idempotent pattern used everywhere else in the seeder suite (previously the only seeder that would duplicate rows if `db:seed` were ever run twice without a preceding `migrate:fresh`).

3. **`database/data/module_questions.json`** — added 20 entries linking every `medical_questions.json` question to `"Basic Medical Module"`. This fixes Bug #2: the module previously had zero linked questions (unplayable) and zero concept coverage. It now has all 20 questions, covering 4 of Medicine's 10 concepts (Anatomy, Physiology, Pathophysiology, Microbiology & Immunology) — see "Not carried forward" below re: the remaining 6 concepts.

4. **`ModuleContentSeeder.php`** — removed the now-dead `data/lolquestions.json` entry from Pass 2's `$sourceFiles` list (those questions no longer exist post-change #2, so leaving the entry in would only produce harmless but noisy "question not found" warnings on every seed).

5. **`database/seeders/ProductionSeeder.php` deleted.** Confirmed dead: never registered in `DatabaseSeeder`, and would fatal on `Class "Database\Seeders\Category" not found` if it ever were run (missing `use App\Models\Category;`/`use App\Models\Subject;`). Fully superseded by `CategorySeeder` + `SubjectSeeder` + `ProficiencySeeder`, which cover a superset of the same categories/subjects/proficiencies.

6. **`database/seeders/GameAxesSeeder.php` and `database/seeders/CategoriesAndAxesSeeder.php` deleted, replaced by a new `database/seeders/AxesSeeder.php`.** The old `GameAxesSeeder` only covered the Games category; `CategoriesAndAxesSeeder` covered the other 6 categories' axes but was never registered in `DatabaseSeeder` (Bug #4) and also duplicated Category/Subject creation that `CategorySeeder`/`SubjectSeeder` already own. The new `AxesSeeder` seeds Axes for **all 7 categories** in one place, contains no Category/Subject creation (removed as redundant — `CategorySeeder`/`SubjectSeeder` are the single source of truth for those), and is registered in `DatabaseSeeder` in the exact slot `GameAxesSeeder` used to occupy.

7. **`database/data/users.json` deleted.** Confirmed orphaned — no seeder ever referenced it. It contained a stale, confusing "system" user (`ai@mindcollector.com` / "Ai_System") that is not the actual system user (`engine@mindcollector.internal`, created by `SystemUserSeeder` and referenced via `User::SYSTEM_ENGINE_EMAIL`). Leaving it in place risked a future edit mistakenly wiring it up as if it were the real system-user source.

8. **`DatabaseSeeder.php`** — updated the Group 1B registration (`GameAxesSeeder::class` → `AxesSeeder::class`) and the Group 5 comment referencing the old class name.

### Final seeder file list (22 files, down from 25)

Removed: `ProductionSeeder.php`, `GameAxesSeeder.php`, `CategoriesAndAxesSeeder.php` (3).
Added: `AxesSeeder.php` (1).

### Final `DatabaseSeeder` run order (unchanged structurally, one class swapped)

1. Inline: `test@example.com`, `christian@mindcollector.com`.
2. `SystemUserSeeder`
3. `CategorySeeder` → `SubjectSeeder` → `ProficiencySeeder`
4. `AxesSeeder` → `ArchetypeSeeder`
5. `TagSeeder` → `ConceptSeeder`
6. `ModuleSeeder` → `ModulePageSeeder` → `QuestionSeeder` → `UnitSeeder`
7. `CountersSeeder` → `UserModuleSeeder` → `ModuleContentSeeder`
8. `WowPvpFundamentalsSeeder` → `LolLaunchSeeder`
9. `TraitsSeeder`
10. `WoWDiagnosticModuleSeeder` → `LoLDiagnosticModuleSeeder` → `SC2DiagnosticModuleSeeder`
11. `BackfillModuleAuthorsSeeder`

### Re-traced FK dependency chain (paper trace after edits, step-by-step)

| # | Seeder | Depends on | Satisfied by step | OK? |
|---|---|---|---|---|
| 1 | `SystemUserSeeder` | none | — | ✓ |
| 2 | `CategorySeeder` | none | — | ✓ |
| 3 | `SubjectSeeder` | Categories | 2 | ✓ |
| 4 | `ProficiencySeeder` | Subjects (by name) | 3 | ✓ |
| 5 | `AxesSeeder` | Categories (by name, all 7) | 2 | ✓ |
| 6 | `ArchetypeSeeder` | Games category | 2 | ✓ |
| 7 | `TagSeeder` | Subjects (by name — fixed) | 3 | ✓ |
| 8 | `ConceptSeeder` | Subjects, Games category + axes | 3, 2, 5 | ✓ |
| 9 | `ModuleSeeder` | Subjects, Proficiencies, SystemUserSeeder | 3, 4, 1 | ✓ |
| 10 | `ModulePageSeeder` | Modules | 9 | ✓ |
| 11 | `QuestionSeeder` | none | — | ✓ |
| 12 | `UnitSeeder` | none | — | ✓ |
| 13 | `CountersSeeder` | Units | 12 | ✓ |
| 14 | `UserModuleSeeder` | Users, Modules | (inline), 9 | ✓ |
| 15 | `ModuleContentSeeder` | Modules, Questions, Concepts | 9, 11, 8 | ✓ |
| 16 | `WowPvpFundamentalsSeeder` | Subject, Proficiency, Category+Axes, Concepts | 3, 4, 2/5, 8 | ✓ |
| 17 | `LolLaunchSeeder` | Subject, Proficiency, Concepts | 3, 4, 8 | ✓ |
| 18 | `TraitsSeeder` | none | — | ✓ |
| 19 | `WoWDiagnosticModuleSeeder` / `LoLDiagnosticModuleSeeder` / `SC2DiagnosticModuleSeeder` | Subject, Proficiency('Beginner'), SystemUserSeeder | 3, 4, 1 | ✓ |
| 20 | `BackfillModuleAuthorsSeeder` | SystemUserSeeder, runs last | 1 | ✓ |

**No FK/order violations found in the final state.** Every dependency is satisfied by an earlier step.

---

## PHASE 3 — REPORT

### Summary of changes

| Type | What | Why |
|---|---|---|
| **Fixed (bug)** | `TagSeeder.php` | Subject-tag mismatch from hardcoded ID drift (Bug #1) |
| **Fixed (bug)** | `QuestionSeeder.php` — dropped `lolquestions.json`, `create()` → `firstOrCreate()` | Orphaned rows (Bug #3) + non-idempotency |
| **Fixed (bug)** | `database/data/module_questions.json` — added 20 Medicine entries | Basic Medical Module had zero questions (Bug #2) |
| **Cleaned up** | `ModuleContentSeeder.php` — dropped dead `lolquestions.json` source | Consequence of the `QuestionSeeder` fix above |
| **Deleted (dead/broken)** | `ProductionSeeder.php` | Unregistered, fatals if run, fully superseded |
| **Merged** | `GameAxesSeeder.php` + `CategoriesAndAxesSeeder.php` → `AxesSeeder.php` | One was Games-only, the other was written but never registered (Bug #4); merged into one seeder covering all 7 categories, with the duplicate Category/Subject creation logic dropped |
| **Deleted (orphaned)** | `database/data/users.json` | Never referenced by any seeder; contained a confusing fake "system user" |
| **Updated** | `DatabaseSeeder.php` | Registration + comments to match the above |

### Data deliberately NOT carried forward (read this section first)

1. **`database/data/lolquestions.json`'s question content (~20+ questions) is no longer loaded into the `questions` table.** Reasoning: it was never linked to any module in any prior state of this codebase (confirmed via `module_questions.json`, which has zero LoL entries), so it only ever produced orphaned rows with no concept tags and no quiz exposure. `LolLaunchSeeder` already provides a complete, dedicated question set covering all 10 LoL concepts. Nothing playable is lost — this content was already inert. **The JSON file itself was left on disk** (not deleted) in case you want to inspect it before deciding whether to remove it entirely; only the reference to it was removed from `QuestionSeeder`/`ModuleContentSeeder`.

2. **`database/data/users.json` was deleted, not just de-registered.** Reasoning: unlike `lolquestions.json`, this file was never loaded by *anything*, ever (confirmed via repo-wide grep) — it wasn't "orphaned content with future value," it was a stale artifact containing a fake system user (`ai@mindcollector.com`) that could be mistaken for the real one (`engine@mindcollector.internal`). Deleting it removes a footgun rather than preserving inert data. If you want it back, it's recoverable from git history.

3. **Medicine's remaining 6 concepts (Pharmacology, Diagnosis, Clinical Skills, Epidemiology & Public Health, Ethics & Professionalism, Emergency Medicine) still have zero question coverage.** This was NOT fixed. Reasoning: fixing Bug #2 (the missing `module_questions.json` links) was a mechanical wiring fix — the 20 existing `medical_questions.json` questions simply didn't cover those 6 concepts to begin with, and I did not author 6 new concepts' worth of quiz content myself, since that's editorial content creation (question writing, answer-option design, difficulty calibration) rather than seeder consolidation. Flagging this explicitly rather than silently leaving it uncovered.

4. **Music, Ancient History, Industrial Materials, Programming, and Global Macro subjects have zero modules, module pages, or questions**, despite having concepts seeded (from `newconcepts.json`/`concepts.json`) and now having Axes (from the new `AxesSeeder`, category-level only — no `concept_axis` links exist for these subjects' concepts, since only SC2/LoL/WoW have dedicated axis-attachment logic in `ConceptSeeder`). This was NOT built. Reasoning: this is unbuilt content for subjects outside the current Games-category launch focus, not a seeder-wiring defect — creating modules/questions for 5 entire subjects is a content-authoring task an order of magnitude larger than "consolidate the seeders," and I did not want to silently fabricate placeholder content for subjects that may not be in scope for this launch. If these subjects should be part of the launch catalog, that's a product decision on the size of the next task, not something resolved here.

5. **WoW's "Target Switching" concept has exactly one question covering it**, and it comes from the older, more fragile `module_questions.json` text-matching pipeline (on "Basic PvP Tips"), not from the newer, more robust `WowPvpFundamentalsSeeder` pattern that covers the other 6 WoW concepts with 10+ questions each. This was left as-is — it is technically covered (satisfies the "zero coverage" bar), so rewriting it wasn't a bug fix, and adding more Target Switching questions to `WowPvpFundamentalsSeeder` would be new content authoring, not consolidation. Flagging as a thin spot, not fixing it.

6. **`wowconcepts.json` (read by `ConceptSeeder`) and `WowPvpFundamentalsSeeder::seedConcepts()` both `firstOrCreate` the same 7 WoW concepts.** This is harmless (identical names, idempotent) but is two sources of truth for the same data. Not consolidated — merging them would mean either deleting `wowconcepts.json` (touching `ConceptSeeder`'s file list, which also handles SC2/Medicine/Global Macro concepts in the same pass) or deleting the concept-creation half of `WowPvpFundamentalsSeeder` (which would break that seeder's stated self-contained design — see its own file comment). Neither side is clearly "the bug," so left alone rather than guessing.

7. **`Card`/mint/collectible system, `SuggestionJob`, and `recommended_module`**: confirmed **zero references** anywhere in `database/seeders/` or `database/data/` (repo-wide grep, false positives only — "Common Mistake," "Cardiology"). Nothing to remove here; this part of the seed data was already clean before this audit.

8. **The `module_suggestions` table and `ModuleSuggestions` model** (mentioned in `CLAUDE.md` as "deliberately left in place, now inert" from the `SuggestionJob` retirement) — no seeder writes to this table, so there was nothing to touch here either. Left as-is, consistent with the CLAUDE.md note that dropping the table itself was a deliberate separate decision.

### Known gaps carried forward from before this audit (not introduced, not fixed)

- WoW and Global Macro subjects have no `Tag` entries at all — the original `TagSeeder` only ever had 7 subjects' worth of tag data for 9 (later more) subjects; fixing the ID-resolution bug didn't add missing data that was never there.
- `QuestionSeeder`'s SC2/Medicine/WoW question sets have no `type`-level validation beyond what `Question::firstOrCreate` naturally enforces via the model's own casts/migrations — out of scope for a seeder-consolidation pass.

---

## VERIFICATION CHECKLIST (run these yourself — none of this was executed by Claude)

Run in PowerShell, from the project root:

```powershell
# 1. Fresh migrate + seed. Watch the seeder output for any ⚠️ warnings —
#    there should be none related to "Subject not found", "Module not found",
#    or "Question not found" for StarCraft 2 / League of Legends / WoW / Medicine.
php artisan migrate:fresh --seed
```

```powershell
# 2. Confirm the system user exists and modules from the self-contained seeders
#    are attributed to it (spot-check a few).
php artisan tinker --execute="echo App\Models\User::where('email', App\Models\User::SYSTEM_ENGINE_EMAIL)->exists() ? 'system user OK' : 'MISSING system user';"
```

```powershell
# 3. Confirm TagSeeder fix: World of Warcraft should NOT have Cardiology/Neurology/Pediatrics tags,
#    and Medicine should NOT have Guitar/Piano/Drums tags.
php artisan tinker --execute="App\Models\Subject::with('tags')->get()->each(fn(\$s) => print(\$s->name . ': ' . \$s->tags->pluck('name')->implode(', ') . PHP_EOL));"
```

```powershell
# 4. Confirm Basic Medical Module now has questions.
php artisan tinker --execute="echo App\Models\Module::where('name', 'Basic Medical Module')->first()->questions()->count();"
```

```powershell
# 5. Confirm no orphaned LoL questions remain (should be 0 — old lolquestions.json text no longer seeded).
php artisan tinker --execute="echo App\Models\Question::where('question', 'like', '%main reason to freeze a minion wave near your turret%')->count();"
```

```powershell
# 6. Confirm every category has at least one Axis (AxesSeeder covers all 7).
php artisan tinker --execute="App\Models\Category::withCount('axes')->get()->each(fn(\$c) => print(\$c->name . ': ' . \$c->axes_count . ' axes' . PHP_EOL));"
```

```powershell
# 7. Re-run seeders a second time WITHOUT migrate:fresh, to confirm idempotency
#    (row counts for questions/modules/concepts should NOT change on the second run).
php artisan db:seed
php artisan tinker --execute="echo App\Models\Question::count();"
# Run db:seed again, then re-check the count matches — should be identical.
```

```powershell
# 8. Run the existing test suite to catch any regression the static analysis couldn't see.
php artisan test
```

If step 1 or step 8 surfaces anything unexpected, that's the highest-priority thing to report back — everything above was verified by reading code, not by executing it.

