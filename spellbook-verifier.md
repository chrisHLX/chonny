# Plan: In-Game Spellbook Verifier (addon + import + diff)

## Goal

Replace manual screenshot verification of spell/talent data with an automated pipeline:

1. A tiny WoW addon exports the logged-in character's spellbook + talent state — **spell IDs, resolved tooltip descriptions, and the official talent loadout string** — to SavedVariables on logout/reload.
2. An artisan command imports that file.
3. A diff command compares it against `spell_class_availability` and prints mismatches.

This is the **trusted-source verification layer**, not a new fact source. SimC import remains the bulk substrate. The addon export is ground truth for "what a real character of this class/spec, with this exact talent build, actually sees" — including description text with spec conditionals (`$?c1[...]`) resolved and talent modifications baked in. The client is the symbol table we don't otherwise have.

Nothing in this plan touches runtime diagnostic/recommendation behaviour.

**Phase split — this plan is Phase 1 only:**
- **Phase 1 (this plan):** capture ground truth (spell presence + resolved descriptions + loadout string), import it as immutable snapshots, diff spell *presence* against `spell_class_availability`. Resolved descriptions are **stored, not yet diffed**.
- **Phase 2 — REDEFINED 2026-08-02, corrects the line below:** ~~a website-side description resolver (template branch selection per spec, talent-driven text changes)~~ is **cut**, not deferred — no template-branch/conditional-text resolution engine is planned unless arbitrary-build tooltips (i.e. rendering accurate spell text for a build nobody has actually played/exported) become a real product need. What Phase 2 actually is instead: wire the site's *existing* talent-build resolver (`TalentSelectionService::resolveActiveBuild()`/`resolveBuildForModule()` — see CLAUDE.md's "Talent-Aware Spell Data" and "Module-linked talent builds" sections) to look up resolved description text from a matching `spellbook_snapshots`/`spellbook_snapshot_entries` row when one exists for that exact build, instead of computing description text from nothing. Much smaller scope than the original idea — a lookup against already-captured ground truth, not a new resolver engine. Not started as of this date.

Patch-day workflow Phase 1 enables: log in → `/mcexport` → `/reload` → run import → run diff → review flagged rows only.

## Why spell IDs matter

Current verification matches by **name**, which is ambiguous (11 "Penance" rows; only 2 real). The spellbook API returns spell IDs, so the export tells us *which* ID is the player-facing one. This turns `Not In Spellbook`-style inference into a cross-check instead of a guess.

## Out of scope

- Any change to `QuizRunner.php` or `AiService.php` (locked — do not open).
- **The Phase 2 description resolver.** Do not build any code that resolves description templates, compares description text, or diffs descriptions. Phase 1 only captures and stores them.
- Promoting resolved description text or resolved numbers into the general `spells` table. Resolved text is build-specific ground truth; it lives on snapshot entries only, never on `spells`.
- Any change to the SimC importer (`ImportSpellData`, `SpellDataFileParser`) or to `resolveBaselineSpecIds()`. This plan only *reads* what they produced.
- Auto-correcting data based on the diff. The diff **prints/flags only**. Corrections remain a human decision recorded via `spell_corrections` (existing conventions apply).
- Talent *tree topology* import (node positions, choice groups). The addon captures which talents the character has selected/knows, for availability verification only.
- Other characters / inspect API. Self-character only.
- Automation of the login/reload step itself. Manual trigger is fine.

## Part 1 — The addon (Lua)

### INVESTIGATE FIRST — report before writing any Lua

The WoW client API changed significantly in 11.x (e.g. the `C_SpellBook` namespace replaced older `GetSpellBookItemInfo`-style calls) and may have changed again in 12.0. **Do not trust any API signature from memory — mine or yours.** Before writing the addon:

1. Check whether the repo already contains any addon/Lua artifacts or notes (search for `SavedVariables`, `.toc`, `Interface/AddOns` mentions).
2. Report the addon skeleton you intend to write, listing every WoW API function you plan to call, marked `UNVERIFIED` — Chriso will validate the calls in-game with `/dump` before you finalize. Expected relevant namespaces (verify, don't assume): `C_SpellBook` (spellbook items + spell IDs, skill line ranges), `C_ClassTalents` / `C_Traits` (active config ID, node/entry state, **loadout export string generation**), PvP talent slot APIs, `UnitClass` / `GetSpecialization` / `GetSpecializationInfo`, and spell description retrieval (likely `C_Spell` description APIs and/or a `Spell` object mixin with an async continuation pattern).
3. **Description text loads asynchronously.** Spell description data is not guaranteed to be in the client cache when queried; the API uses a request/callback (continuation) pattern. Investigate and report how the export will handle this — expected shape: on `/mcexport`, request descriptions for all collected spell IDs, collect results as callbacks fire, mark any still-unloaded entries `desc = nil` with a chat-line count of misses so the user can re-run. Do not block or busy-wait.

**CHECKPOINT: stop and report findings before proceeding.**

### Addon requirements

- Name: `MindCollectorExport` (folder + `.toc` + single `main.lua`).
- `.toc` declares `## SavedVariables: MindCollectorExportDB`.
- On a slash command (`/mcexport`) — not automatically — build a table:

```lua
MindCollectorExportDB = {
  exported_at = <server timestamp>,
  build = <client build string>,        -- e.g. from GetBuildInfo()
  class = <english class token>,        -- e.g. "PRIEST"
  spec_id = <numeric specialization id>,-- Blizzard's spec id, matches SimC's
  spec_name = <string>,
  loadout_string = <string>,            -- official Blizzard talent loadout export string for the active build
  spellbook = {
    -- one entry per player spellbook item, spells only (skip flyouts/futures if distinguishable)
    { id = <spellID>, name = <string>, tab = <skill line name or index>,
      desc = <resolved description text or nil> },  -- as displayed for THIS spec + talent build
    ...
  },
  talents = {
    selected = { { id = <spellID>, desc = <resolved text or nil> }, ... },
    known_pvp = { { id = <spellID>, desc = <resolved text or nil> }, ... },
  },
}
```

- `desc` is the **resolved** in-game text — conditionals evaluated for this spec, talent modifications applied, variables computed. This is the point of capturing it: it is the answer key the offline data cannot produce (unresolvable `$?c1` branches, `$<var>` evaluation).
- Print a confirmation line to chat with counts including description misses (e.g. "MindCollector: exported 87 spellbook entries, 51 talents, 3 pvp talents, 4 descriptions pending — re-run /mcexport. /reload to flush.").
- No libraries, no dependencies, no UI. Target ~200 lines (async description handling justifies the increase over a bare name export).

Deliver the addon as files in a new repo directory `tools/wow-addon/MindCollectorExport/` so it's versioned, with a short README noting: copy folder to `Interface/AddOns/`, run `/mcexport`, then `/reload`, then find the file at `WTF/Account/<ACCOUNT>/SavedVariables/MindCollectorExport.lua`.

## Part 2 — Import command

### INVESTIGATE FIRST

1. Read the actual schemas of `spells`, `spell_class_availability`, and `spell_corrections` directly from migrations/DB — do not rely on this plan's description of them.
2. Check how spec identity is represented in `spell_class_availability.spec_id` (Blizzard numeric spec id? FK to a local table?) and report — the addon exports Blizzard's numeric spec id and the import must map to whatever the DB uses.
3. Check whether a PHP Lua-table parser already exists in the codebase or vendor; if not, note that SavedVariables is a restricted, predictable Lua subset and a small dedicated parser (or a `lua -> json` preprocessing step) is acceptable. Report your chosen approach before implementing.

**CHECKPOINT: stop and report findings before proceeding.**

### Requirements

- New table `spellbook_snapshots`:
  - `id`, `class` (string token), `spec_id` (whatever form matches the rest of the schema), `client_build` (string), `loadout_string` (text — the build's canonical identity; the snapshot is meaningless without it), `exported_at` (timestamp), `source_file_hash` (string), `created_at`.
- New table `spellbook_snapshot_entries`:
  - `id`, `snapshot_id` FK, `spell_id` (Blizzard spell id, **not** local PK), `name`, `kind` enum: `spellbook` | `talent` | `pvp_talent`, `resolved_description` (nullable text).
  - `resolved_description` is build-specific ground truth. It is only ever read in the context of its snapshot (and therefore its loadout string). Never copy it onto `spells` or any general table.
- Command: `php artisan wow:import-spellbook {path}`.
  - Parses the SavedVariables file, creates one snapshot row + entries.
  - **Idempotent by content**: if a snapshot with the same `source_file_hash` exists, skip and say so. (Lesson from the SimC importer's idempotency bugs — design for re-runs from day one.)
  - Snapshots are append-only history, never updated in place — a new export is a new snapshot. This gives patch-over-patch diffing for free.

## Part 3 — Diff command

- Command: `php artisan wow:diff-spellbook {snapshot_id?}` (default: latest snapshot).
- For the snapshot's class/spec, compare in both directions:

**A. In game, missing/mistagged in DB** — for each snapshot entry:
  - spell id absent from `spells` entirely → flag `MISSING_SPELL`.
  - spell id present but no `spell_class_availability` row covering this spec (matching spec id or NULL/class-wide per existing semantics) → flag `MISSING_AVAILABILITY`.

**B. In DB, not in game** — for each `spell_class_availability` row scoped to this class where the spell claims availability to this spec:
  - spell id not in snapshot → flag `NOT_IN_SPELLBOOK_CANDIDATE`.
  - Expect many legitimate hits here (passives, auras, procs aren't spellbook entries). Direction B is **informational**, direction A is the real alarm. Make the output separate the two clearly and print counts first, details after.

- Output: plain table to stdout, plus `--json` flag writing to storage for later tooling. No writes to any existing table. No AI calls anywhere in this pipeline — fully deterministic.
- **Descriptions are not diffed in this phase.** They are captured and stored only. (Design note for the future Phase 2 resolver diff, recorded here so the intent isn't lost: description comparison must be two-tier — Tier 1 structural/text comparison is the alarm, Tier 2 numeric values are informational only, because gear/stats are baked into resolved numbers and will legitimately drift between exports.)

## Verification

Run and paste output for each:

1. `ls tools/wow-addon/MindCollectorExport/` — expect `MindCollectorExport.toc`, `main.lua`, `README.md`.
2. `php artisan migrate` — expect the two new tables to migrate cleanly; then `php artisan migrate:rollback && php artisan migrate` to prove reversibility.
3. Create a small fixture SavedVariables file at `tests/fixtures/MindCollectorExport.sample.lua` (hand-written, ~5 spellbook entries incl. Mind Blast 8092 and Penance 47540 — at least one with a `desc` string and one with `desc = nil` — plus 2 talents and a dummy `loadout_string`) and run:
   - `php artisan wow:import-spellbook tests/fixtures/MindCollectorExport.sample.lua` — expect "created snapshot #N, 7 entries"; confirm via tinker that the snapshot row stores the loadout string and the entry rows store the description / NULL correctly.
   - Re-run the same command — expect "skipped, duplicate hash" and **no** new rows (`SELECT COUNT(*) FROM spellbook_snapshots` unchanged).
4. `php artisan wow:diff-spellbook` against the fixture — expect Mind Blast/Penance to resolve (given current DB state, report what the diff actually flags for them; per the 2026-08-02 session, Mind Blast may flag `MISSING_AVAILABILITY` for Discipline until the `free=(...)` parser fix lands — that flag appearing is the tool *working*).
5. Feature test covering: import creates snapshot + entries, duplicate import skips, diff flags a spell id absent from `spells` as `MISSING_SPELL`. `php artisan test --filter=Spellbook` — expect green.

## Notes for implementation

- Investigate the codebase directly for all conventions (command signatures, service placement, test style) — do not infer them from this plan.
- The Lua API surface is the one part that cannot be verified from the repo. Deliver Part 1 as a proposal with `UNVERIFIED` markers and wait for in-game confirmation before finalizing; Parts 2–3 can proceed against the fixture file in parallel.
