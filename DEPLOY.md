# Production and deploying

The runbook, checked against `deploy.sh` on 2026-11-01. Incident write-ups and the older password
method: `docs/history/deploy-incidents-2026.md`.

## The box

Ubuntu 22.04 on Vultr, `45.76.116.44` (`mindcollector.com`), Nginx + PHP-FPM 8.2, code at
`/var/www/mindcollector`, deployed from `github.com/chrisHLX/chonny`. No CI/CD. **Production runs
whatever branch is checked out there**: push it, then deploy.

**1 vCPU, ~1,958 MB RAM, 5.3 GB swap. The single core is the ceiling.** `/` and `/wow-comps` are about
0.12s of CPU, `/spell-counters` about 0.57s, and latency scales linearly under concurrency (12
concurrent `/wow-comps` is about 1.45s each). No PHP tuning changes that; a second vCPU would. PHP-FPM
is `pm.max_children = 12`, `pm.max_requests = 500`. Size workers from **PSS** in
`/proc/<pid>/smaps_rollup`, never `ps` RSS: OPcache's 192 MB is shared, so RSS overstates badly.

**Queue workers:** 2 Supervisor-managed (`laravel-worker_00`, `mindcollector-worker`), each
`queue:work --queue=cards,default`, 90s timeout. `cards` is a game's card alone (`BuildCoachPages` with
`cardsOnly`), taken before anything on `default`. A job on a queue no worker reads never runs: a new
queue name needs `/etc/supervisor/conf.d` changed first.

**No scheduler:** `schedule:run` is not in cron, so every `Schedule::command(...)` in
`routes/console.php` (e.g. `next-steps:expire`) is inert on live. **Cron is one line**, deliberately a
direct command so it wakes nothing else:

```
0 18 * * * cd /var/www/mindcollector && nice -n 15 sudo -u www-data php -d memory_limit=512M artisan wow:pvp-leaderboards >> storage/logs/leaderboards.log 2>&1
```

18:00 UTC: every PvP leaderboard of the season for US and EU, ~170 Blizzard calls, ~380,000 entries, a
few minutes. As www-data, so the files it writes are the site's own.

## Connecting

```bash
ssh -i ~/.ssh/mindcollector_deploy -o BatchMode=yes root@45.76.116.44 "cd /var/www/mindcollector && ./deploy.sh"
```

- **The deploy key is the only way in over SSH** (keys only since 2026-10-29,
  `/etc/ssh/sshd_config.d/00-keys-only.conf`). Both PCs have one (`chris-pc`, `chris-desktop` in
  `authorized_keys`). Lost both: Vultr's web console still takes the password, and deleting that file
  then `systemctl reload ssh` brings password SSH back. Never print the password file.
- **Start the remote command with `cd`**, or git-bash rewrites a `/var/...` path to `C:/Program Files/Git/...`.
- **Uploading a file:** SFTP is broken. Pipe base64 through stdin, then check the sha256:
  `base64 file | ssh ... "base64 -d > /var/www/mindcollector/path.new"`, then `mv` it into place.
- **Running PHP on the box:** `--execute` mangles backslashes through this many quoting layers. Upload a
  script, then:
  `sudo -u www-data HOME=/tmp TERM=dumb timeout 120 php artisan tinker /tmp/x.php < /dev/null`.
  Without the stdin redirect tinker hangs. As www-data, so nothing in `storage/` ends up root-owned.
- **Redis cache is DB 1**, not 0: `redis-cli -n 1 ...`, or it looks empty.

## Permissions

- **Deploy only when the user's current message asks for it.** The auto-mode classifier refuses
  otherwise. When it isn't asked for, commit and say it's ready.
- **Reads are fine:** logs, `git status`, `systemctl status`, read-only queries.
- **One-off production DB writes are blocked.** Hand the user a ready-to-paste `!` command.
- **Never add an allow rule for yourself.** The classifier denies it. If one is missing, ask the user
  to add it via `/permissions`.

## What `deploy.sh` does

`cd /var/www/mindcollector && ./deploy.sh`, never a bare `git pull`. `--force` runs the full path
with nothing new to pull. Commit and push first; check the server's `git status` if a pull might
conflict with untracked files.

**Why a bare pull is not enough:** `opcache.validate_timestamps=Off`, so php-fpm workers keep running
old bytecode until restarted, and the queue workers hold old classes in memory. Stale code writes
stale Redis entries, and bumping the cache version does not fix an entry already under the current
version (2026-08-28, real 500s on `/wow-comps`).

1. **Discards the kits and profiles it rewrites**, `git pull`, then **re-execs itself** so a change to
   `deploy.sh` applies on the same run (bash reads a script by byte offset; a rewritten file once ran
   spliced and skipped the build, 2026-09-07).
2. **Fast path.** A commit touching only `data/population/`, `docs/`, `tools/` or `.md` files stops
   here: the live-norms refresh, the untranslated-names report and a short smoke test, in seconds
   rather than about two and a half minutes. No restart, no cache bump, no kits.
3. `composer install`, only if `composer.lock` changed.
4. **Vite**, only if `resources/` or the package files changed, and not for `resources/views/desktop/`
   alone (those pages carry their own styles). It builds to `public/build-next` and swaps it in, with
   the previous build's assets kept for pages already open. `npm run build` empties `public/build`
   first, which made every page a 500 while it ran.
5. `migrate --force`; `view:clear`, `route:clear`, `config:clear`.
6. **Restarts `php8.2-fpm`**, the step that clears stale bytecode, and `queue:restart`.
7. **Writes `storage/app/deployed-commit.txt`**, folded into every `wow_spell_references:*` and
   `wow_cc_formula:*` key by `TalentSelectionService::deployedCodeFingerprint()`, so every deploy busts
   those caches. Strictly after the restart: the fingerprint must only change once the code it
   describes is the code running.
8. **Bumps the spell cache version**, then the data: `import:spelldata wow` if `data/spelldata/` or a
   spell or talent migration changed (it regenerates the kits itself), else `wow:precompute-spell-kits`.
   Kits embed the fingerprint, so every deploy invalidates all 40, and a stale kit falls back to a slow
   live compute. Always after step 7 (rule 19).
9. `wow:build-matchup-profiles`, after the kits (rule 31); `wow:apply-icon-manifest` (icon files travel
   in git, `icon_name` is a database column).
10. `wow:population-refresh`, as the owner of `storage/` and never root: root-owned files block the
    workers' own rebuilds. Then `wow:translate-spell-names --report`, writing the names still to add by
    hand to `storage/app/spell-names-to-add.md`.
11. **Smoke test** of ten URLs (`/`, `/wow/comps`, the Lab, guides, spells, browse, `/wow-comps` through
    its redirect, `/training`, `/wow/sample`, `/wow/app`), and a count of `ERROR` lines in `laravel.log`.
    Exits non-zero if a URL fails. Add a page here when it can break without any template changing.

Kits, profiles, the Vite build and the norms run under `nice -n 15`: on one core they otherwise take
the CPU from every page. Every run logs to `storage/logs/deploy-<timestamp>.log`.

## Environment variables

```
OPENAI_API_KEY=            # AI question/content generation
GEMINI_API_KEY=            # Gemini web-search grounding (ResearchService)
STRIPE_KEY= STRIPE_SECRET= STRIPE_WEBHOOK_SECRET=
BLIZZARD_CLIENT_ID= BLIZZARD_CLIENT_SECRET=   # Game Data API + Battle.net OAuth
BATTLENET_REDIRECT_URI=    # optional override
GOOGLE_CLIENT_ID= GOOGLE_CLIENT_SECRET=       # Google sign-in; button hidden if unset
BUYMEACOFFEE_URL=          # support link; hidden if unset
RECAPTCHA_SITE_KEY= RECAPTCHA_SECRET_KEY=
DISCORD_FEEDBACK_WEBHOOK_URL=
AUTH_REMEMBER_DAYS=30
DB_CONNECTION=mysql QUEUE_CONNECTION=redis SESSION_DRIVER=redis CACHE_STORE=redis

# YouTube uploads (docs/youtube-verification.md)
YOUTUBE_CLIENT_ID= YOUTUBE_CLIENT_SECRET=
YOUTUBE_PUBLIC=            # true once Google has verified the app
YOUTUBE_TESTERS=           # user ids offered it before then, "8,12"
YOUTUBE_API_KEY=           # views, and public/unlisted/gone (YouTubeVideoService::refresh)

# The Microsoft Store listing (tools/desktop-app/README.md)
MICROSOFT_STORE_URL= MICROSOFT_STORE_CURRENT=  # true once the Store holds the download's version

# On the PC, not the server
ARENA_LOG_ARCHIVE_PATH=    # optional; defaults to in-repo data/arena-logs/
WOW_COMBATLOG_PATH=
MINDCOLLECTOR_SITE= MINDCOLLECTOR_KEY=         # where wow:push-rounds sends this PC's games
```

Battle.net and Google OAuth need their redirect URLs registered for **both** `mindcollector.com` and
`www.mindcollector.com` (both serve the site directly), plus the local `.test` host. Production does
not cache config, so php-fpm sees a `.env` change at once; queue workers need `queue:restart`.

## Nginx

Its config is not in the repo: `/etc/nginx/nginx.conf` (real `gzip_types`, marked `MINDCOLLECTOR_GZIP`)
and `/etc/nginx/sites-enabled/mindcollector` (immutable caching on `/build/`). `gzip on` alone
compresses only HTML, and `expires` plus `add_header Cache-Control` sends the header twice: use one.
**Back configs up outside `sites-enabled/`** (`/root/nginx-backups/`), because nginx globs that folder and
parses a backup as a second site. Always `nginx -t` before `systemctl reload nginx`.

## Housekeeping

The systemd journal is capped at 500 MB (`/etc/systemd/journald.conf.d/size.conf`; it had reached 4.1 GB).
Old app installers are removed, keeping the one before the current
(`storage/app/desktop/MindCollector-<version>.exe`) for a rollback. Publishing a new app version:
`tools/desktop-app/README.md`.

**Not done, on purpose:** `opcache.validate_timestamps` stays Off. Turning it on would self-heal within
seconds but is global PHP-FPM config, and deployed-commit fingerprinting closed the failure class.
Revisit if this class of bug is seen a third time.
