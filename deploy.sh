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

cd "$(dirname "$0")"

FORCE=0
if [[ "${1:-}" == "--force" ]]; then
    FORCE=1
fi

LOG_DIR="storage/logs"
LOG_FILE="${LOG_DIR}/deploy-$(date +%Y%m%d-%H%M%S).log"
mkdir -p "$LOG_DIR"

# Every echo below also lands in the timestamped log file — this IS the "detailed live run
# breakdown" record: what changed, what ran, and what the post-deploy smoke test found, kept on
# disk instead of only ever existing in a terminal scrollback that's gone the moment it's closed.
exec > >(tee -a "$LOG_FILE") 2>&1

echo "=== Deploy started $(date -u +"%Y-%m-%dT%H:%M:%SZ") ==="

BEFORE_COMMIT=$(git rev-parse HEAD)
echo "Current commit: ${BEFORE_COMMIT}"

echo "==> git pull"
git pull

AFTER_COMMIT=$(git rev-parse HEAD)

if [[ "$BEFORE_COMMIT" == "$AFTER_COMMIT" && "$FORCE" -eq 0 ]]; then
    echo "==> Already up to date (${AFTER_COMMIT:0:7}) — nothing to deploy. Use --force to re-run the safety-net steps anyway."
    echo "=== Deploy finished (no-op) $(date -u +"%Y-%m-%dT%H:%M:%SZ") ==="
    exit 0
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

if echo "$CHANGED_FILES" | grep -qE '^data/spelldata/|^database/migrations/.*(spell|talent)'; then
    echo ""
    echo "*** data/spelldata/ or a spell/talent-related migration changed in this deploy. ***"
    echo "*** This script deliberately does NOT run the spell import automatically — the ***"
    echo "*** patch version argument must be verified against the DB, never hardcoded or  ***"
    echo "*** guessed (see CLAUDE.md's frozen-patch-string warning). Run by hand:          ***"
    echo ""
    echo '    php artisan tinker --execute="echo App\Models\Patch::where(\"is_current\", true)->first()->build_version;"'
    echo '    php -d memory_limit=512M artisan import:spelldata wow <that-version>'
    echo ""
fi

echo "==> Post-deploy smoke test"
SMOKE_URLS=(
    "https://mindcollector.com/"
    "https://mindcollector.com/wow-comps"
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
