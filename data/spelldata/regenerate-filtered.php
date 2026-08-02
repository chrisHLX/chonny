<?php

/**
 * One-shot regeneration of every class's filtered/{class}/*.txt from data/spelldata/raw/*.txt —
 * added 2026-08-01 alongside split-by-tree.php's --specs/--heroes auto-derivation (see that
 * file's docblock), so the whole filtered dataset can be rebuilt with a single command instead
 * of one split-by-tree.php invocation per class with hand-typed spec/hero name lists that aren't
 * recorded anywhere in this repo. Re-run this any time raw/*.txt changes (a new patch fetch) or
 * split-by-tree.php's own logic changes (e.g. the 2026-08-01 multi-paragraph-description
 * truncation fix) and the filtered output needs to reflect it.
 *
 * Shells out to split-by-tree.php once per raw/*.txt file rather than including it directly —
 * that script executes top-level on load (not written as a library function), so looping via
 * `require` would redeclare its functions on the second iteration and fatal.
 *
 * Usage: php regenerate-filtered.php
 */

// Proper display casing for known WoW class slugs — purely cosmetic (banner/title text inside
// each filtered file, never used for classification), so this only needs to cover classes
// actually present in data/spelldata/raw/; an unrecognized slug just falls back to
// split-by-tree.php's own ucfirst() default rather than failing.
const CLASS_LABELS = [
    'deathknight' => 'Death Knight',
    'demonhunter' => 'Demon Hunter',
    'druid'       => 'Druid',
    'evoker'      => 'Evoker',
    'hunter'      => 'Hunter',
    'mage'        => 'Mage',
    'monk'        => 'Monk',
    'paladin'     => 'Paladin',
    'priest'      => 'Priest',
    'rogue'       => 'Rogue',
    'shaman'      => 'Shaman',
    'warlock'     => 'Warlock',
    'warrior'     => 'Warrior',
];

$rawDir      = __DIR__ . '/raw';
$filteredDir = __DIR__ . '/filtered';
$splitScript = __DIR__ . '/split-by-tree.php';
$phpBinary   = PHP_BINARY;

$rawFiles = glob($rawDir . '/*.txt');
sort($rawFiles);

if (empty($rawFiles)) {
    fwrite(STDERR, "No raw dumps found in {$rawDir}\n");
    exit(1);
}

$failures = [];

foreach ($rawFiles as $rawFile) {
    $slug      = pathinfo($rawFile, PATHINFO_FILENAME);
    $outputDir = $filteredDir . '/' . $slug;
    $label     = CLASS_LABELS[$slug] ?? ucfirst($slug);

    fwrite(STDOUT, "=== {$label} ({$slug}) ===\n");

    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($splitScript) . ' '
        . escapeshellarg($rawFile) . ' ' . escapeshellarg($outputDir) . ' ' . escapeshellarg($label);

    passthru($cmd, $exitCode);
    fwrite(STDOUT, "\n");

    if ($exitCode !== 0) {
        $failures[] = $slug;
    }
}

if (!empty($failures)) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'All ' . count($rawFiles) . " class dump(s) regenerated.\n");
fwrite(STDOUT, "Review each class's \"Anomalies\" output above before re-importing — an anomaly\n");
fwrite(STDOUT, "means a Talent Entry didn't classify (e.g. an auto-derived name didn't match),\n");
fwrite(STDOUT, "not that the record was dropped (unmatched records still land in baseline.txt).\n");
