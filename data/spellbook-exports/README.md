# Spellbook exports

Raw `MindCollectorExportDB` SavedVariables dumps, produced in-game via the `MindCollectorExport`
addon (`tools/wow-addon/MindCollectorExport/`) and committed here so they can be imported into
`spellbook_snapshots`/`spellbook_snapshot_entries` on any machine — no WoW install, WTF folder, or
manual file-copying required once the file is pulled from git.

Contains no personal/account data — just class, spec_id, client build, exported_at, the character's
talent loadout string, and the spellbook/talent/PvP-talent entries themselves (id/name/description).
No character name, account name, or realm.

## Workflow

**Capturing a new batch (on the machine with WoW installed):**
1. Follow `tools/wow-addon/MindCollectorExport/README.md` to export one or more characters —
   exports accumulate in the same account-wide SavedVariables file across `/mcexport` runs, so you
   can do your whole roster in one session before copying anything out.
2. Copy `WTF/Account/<ACCOUNT>/SavedVariables/MindCollectorExport.lua` into this folder as a new,
   dated file — e.g. `2026-08-20-batch2.lua`. **Always add a new file, never overwrite an existing
   one** — each committed file is a permanent record of that batch; overwriting loses history the
   same way the old root-level `MindCollectorExport.lua` file used to.
3. Commit it.

**Using a batch (any machine, once pulled from git):**
```
php artisan wow:import-spellbook "data/spellbook-exports/2026-08-13-batch1.lua"
php artisan wow:diff-spellbook          # diffs the latest snapshot
php artisan wow:diff-spellbook {id}     # diffs a specific one
```
Import is idempotent per-export (content-hash deduped), so re-running against a file you've already
imported on that machine just reports skips — safe to do.

## Files

| File | Contents |
|---|---|
| `2026-08-13-batch1.lua` | 8 characters: Priest/Discipline, Paladin/Holy, Druid/Feral, Hunter/Beast Mastery, Mage/Frost, Rogue/Assassination, Shaman/Restoration, Warrior/Arms (patch 12.0.7.68887) |
