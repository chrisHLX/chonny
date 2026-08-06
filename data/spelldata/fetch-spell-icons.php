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
 * These are queried directly from the local Laravel database rather than derived from the
 * JSON files in data/talenttrees/ or data/pvptalents/, ensuring consistency with whatever
 * was actually imported.
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

$targetSpellDbIds = array_values(array_unique(array_merge($talentSpellDbIds, $pvpTalentSpellDbIds, $verifiedOverrideSpellDbIds)));

if ($limitSpellIds !== null) {
    $targetSpellDbIds = array_slice($targetSpellDbIds, 0, $limitSpellIds);
}

$totalSpellIds = count($targetSpellDbIds);
fwrite(STDOUT, "Found {$totalSpellIds} distinct spells.id referenced by talent data.\n\n");

// Filter to only spells that don't already have icon_name set
$placeholders = implode(',', array_fill(0, count($targetSpellDbIds), '?'));
$stmt = $pdo->prepare("SELECT id, spell_id FROM spells WHERE id IN ({$placeholders}) AND icon_name IS NULL");
$stmt->execute($targetSpellDbIds);
$needsIcon = [];
foreach ($stmt->fetchAll() as $row) {
    $needsIcon[$row['id']] = (int) $row['spell_id'];
}

$skippedCount = $totalSpellIds - count($needsIcon);
fwrite(STDOUT, "Processing " . count($needsIcon) . " spell_ids without icon_name already set.\n");
fwrite(STDOUT, "Skipping {$skippedCount} spell_ids that already have icon_name.\n\n");

if (empty($needsIcon)) {
    fwrite(STDOUT, "All spells already have icon_name set. Nothing to do.\n");
    exit(0);
}

// Fetch icon metadata and download files
$stats = [
    'processed'      => 0,
    'downloaded'     => 0,
    'failed'         => 0,
    'not_found'      => 0,
    'unique_files'   => [],
];

$spellsBySpellId = [];
foreach ($needsIcon as $spellDbId => $spellId) {
    $spellsBySpellId[$spellId] = $spellDbId;
}

$processed = 0;
foreach ($needsIcon as $spellDbId => $spellId) {
    $processed++;
    if ($processed % 100 === 0) {
        fwrite(STDOUT, "  {$processed}/" . count($needsIcon) . " processed...\n");
    }

    try {
        $mediaResponse = apiGet($token, "/data/wow/media/spell/{$spellId}");

        $iconUrl = null;
        if (!empty($mediaResponse['assets']) && is_array($mediaResponse['assets'])) {
            foreach ($mediaResponse['assets'] as $asset) {
                if (($asset['key'] ?? null) === 'icon' && !empty($asset['value'])) {
                    $iconUrl = $asset['value'];
                    break;
                }
            }
        }

        if ($iconUrl === null) {
            fwrite(STDERR, "  [no-icon] spell_id {$spellId} has no icon asset\n");
            $stats['not_found']++;
            continue;
        }

        $iconFilename = basename($iconUrl);
        if (empty($iconFilename)) {
            fwrite(STDERR, "  [invalid] spell_id {$spellId} has empty filename from {$iconUrl}\n");
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

// Verify counts match
if (!$skipDownload) {
    $actualFileCount = count(array_filter(
        scandir($iconDir) ?: [],
        fn ($f) => !str_starts_with($f, '.')
    ));
    fwrite(STDOUT, "\nVerification:\n");
    fwrite(STDOUT, "  Unique icon_names in icon_name column: " . count($stats['unique_files']) . "\n");
    fwrite(STDOUT, "  Actual files in {$iconDir}: {$actualFileCount}\n");
    if (abs($actualFileCount - count($stats['unique_files'])) <= 2) { // Allow small rounding
        fwrite(STDOUT, "  ✓ Counts match (within tolerance)\n");
    } else {
        fwrite(STDERR, "  ✗ Counts DO NOT MATCH! This indicates a bug.\n");
        exit(1);
    }
}

fwrite(STDOUT, "\nDone!\n");
