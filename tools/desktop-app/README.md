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

## Getting it

Signed-in players download it from **mindcollector.com/wow/coach/app** (`DesktopAppController`).
The link is unlisted while two players test it. The exe is not in git; after `dotnet publish`, copy
`publish\MindCollector.exe` to the server's `storage/app/desktop/MindCollector.exe`.

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
