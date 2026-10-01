# MindCollector Logs

A small Windows program that moves your arena games from WoW into the archive for you.
Double-click **`MindCollector Logs.vbs`** to open it.

## What it does

1. **Watches WoW's Logs folder** (`WOW_COMBATLOG_PATH`) for `WoWCombatLog-*.txt`.
2. **When an arena ends, it reads the game in.** The addon stops logging one second after
   a match. The app waits until the last arena marker in the file is an `ARENA_MATCH_END` and
   the file has been quiet for 20 seconds. Then it runs `php artisan wow:ingest-combatlog <file>`
   and `php artisan wow:sync --skip-ingest`. A Solo Shuffle writes a START per round but only one
   END per lobby, so it is never read mid-lobby. Reading it mid-lobby would store a part-lobby
   review that no later sync rebuilds.
3. **It moves each log out of WoW's folder** into "Move WoW logs to" (default
   `D:\MindCollector\wow-logs`). It does this only once every byte of the log has been read in
   successfully and WoW is no longer holding the file, which usually means after you close WoW.
   It copies first, checks the size, and only then deletes the original.
4. **It lists your games.** It shows every game read from your own combat log in the archive,
   with the shuffle rounds grouped into one lobby. Click a game to see its players and rounds.
   **Open Match Review** starts the local site on port 8321 and opens `/wow/game-review`.
5. **Settings** holds the three folders, auto-sync, auto-move, and "start with Windows". "Start
   with Windows" puts a shortcut in your Startup folder, so the app starts in the tray.

Closing the window keeps the app running in the tray. To quit, right-click the tray icon and
choose **Exit**.

## Where things are kept

- The WoW Logs folder and the archive are the two `.env` keys the site reads. The app edits
  those keys, so the site and the app always agree.
- "Move WoW logs to", the checkboxes, and which files have already been read are stored in
  `%APPDATA%\MindCollector\logs-app.json`.
- The Activity tab is also written to `%APPDATA%\MindCollector\logs-app.log`.

## Things to know

- **Herd's MySQL must be running.** Ingest looks up specs in the database. If a read fails, the
  app says so and tries again two minutes later.
- The moved logs on D are full backups. To re-read one, run
  `php artisan wow:ingest-combatlog "D:/MindCollector/wow-logs/<file>"`.
- It does not run `wow:refresh-match-derived`. That is still a deliberate step (CLAUDE.md,
  rule 15).
- Times are shown as the combat log wrote them, which is WoW's local clock. The ingest stores
  that wall-clock time as if it were UTC, so the app does not convert it again.
