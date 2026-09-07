#!/usr/bin/env bash
#
# deploy.sh — the ONLY supported way to ship a code change to production from now on.
#
# Written 2026-08-29 after a real incident: a plain `git pull` on the production server left
# php-fpm serving old OPcache bytecode (opcache.validate_timestamps=Off on this box, confirmed
# 2026-08-28 — restarting php-fpm is the ONLY way already-running workers pick up new PHP code).
# The stale bytecode computed and wrote a Redis-cached array missing a field the current blade
# template required, causing a real 500 for real users on /wow-comps. Bumping the app-level spell
# cache version did NOT fix it, because the stale cache entry was already sitting under the
# CURRENT version number — the version only changes which key a *future* write uses, it does
# nothing to a key that already exists. See DEPLOY.md for the full incident writeup.
#
# This script exists so none of the fix steps depend on a human remembering them under pressure
# with real users seeing errors. Run it in place of a bare `git pull`:
#
#   cd /var/www/mindcollector && ./deploy.sh
#
# Safe to re-run. Exits early (does nothing) if there was nothing new to pull, unless --force is
# passed (use --force if you only need the safety-net steps re-run without a code change, e.g.
# after you've directly edited data/spelldata/*-overrides.txt and re-imported by hand).
#
set -euo pipefail

# Resolve this script absolutely BEFORE the cd, so the stage-2 exec below cannot be broken by a
# relative $0 (e.g. `bash ../mindcollector/deploy.sh`) once the working directory has moved.
SELF="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"

cd "$(dirname "$SELF")"

FORCE=0
if [[ "${1:-}" == "--force" ]]; then
    FORCE=1
fi

LOG_DIR="storage/logs"
# Reused across the stage-2 exec handoff below so one deploy still produces exactly one log.
LOG_FILE="${LOG_FILE:-${LOG_DIR}/deploy-$(date +%Y%m%d-%H%M%S).log}"
mkdir -p "$LOG_DIR"

# Every echo below also lands in the timestamped log file — this IS the "detailed live run
# breakdown" record: what changed, what ran, and what the post-deploy smoke test found, kept on
# disk instead of only ever existing in a terminal scrollback that's gone the moment it's closed.
exec > >(tee -a "$LOG_FILE") 2>&1

if [[ "${DEPLOY_STAGE:-1}" == "1" ]]; then
    echo "=== Deploy started $(date -u +"%Y-%m-%dT%H:%M:%SZ") ==="
fi

# --- Self-stability guard ------------------------------------------------------------------
#
# Bash reads a script incrementally by byte offset, so a `git pull` that rewrites THIS file
# mid-run leaves execution continuing at the old offset inside the new bytes — silently running
# a spliced mix of both versions, with no error anywhere.
#
# This is not hypothetical. On 2026-09-07 a deploy that changed deploy.sh reported success and
# exited 0 while never running the npm build or the spell import, and used the PREVIOUS smoke-URL
# list. Production was left with new code and a new schema but an empty spell_counters table and
# a stale CSS bundle — the badge-orange fix in that same deploy was not actually live.
#
# Fix: do the pull, then hand off with `exec` to the freshly-pulled file, which bash re-reads
# from byte 0. Stage 2 gets the commit range through the environment.
if [[ "${DEPLOY_STAGE:-1}" == "1" ]]; then
    BEFORE_COMMIT=$(git rev-parse HEAD)
    echo "Current commit: ${BEFORE_COMMIT}"

    # Machine-generated artifacts that this script itself rewrites later in the run (kits embed
    # the deployed-commit fingerprint, so they differ from the committed copies by design). They
    # are tracked, so without this a pull aborts with "local changes would be overwritten" —
    # discarding them is safe precisely because the run regenerates them from scratch.
    git checkout -- data/spell-kits/ 2>/dev/null || true
    git checkout -- deploy.sh 2>/dev/null || true

    echo "==> git pull"
    git pull

    AFTER_COMMIT=$(git rev-parse HEAD)

    if [[ "$BEFORE_COMMIT" == "$AFTER_COMMIT" && "$FORCE" -eq 0 ]]; then
        echo "==> Already up to date (${AFTER_COMMIT:0:7}) — nothing to deploy. Use --force to re-run the safety-net steps anyway."
        echo "=== Deploy finished (no-op) $(date -u +"%Y-%m-%dT%H:%M:%SZ") ==="
        exit 0
    fi

    export DEPLOY_STAGE=2 BEFORE_COMMIT AFTER_COMMIT LOG_FILE
    exec bash "$SELF" "$@"
fi


echo "==> Deploying ${BEFORE_COMMIT:0:7} -> ${AFTER_COMMIT:0:7}"
CHANGED_FILES=$(git diff --name-only "$BEFORE_COMMIT" "$AFTER_COMMIT" || true)
echo "--- Changed files ---"
echo "$CHANGED_FILES"
echo "---------------------"

if echo "$CHANGED_FILES" | grep -q '^composer\.lock$'; then
    echo "==> composer.lock changed — running composer install"
    composer install --no-dev --optimize-autoloader --no-interaction
fi

if echo "$CHANGED_FILES" | grep -qE '^(package-lock\.json|package\.json|resources/)'; then
    echo "==> Frontend assets changed — running npm build"
    npm ci
    npm run build
fi

echo "==> Running migrations (safe no-op if nothing pending)"
php artisan migrate --force

echo "==> Clearing view/route/config caches (cheap, always safe even if nothing was cached)"
php artisan view:clear
php artisan route:clear
php artisan config:clear

echo "==> Restarting php8.2-fpm — THE step that actually clears stale OPcache bytecode."
echo "    (opcache.validate_timestamps is Off on this box — without this restart, already-"
echo "    running workers keep executing whatever code was compiled at their last restart,"
echo "    regardless of what git pull just changed on disk.)"
sudo systemctl restart php8.2-fpm

echo "==> Restarting queue workers (they hold class definitions in memory for their whole run;"
echo "    same staleness risk as php-fpm, different mechanism — a long-lived CLI process, not"
echo "    OPcache)"
php artisan queue:restart

echo "==> Writing the deployed-commit fingerprint (storage/app/deployed-commit.txt) — read by"
echo "    TalentSelectionService::deployedCodeFingerprint() and folded into every"
echo "    wow_spell_references:* cache key. Written HERE, strictly AFTER the php8.2-fpm restart"
echo "    above, on purpose: this guarantees the fingerprint only ever changes to a new value"
echo "    once the process serving requests is actually running the code that value describes —"
echo "    closing the exact race that made bumping spellCacheVersion() alone insufficient during"
echo "    the 2026-08-28 incident (see DEPLOY.md). Do not move this step earlier."
mkdir -p storage/app
git rev-parse --short HEAD > storage/app/deployed-commit.txt
echo "    Fingerprint now: $(cat storage/app/deployed-commit.txt)"

echo "==> Bumping the WoW spell-reference cache version — belt-and-suspenders alongside the"
echo "    fingerprint above; still the right tool for a DATA-only change (import:spelldata, an"
echo "    admin default-build edit) that doesn't involve a code deploy at all."
php artisan tinker --execute="app(App\Http\Services\TalentSelectionService::class)->bumpSpellCacheVersion(); echo 'Spell cache version now: ' . app(App\Http\Services\TalentSelectionService::class)->spellCacheVersion();"

# --- Spell data derivation -----------------------------------------------------------------
#
# Both branches below MUST run after the fingerprint write above, never before: a precomputed
# kit file embeds the fingerprint it was built against, so building kits first would stamp them
# with the OLD value and they would be invalidated seconds later by the write above.
#
# This used to be a printed reminder telling a human to look up the patch version and run the
# import by hand, because a wrong version argument silently forks every patch-scoped table
# rather than failing. That is now impossible to get wrong: `import:spelldata wow` with no patch
# argument reuses the DB's own current patch and refuses to run if there isn't one, which is the
# same verification the reminder asked for, performed by the command itself.
if echo "$CHANGED_FILES" | grep -qE '^data/spelldata/|^database/migrations/.*(spell|talent)'; then
    echo "==> Spell data or a spell/talent migration changed — running the full import."
    echo "    No patch argument is passed on purpose: the command resolves the current patch"
    echo "    from the database itself. Never pass a literal version here — a string that"
    echo "    doesn't match the DB creates a NEW patches row and forks every patch-scoped"
    echo "    table away from the one the live site reads (this has happened for real)."
    echo "    A genuine patch transition is a deliberate, separate, manual action."
    php -d memory_limit=1024M artisan import:spelldata wow
    # import:spelldata regenerates every spec kit itself as its final step, so the standalone
    # precompute below would be redundant work on an already-slow deploy.
else
    echo "==> Regenerating precomputed spell kits (data/spell-kits/{class}/{spec}.json)."
    echo "    This is NOT optional housekeeping. Every kit file embeds the deployed-commit"
    echo "    fingerprint written moments ago, so EVERY deploy invalidates all 40 of them by"
    echo "    definition. Nothing else regenerates them, and a stale kit silently falls back to"
    echo "    a live recompute: profiled at 6,964ms/3,042 queries for a 3-spec WowComps render"
    echo "    versus 970ms/146 with fresh files. Before this step existed, production ran that"
    echo "    slow path after every single deploy."
    php -d memory_limit=1024M artisan wow:precompute-spell-kits
fi

echo "==> Post-deploy smoke test"
SMOKE_URLS=(
    "https://mindcollector.com/"
    "https://mindcollector.com/wow-comps"
    "https://mindcollector.com/pvp-guides"
    "https://mindcollector.com/spells"
)
SMOKE_FAILED=0
for url in "${SMOKE_URLS[@]}"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$url" || echo "000")
    echo "    ${code}  ${url}"
    if [[ "$code" != "200" ]]; then
        SMOKE_FAILED=1
    fi
done

echo "==> Checking laravel.log for errors logged during this deploy"
DEPLOY_START_EPOCH=$(date -d "@$(stat -c %Y "$LOG_FILE" 2>/dev/null || echo 0)" +%s 2>/dev/null || echo 0)
NEW_ERRORS=$(awk -v start="$(date -u +"%Y-%m-%d %H:%M")" '$0 >= "[" start { print }' storage/logs/laravel.log 2>/dev/null | grep -c '\.ERROR:' || true)
echo "    ERROR-level log lines around this deploy window: ${NEW_ERRORS:-0}"

echo "=== Deploy finished ${AFTER_COMMIT:0:7} $(date -u +"%Y-%m-%dT%H:%M:%SZ") ==="

if [[ "$SMOKE_FAILED" -eq 1 ]]; then
    echo ""
    echo "!!! SMOKE TEST FAILED — one or more pages did not return 200. Check ${LOG_FILE} and"
    echo "!!! storage/logs/laravel.log immediately. Consider: is this the same OPcache/stale-"
    echo "!!! cache class of bug? Try: sudo systemctl restart php8.2-fpm, then bump the spell"
    echo "!!! cache version again, then re-check."
    exit 1
fi

echo "Deploy log saved to: ${LOG_FILE}"
