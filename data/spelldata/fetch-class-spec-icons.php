<?php

/**
 * Fetches class and specialization icon images from Blizzard's Game Data API media
 * endpoints, self-hosts them on the local Laravel public disk (storage/app/public/
 * class-icons/ and storage/app/public/spec-icons/), and stores the filename (not the CDN
 * URL) on each classes.icon_name / specializations.icon_name column. Same
 * fetch-once-self-host-forever principle as data/spelldata/fetch-spell-icons.php — icons
 * are never hotlinked from Blizzard's live CDN.
 *
 * Class media: /data/wow/media/playable-class/{id} — Blizzard's numeric playable-class IDs
 * are a small, stable, well-documented set that has not changed since Legion (1=Warrior,
 * 2=Paladin, 3=Hunter, 4=Rogue, 5=Priest, 6=Death Knight, 7=Shaman, 8=Mage, 9=Warlock,
 * 10=Monk, 11=Druid, 12=Demon Hunter, 13=Evoker) — hardcoded below, keyed by our own
 * classes.slug (which comes from the data/spelldata/filtered/{slug}/ directory name, see
 * ImportSpellData::importClass()), rather than re-deriving it from any API index call.
 *
 * Spec media: /data/wow/media/playable-specialization/{id} — uses specializations.
 * external_spec_id, already captured at import time from the talent tree JSON (see
 * ImportSpellData::importSpecializations()), so no separate ID-resolution step is needed.
 *
 * Idempotent: classes/specs that already have icon_name set are skipped, and icon files
 * already present on disk are not re-downloaded.
 *
 * Auth: OAuth2 client_credentials against oauth.battle.net, using BLIZZARD_CLIENT_ID /
 * BLIZZARD_CLIENT_SECRET from .env, same pattern as fetch-spell-icons.php.
 *
 * Usage:
 *   php fetch-class-spec-icons.php [--skip-download]
 */

// ---------------------------------------------------------------------------------------
// Config / .env
// ---------------------------------------------------------------------------------------

const REGION    = 'us';
const API_NAMESPACE = 'static-us';
const LOCALE    = 'en_US';
const API_HOST  = 'https://us.api.blizzard.com';
const OAUTH_URL = 'https://oauth.battle.net/token';

const CLASS_ICON_STORAGE_SUBDIR = 'class-icons';
const SPEC_ICON_STORAGE_SUBDIR  = 'spec-icons';

// Shared with fetch-spell-icons.php — see that script's ICON_MANIFEST_PATH docblock and
// wow:apply-icon-manifest. This script only ever rewrites the 'classes'/'specs' sections.
const ICON_MANIFEST_PATH = __DIR__ . '/icon-manifest.json';

// Courtesy delay between requests (microseconds) — same budget as fetch-spell-icons.php.
const REQUEST_DELAY_US = 40000; // 40ms ≈ 25 req/s

const MAX_RETRIES = 5;

// Blizzard's playable-class numeric IDs, stable since Legion. Keyed by our own
// classes.slug (Str::slug() of the data/spelldata/filtered/{...}/ directory name).
const BLIZZARD_CLASS_IDS = [
    'warrior'     => 1,
    'paladin'     => 2,
    'hunter'      => 3,
    'rogue'       => 4,
    'priest'      => 5,
    'deathknight' => 6,
    'shaman'      => 7,
    'mage'        => 8,
    'warlock'     => 9,
    'monk'        => 10,
    'druid'       => 11,
    'demonhunter' => 12,
    'evoker'      => 13,
];

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

$dbHost     = $env['DB_HOST'] ?? 'localhost';
$dbPort     = $env['DB_PORT'] ?? 3306;
$dbDatabase = $env['DB_DATABASE'] ?? '';
$dbUser     = $env['DB_USERNAME'] ?? '';
$dbPassword = $env['DB_PASSWORD'] ?? '';

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

$skipDownload = in_array('--skip-download', array_slice($argv, 1), true);

// ---------------------------------------------------------------------------------------
// HTTP layer: auth + retry/backoff on 429 (and transient 5xx) — identical to
// fetch-spell-icons.php, duplicated rather than shared since these are standalone,
// non-Laravel-bootstrapped CLI scripts (same convention as fetch-talent-trees.php).
// ---------------------------------------------------------------------------------------

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

function downloadFile(string $url, string $targetPath): bool
{
    if (is_file($targetPath)) {
        return true;
    }

    usleep(REQUEST_DELAY_US);

    $response = httpRequest('GET', $url);

    if ($response['status'] !== 200) {
        fwrite(STDERR, "  [skip] HTTP {$response['status']} fetching {$url}\n");
        return false;
    }

    $dir = dirname($targetPath);
    @mkdir($dir, 0777, true);

    if (!file_put_contents($targetPath, $response['body'])) {
        fwrite(STDERR, "  [error] Failed to write {$targetPath}\n");
        return false;
    }

    return true;
}

function iconUrlFromMediaResponse(array $mediaResponse): ?string
{
    if (empty($mediaResponse['assets']) || !is_array($mediaResponse['assets'])) {
        return null;
    }

    foreach ($mediaResponse['assets'] as $asset) {
        if (($asset['key'] ?? null) === 'icon' && !empty($asset['value'])) {
            return $asset['value'];
        }
    }

    return null;
}

/** See fetch-spell-icons.php's identical helper — always a full refresh of one section. */
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

$classIconDir = $projectRoot . '/storage/app/public/' . CLASS_ICON_STORAGE_SUBDIR;
$specIconDir  = $projectRoot . '/storage/app/public/' . SPEC_ICON_STORAGE_SUBDIR;
@mkdir($classIconDir, 0777, true);
@mkdir($specIconDir, 0777, true);

$stats = ['classes' => 0, 'specs' => 0, 'skipped' => 0, 'failed' => 0, 'not_found' => 0];

// --- Classes -----------------------------------------------------------------------

fwrite(STDOUT, "Fetching class icons...\n");

$stmt = $pdo->query('SELECT id, slug, name FROM classes WHERE icon_name IS NULL');
foreach ($stmt->fetchAll() as $row) {
    $blizzardId = BLIZZARD_CLASS_IDS[$row['slug']] ?? null;
    if ($blizzardId === null) {
        fwrite(STDERR, "  [unmapped] classes.slug '{$row['slug']}' has no entry in BLIZZARD_CLASS_IDS\n");
        $stats['skipped']++;
        continue;
    }

    try {
        $media = apiGet($token, "/data/wow/media/playable-class/{$blizzardId}");
        $iconUrl = iconUrlFromMediaResponse($media);

        if ($iconUrl === null) {
            fwrite(STDERR, "  [no-icon] {$row['name']} (class id {$blizzardId}) has no icon asset\n");
            $stats['not_found']++;
            continue;
        }

        $filename = basename($iconUrl);

        if (!$skipDownload && !downloadFile($iconUrl, $classIconDir . '/' . $filename)) {
            $stats['failed']++;
            continue;
        }

        $pdo->prepare('UPDATE classes SET icon_name = ? WHERE id = ?')->execute([$filename, $row['id']]);
        $stats['classes']++;
        fwrite(STDOUT, "  {$row['name']} -> {$filename}\n");
    } catch (RuntimeException $e) {
        fwrite(STDERR, "  [error] {$row['name']}: " . $e->getMessage() . "\n");
        $stats['failed']++;
    }
}

// --- Specializations -----------------------------------------------------------------

fwrite(STDOUT, "\nFetching specialization icons...\n");

$stmt = $pdo->query('SELECT id, name, external_spec_id FROM specializations WHERE icon_name IS NULL');
foreach ($stmt->fetchAll() as $row) {
    if (empty($row['external_spec_id'])) {
        fwrite(STDERR, "  [no-external-id] specialization '{$row['name']}' (id {$row['id']}) has no external_spec_id\n");
        $stats['skipped']++;
        continue;
    }

    try {
        $media = apiGet($token, "/data/wow/media/playable-specialization/{$row['external_spec_id']}");
        $iconUrl = iconUrlFromMediaResponse($media);

        if ($iconUrl === null) {
            fwrite(STDERR, "  [no-icon] {$row['name']} (spec id {$row['external_spec_id']}) has no icon asset\n");
            $stats['not_found']++;
            continue;
        }

        $filename = basename($iconUrl);

        if (!$skipDownload && !downloadFile($iconUrl, $specIconDir . '/' . $filename)) {
            $stats['failed']++;
            continue;
        }

        $pdo->prepare('UPDATE specializations SET icon_name = ? WHERE id = ?')->execute([$filename, $row['id']]);
        $stats['specs']++;
        fwrite(STDOUT, "  {$row['name']} -> {$filename}\n");
    } catch (RuntimeException $e) {
        fwrite(STDERR, "  [error] {$row['name']}: " . $e->getMessage() . "\n");
        $stats['failed']++;
    }
}

// ---------------------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------------------

fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
fwrite(STDOUT, "Summary\n");
fwrite(STDOUT, str_repeat('=', 60) . "\n\n");
fwrite(STDOUT, "Classes updated:        {$stats['classes']}\n");
fwrite(STDOUT, "Specializations updated: {$stats['specs']}\n");
fwrite(STDOUT, "Skipped (unmapped):      {$stats['skipped']}\n");
fwrite(STDOUT, "Not found (no icon):     {$stats['not_found']}\n");
fwrite(STDOUT, "Failed:                  {$stats['failed']}\n");
fwrite(STDOUT, "Total API requests:      {$requestCount}\n");

// Refresh the committed manifest from the DB's full current state (not just this run's
// additions) — see refreshIconManifestSection()'s docblock in this file / fetch-spell-icons.php.
$classEntries = [];
$stmt = $pdo->query('SELECT slug, icon_name FROM classes WHERE icon_name IS NOT NULL');
foreach ($stmt->fetchAll() as $row) {
    $classEntries[$row['slug']] = $row['icon_name'];
}
refreshIconManifestSection('classes', $classEntries);

$specEntries = [];
$stmt = $pdo->query('SELECT external_spec_id, icon_name FROM specializations WHERE icon_name IS NOT NULL AND external_spec_id IS NOT NULL');
foreach ($stmt->fetchAll() as $row) {
    $specEntries[(string) $row['external_spec_id']] = $row['icon_name'];
}
refreshIconManifestSection('specs', $specEntries);

fwrite(STDOUT, "\nManifest updated: " . ICON_MANIFEST_PATH . " now has " . count($classEntries) . " class + " . count($specEntries) . " spec entries.\n");
fwrite(STDOUT, "Commit the manifest plus storage/app/public/class-icons/ and spec-icons/ so other machines never need Blizzard API credentials for icons.\n");

fwrite(STDOUT, "\nDone!\n");
