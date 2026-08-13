# MindCollectorExport

Part 1 of the plan in `spellbook-verifier.md` (repo root). Exports the logged-in character's
spellbook + talent state to SavedVariables so it can be imported via
`php artisan wow:import-spellbook` and diffed against the imported spell data via
`php artisan wow:diff-spellbook`.

## Status: CONFIRMED working end-to-end as of 2026-08-02 (live-tested, Discipline Priest, patch 12.0.7); multi-character export fixed 2026-08-12

**2026-08-12 fix — exports now accumulate instead of overwriting.** Every `/mcexport` used to *replace* the entire `MindCollectorExportDB` table, so exporting Character B after Character A silently discarded A's data the moment SavedVariables flushed to disk (confirmed as a real bug, not a misunderstanding — `## SavedVariables: MindCollectorExportDB` is account-wide, not per-character, so this bit everyone regardless of which characters were on the account). Fixed: each export now writes into its own key (`CLASS_specID_timestamp`), so you can log into as many characters/specs as you want across a session (or many sessions) and every export survives in the same file. `php artisan wow:import-spellbook` was updated to match — one command now imports every export currently sitting in the file, deduping already-imported ones individually rather than skipping the whole file.

Every part of the export has now been confirmed live, in two passes:

1. First run (`## Interface: 120007`, after fixing an earlier expansion-mismatch load failure)
   reported 70–71 spellbook entries and 81 talents — confirming `C_SpellBook.*` (spellbook
   enumeration) and the `C_Traits.GetConfigInfo` / `GetTreeNodes` / `GetNodeInfo` / `GetEntryInfo`
   / `GetDefinitionInfo` talent node walk all work as written. That same run also surfaced two
   real bugs (loadout string returning `""`; PvP talents always reporting 0), both fixed — see
   git history / CLAUDE.md's "In-Game Spellbook Verifier" section for the root causes
   (`GenerateInspectImportString` vs `GenerateImportString`; `GetPvpTalentInfoByID` being a
   global with 11 positional returns, not a `C_SpecializationInfo` table call).
2. Second run after the fix confirmed all three: 3 real PvP talent entries (previously 0), a
   correct non-empty loadout string (`/dump C_Traits.GenerateImportString(...)` printed a real
   base64-looking string), and 0 descriptions pending (confirming the async
   `Spell:CreateFromSpellID():ContinueOnSpellLoad()` continuation pattern resolves cleanly).

Nothing in `main.lua` is marked `UNVERIFIED` anymore. Re-verify only if a future WoW patch
changes these APIs — same process: log in, `/mcexport`, sanity-check the printed counts.

## Usage

1. Copy this whole folder (`MindCollectorExport/`) into `Interface/AddOns/` in your WoW
   installation, so the path is `.../Interface/AddOns/MindCollectorExport/MindCollectorExport.toc`.
2. Launch WoW, log into the character you want to export, make sure `MindCollectorExport` is
   enabled in the AddOns list. **If it shows "Incompatible"**, don't just rely on "Load out of
   date AddOns" — that toggle does not reliably load an addon whose `.toc` Interface number is a
   full expansion behind the client (confirmed: it silently fails to load at all, no error, no
   slash command, even with the box checked). Instead run `/dump GetBuildInfo()` in-game, take
   the 3rd return value (interfaceVersion, e.g. `120007`), and set `## Interface:` in the `.toc`
   to that number exactly, then `/reload`.
3. Run `/mcexport` in chat. Watch the printed summary line — if it reports pending descriptions,
   wait a few seconds and run `/mcexport` again (the addon re-checks the client cache each time;
   this is not automatic/polling, by design — see spellbook-verifier.md's "no busy-wait" rule).
   Re-running it doesn't lose the previous attempt — it just adds another entry (see step 5) —
   so import whichever timestamp for that character ended up with full description coverage.
4. Run `/reload` to flush SavedVariables to disk (WoW only writes SavedVariables on logout or
   `/reload`, not immediately after the slash command).
5. **To export more characters/specs, just log into them and repeat steps 3–4 — no need to copy
   the file out or run the import command in between.** Each export is stored under its own key
   (`CLASS_specID_timestamp`) inside the same account-wide `MindCollectorExportDB` table, so
   nothing gets overwritten. Export your whole roster first, then import once at the end.
6. Find the exported file at:
   `WTF/Account/<ACCOUNT>/SavedVariables/MindCollectorExport.lua`
   (account-wide, not per-character — the `.toc` declares a single `MindCollectorExportDB`,
   which is exactly why step 5's key-per-export design matters).
7. Copy that file into `data/spellbook-exports/` in the Laravel project as a new, dated file (e.g.
   `2026-08-20-batch2.lua`) — see that folder's own README for why it must be a *new* file, never
   an overwrite of an existing one. Then:
   ```
   php artisan wow:import-spellbook "data/spellbook-exports/2026-08-20-batch2.lua"
   php artisan wow:diff-spellbook
   ```
   The import command reports one line per export found in the file (`Created snapshot #N from
   'CLASS_specID_timestamp' ...`) and a final summary (`Done: N snapshot(s) created, N skipped`).
   Skipped means already-imported (per-export content hash, not per-file) — safe to re-run after
   exporting more characters later; it only ever imports what's new. Once committed, any machine
   can `git pull` and run the same import command locally — no WoW install or WTF folder needed
   there, since `spellbook_snapshots` is a per-machine DB table populated from the committed file.

## What this does NOT do

- No automation of login/reload — manual trigger only.
- No talent tree topology (node positions, prerequisites) — only which talents are currently
  selected, for availability verification.
- No other characters / inspect API — self-character only.
- No description diffing yet — descriptions are captured and stored, not compared (Phase 2,
  not built).
