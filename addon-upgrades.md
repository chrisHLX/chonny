# Addon upgrades

What I want next for getting arena games from WoW into MindCollector.

## What I want

**1. Make the process automatic.** Right now, after playing I have to run `wow:sync` (or
double-click `tools/arena.bat`) and then clear out WoW's `Logs` folder myself. I want games to
end up in the archive without me having to remember any of that.

**2. A program I can open to see my matches.** A desktop app that is basically a GUI over the
archive on the D drive (`D:\MindCollector\arena-logs\`): open it and see every match, and click
into one to see its data.

**3. Choose where the files go.** The app should let me set the archive location (and where to
read WoW's combat logs from) instead of editing `.env` by hand.

## Status (2026-09-30)

All three have a first version in `tools/log-manager/` (**MindCollector Logs**, a tray app;
see its README). It reads each game in when the arena ends, moves WoW's logs to
`D:\MindCollector\wow-logs` once they are fully read and WoW has let go of them, lists the
archive, and sets the folders. The paragraphs below describe the manual path it wraps.

## How it works today, for reference

- The `MindCollectorArenaLog` addon only turns combat logging on when an arena starts and off
  when it ends, and makes sure Advanced Combat Logging is on. It never moves or copies files.
- WoW writes `WoWCombatLog-*.txt` into `<WoW>\_retail_\Logs\` (`WOW_COMBATLOG_PATH`).
- `php artisan wow:ingest-combatlog` reads those, cuts out each rated game (one per Solo Shuffle
  round), and writes a compressed `raw/` file plus a `metadata/` JSON per game into the archive
  (`ARENA_LOG_ARCHIVE_PATH`, currently `D:/MindCollector/arena-logs`). Already-imported games
  are skipped, so re-running is safe.
- `php artisan wow:sync` runs the ingest, then builds reviews for any new games, which show up
  at `/wow/game-review`. `tools/arena.bat` runs the sync and opens that page.
- Nothing deletes the WoW log files, so the `Logs` folder keeps growing (about 1.9 GB by
  2026-09-27) until cleared by hand. Once a game is in the archive, the archive is the only copy
  that matters.
