# Production Deploy Runbook

Production is a single Ubuntu 22.04 box (Vultr, `45.76.116.44` / `mindcollector.com`), Nginx +
PHP-FPM 8.2, code at `/var/www/mindcollector`. There is no CI/CD pipeline — deploy has always
meant SSHing in and running `git pull` directly against the live checkout. That gap is what
caused the incident below, and `deploy.sh` (repo root) is the fix: **use it instead of a bare
`git pull` from now on.**

```
cd /var/www/mindcollector && ./deploy.sh
```

SSH access: `ssh -i ~/.ssh/id_ed25519 root@45.76.116.44` (password fallback documented in the
usual local notes file if the key ever stops being accepted). To pull the log without a full
SSH session: `scp root@45.76.116.44:/var/www/mindcollector/storage/logs/laravel.log <local path>`.

## What `deploy.sh` does, and why each step exists

1. **`git pull`**, tracking before/after commit so every later step only fires when relevant.
2. **`composer install`** — only if `composer.lock` changed in this pull.
3. **`npm ci && npm run build`** — only if `package.json`/`package-lock.json`/`resources/` changed.
4. **`php artisan migrate --force`** — always; a no-op if nothing's pending.
5. **`view:clear` / `route:clear` / `config:clear`** — cheap, always safe, even if nothing was
   actually cached (production doesn't currently run `config:cache`/`route:cache`, confirmed
   2026-08-29 by checking `bootstrap/cache/` — only the Composer package-discovery cache files
   are present — but this is defensive in case that ever changes).
6. **`sudo systemctl restart php8.2-fpm`** — the step that actually matters. See the incident
   below for why.
7. **`php artisan queue:restart`** — the two Supervisor-managed queue workers
   (`laravel-worker_00`, `mindcollector-worker`) are long-running CLI processes that hold PHP
   class definitions in memory for their entire run. A code deploy with no `queue:restart` means
   queued jobs keep executing whatever code existed when the worker last booted — a different
   mechanism than OPcache, same practical effect (stale code silently still running).
8. **Bumps the WoW spell-reference cache version** (`TalentSelectionService::bumpSpellCacheVersion()`)
   unconditionally. Cheap, and directly closes the exact failure class from the incident below —
   see [[chonny_spell_cache_deploy_incident]] for why a version bump alone, done *after* the
   underlying staleness already produced a bad cache entry, does not retroactively fix that entry.
9. Prints a **reminder** (never auto-runs it) to check the current patch and run
   `import:spelldata` if `data/spelldata/` or a spell/talent migration changed — this is
   deliberately manual because the patch version argument must be verified against the DB, never
   hardcoded (see CLAUDE.md's frozen-patch-string section — using the wrong string forks a
   disconnected patch row).
10. **Smoke test**: curls `/`, `/wow-comps`, `/spells` and reports the HTTP status of each.
11. **Checks `laravel.log`** for `ERROR`-level lines in the deploy window.
12. **Saves the full run to `storage/logs/deploy-<timestamp>.log`** — a persistent, timestamped
    record of what changed and what the smoke test found, not just terminal scrollback that's
    gone the moment the session closes. This is the "detailed live run breakdown" this whole
    doc exists to produce.

Exits non-zero (and says so loudly) if the smoke test fails, so a deploy that broke something is
never silently reported as done.

## Incident: 2026-08-28 — WoW Comps 500s after a plain `git pull`

**Symptom:** real users got a 500 clicking into a WoW Comps spec. Laravel log:
`Undefined array key "cooldown"` in `wow-comps.blade.php`'s "Modifies / Enhances" section.

**What did NOT fix it:** manually bumping the spell-reference cache version
(`bumpSpellCacheVersion()`). This looked like it should work — the cache key embeds the version —
but didn't, because of the actual mechanism below.

**Root cause, traced live on the box (not guessed):**
1. `opcache.validate_timestamps = Off` on this server. With this setting, PHP-FPM workers do
   **not** notice a file's content changed just because `git pull` updated it on disk — they keep
   executing whatever bytecode OPcache already compiled, indefinitely, until the FPM process
   itself restarts.
2. A `git pull` had landed on the server updating `WowComps.php`'s modifier-enrichment logic, but
   nothing restarted `php8.2-fpm` afterward. Already-running FPM workers kept executing the *old*
   compiled version of that method — one that didn't write a `cooldown` key onto each modifier.
3. That stale execution computed a result and wrote it into Redis
   (`wow_spell_references:spec:{id}:build:{stamp}:v{version}`) — a plain serialized PHP array,
   missing the `cooldown` key, permanently, regardless of what the code on disk said.
4. **Bumping the version number does not fix an already-written entry under the version it was
   already bumped to.** It only changes what key a *future, uncached* computation will be stored
   under. If the stale entry was written under version 13, and the version is still 13 (or gets
   bumped to 14 by someone unaware the underlying computation itself was still stale at that
   moment), the bad entry keeps being served on every cache hit.
5. **Confirmed empirically, not assumed:** deleting the specific stale `wow_spell_references:*`
   keys directly from Redis (they live in Redis **DB 1** — Laravel's default cache database, not
   DB 0, which is why an initial `redis-cli --scan` against DB 0 found nothing and briefly looked
   like the stale-cache theory was wrong) and restarting `php8.2-fpm` first, together, resolved
   it. Verified by directly re-running `WowComps`'s spec-selection logic for every class/spec on
   the site afterward and confirming zero modifiers were missing the `cooldown` key anywhere —
   not just for the one spec from the error report.

**Why a bare `git pull` will keep causing this class of bug until `deploy.sh` is used:** nothing
about "update the files on disk" tells a running PHP-FPM worker (with `validate_timestamps=Off`)
or a running queue worker to reload. Every future code change to any hot path that gets Redis-
cached carries the same risk unless the process serving it restarts as part of the same deploy
action, not as a separately-remembered step under pressure once users are already seeing errors.

**Not changed as part of this fix, flagged as a considered alternative:** flipping
`opcache.validate_timestamps` to `On` (with a low `opcache.revalidate_freq`) would make OPcache
self-heal within a couple of seconds of any file change, independent of whether `deploy.sh` (or
any restart) ever runs — a stronger, defense-in-depth fix at a small, likely-negligible
performance cost. Deliberately not flipped without checking first — it's a global PHP-FPM config
change on live infra, and there may be a reason it was set to `Off` (deploy frequency, whoever
originally provisioned the box). Worth a short discussion, not a silent change.

## Known separate gap, found while investigating this incident (not fixed, flagged)

`crontab -l` on production returns **no crontab for root** — the Laravel scheduler
(`schedule:run`) is not actually running there. CLAUDE.md's Next Step + Reflection Loop section
documents `next-steps:expire` as `Schedule::command(...)->daily()`, which explicitly "requires the
standard Laravel scheduler (`schedule:run` cron entry) to be active on the server for this to
actually fire." It is not. Any other `routes/console.php` scheduled command is equally silently
not running. Confirmed 2026-08-29, not acted on — out of scope for the caching incident, but a
real, separate production gap worth its own fix (`* * * * * php artisan schedule:run` in root's
crontab is the standard remedy).
