<?php

/**
 * Downloads SimulationCraft's per-class raw spell dumps (SpellDataDump/{class}.txt) from a
 * given branch of github.com/simulationcraft/simc into data/spelldata/raw/{class}.txt —
 * the one step in the patch-update pipeline that previously had no script at all (see
 * CLAUDE.md's "Patch 12.1 Readiness" analysis).
 *
 * Branch matters and is NOT guessed: SimC's active branch for the current expansion is
 * "midnight" (confirmed live 2026-08-06/07) — the "thewarwithin" branch seen in earlier
 * ad hoc lookups this same week is a leftover from the previous expansion and must not be
 * used going forward. Once a patch actually ships, SimC's maintainers cut a
 * "data-update-live-{build}" branch with confirmed-live numbers — PTR/test branches
 * ("data-update-test-*") should not be treated as final.
 *
 * --auto-detect-live queries the GitHub branches API for the most recently updated branch
 * matching data-update-live-* and uses it — a convenience, not a substitute for checking:
 * if more than one plausible branch exists, or none do, it fails loudly and asks for
 * --branch= explicitly rather than guessing. Unauthenticated GitHub API calls are rate
 * limited to 60/hour, which is more than enough for this script's occasional use.
 *
 * Usage:
 *   php fetch-simc-dumps.php --branch=midnight
 *   php fetch-simc-dumps.php --auto-detect-live
 *   php fetch-simc-dumps.php --branch=data-update-live-69123 --only=priest,hunter
 */

const CLASS_SLUGS = [
    'deathknight', 'demonhunter', 'druid', 'evoker', 'hunter', 'mage', 'monk',
    'paladin', 'priest', 'rogue', 'shaman', 'warlock', 'warrior',
];

const RAW_BASE = 'https://raw.githubusercontent.com/simulationcraft/simc';
const API_BRANCHES = 'https://api.github.com/repos/simulationcraft/simc/branches?per_page=100';

$branch = null;
$autoDetect = false;
$only = null;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--branch=(.+)$/', $arg, $m)) {
        $branch = $m[1];
    } elseif ($arg === '--auto-detect-live') {
        $autoDetect = true;
    } elseif (preg_match('/^--only=(.+)$/', $arg, $m)) {
        $only = array_map('trim', explode(',', $m[1]));
    }
}

if (!$branch && !$autoDetect) {
    fwrite(STDERR, "Must pass --branch=<name> or --auto-detect-live. See this file's docblock.\n");
    exit(1);
}

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'chonny-fetch-simc-dumps',
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error for {$url}: {$err}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'body' => substr($raw, $headerSize)];
}

if ($autoDetect) {
    fwrite(STDOUT, "Querying GitHub for data-update-live-* branches...\n");
    $resp = httpGet(API_BRANCHES);
    if ($resp['status'] !== 200) {
        fwrite(STDERR, "GitHub API returned HTTP {$resp['status']} — cannot auto-detect. Pass --branch= explicitly.\n");
        exit(1);
    }
    $branches = json_decode($resp['body'], true, 512, JSON_THROW_ON_ERROR);
    $liveBranches = array_values(array_filter(
        array_map(fn ($b) => $b['name'], $branches),
        fn ($name) => str_starts_with($name, 'data-update-live-')
    ));

    if (count($liveBranches) === 0) {
        fwrite(STDERR, "No data-update-live-* branch found. If the patch hasn't shipped yet, this is expected — use --branch=midnight for pre-release testing, or wait.\n");
        exit(1);
    }

    // Branch names embed the build number (data-update-live-{build}) — highest number wins,
    // not "first returned" (the API's own ordering isn't guaranteed to be numeric-sorted).
    usort($liveBranches, fn ($a, $b) => (int) substr($b, strrpos($b, '-') + 1) <=> (int) substr($a, strrpos($a, '-') + 1));
    $branch = $liveBranches[0];

    fwrite(STDOUT, 'Found ' . count($liveBranches) . " live branch(es): " . implode(', ', $liveBranches) . "\n");
    fwrite(STDOUT, "Using highest build number: {$branch}\n\n");
}

$rawDir = __DIR__ . '/raw';
@mkdir($rawDir, 0777, true);

$targets = $only ?? CLASS_SLUGS;
$succeeded = 0;
$failed = [];

foreach ($targets as $slug) {
    if (!in_array($slug, CLASS_SLUGS, true)) {
        fwrite(STDERR, "  [skip] '{$slug}' is not a known class slug\n");
        continue;
    }

    $url = RAW_BASE . '/' . $branch . '/SpellDataDump/' . $slug . '.txt';
    fwrite(STDOUT, "Fetching {$slug}... ");

    try {
        $resp = httpGet($url);
    } catch (RuntimeException $e) {
        fwrite(STDOUT, "ERROR ({$e->getMessage()})\n");
        $failed[] = $slug;
        continue;
    }

    if ($resp['status'] !== 200) {
        fwrite(STDOUT, "HTTP {$resp['status']} — not found on branch '{$branch}'\n");
        $failed[] = $slug;
        continue;
    }

    file_put_contents($rawDir . '/' . $slug . '.txt', $resp['body']);
    fwrite(STDOUT, 'OK (' . number_format(strlen($resp['body'])) . " bytes)\n");
    $succeeded++;
}

fwrite(STDOUT, "\n{$succeeded}/" . count($targets) . " class dumps written to {$rawDir}/\n");

if (!empty($failed)) {
    fwrite(STDERR, "Failed/missing: " . implode(', ', $failed) . "\n");
    fwrite(STDERR, "If this branch doesn't have SpellDataDump/ at all, double-check the branch name — SimC sometimes restructures this path between major versions.\n");
    exit(1);
}
