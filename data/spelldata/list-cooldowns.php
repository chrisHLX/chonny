<?php

/**
 * Lists Name / School / Cooldown / Charges / Description for every record in the given
 * filtered spelldata file(s) that has a Cooldown field — a quick way to answer "what are
 * this tree's cooldown abilities" questions straight from the source data instead of memory.
 *
 * Usage: php list-cooldowns.php <file1> [file2 ...]
 */

$files = array_slice($argv, 1);
if (empty($files)) {
    fwrite(STDERR, "Usage: php list-cooldowns.php <file1> [file2 ...]\n");
    exit(1);
}

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Skipping missing file: {$file}\n");
        continue;
    }

    $blocks = preg_split('/\n\s*\n/', file_get_contents($file));

    foreach ($blocks as $block) {
        if (!preg_match('/^Name\s*:\s*(.*)$/m', $block, $m)) continue;
        if (!preg_match('/^Cooldown\s*:\s*(.*)$/m', $block, $cd)) continue;

        $name        = trim($m[1]);
        $school      = preg_match('/^School\s*:\s*(.*)$/m', $block, $s) ? trim($s[1]) : '';
        $cooldown    = trim($cd[1]);
        $charges     = preg_match('/^Charges\s*:\s*(.*)$/m', $block, $c) ? trim($c[1]) : '';
        $description = preg_match('/^Description\s*:\s*(.*)$/m', $block, $d) ? trim($d[1]) : '';

        echo "[" . basename($file) . "] {$name}\n";
        echo "  School: {$school} | Cooldown: {$cooldown}" . ($charges ? " | Charges: {$charges}" : '') . "\n";
        if ($description) {
            echo "  {$description}\n";
        }
        echo "\n";
    }
}
