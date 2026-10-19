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

Not in this version: the live game column, notes and the Ctrl+Shift+M hotkey (the PowerShell app
has them), and linking one game logged by two players.

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

Both switches write their report to the app's data folder (`%APPDATA%\MindCollector Desktop`, or
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

It is separate from the PowerShell app's `%APPDATA%\MindCollector`, so both can run on one PC. **On
Chriso's PC, turn this app's "Move logs" off.** The PowerShell app moves each log to
`D:\MindCollector\wow-logs` after its own sync, possibly before this app has read it, and it already
sends his games to the site (`wow:push-rounds`). Both sending the same round is harmless, because
the server keys a round by its match and measures it again, but it is wasted work.

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
