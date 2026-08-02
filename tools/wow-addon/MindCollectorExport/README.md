# MindCollectorExport

Part 1 of the plan in `spellbook-verifier.md` (repo root). Exports the logged-in character's
spellbook + talent state to SavedVariables so it can be imported via
`php artisan wow:import-spellbook` and diffed against the imported spell data via
`php artisan wow:diff-spellbook`.

## Status: CONFIRMED working end-to-end as of 2026-08-02 (live-tested, Discipline Priest, patch 12.0.7)

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
4. Run `/reload` to flush SavedVariables to disk (WoW only writes SavedVariables on logout or
   `/reload`, not immediately after the slash command).
5. Find the exported file at:
   `WTF/Account/<ACCOUNT>/SavedVariables/MindCollectorExport.lua`
   (or `WTF/Account/<ACCOUNT>/<Realm>/<Character>/SavedVariables/MindCollectorExport.lua` if this
   ever becomes character-scoped instead of account-scoped — it currently is not; the `.toc`
   declares a single account-wide `MindCollectorExportDB`, so exporting a second character
   overwrites the first character's export in that file).
6. Copy that file wherever you're running the Laravel app from, then:
   ```
   php artisan wow:import-spellbook "path/to/MindCollectorExport.lua"
   php artisan wow:diff-spellbook
   ```

## What this does NOT do

- No automation of login/reload — manual trigger only.
- No talent tree topology (node positions, prerequisites) — only which talents are currently
  selected, for availability verification.
- No other characters / inspect API — self-character only.
- No description diffing yet — descriptions are captured and stored, not compared (Phase 2,
  not built).
