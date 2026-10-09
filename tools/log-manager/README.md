# MindCollector Dev (was MindCollector Logs)

A small Windows program that moves your arena games from WoW into the archive for you. Named
**MindCollector Dev** since 2026-10-10 (it was MindCollector Logs), to tell it apart from the
downloadable app testers use (`tools/desktop-app`, "MindCollector"), which needs no copy of this
project and sends rounds to the site to be measured there. This one is the developer's: it keeps the
archive, runs the measures locally and feeds the research tools. Re-run `Install-Shortcuts.ps1` after
the rename; it removes the old shortcuts.
Double-click **`MindCollector Logs.vbs`** (the launcher kept its file name) to open it. To get a shortcut with the app's icon on
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
   - for your team's first death, **what your team had for their go**: their offensive cooldowns
     and their crowd control on you, each player's lockout with the free moment before it, and
     every button your team had, sorted into ready and never pressed, pressed in their go, or
     already on cooldown (analysis version 7, 3 Oct);
   - whether the game was **harder, even or easier** before anyone pressed anything (the MMR gap
     and the Gladiator seasons on each side);
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
6. **Character, in the bar beside the pages.** The app finds your characters from the games
   themselves: each game marks the character whose log it came from. Pick one and the Matches list
   shows only that character's games, and the Improve page shows that character. "All characters"
   shows every game. The choice is remembered.
7. **Improve: what to work on.** One page per character, with the habits the match reviews found
   mattered (`match-review-analysis.md`, 2 Oct), each measured against every other player of the
   same spec in your games, both teams, so "behind" means behind the players you actually meet:
   - **Dispels:** what you dispelled off your team, per minute your team carried a debuff that
     players of your spec were seen dispelling. Only a dispel counts (a spell with a Dispel effect
     in the spell data), not a shapeshift or Phantasm breaking a slow. The debuffs left on your
     team most are listed.
   - **Crowd control on their healer:** the share of your team's goes with your CC on their healer,
     how often those goes killed, and which spells you used.
   - **Big defensives when their go started:** how often their go began with two or more of your
     team's 90s+ defensives on cooldown, and how often each case killed one of you.
   - **Defensives outside their goes**, and for a healer, a teammate dying while you were locked out
     with your Medallion ready.
   - **Time locked out**, in wins and losses, and for a damage dealer, **dying first**.

   Each shows your number, the others', your last 20 games against the ones before, and a small
   **chart by session** (each day you played, with the other players' level dashed). Your **last
   session** is set against the range of your sessions before it (3+ games each, 3 or more of
   them): outside it, it is your best or worst session yet; inside, within your usual range. The
   furthest behind comes first, and the top of the page names it as **your focus**: one habit at
   a time, with its chart. Under 10 games on either side a difference reads as a lead, not a
   finding. The page describes; whether a dispel is worth the global is your call.
   `wow:game-cards` writes the pages (`ImprovementService`) and redraws them only when a game
   changed. Dispels and big defensives need analysis version 6 (3 Oct): games synced before then
   need `php artisan wow:sync --skip-ingest --fresh` once.
8. **Comps: the comp library.** Every enemy comp you have met, grouped by their two DPS specs with
   any healer (exact three-spec teams barely repeat; the DPS pair is how comps are named, and TSG
   is a Warrior and a Death Knight whoever heals). Nicknames come from the repo's own guide titles.
   3v3, Solo Shuffle and 2v2 are separate comps, never pooled: a shuffle team is three strangers
   re-dealt every round and does not play like a premade. The **Comps** tab lists 3v3 comps and
   the **Shuffle** tab Solo Shuffle ones (2v2 comps are built but not listed).
   The list follows the character picker and shows that character's games and record against
   each. A comp's page reads every game against it, on any character. **Click any spell** for
   its tooltip: the description the site shows for it, its cooldown and its arena duration.
   - **their goes:** opens with **What to expect**, the section in a few sentences (their usual
     pair of cooldowns, the crowd control that repeats most, what they put on your healer, whom
     they go for). Then the offensive cooldowns in their goes and which they press together, their
     crowd control on you in the same order in 2+ goes (numbered steps, each marked with whom it
     landed on: your healer, their kill target, or your other DPS), what they put on your healer,
     and whom the goes were on;
   - **who dies:** yours and theirs, killing blows, and whether your healer was locked out;
   - **defensives traded:** what they answer your goes with, what their goes force from you, and
     how often a go of theirs killed with your big defensives up or down;
   - **ready and never pressed when they killed**, from the losses' answer sheets;
   - **less against more experienced teams**: the comp's games split by the team's Gladiator
     seasons (experience, not MMR: MMR is deflated early in a season and missing in Solo
     Shuffle), at the line that divides them most evenly. Each side shows your record, their
     goes, how often a go killed, your first death, your goes' kill rate and what they answered
     with. It appears once each side has 2 games;
   - **every game against them**: when, on which character, the result, both MMRs, their
     Gladiator seasons and the first death.

   Under 10 games a page says it is a lead. `wow:game-cards` writes the pages
   (`CompLibraryService`) and redraws them only when a game changed.
9. **Classes: the strongest player of each spec you met** (from 7 Oct 2026). For every spec you
   have played against, two players, grouped by class in the list:
   - **the highest rated:** the one you met at the highest team MMR (3v3 and 2v2; a Solo Shuffle
     round has none);
   - **the most experienced:** the most Gladiator seasons on their profile, then Rank 1 titles,
     then best 3v3 ever.

   One player can be both. The list shows every character's games (the picker does not filter it),
   with why each player is listed and your record against them. Click one for their page, read from
   every round you played against them:
   - **what they press:** every button, a minute free to act (alive and not crowd-controlled),
     grouped as rotation, offensive cooldowns, defensives, crowd control, interrupts, movement,
     other and pet. Beside each is the median player of the spec in the archive
     (`data/population/norms.json`), and **you** when you have played the spec yourself. A gap of
     half again, or under two thirds, is marked "more" or "less than most": a difference to read,
     not a fault;
   - **their output and habits** against the spec and you: damage (or healing) a minute free,
     time idle, time crowd-controlled, kicks, crowd control landed and how much of it went off their
     damage target;
   - **in their team's goes:** the offensive cooldowns they pressed and their crowd control on you,
     with whom it landed on;
   - **their defensives:** which, at what health, how many outside your goes, and their casts your
     team kicked;
   - **where their output came from**, and every round against them.

   Most of these players were met once or twice, and a page under 3 rounds says it is a glimpse.
   Presses need analysis version 10 (6 Oct). `wow:game-cards` writes the pages
   (`ClassLibraryService`, `player-{hash}.html`) and redraws them only when a game, the page's code,
   the norms or anyone's experience changed. Why the top's habits are a level and not a recipe:
   `docs/learning/population-findings-2026-10-07-top-tier.md`.
10. **Settings** holds the folders, auto-sync, auto-move, and "start with Windows". "Start
   with Windows" puts a shortcut in your Startup folder, so the app starts in the tray.
   **"Back up games to"** (empty by default) keeps a second copy of the game archive and your
   notes in a folder you choose: another drive, or a cloud-synced folder. It copies everything
   when you first set it, then new games after each sync. It uses robocopy, which copies only
   new and changed files and never deletes from the copy. The archive cannot be rebuilt once a
   combat log is gone, so this is the copy that matters. The moved logs are not included.
   **"Website key"** sends your games to your account on the website after each sync, so the same
   pages are at mindcollector.com/wow/coach when you are away from the PC. Make the key on that page
   (it is shown once) and paste it here. The app runs `php artisan wow:push-rounds`, which sends each
   game's archived slice of the log, in pieces when it is over 768 KB. The server measures it with the
   same code and builds your pages in the background. A change to a measure
   (`RoundAnalysisService::VERSION`) makes it send everything again, since the server keeps no raw log.
   What has been sent is in `storage/app/pushed-rounds.json`.
11. **A new patch or log format is flagged.** Each game records the combat log version and patch
    it was written in. When either differs from the one the measures were checked on, the tray
    says so once ("Check before trusting new games"). `docs/combat-log-ingest.md` says what to do.

Closing the window keeps the app running in the tray. To quit, right-click the tray icon and
choose **Exit**.

## Where things are kept

- The WoW Logs folder and the archive are the two `.env` keys the site reads. The app edits
  those keys, so the site and the app always agree.
- "Move WoW logs to", "Back up games to", the checkboxes, and which files have already been read
  are stored in `%APPDATA%\MindCollector\logs-app.json`.
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
