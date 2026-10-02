# MindCollector Logs

A small Windows program that moves your arena games from WoW into the archive for you.
Double-click **`MindCollector Logs.vbs`** to open it. To get a shortcut with the app's icon on
the desktop and in the Start menu, run this once:
`powershell -ExecutionPolicy Bypass -File tools\log-manager\Install-Shortcuts.ps1`.

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
   with the shuffle rounds grouped into one lobby. Click a game to see its **card**. The card is
   a page drawn in Windows' built-in browser control, with spec and spell icons and class
   colours. It shows:
   - who you played and how experienced each of them is (Gladiator seasons, best 3v3 rating,
     title);
   - how each death happened: the killing blow, who did the damage, your healer's state, and the
     defensives used in the last 30 seconds;
   - both sides' goes, defensives, interrupts, and time spent crowd-controlled;
   - for a loss, what the site's loss rules flag. That is an estimate, not a verdict;
   - **checks**: losses the log shows by itself. These are an offensive cooldown left sitting
     ready for a whole cooldown or more (counted from base cooldowns, so a lower bound), and a
     defensive put on a teammate who was already immune (Ice Block, Divine Shield, Aspect of the
     Turtle, Netherwalk). A shuffle shows only yours;
   - **damage and healing**: every player's damage onto enemy players, and healing and absorbs onto
     their own team, with the share of time spent not pressing anything. Click a player to see
     their abilities, with hits and overhealing. Yours opens by itself. A shuffle adds the six
     rounds together;
   - your notes and marks for that game.

   **Notes from the Matches page.** Under the card there is a box. Type a note, then:
   - **Add to this game** (or press Enter) puts it on the game you have selected.
   - **For next game** keeps it until you play again, then puts it on the first game that starts
     after you wrote it. Anything still waiting shows in gold under the box.

   Both are saved to the same `notes.json` as the notes you take during a game, and the card updates
   straight away.

   The breakdown and checks are measured when a game is synced (`RoundAnalysisService`, version
   5). Games synced before 2 Oct 2026 need `php artisan wow:sync --skip-ingest --fresh` once to
   get them.

   After each sync, and after a note, the app runs `php artisan wow:game-cards` to build the
   cards (`GameCardService`). It only formats what the sync already measured, and only redraws
   games whose rounds, notes or players' experience changed. A run where nothing changed takes
   about 1.3 seconds, most of it PHP starting. Each card is its own file in
   `%APPDATA%\MindCollector\cards\`, next to a small `index.json`. After changing spell data
   (icons), `--fresh` redraws them all.
   **Open Match Review** starts the local site on port 8321 and opens `/wow/game-review`.
5. **This game, beside your matches.** While WoW runs, the app follows the log as it is written.
   When you zone into an arena, a column opens on the right of the Matches page. It is part of
   the one window, never a window of its own, and it never takes focus, so you can keep the app
   on another screen. (Until 2 Oct 2026 this was a separate always-on-top panel that opened over
   WoW at every arena. That got in the way.) It shows the arena, the bracket, the round, and a
   clock from the gates. As the gates open it lists every player in their class colour, with
   your team and theirs split out, and looks up anyone whose experience isn't on file yet.
   - **Notes:** use the note box under the card. While this column shows, its main button reads
     **Add to round N** (or **Add to this game**) and Enter writes there. Otherwise it reads
     **Add to selected game**.
   - **Ctrl+Shift+M, from inside WoW,** marks the moment with a sound and nothing else. Pick the
     mark in the column afterwards and write what happened.
   - Notes are saved to `%APPDATA%\MindCollector\notes.json` as you write them, and appear on
     the game's card after the next sync. A note written in the prep room goes on the round that
     follows. A note written up to five minutes after a game still counts as that game's.
   - The enemy team can't be shown before the gates open: until then the log holds only your own
     team.
   - **Hide** closes the column until the next arena. Tray menu, **Show this game**, brings it
     back. It can be turned off in Settings.

   **The card is in tabs, so nothing needs scrolling:**
   - **Summary:** the teams, how it ended, and the checks.
   - **Damage & healing**.
   - **Numbers:** goes, defensives, interrupts, and what the loss rules flag.
   - **Notes**.

   A shuffle has **Rounds**, **Damage & healing** and **Notes**. The tab you are on stays open as
   you click from game to game.
6. **Settings** holds the three folders, auto-sync, auto-move, and "start with Windows". "Start
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
