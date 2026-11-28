# What runs on its own

Every MindCollector task that runs without someone starting it: what triggers it, where it runs, and
how you find out it failed. **Read this before adding a scheduled or background task**, and add the
new task here the day it ships (written 2026-11-24, when the daily check email was added).

The server's clock is **Sydney time** (AEDT/AEST, so it follows daylight saving). Every time below
is Sydney time unless it says otherwise.

## On the server, on a timer

There is **no Laravel scheduler**: `schedule:run` isn't in cron, so anything in `routes/console.php`
(`Schedule::command('next-steps:expire')`) never runs on live. Each timed task is its own cron line,
run as `www-data` and logged to its own file. `crontab -l` as root shows them; `DEPLOY.md` has the
exact lines.

| When | What | Log | If it fails |
|---|---|---|---|
| 6pm daily | `wow:pvp-leaderboards`: every PvP leaderboard of the season, US and EU (~170 Blizzard calls, ~380,000 entries, a few minutes) | `storage/logs/leaderboards.log` | The daily check marks Leaderboards failed after 26 hours |
| 7am daily | `ops:daily-report`: **the daily check email** to `ADMIN_EMAIL` (below) | `storage/logs/daily-report.log` | No email that morning |

The system's own timers (`systemctl list-timers`): **certbot** renews the HTTPS certificate twice a
day once it's within 30 days of running out; **logrotate** daily; **apt** downloads and installs
security updates daily. MySQL keeps its binary logs 3 days (`binlog_expire_logs_seconds = 259200`,
set 2026-11-23); they'd filled 20 GB before that.

**Nothing backs up the database.** The one copy is a manual dump from 7 Sep. Chriso's call
(2026-11-23) for now.

### The daily check email (`ops:daily-report`)

`app/Console/Commands/DailyReport.php`. One email each morning, the things to look at listed first
and named in the subject ("all clear" otherwise):

- **Website:** the home page loads, and how fast.
- **Queue workers:** a test job (`QueueHeartbeat`) goes through, and how many jobs are waiting.
- **Failed jobs:** jobs that gave up in the last 24 hours, in words, with the player each was for.
- **Leaderboards:** both regions pulled within 26 hours.
- **Errors logged:** errors in `laravel.log` in the last 24 hours, the commonest three (a warning at 20).
- **Disk space:** a warning under 15% free.
- **HTTPS certificate:** days left.
- **In the last 24 hours:** new accounts by name, arena rounds and Forever scans by player, the last deploy.

API keys in logged URLs are hidden before mailing. `--dry-run` prints it, `--html` prints the email's
HTML to look at, `--to=` sends it elsewhere. It's plain PHP on the server: no Claude, no PC.

## On the server, when something happens

Two queue workers run under Supervisor (`/etc/supervisor/conf.d/`), both on `cards,default`:
`laravel-worker` (300s timeout) and `mindcollector-worker` (120s). A failed job goes to `failed_jobs`
(`php artisan queue:failed`) and shows in the next morning's email.

| Trigger | What runs |
|---|---|
| The desktop app uploads a round | The round is measured in the request (`CoachUploadController`). Then: `BuildCoachPages` draws the player's game pages (cards first, in parts of 85s); `FetchPlayerExperience` after 30s looks up opponents' rating history; `RefreshPopulationNorms` after 120s rebuilds the norms |
| A browser upload on the site | The same, through `ArenaUploadController` |
| A note or a YouTube link from the app | `BuildCoachPages`, cards only |
| A Battle.net account is linked | `SyncBattlenetCharacter` |
| A Forever auction scan arrives | Stored and folded into that market's prices in the request (`docs/forever/observer-handoff-2026-11-10.md`). Crafting Profits (`/wow/forever/crafts`) reprices that market on its next view: no timer, the cache is keyed on the newest scan |

**Known timeouts** (2026-11-23): `BuildCoachPages` for big accounts (Muroto, 468 rounds) sometimes
runs past its limit, and the next upload's build catches it up. Both show as warnings in the email.

**`BuildCoachPages` timing out on spell tooltips** (9 of its 12 failures from 17 to 26 Nov, fixed
2026-11-27): every one died in `SpellTips::kitEntries()`. `CompLibraryService::tip()` asks the
container for `SpellTips` once per spell, and with no binding each ask was a new instance, so every
tooltip not yet in the cache re-read all 40 spell kits (21 MB, 0.26s a read on the server). The
tooltip cache is keyed by the deployed code, so after each deploy the next player's Classes page needed
a few hundred of them. It is now `scoped` in `AppServiceProvider` (one per request or job): 120 cold
tooltips went from 27.7s to 0.4s with identical results. **A class that keeps a memo must be bound
`scoped` (or held in one variable) before it is fetched with `app()` in a loop.**

**Jobs during a deploy** (2026-11-28): the one `RefreshPopulationNorms` failure after that fix came
two minutes into a deploy (27 Nov, 09:26), while the spell import and kit rebuild held the vCPU. The
deploy rebuilds the norms itself at the end, so nothing was lost, but it showed in the email.
`deploy.sh` now keeps `storage/app/deploying` while it runs, and a norms rebuild that finds it queues
itself five minutes later instead (a marker over half an hour old is ignored).

**`RefreshPopulationNorms` timing out** (23 failures in the 10 days to 2026-11-26, fixed then): it
rebuilds the live norms (`wow:population-refresh` = `wow:pool-export` + the aggregate) after uploads.
Alone it had grown from ~11s (25 Oct) to ~60s against its 85s limit; it failed whenever a
`BuildCoachPages` ran on the other worker, since the two share the one vCPU. A failure costs nothing
lasting (the next upload re-queues it and the norms catch up), but the time was waste: the export
decoded every round's ~180 KB payload four times. `$model->payload['x'] ?? y` on an `array` cast
decodes the JSON twice (`__isset`, then `__get`), and it read two keys that way. Reading the payload
once took the export from 42s to 14s on production's 3,164 rounds, with identical output. **Read a
round's payload once, `$p = $r->payload ?: []`, never `$r->payload[...] ?? ...`.** The same fix went
into `ArenaRound::readSlim()` (the page builds), `TeammatesService`, `RoundPlayerIndexer` and the
other round readers, so `BuildCoachPages` decodes each round once too.

## On every deploy

`./deploy.sh` (`DEPLOY.md`) does, in order and only as needed: pull, `composer install`, `npm run
build`, migrations, clear caches, restart PHP-FPM (clears OPcache), restart the workers, write
`storage/app/deployed-commit.txt`, bump the spell cache, re-import spell data, rebuild spell kits and
Matchup Lab profiles, apply the icon manifest, rebuild the live norms, list untranslated spell names,
a smoke test of ten pages, and a count of errors logged during the deploy. A deploy is started by
hand, never on a timer.

## On players' PCs

The desktop app (`tools/desktop-app/README.md`) runs on its own once installed: it records games
(OBS), uploads each finished arena log, builds the local review, checks `/api/coach/version` every
hour and downloads a new version while WoW is open, and re-sends old games when the site's measure
version goes up. The Observer addon's auction scans go out after an AH scan and a reload. **Nothing
on a player's PC reports back when it fails**, so the daily email can't see it.

## On Chriso's PC

- **The /ask bridge was removed** on 2026-11-27 (app 0.6.40), with the site's Gemini answers
  (`ForeverAskService`) and the addon's Ask tab: the built-in features cover it (Chriso). In git history.
- **Not yet built:** a weekly usage read by `claude -p` from Task Scheduler, on plan usage (talked
  through 2026-11-23). It would read a server report and write recommendations; it needs the PC on.

## Not automated

Backups. `wow:sync` on Chriso's PC. Publishing an app version. Spell-data patches. Guides. The usage
research (`project_usage_research` memory, a read-only report run by hand).

## Adding a task

1. A command first, runnable by hand with a `--dry-run`, then a cron line: direct (`cd
   /var/www/mindcollector && sudo -u www-data php artisan ...`), logged to `storage/logs/{name}.log`.
   Not the Laravel scheduler, which would wake every `Schedule::` entry nobody has checked.
2. Heavy work: `nice -n 15` and a time well away from 6pm (the box has one vCPU).
3. Give the email a check that it ran, so a silent stop is found the next morning.
4. Add it to this doc and to the cron block in `DEPLOY.md`.
