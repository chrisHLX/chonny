<?php

/**
 * Fetches spell icon images from Blizzard's Game Data API media endpoint, self-hosts them
 * on the local Laravel public disk (storage/app/public/spell-icons/), and stores the filename
 * (not the CDN URL) on each spell's icon_name column. This ensures icons are never hotlinked
 * from Blizzard's live CDN, following the project's offline-fetch-and-self-host principle.
 *
 * The target spell set is every spell_id referenced by the current talent data:
 *   - All distinct spell_id from talent_node_entries (PvE talents)
 *   - All distinct spell_id from pvp_talents (PvP talents)
 *   - All distinct spell_id from spell_class_availability where source='verified_override'
 *     (added 2026-08-06 — the hand-curated baseline-ability display path, e.g. Leg Sweep,
 *     Freezing Trap, Hammer of Justice; see CLAUDE.md's "Baseline ability display" section.
 *     These are never talents, so they were invisible to this script's original two sources
 *     entirely — confirmed missing icons on real spells before this was added.)
 *   - Explicit-spec_id baseline spells with a real cooldown/CC mechanic (added 2026-08-10 —
 *     TalentSelectionService::explicitBaselineCooldownAbilityIds()'s display path for
 *     baseline-heavy specs like Demon Hunter/Evoker, e.g. Devourer's Blur/Shift/Spectral
 *     Sight — added 2026-08-07, well after this script's original target-set query was
 *     written, so it was never wired in; confirmed missing icons on real spells before this
 *     was added, same shape as the verified_override gap above). Mirrors that method's exact
 *     filter (source='baseline', explicit spec_id, not passive/not_in_spellbook/`(desc=...)`-
 *     suffixed, cooldown >= 10s or a Sleep/Disorient mechanic) — deliberately not deduped to
 *     one-per-name the way the display method is, since fetching a few extra icons for
 *     same-named duplicate copies is harmless and this script doesn't need to know which
 *     specific copy the display layer will end up picking.
 * These are queried directly from the local Laravel database rather than derived from the
 * JSON files in data/talenttrees/ or data/pvptalents/, ensuring consistency with whatever
 * was actually imported.
 *
 * After the API-driven pass above, a separate step applies
 * data/spelldata/icon-name-overrides.txt — hand-curated icon filenames for spells that are
 * completely real and player-visible but that Blizzard's public API doesn't index under ANY
 * spell_id at all (confirmed 2026-08-24: both /data/wow/spell/{id} and the name-search endpoint
 * return nothing for these — a deeper gap than the sibling-recovery above, which only helps when
 * some OTHER copy of the same name IS indexed). See that file's own header for the full
 * rationale, source (Wowhead), and verification requirement per entry.
 *
 * Idempotent: spells that already have icon_name set are skipped, and icon files already
 * present on disk are not re-downloaded. A second run processes only new spells. The dedup
 * is by filename — many spell_ids resolve to the same icon file (rank/tier variants), so
 * expect the unique file count to be much smaller than the spell_id count (roughly 40-50%
 * of spell_ids map to each unique icon).
 *
 * Auth: OAuth2 client_credentials against oauth.battle.net, using BLIZZARD_CLIENT_ID /
 * BLIZZARD_CLIENT_SECRET from .env, same pattern as fetch-talent-trees.php.
 *
 * Usage:
 *   php fetch-spell-icons.php [--limit=N] [--skip-download]
 *
 *   --limit=N        Process only the first N spell_ids (useful for testing).
 *   --skip-download  Skip downloading icon files; only fetch metadata from the API
 *                    and update the icon_name column. Useful for dry runs or if the
 *                    files are already present from a previous run.
 */

// ---------------------------------------------------------------------------------------
// Config / .env
// ---------------------------------------------------------------------------------------

const REGION    = 'us';
const API_NAMESPACE = 'static-us';
const LOCALE    = 'en_US';
const API_HOST  = 'https://us.api.blizzard.com';
const OAUTH_URL = 'https://oauth.battle.net/token';

// Icon storage subdirectory under Laravel's public disk (storage/app/public)
const ICON_STORAGE_SUBDIR = 'spell-icons';

// Committed alongside the image files (see storage/app/public/.gitignore) so a fresh
// migrate:fresh on any machine can repopulate spells.icon_name via
// `php artisan wow:apply-icon-manifest` with zero Blizzard API calls — see that command's
// docblock. Shared with fetch-class-spec-icons.php (each script only rewrites its own
// section of the file, always regenerated from the DB's current icon_name values rather
// than tracked incrementally during the run — see refreshIconManifest() below).
const ICON_MANIFEST_PATH = __DIR__ . '/icon-manifest.json';

// Hand-curated icon filenames for spells Blizzard's public API doesn't index under ANY spell_id
// (confirmed 2026-08-24 — see that file's own header for the full rationale and verification
// steps required before adding a new line here).
const ICON_NAME_OVERRIDES_PATH = __DIR__ . '/icon-name-overrides.txt';

// Courtesy delay between requests (microseconds) — keeps us well under Blizzard's
// per-second limit without needing to track a request counter/window ourselves.
const REQUEST_DELAY_US = 40000; // 40ms ≈ 25 req/s

const MAX_RETRIES = 5;

function loadEnv(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "Cannot find .env at {$path}\n");
        exit(1);
    }

    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Strip matching surrounding quotes, if present.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }

    return $env;
}

$projectRoot = dirname(__DIR__, 2);
$env         = loadEnv($projectRoot . '/.env');

$clientId     = $env['BLIZZARD_CLIENT_ID'] ?? null;
$clientSecret = $env['BLIZZARD_CLIENT_SECRET'] ?? null;

if (!$clientId || !$clientSecret) {
    fwrite(STDERR, "BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET missing from .env\n");
    exit(1);
}

// Database configuration from .env
$dbHost     = $env['DB_HOST'] ?? 'localhost';
$dbPort     = $env['DB_PORT'] ?? 3306;
$dbDatabase = $env['DB_DATABASE'] ?? '';
$dbUser     = $env['DB_USERNAME'] ?? '';
$dbPassword = $env['DB_PASSWORD'] ?? '';

// Set up PDO connection
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbDatabase}",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------------------------

$limitSpellIds = null;
$skipDownload  = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limitSpellIds = (int) $m[1];
    } elseif ($arg === '--skip-download') {
        $skipDownload = true;
    }
}

// ---------------------------------------------------------------------------------------
// HTTP layer: auth + retry/backoff on 429 (and transient 5xx)
// ---------------------------------------------------------------------------------------

/**
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error for {$url}: {$error}");
    }

    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders  = substr($raw, 0, $headerSize);
    $respBody    = substr($raw, $headerSize);
    $respHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $respHeaders, 'body' => $respBody];
}

/**
 * Wraps httpRequest with retry/backoff on 429 and 5xx. Honors Retry-After when present,
 * otherwise falls back to exponential backoff (1s, 2s, 4s, ...).
 */
function httpRequestWithRetry(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $attempt = 0;

    while (true) {
        $attempt++;
        $response = httpRequest($method, $url, $headers, $body);

        if ($response['status'] === 429 || $response['status'] >= 500) {
            if ($attempt >= MAX_RETRIES) {
                throw new RuntimeException(
                    "Giving up on {$url} after {$attempt} attempts, last status {$response['status']}"
                );
            }

            $retryAfter = isset($response['headers']['retry-after'])
                ? (int) $response['headers']['retry-after']
                : (2 ** ($attempt - 1));

            fwrite(STDERR, "  [retry] {$response['status']} on {$url} — waiting {$retryAfter}s (attempt {$attempt}/" . MAX_RETRIES . ")\n");
            sleep(max(1, $retryAfter));
            continue;
        }

        if ($response['status'] >= 400) {
            throw new RuntimeException("HTTP {$response['status']} for {$url}: " . substr($response['body'], 0, 500));
        }

        return $response;
    }
}

function getAccessToken(string $clientId, string $clientSecret): string
{
    $response = httpRequestWithRetry('POST', OAUTH_URL, [
        'Authorization: Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
        'Content-Type: application/x-www-form-urlencoded',
    ], 'grant_type=client_credentials');

    $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

    if (empty($decoded['access_token'])) {
        throw new RuntimeException('Blizzard OAuth response had no access_token: ' . $response['body']);
    }

    return $decoded['access_token'];
}

/** Running counter for the end-of-run summary. */
$requestCount = 0;

/**
 * Attempts to resolve a spell_id's icon URL via Blizzard's media API. Returns null (never
 * throws) for a genuine "no icon" case — either an HTTP 404 or a 200 response with no icon
 * asset — so callers can try a sibling spell_id instead of giving up outright. Other HTTP
 * failures (unexpected 5xx after retries, etc.) still propagate, since those are
 * transient/environmental, not "this spell has no icon."
 */
function tryFetchIconUrl(string $token, int $spellId): ?string
{
    try {
        $mediaResponse = apiGet($token, "/data/wow/media/spell/{$spellId}");
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'HTTP 404')) {
            return null;
        }
        throw $e;
    }

    foreach ($mediaResponse['assets'] ?? [] as $asset) {
        if (($asset['key'] ?? null) === 'icon' && !empty($asset['value'])) {
            return $asset['value'];
        }
    }

    return null;
}

/** Mirrors App\Models\Spell::getDisplayNameAttribute()'s "(desc=Color)" suffix stripping. */
function baseSpellName(string $name): string
{
    return trim(preg_replace('/\s*\(desc=[^)]*\)\s*$/i', '', $name));
}

/**
 * Finds candidate sibling spell_ids sharing the same base display name, same patch, excluding
 * the spell itself — e.g. multiple internal copies literally named "Void Bolt", or Evoker's
 * clean-named wrapper record vs. its "(desc=Red)" detailed-data twin. Confirmed real via a
 * live API check before building this: Void Bolt's own selected copy 404s on the media API,
 * but a same-named sibling (228266) has a real icon; Living Flame's clean-named copies all
 * 404 but the "(desc=Red)" sibling carrying its real description/effects (361469) has one.
 * Exact-name matches ordered first (most likely to be visually identical), then
 * "(desc=Color)" siblings.
 *
 * @return array<int, int> candidate spell_ids
 */
function findSiblingSpellIds(PDO $pdo, int $patchId, int $excludeDbId, string $name): array
{
    $base = baseSpellName($name);

    $stmt = $pdo->prepare(
        'SELECT id, spell_id, name FROM spells
         WHERE patch_id = ? AND id != ? AND (name = ? OR name LIKE ?)
         ORDER BY (name = ?) DESC, id'
    );
    $stmt->execute([$patchId, $excludeDbId, $base, $base . ' (desc=%', $base]);

    return array_map(fn ($row) => (int) $row['spell_id'], $stmt->fetchAll());
}

/**
 * Applies data/spelldata/icon-name-overrides.txt — spells that are 100% real and player-visible
 * but that Blizzard's public API doesn't index under ANY spell_id (confirmed via a direct check
 * of both /data/wow/spell/{id} and the name-search endpoint before this file's first entry was
 * added — see that file's header). Downloads straight from Blizzard's static icon CDN using the
 * already-known filename (sourced from Wowhead, verified live before being committed) — no media
 * API call needed since sibling recovery already found nothing.
 *
 * Applied to every same-named sibling spell_id in the current patch that still has icon_name
 * NULL, not just the one anchor spell_id listed in the override line — same "one visible ability,
 * several internal spell_id copies" reasoning as findSiblingSpellIds(), so whichever copy a
 * display path happens to surface (rotation logs, talent picks, etc.) still gets a real icon.
 */
function applyIconNameOverrides(PDO $pdo, string $iconDir, bool $skipDownload, array &$stats): void
{
    if (!is_file(ICON_NAME_OVERRIDES_PATH)) {
        return;
    }

    foreach (file(ICON_NAME_OVERRIDES_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 2 || !ctype_digit($parts[0])) {
            fwrite(STDERR, "  [icon-override] malformed line, skipping: {$line}\n");
            continue;
        }

        $anchorSpellId = (int) $parts[0];
        $iconBase      = $parts[1];
        $label         = $parts[2] ?? "spell_id {$anchorSpellId}";

        $stmt = $pdo->prepare('SELECT id, patch_id, name FROM spells WHERE spell_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$anchorSpellId]);
        $anchor = $stmt->fetch();
        if (!$anchor) {
            fwrite(STDERR, "  [icon-override] anchor spell_id {$anchorSpellId} not found in DB, skipping ({$label})\n");
            continue;
        }

        $base = baseSpellName($anchor['name']);
        $stmt = $pdo->prepare(
            'SELECT id FROM spells
             WHERE patch_id = ? AND (name = ? OR name LIKE ?) AND icon_name IS NULL'
        );
        $stmt->execute([$anchor['patch_id'], $base, $base . ' (desc=%']);
        $targetDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        if (empty($targetDbIds)) {
            fwrite(STDOUT, "  [icon-override] {$label} — every same-named copy already has an icon, nothing to do\n");
            continue;
        }

        $iconFilename = $iconBase . '.jpg';

        if (!$skipDownload) {
            $iconUrl    = "https://render.worldofwarcraft.com/us/icons/56/{$iconFilename}";
            $targetPath = $iconDir . '/' . $iconFilename;
            if (!downloadFile($iconUrl, $targetPath)) {
                fwrite(STDERR, "  [icon-override] failed to download {$iconUrl} for {$label}\n");
                continue;
            }
        }

        $updateStmt = $pdo->prepare('UPDATE spells SET icon_name = ? WHERE id = ?');
        foreach ($targetDbIds as $dbId) {
            $updateStmt->execute([$iconFilename, $dbId]);
            $stats['processed']++;
            $stats['unique_files'][$iconFilename] = true;
        }

        fwrite(STDOUT, "  [icon-override] {$label} — applied {$iconFilename} to " . count($targetDbIds) . " same-named spell_id(s)\n");
    }
}

function apiGet(string $token, string $path, array $extraParams = []): array
{
    global $requestCount;

    $params = array_merge([
        'namespace' => API_NAMESPACE,
        'locale'    => LOCALE,
    ], $extraParams);

    $separator = str_contains($path, '?') ? '&' : '?';
    $url       = (str_starts_with($path, 'http') ? $path : API_HOST . $path) . $separator . http_build_query($params);

    usleep(REQUEST_DELAY_US);
    $requestCount++;

    $response = httpRequestWithRetry('GET', $url, ["Authorization: Bearer {$token}"]);

    return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Downloads a file from a URL and saves it to disk. Returns true on success, false otherwise.
 * If the file already exists on disk, returns true without re-downloading.
 */
function downloadFile(string $url, string $targetPath): bool
{
    // If file already exists, skip re-download (idempotent).
    if (is_file($targetPath)) {
        return true;
    }

    usleep(REQUEST_DELAY_US);

    $response = httpRequest('GET', $url);

    if ($response['status'] !== 200) {
        fwrite(STDERR, "  [skip] HTTP {$response['status']} fetching {$url}\n");
        return false;
    }

    // Ensure the target directory exists
    $dir = dirname($targetPath);
    @mkdir($dir, 0777, true);

    if (!file_put_contents($targetPath, $response['body'])) {
        fwrite(STDERR, "  [error] Failed to write {$targetPath}\n");
        return false;
    }

    return true;
}

/**
 * Rewrites only the given top-level section of the shared manifest file from the current
 * DB state (SELECT of the relevant table's own icon_name column) — always a full refresh
 * of that section, never an incremental in-loop track, so the manifest self-heals on every
 * run regardless of where a previous run stopped. Other sections (written by the other
 * fetch script) are read back unchanged and re-written as-is.
 */
function refreshIconManifestSection(string $section, array $entries): void
{
    $manifest = is_file(ICON_MANIFEST_PATH)
        ? json_decode(file_get_contents(ICON_MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR)
        : [];
    $manifest += ['spells' => [], 'classes' => [], 'specs' => []];

    ksort($entries, SORT_STRING);
    $manifest[$section] = $entries;

    file_put_contents(
        ICON_MANIFEST_PATH,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

// ---------------------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------------------

fwrite(STDOUT, "Authenticating with Blizzard OAuth...\n");
$token = getAccessToken($clientId, $clientSecret);
fwrite(STDOUT, "OK.\n\n");

// Set up icon storage directory
$iconDir = $projectRoot . '/storage/app/public/' . ICON_STORAGE_SUBDIR;
@mkdir($iconDir, 0777, true);

fwrite(STDOUT, "Querying local database for target spell_ids...\n");

// talent_node_entries.spell_id and pvp_talents.spell_id are foreign keys to spells.id (the
// internal auto-increment PK) — Laravel's foreignId('spell_id')->constrained() resolves to
// the referenced table's `id` column, NOT a same-named column. They are NOT Blizzard's
// numeric spell IDs directly, despite the column name. An earlier version of this script
// queried `spells.spell_id IN (...)` using these values, which only spuriously matched a
// handful of rows by coincidence (spells.id is a small sequential 1..~12527 range that
// rarely collides with spells.spell_id, Blizzard's large arbitrary numeric IDs) instead of
// the real ~3,400 target spells. Fixed: these values ARE spells.id — look them up directly.
$stmt = $pdo->query('SELECT DISTINCT spell_id FROM talent_node_entries ORDER BY spell_id');
$talentSpellDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

$stmt = $pdo->query('SELECT DISTINCT spell_id FROM pvp_talents ORDER BY spell_id');
$pvpTalentSpellDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

$stmt = $pdo->query("SELECT DISTINCT spell_id FROM spell_class_availability WHERE source = 'verified_override' ORDER BY spell_id");
$verifiedOverrideSpellDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Mirrors TalentSelectionService::explicitBaselineCooldownAbilityIds()'s exact filter — see
// this file's docblock for why this source was missing until 2026-08-10.
$stmt = $pdo->query(
    "SELECT DISTINCT sca.spell_id
     FROM spell_class_availability sca
     JOIN spells s ON s.id = sca.spell_id
     WHERE sca.source = 'baseline'
       AND sca.spec_id IS NOT NULL
       AND s.is_passive = 0
       AND s.not_in_spellbook = 0
       AND s.name NOT LIKE '%(desc=%'
       AND (s.cooldown_seconds >= 10 OR s.mechanic IN ('Sleep', 'Disorient'))
     ORDER BY sca.spell_id"
);
$explicitBaselineCooldownSpellDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Every spell_id referenced by a promoted rotation file's steps (WowComps' Top DPS Rotation
// tab / TopDamageRotations page — data/arena-logs/rotations/{class}/{spec}.json's
// topDpsWindow(sByLength).steps). Added 2026-08-23 after a real report of missing icons across
// this data — this display path didn't exist when this script's target-set query was last
// extended, so it was never wired in (same shape as every other gap documented in this
// docblock's history). These are Blizzard's EXTERNAL spell_id (unlike the sources above, which
// already query internal spells.id directly) — resolved to spells.id here via a lookup, since
// every other part of this script works in terms of spells.id.
$rotationExternalIds = [];
foreach (glob($projectRoot . '/data/arena-logs/rotations/*/*.json') as $rotFile) {
    $decoded = json_decode(file_get_contents($rotFile), true);
    if (!$decoded) {
        continue;
    }
    foreach (($decoded['topDpsWindowsByLength'] ?? []) as $window) {
        if (!$window) {
            continue;
        }
        foreach (($window['steps'] ?? []) as $step) {
            if (!empty($step['spellId'])) {
                $rotationExternalIds[(int) $step['spellId']] = true;
            }
        }
    }
}
$rotationSpellDbIds = [];
if ($rotationExternalIds !== []) {
    $placeholders = implode(',', array_fill(0, count($rotationExternalIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM spells WHERE spell_id IN ({$placeholders})");
    $stmt->execute(array_keys($rotationExternalIds));
    $rotationSpellDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Every spell_id referenced in a promoted rotation file's "What Was Happening" mechanics block
// (window.mechanics.championBuffs/championDebuffs/targetBuffs/targetDebuffs — the real
// champion/target buff+debuff facts embedded by wow:enrich-rotation-mechanics). Added
// 2026-09-01 after a real report ("I think maybe the spells aren't linking to our icons
// properly") — confirmed by direct check: real, correctly-resolved spells (Tiger's Tenacity,
// Predatory Swiftness, Executioner's Precision, and many more) were rendering with no icon at
// all, because this script's target-set was extended for window.steps on 2026-08-23 but never
// again when the separate `mechanics` field was added a few days later (2026-08-27+) — the
// exact same "a display path was added after this script's target-set query was last written,
// so it was invisible to it" shape documented for every other source above. Unlike `steps`
// (an ordered rotation a viewer picks talents/spec context for), these four categories are
// deliberately cross-class by design (a teammate's healing cooldown, an enemy's buff) — so this
// pulls in spells belonging to every class, not just the 40 already covered by talent/PvP data.
$mechanicsExternalIds = [];
foreach (glob($projectRoot . '/data/arena-logs/rotations/*/*.json') as $rotFile) {
    $decoded = json_decode(file_get_contents($rotFile), true);
    if (!$decoded) {
        continue;
    }
    foreach (($decoded['topDpsWindowsByLength'] ?? []) as $window) {
        if (!$window) {
            continue;
        }
        foreach (['championBuffs', 'championDebuffs', 'targetBuffs', 'targetDebuffs'] as $key) {
            foreach (($window['mechanics'][$key] ?? []) as $item) {
                if (!empty($item['spellId'])) {
                    $mechanicsExternalIds[(int) $item['spellId']] = true;
                }
            }
        }
    }
}
$mechanicsSpellDbIds = [];
if ($mechanicsExternalIds !== []) {
    $placeholders = implode(',', array_fill(0, count($mechanicsExternalIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM spells WHERE spell_id IN ({$placeholders})");
    $stmt->execute(array_keys($mechanicsExternalIds));
    $mechanicsSpellDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Every spell_id referenced in the CC-chain corpus (data/arena-logs/cc-chains/{class}/
// {spec}.json's steps — powers the Top 10 CC Chains page). Added 2026-08-31 after a real report
// ("some icons are missing yet the same spell in crowd control has the icon on it") — confirmed
// by direct check: Holy Word: Chastise, Binding Shot, Freezing Trap, and Ring of Frost were all
// showing with no icon, while a same-NAMED sibling spell_id (a different internal spells.id for
// the identical real ability) already had one. Same exact "a display path existed before this
// script's target-set query ever covered it" shape as every source above — wow:find-cc-chains's
// spell_ids come from real raw combat-log casts, not from talent_node_entries/pvp_talents/etc.,
// so they were never in scope until now.
$ccChainExternalIds = [];
foreach (glob($projectRoot . '/data/arena-logs/cc-chains/*/*.json') as $ccFile) {
    $decoded = json_decode(file_get_contents($ccFile), true);
    if (!is_array($decoded)) {
        continue;
    }
    foreach ($decoded as $chain) {
        foreach (($chain['steps'] ?? []) as $step) {
            if (!empty($step['spellId'])) {
                $ccChainExternalIds[(int) $step['spellId']] = true;
            }
        }
    }
}
$ccChainSpellDbIds = [];
if ($ccChainExternalIds !== []) {
    $placeholders = implode(',', array_fill(0, count($ccChainExternalIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM spells WHERE spell_id IN ({$placeholders})");
    $stmt->execute(array_keys($ccChainExternalIds));
    $ccChainSpellDbIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

$targetSpellDbIds = array_values(array_unique(array_merge(
    $talentSpellDbIds,
    $pvpTalentSpellDbIds,
    $verifiedOverrideSpellDbIds,
    $explicitBaselineCooldownSpellDbIds,
    $rotationSpellDbIds,
    $mechanicsSpellDbIds,
    $ccChainSpellDbIds
)));

if ($limitSpellIds !== null) {
    $targetSpellDbIds = array_slice($targetSpellDbIds, 0, $limitSpellIds);
}

$totalSpellIds = count($targetSpellDbIds);
fwrite(STDOUT, "Found {$totalSpellIds} distinct spells.id referenced by talent data.\n\n");

// Filter to only spells that don't already have icon_name set
$placeholders = implode(',', array_fill(0, count($targetSpellDbIds), '?'));
$stmt = $pdo->prepare("SELECT id, spell_id, patch_id, name FROM spells WHERE id IN ({$placeholders}) AND icon_name IS NULL");
$stmt->execute($targetSpellDbIds);
$needsIcon = [];
foreach ($stmt->fetchAll() as $row) {
    $needsIcon[$row['id']] = ['spell_id' => (int) $row['spell_id'], 'patch_id' => (int) $row['patch_id'], 'name' => $row['name']];
}

$skippedCount = $totalSpellIds - count($needsIcon);
fwrite(STDOUT, "Processing " . count($needsIcon) . " spell_ids without icon_name already set.\n");
fwrite(STDOUT, "Skipping {$skippedCount} spell_ids that already have icon_name.\n\n");

// Fetch icon metadata and download files
$stats = [
    'processed'      => 0,
    'downloaded'     => 0,
    'failed'         => 0,
    'not_found'      => 0,
    'via_sibling'    => 0,
    'unique_files'   => [],
];

if (empty($needsIcon)) {
    fwrite(STDOUT, "All spells already have icon_name set via the API-driven pass.\n");
}

$processed = 0;
foreach ($needsIcon as $spellDbId => $target) {
    $spellId = $target['spell_id'];
    $processed++;
    if ($processed % 100 === 0) {
        fwrite(STDOUT, "  {$processed}/" . count($needsIcon) . " processed...\n");
    }

    try {
        $iconUrl = tryFetchIconUrl($token, $spellId);
        $resolvedVia = $spellId;

        // The specific spell_id copy carrying this ability's real gameplay data is often NOT
        // the copy Blizzard attached an icon to (multiple internal spell_id copies commonly
        // share one visible ability — see CLAUDE.md's many "duplicate spell_id" findings).
        // Confirmed live before building this: Void Bolt's selected copy 404s on the media
        // API but a same-named sibling has a real icon; same for Living Flame across the
        // "(desc=Color)" suffix boundary. Try same-base-name siblings before giving up.
        if ($iconUrl === null) {
            foreach (findSiblingSpellIds($pdo, $target['patch_id'], $spellDbId, $target['name']) as $siblingSpellId) {
                $iconUrl = tryFetchIconUrl($token, $siblingSpellId);
                if ($iconUrl !== null) {
                    $resolvedVia = $siblingSpellId;
                    $stats['via_sibling']++;
                    fwrite(STDOUT, "  [sibling] spell_id {$spellId} ('{$target['name']}') has no icon of its own — using sibling {$siblingSpellId}'s\n");
                    break;
                }
            }
        }

        if ($iconUrl === null) {
            fwrite(STDERR, "  [no-icon] spell_id {$spellId} has no icon asset (checked siblings too)\n");
            $stats['not_found']++;
            continue;
        }

        $iconFilename = basename($iconUrl);
        if (empty($iconFilename)) {
            fwrite(STDERR, "  [invalid] spell_id {$resolvedVia} has empty filename from {$iconUrl}\n");
            $stats['not_found']++;
            continue;
        }

        // Track unique files (for dedup analysis)
        $stats['unique_files'][$iconFilename] = true;

        // Download file if not already present and --skip-download not passed
        if (!$skipDownload) {
            $targetPath = $iconDir . '/' . $iconFilename;
            if (!downloadFile($iconUrl, $targetPath)) {
                $stats['failed']++;
                continue;
            }
            $stats['downloaded']++;
        }

        // Update the spell's icon_name in the database
        $updateStmt = $pdo->prepare('UPDATE spells SET icon_name = ? WHERE id = ?');
        $updateStmt->execute([$iconFilename, $spellDbId]);

        $stats['processed']++;

    } catch (RuntimeException $e) {
        fwrite(STDERR, "  [error] spell_id {$spellId}: " . $e->getMessage() . "\n");
        $stats['failed']++;
    }
}

// ---------------------------------------------------------------------------------------
// Hand-curated overrides — spells Blizzard's API doesn't index under any spell_id at all.
// Runs after the main API-driven pass so it only ever fills gaps that pass genuinely couldn't
// (any spell resolved above already has icon_name set, so applyIconNameOverrides()'s own
// "icon_name IS NULL" filter naturally skips it).
// ---------------------------------------------------------------------------------------

fwrite(STDOUT, "\nApplying hand-curated icon-name overrides...\n");
applyIconNameOverrides($pdo, $iconDir, $skipDownload, $stats);

// ---------------------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------------------

fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
fwrite(STDOUT, "Summary\n");
fwrite(STDOUT, str_repeat('=', 60) . "\n\n");

fwrite(STDOUT, "Spell_ids processed:           " . $stats['processed'] . "\n");
fwrite(STDOUT, "Unique icon files:             " . count($stats['unique_files']) . "\n");
if (!$skipDownload) {
    fwrite(STDOUT, "Icons actually downloaded:     " . $stats['downloaded'] . "\n");
    fwrite(STDOUT, "  (others were already on disk)\n");
}
fwrite(STDOUT, "Resolved via sibling spell_id: " . $stats['via_sibling'] . "\n");
fwrite(STDOUT, "Spells without icon (no API):  " . $stats['not_found'] . "\n");
fwrite(STDOUT, "Fetch/download errors:         " . $stats['failed'] . "\n");
fwrite(STDOUT, "\n");

if ($skipDownload) {
    fwrite(STDOUT, "--skip-download was used; no files were actually downloaded.\n");
} else {
    fwrite(STDOUT, "Icon files stored at: {$iconDir}/\n");
}

fwrite(STDOUT, "Total API requests made:       {$requestCount}\n");
fwrite(STDOUT, "Database updated with icon names for: " . $stats['processed'] . " spells\n");
fwrite(STDOUT, "\n" . str_repeat('-', 60) . "\n");

// Refresh the committed manifest from the DB's full current state (not just this run's
// additions) — see refreshIconManifestSection()'s docblock.
$manifestEntries = [];
$stmt = $pdo->query('SELECT spell_id, icon_name FROM spells WHERE icon_name IS NOT NULL');
foreach ($stmt->fetchAll() as $row) {
    $manifestEntries[(string) $row['spell_id']] = $row['icon_name'];
}
refreshIconManifestSection('spells', $manifestEntries);
fwrite(STDOUT, "\nManifest updated: " . ICON_MANIFEST_PATH . " now has " . count($manifestEntries) . " spell entries.\n");
fwrite(STDOUT, "Commit both the manifest and storage/app/public/spell-icons/ so other machines never need Blizzard API credentials for icons.\n");

// Verify counts match. Fixed 2026-08-10 — the original check compared THIS RUN's unique
// download count against the TOTAL directory file count, which only ever matches on a single
// one-shot full run: any later idempotent/incremental re-run (the normal case once the dataset
// is fully populated — e.g. re-running after adding one baseline-spec-overrides.txt entry)
// downloads 0 new files and was therefore comparing "0" against "everything on disk," always
// failing. Confirmed a real bug, not just a cosmetic one: it broke `wow:refresh-icons`, which
// treats this script's exit code as authoritative. Now compares the DB's own total distinct
// icon_name count (every spell that has one, not just ones touched this run) against the
// directory's file count — the actually-authoritative check.
if (!$skipDownload) {
    $actualFileCount = count(array_filter(
        scandir($iconDir) ?: [],
        fn ($f) => !str_starts_with($f, '.')
    ));
    $dbUniqueIconCount = count(array_unique(array_values($manifestEntries)));
    fwrite(STDOUT, "\nVerification:\n");
    fwrite(STDOUT, "  Unique icon_names in the spells table: {$dbUniqueIconCount}\n");
    fwrite(STDOUT, "  Actual files in {$iconDir}: {$actualFileCount}\n");
    if (abs($actualFileCount - $dbUniqueIconCount) <= 2) { // Allow small rounding
        fwrite(STDOUT, "  ✓ Counts match (within tolerance)\n");
    } else {
        fwrite(STDERR, "  ✗ Counts DO NOT MATCH! This indicates a bug.\n");
        exit(1);
    }
}

fwrite(STDOUT, "\nDone!\n");
