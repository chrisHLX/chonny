# MindCollector desktop app

The downloadable version of the desktop app: it needs no copy of this project, no PHP and no
database. Built 2026-10-08 for a two-player test (Chriso and Cbags) of the app as a shipped
product talking to the live site. The developer's PowerShell app (`tools/log-manager`) stays as it
is, for the archive and the research tools.

## What it does

1. **Connects with the player's key.** The player makes the key on mindcollector.com under Your
   games > Upload (`/wow/coach`) and pastes it into Settings. The app checks it with the site
   before saving it, and stores it encrypted for that Windows user.
2. **Watches WoW's Logs folder** and finds finished arena rounds. It uses the same rule as the
   site's browser upload: a round runs from `ARENA_MATCH_START` to `ARENA_MATCH_END` or to the next
   start. A round still being written is left alone, and each log is read on from where the last
   read stopped. (`RoundScanner`)
3. **Sends each finished round's log text** to `POST /api/coach/round`, compressed, in 768 KB
   parts when larger. The **server** measures every round itself (`CoachUploadController` →
   `ingestRound()`), the same code `wow:sync` runs; the app never sends a measurement. After a
   batch, and only between shuffle lobbies, it calls `POST /api/coach/done`, which assembles the
   lobbies and builds the player's pages in the background. (`Syncer`, `ApiClient`)
4. **Shows the player's pages** (Games, Improve, Comps, Classes) from `GET /api/coach/index` and
   `GET /api/coach/page/{file}`, in WebView2. Unchanged pages are not downloaded again (ETag), and
   the last copy shows when offline. (`MainForm`)
5. **Installs the arena-log addon** (`AddonInstaller`, at start and from Settings). The addon is
   built into the app from `tools/wow-addon/MindCollectorArenaLog`, and goes into
   `_retail_\Interface\AddOns`, beside the Logs folder. It never overwrites a copy it did not put
   there (no `.installed-by-mindcollector` marker) unless the player clicks *Install the addon*.
   A fix to the addon reaches every player with the next app version.
6. **Moves each log out of WoW's folder** once all of it is sent and WoW has let go of it: it copies,
   checks the size, then deletes. Moved logs are kept. When the site's measure version goes up, the
   app sends every kept log again so old games are measured with the new code.

7. **Records each arena game with OBS** (0.5.6, 2026-10-25, off until turned on in Settings). See
   "Recording" below.

8. **This game, notes and marks** (0.5.7, 2026-10-25, from the developer app). See "This game"
   below.
9. **A character picker** over the Games list, once the games hold two characters (remembered).

Not in this version: linking one game logged by two players.

## This game, notes and marks (0.5.7, 2026-10-25)

What the developer app (`tools/log-manager`) had beside its matches, brought here so it could be
retired:

- **"This game"** (first in the navigation, with a gold dot while you are in an arena): the arena,
  the bracket and round, a clock from the gates, and both teams in class colours with each player's
  Gladiator seasons and best 3v3. `LiveGame` reads the log as the Recorder follows it (once a
  second, whether or not recording is on); the experience comes from the website
  (`POST /api/coach/players`, known players at once, the rest looked up and asked again 15s later).
  The enemy team appears as the gates open: until then the log holds only your own team, and WoW
  often writes the prep only at the gates. Checked on the 25 Oct 11:41 shuffle with
  `--check-live`: every round's two teams and specs match the arena frames in that game's video.
- **Notes:** the box on "This game" writes to the round being played (a prep-room note joins the
  round that follows), and a box under every game's card writes to that game. Either can keep a
  note "for next game". **Ctrl+Shift+M** from inside WoW marks the moment with a sound; click the
  mark on "This game" afterwards to write what happened (Settings has no switch: `MarkHotkey` in
  settings.json turns it off). The tray menu has "Mark this moment" too.
- **Where notes go:** `POST /api/coach/note` (CoachAppController) into `arena_notes`. A note during
  a game has no game yet, so it carries its time (and round) and the website files it by time when
  the cards are built, the rule the developer app's notes.json used (GameCardService::attachNotes).
  Notes are kept in settings.json until the website answers "stored" (a PHP error page arrives as
  200 OK and must not count), so none is lost offline or before the website has the endpoint. The
  app's own id makes a retry or a written-up mark update in place. The developer app's notes.json
  is brought over once.

## Developer mode (2026-10-25): the local archive without the developer app

On the developer's PC this app does the developer app's other job: after each lobby is sent, it
reads the logs into this project's local archive and database with the same two commands the
developer app ran (`wow:ingest-combatlog "<log>"`, then `wow:sync --skip-ingest`), then rebuilds the
Watch pages when the app records. The research tools, `wow:population` and the Watch page read what
these write. It is set in settings.json only, never in Settings, because a player has no copy of
the project:

```json
"LocalSyncDir": "C:\\Users\\chris\\Herd\\chonny",
"LocalSyncUser": 2,
"LocalSyncSince": "2026-10-25T13:40:00"
```

- `php` must be on the PATH (Herd puts it there) and Herd's MySQL running; a failed run is logged
  and tried again after the next game or when WoW closes.
- A log is moved out of WoW's folder only once the local archive has read it, so "Move logs" can be
  on (Chriso's PC: to `D:\MindCollector\wow-logs`, where the developer app put them).
- `WARNING:` lines from the ingest (a new patch or log version) come up as a tray balloon, as they
  did in the developer app.
- Logs last written before `LocalSyncSince` are left alone: the developer app had read them.
- **Trap (2026-10-25):** "Move logs to" is also read for logs to send, so pointing it at a folder of
  logs another app already sent sends them all again (it did: about 95 rounds re-measured on the
  one-core server before it was stopped). Mark such logs as sent first: a `Files` entry per log with
  `Offset` and `LocalOffset` at its length.

## Recording (0.5.6, 2026-10-25)

The app drives the player's own OBS; it does not record anything itself (docs/vision-experiment.md:
"do not build a recorder"). OBS has a remote control built in since 28, obs-websocket 5 on
127.0.0.1:4455, and the app talks to it with no library (`ObsClient`).

- **Setting it up:** Settings > Set up OBS. It reads OBS's port and password from OBS's own
  settings file (`%APPDATA%\obs-studio\plugin_config\obs-websocket\config.json`), so nothing is typed
  or kept here; turns the server on in that file if it is off and OBS is closed (an open OBS writes
  its own copy back on exit, so then it says where to turn it on); opens OBS in the tray; connects;
  and checks the recording format, since WebView2 plays MP4 but not MKV. (`ObsSetup`)
- **When:** `GameTrigger` decides, `Recorder` reads the newest log once a second. It starts when you
  zone into an arena, so the prep is in the video: a new log appearing (the addon turns logging on as
  you zone in) or a `ZONE_CHANGE` naming an arena's instance id, for a log left on all session.
  `ARENA_MATCH_START`, written as the gates open about a minute later, starts it too if nothing did,
  and marks each round. It stops 8s after `ARENA_MATCH_END` (one per shuffle lobby, so a shuffle is
  one video), or when WoW closes, after 90 minutes, or for a game that never wrote its END, at the
  next game's START or after 20 minutes with nothing written. A video with no game in it after 4
  minutes is deleted. OBS is opened if it is closed.
- **WoW writes its log in bursts**, so the file goes quiet mid-game: on 25 Oct round 6 ran 3.5
  minutes with nothing reaching the disk, and a "3 minutes quiet" stop cut that video 35s short.
  Quiet is now a sign only before a game. The same buffering held the zone-in back until round 1's
  START, so the prep is recorded only when the arena opens a new log (the first arena of a WoW
  session); later arenas in that session start at their gates, about 0.4s after the START line.
- **OBS is left as found.** Its folder is pointed at the videos folder only while a recording starts
  and put straight back; a recording the player started is never touched; only the app's own videos
  are ever deleted (Settings: "Keep up to (GB)", 100 by default, oldest first).
- **Each video has a timing file** beside it, `<video>.mindcollector.json`: the wall-clock time of the
  first frame (OBS's RecordStateChanged STARTED event) and each round's `ARENA_MATCH_START`, both on
  this PC's clock, which is the combat log's clock too. RoundAnalysisService's clock is zero at the
  START line, so round n is (its START minus the first frame) seconds into the video, to the
  millisecond. `php artisan wow:watch-page --user=N --videos=FOLDER` reads it, opens the video by
  itself and places every round with no lining up by hand.

**Checked 2026-10-25:**
- `--check-recorder` over the 19 Oct log (two shuffles, 28 3v3s, logging on all session) and the
  25 Oct shuffle (its own log): 31 videos, all 40 STARTs placed, about 53s of prep before every 3v3
  and 59s before each shuffle (the replay reads line times, so it cannot show the buffering below).
  The one 3v3 with no END runs 24 minutes, to the 20-minute rule: a long video, but a whole one.
- `--obs-test 6` against OBS 32.2.2 (obs-websocket 5.7.4) on Chriso's PC: the server turned on, OBS
  opened in the tray, a 6s MP4 written into the videos folder, and OBS's own folder (`D:/`) put back.
  The timing file's first frame was 11:21:31.280; OBS's own log says "Recording Start" at
  11:21:31.289. A cold OBS took about 6s from the request to the first frame, well inside the prep.
- A page opened from disk (as the Watch page is) loads the video by its `file:///` address in Edge.
- **The first real game (25 Oct, 11:41 shuffle):** recorded from 0.4s after round 1's START, all six
  rounds in the timing file, 851.8s of video against 852.1s between the file's start and stop.
  Frames pulled at the page's computed positions: 2-4s before round 1's measured death the teammate
  is alive and 2.5s after it "Round Lost / Phergo has died" is on screen; round 4, eight minutes
  later, the same 2.5s to "Round Won" with the warrior skulled. The same lag in both means no drift.
  That game is also where the buffering above was found (it stopped on the old quiet rule).

**The Videos tab (2026-10-25, `VideoLibrary`):** every video in the videos folder, newest first, named
after its game when its round 1 matches a game on the website (the index's `playedAt` is that START
cut to the second). It plays in the window: WebView2 serves the folder under a made-up host
(`SetVirtualHostNameToFolderMapping`), so nothing is copied and no file:/// address is needed. A
button per round jumps to its start, from the timing file. A game with a video shows ▶ in the Games
list and a "Watch the video" button on its card; the video page links back to the game.

Not yet: the Watch page (the timeline under the video) inside this app's window (its pages come from the website, and a local video
needs a local page), and the prep for later arenas of a session.

## A first sync, and an empty list (0.6.0, 2026-10-26)

The first stranger to connect (26 Oct) sent 113 rounds from two weeks of logs over eight minutes
while the window said only "Watching your WoW logs" and the list stayed empty, and the app stopped
before it asked the website for pages ("done" came only after the last log), so they had none.

- **Progress:** `Syncer.Progress` after every 5 rounds and each log; the status line and the empty
  list say "Sending your games from the last two weeks: 45 sent so far, log 3 of 9. Keep the app open".
- **Pages as it goes:** "done" after each finished log of a catch-up, and the list refreshes while
  the sync is still running, so games appear as they are built. The website also builds pages two
  minutes after any upload (`BuildCoachPages::backup`), for apps older than this.
- **Samples:** an empty Games list shows "Your games arrive here" and the website's three samples
  (`/wow/sample/page/{game,shuffle,watch}`: real games, names hidden), opened in the window.

Checked: `--sync-once` against a local site with three copied logs: progress at 5, 6, 10, 14, 15
rounds, pages asked for after logs 1 and 2, done at the end, 15 sent, none failed.

## Finding WoW (0.6.1, 2026-10-26)

A player in China connected and the app never read a log: it had looked for WoW in two registry keys
and six folders, once, at start. `AppSettings.DetectWowLogs()` now tries, in order: the running game
itself (`Wow.exe`'s folder), every uninstall entry naming World of Warcraft or 魔兽世界 (32- and 64-bit
views, and this user), Battle.net's installed-games list (`ProgramData\Battle.net\Agent\product.db`,
its paths are plain text: on Chriso's PC it gives `C:/World of Warcraft`), the usual folders, then every
fixed drive at the top and one folder down. While WoW is still missing the app looks again: the
running game every tick, the whole search every minute, so starting WoW afterwards fixes it.

## A round that never ends, and a refused round (0.6.2, 2026-10-27)

A player in China sent 130 rounds in 1,434 uploads. Two causes, both fixed:
- **A round with no ARENA_MATCH_END ran on to the next START**, through whatever was logged in
  between, past the server's 24 MB. `RoundScanner` now also ends a round at a ZONE_CHANGE (leaving
  the arena). In 38 of Chriso's logs every ZONE_CHANGE inside a round was the player leaving for
  Silvermoon, never one mid-round; the two such 3v3s of 25 Oct still read with all six specs. The
  server cuts at the same place (`ArenaReviewIngestService::untilLeaving`) for older apps.
- **A refused round held its whole log back.** `Syncer` treated "rejected" like a network failure,
  so the log's offset never moved and every round in it was sent, and measured, again each pass.
  "rejected" and a 422 are now final: logged in Activity and passed over. The server also answers
  a round too large even after the cut with "skipped", which older apps already pass over.
- **The server's limit was too low anyway:** 24 MB, against real 3v3s of up to 17 MB for 6.5 minutes.
  It is 48 MB now (memory measured in `ArenaReviewIngestService::MAX_ROUND_BYTES`).

## YouTube uploads (2026-10-26, shown once the website has it set up)

**Who sees it (2026-10-27):** until Google verifies the app only its test users can link, so the
website offers YouTube (and the index's `youtube` flag shows the app's row) only to admins and to the
accounts in `YOUTUBE_TESTERS` (user ids; Chriso's app sends to account 8). `YOUTUBE_PUBLIC=true` opens
it to everyone once Google has approved. Google project: `mindcollector-youtube`.

The Videos tab's first row, **Upload to YouTube…**, opens `YouTubeQueueForm`: the recordings matched
to a game and not on YouTube yet, the last day's ticked, and who can see them (unlisted unless the
player picks otherwise). For each ticked game the website's `/api/coach/youtube/prepare` gives a
short-lived token and the title and chapters (written from the game, specs never names), then
`YouTubeUploader` sends the file straight to YouTube (resumable, 8 MB pieces, resumes after a drop,
a fresh token after an hour), reports the id to `/uploaded` (the game's Watch page then plays it from
YouTube) and notes it in the video's timing file (`"youtube": {id, privacy, at}`), so it is never sent
twice and the list says "on YouTube". The player links the channel on the website (Your games >
YouTube). The row appears only when the website's index says `youtube: true` (its Google client set
up); shipped in 0.6.1 hidden, and to be checked with one real upload before it is relied on.

**Sharing (2026-10-28).** The row is now **YouTube: upload and share…**, and the window has a second
half, **On your channel**: each uploaded video as the website last heard from YouTube
(`/api/coach/youtube/videos`): public, unlisted, private or gone, still processing, in the video
library, its views. **Open its page** and **Copy link** give the game's page on the website
(`/wow/videos/{id}`, the video with its timeline, names hidden), the one to send to people; **Manage on
the website** opens Your games > Videos. Each privacy choice says under it what it means for that page:
a private video plays for nobody else, on YouTube or on the website. Telling unlisted from public needs
the website's `YOUTUBE_API_KEY` (Google will not let the upload link read, and oEmbed plays both the
same): with it, a video uploaded unlisted and made public in YouTube Studio joins the library within 15
minutes; without it, it never does (2026-10-30).

**Picking (2026-10-29).** The upload list holds every recorded game, the ones on YouTube marked "on
YouTube" and never tickable. A character picker (shown once there are two characters, starting on the
Games tab's) narrows it, **Tick all** and **Untick all** act on what it shows, and the button says how
many go ("Upload 3 games"). Only shown games stay ticked, so the count is what is sent.

**Not a tester yet (0.6.4, 2026-10-29).** When the website is set up for YouTube but the player is not
one of its testers, the index says `youtubeInvite: true` and the Videos tab's first row reads
**YouTube uploads: testers for now**; clicking it opens Your games' YouTube box, which says to ask on
Discord. Adding a tester, and what Google needs to open it to everyone: `docs/youtube-verification.md`.

## To improve later

- **A card sooner after a game (noted 2026-10-27, held until it is quieter).** Measured on a player's game
  of 27 Oct 01:56:38 UTC: sent and "done" at 01:57:01, card written 01:57:03, opened 01:57:25. Of the
  ~25s, the server takes 2; the rest is the app waiting for the log to be quiet (`Syncer.Quiet`, 8s)
  and for its next tick (`MainForm._timer`, 10s). Send a round as soon as its ARENA_MATCH_END (or
  ZONE_CHANGE) is in the log, for example with a FileSystemWatcher on the live log, instead of
  waiting for the quiet period and the tick: about 5-10s from the end of a game to its card.

## Getting it (2026-10-11): one file, one click

A new player is sent to **mindcollector.com/wow/app** (public, unlisted): what the app does and three
steps. From there:

1. **Download** (`/wow/coach/app`, public: the app does nothing until it is connected to an account).
   The exe is not code-signed, so SmartScreen warns; the page says to click More info, Run anyway.
2. **First run installs it** (`Installer`): copies itself to `%LOCALAPPDATA%\Programs\MindCollector`,
   adds Start menu and desktop shortcuts, starts with Windows in the tray (Settings turns it off), and
   opens the installed copy with `--first-run`. Opening a newer download later replaces the installed
   copy the same way, closing the running one first: that is how an update installs.
3. **Connect** (`MainForm.ConnectAsync`): on `--first-run` the app opens `/wow/app/connect?code=...`
   with a fresh random code. The player signs in or makes an account (the intended URL brings them
   back), clicks Connect, and the app picks its key up from `/api/coach/connect/{code}` within two
   seconds (`AppDownloadController`). No key to copy; the Connect button in the app and in Settings
   starts the same flow again.
4. **WoW is found** from Battle.net's registry entry (`WowRegistry`), then the usual folders; the addon
   installs beside it.

Opening the app while it is in the tray brings its window up instead of a message.

Checked end to end on 2026-10-11 against a local server with a throwaway account: first run, Connect,
approval, the key picked up, one real round sent, measured and built, and the game in the library.

**Testing without touching the real install:** set `MINDCOLLECTOR_DATA` to a folder (its own data,
its own single-instance lock, and no self-install); add `MINDCOLLECTOR_NO_BROWSER=1` to have Connect
write its link to the activity log instead of opening a browser.

**Updates itself (0.5.0, 2026-10-11).** The pages are the website's, so a page, analysis or design
change reaches every player with a deploy. The window around them (this code) checks
`/api/coach/app-version` at start and every three hours; when the website serves a newer version and
WoW is not running, `Updater` downloads it and the installer swaps it in, back in the tray. Players on
0.4.0 or older have no updater and need one more download from /wow/app.

**Publishing a new version:** bump `<Version>` in the csproj, `dotnet publish`, then upload
`publish\MindCollector.exe` to the server's `storage/app/desktop/MindCollector.exe` **and** write
`storage/app/desktop/version.json` as `{"version": "x.y.z"}` (DEPLOY.md, "Uploading a file"). Upload
the exe first: an app that sees the new version before the new exe is there downloads the old one,
finds nothing newer to install, and tries again in three hours.

## Building

The .NET 8 SDK is in `%LOCALAPPDATA%\Microsoft\dotnet8` on Chriso's PC (installed with
Microsoft's `dotnet-install.ps1`, so it is not on the PATH):

```powershell
$dn = "$env:LOCALAPPDATA\Microsoft\dotnet8\dotnet.exe"
& $dn build -c Debug                                        # bin\Debug\net8.0-windows\win-x64\MindCollector.exe
& $dn publish -c Release -o publish                         # publish\MindCollector.exe: one file, .NET inside
```

The published exe carries the .NET runtime, so a tester installs nothing else. WebView2 comes with
Windows 11. The exe is not code-signed, so Windows SmartScreen warns on first run ("More info" >
"Run anyway"). That is acceptable for testers and not for the public.

## Checking it without the window

Each switch writes its report to the app's data folder (`%APPDATA%\MindCollector Desktop`, or
`MINDCOLLECTOR_DATA` when set, which tests use so a real install is never touched):

- `MindCollector.exe --check-log "path\to\WoWCombatLog-....txt"` lists every round the app would
  send from that log and sends nothing (`check-log.txt`). Ask a tester for this when their games do
  not show up.
- `MindCollector.exe --install-addon --logs FOLDER [--replace]` installs or updates the addon for that
  WoW Logs folder (`install-addon.txt`). Checked against a fake WoW folder: installs when missing,
  leaves an identical or a foreign copy alone, updates its own copy, replaces a foreign one only
  with `--replace`, and writes nothing to a folder that is not WoW.
- `MindCollector.exe --sync-once --server URL --key KEY --logs FOLDER [--move FOLDER]` runs one sync
  pass (`sync-once.txt`).
- `MindCollector.exe --check-recorder LOG [LOG...]` replays logs through the recording rules and lists
  where each video would start and stop and where each round falls in it (`check-recorder.txt`).
  Records nothing.
- `MindCollector.exe --check-live LOG [FROM-LINE]` replays a log as "This game" reads it and lists each
  round's two teams, specs and which player is you (`check-live.txt`).
- `MindCollector.exe --check-notes --server URL --key KEY [--players NAME...]` brings the developer
  app's notes over, writes a note, a mark and a next-game note, sends them, and asks about the
  players (`check-notes.txt`). Run it with `MINDCOLLECTOR_DATA` set, against a local server.
- `MindCollector.exe --obs-test [SECONDS]` finds and opens OBS, connects, and reports what it records;
  with SECONDS it records that long into the videos folder as a game would (`obs-test.txt`). Run it
  with `MINDCOLLECTOR_DATA` set to keep the clip out of the real videos folder. From PowerShell, do
  not use `Start-Process -Wait`: it also waits for the OBS the test opened.

**Checked 2026-10-08:**
- `--check-log` on the 30 Sep shuffle log found the same 6 rounds, with the same start times, as
  `wow:ingest-combatlog`.
- `--sync-once` against a local server with a throwaway account:
  - 6 rounds sent, including the 8 MB final round in parts;
  - stored at analysis version 11 and assembled into one lobby;
  - pages built, and the log moved.
- A second run sent nothing.
- The window listed the game and showed its card with icons from the site.

A local test server must be Laravel's router started from `public`
(`php -S 127.0.0.1:8321 ...\Illuminate\Foundation\resources\server.php`). Plain `php -S -t public`
answers any `.html` path as a missing static file and never reaches the API.

## Data

- `%APPDATA%\MindCollector Desktop\settings.json`: the site, the key (encrypted), the folders, and how
  far each log has been read.
- `pages\`: the last copy of each page, with its ETag.
- `activity.log`: what the app did, rolled over at 1 MB.
- `WebView2\`: the browser control's own cache.

It is separate from the PowerShell app's `%APPDATA%\MindCollector`. **Since 2026-10-25 the PowerShell
app no longer runs on Chriso's PC:** this app sends the games, moves the logs to
`D:\MindCollector\wow-logs`, and keeps the local archive in developer mode. Running both again would
send every round twice (harmless, as the server keys a round by its match, but wasted work) and the
PowerShell app could move a log before this one has read it.

## The Microsoft Store build (2026-10-19)

The downloaded exe is not code-signed, so Windows warns about it. The Store version is signed by
Microsoft when it is published, so it installs with no warning. Individual developer accounts are
free; the account and the reserved name "MindCollector" are Chriso's (Partner Center, Product
identity), and `store/AppxManifest.xml` carries its three identity values, which must match exactly.

- **Build:** `powershell -ExecutionPolicy Bypass -File tools\desktop-app\store\build-store.ps1`
  gives `store\out\MindCollector-<version>.msix` to upload. Needs the .NET 8 SDK and makeappx /
  makepri (the Windows SDK, or the Microsoft.Windows.SDK.BuildTools NuGet package, passed as
  `-Tools`). Each upload needs a higher `<Version>` in the csproj.
- **What differs (`-p:Store=true`, the `STORE` constant):** no self-install (`Installer`) and no
  self-update (`Updater`): the Store installs and updates it. Starting with Windows is the package's
  StartupTask (`StoreStartup`), on from the first launch; one the player turns off in Task Manager
  can only be turned on there again. The first launch goes straight to Connect, as `--first-run`
  does for the download. Settings and the WebView2 data stay in %APPDATA%, which Windows keeps
  per package.
- **Not tested on this PC:** an unsigned package installs only with Developer Mode on, which is
  off here. The Store's certification runs it before publishing.
