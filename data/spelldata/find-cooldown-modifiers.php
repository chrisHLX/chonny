<?php

/**
 * Finds every talent/passive in the given filtered spelldata file(s) that modifies a
 * target ability's Cooldown or Charges — as opposed to its damage, duration, resource
 * cost, etc. A record's "Affecting Spells" line names everything that touches it in
 * any way; this narrows that down to only the effects whose own header mentions
 * Cooldown or Charges, and whose "Affected Spells" list includes the target.
 *
 * Usage: php find-cooldown-modifiers.php --target="Feral Frenzy" <file1> [file2 ...]
 */

$target = null;
$files  = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--target=(.+)$/', $arg, $m)) {
        $target = $m[1];
    } else {
        $files[] = $arg;
    }
}

if (!$target || empty($files)) {
    fwrite(STDERR, "Usage: php find-cooldown-modifiers.php --target=\"Ability Name\" <file1> [file2 ...]\n");
    exit(1);
}

$found = [];

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Skipping missing file: {$file}\n");
        continue;
    }

    $blocks = preg_split('/\n\s*\n/', file_get_contents($file));

    foreach ($blocks as $block) {
        if (!preg_match('/^Name\s*:\s*(.*)$/m', $block, $m)) continue;
        $name = trim($m[1]);

        // Split the record into effect chunks, each starting at "#N (id=...)".
        $lines = explode("\n", $block);
        $chunks = [];
        $current = [];
        foreach ($lines as $line) {
            if (preg_match('/^#\d+\s*\(id=/', $line)) {
                if (!empty($current)) $chunks[] = $current;
                $current = [$line];
            } elseif (!empty($current)) {
                $current[] = $line;
            }
        }
        if (!empty($current)) $chunks[] = $current;

        foreach ($chunks as $chunk) {
            $header = $chunk[0];
            if (!preg_match('/cooldown|charges/i', $header)) continue;

            $chunkText = implode("\n", $chunk);
            if (!preg_match('/^\s*Affected Spells(?: \(Label\))?\s*:\s*(.*)$/m', $chunkText, $am)) continue;
            if (!str_contains($am[1], $target)) continue;

            $baseValue = preg_match('/Base Value:\s*(-?[\d.]+)/', $chunkText, $bv) ? $bv[1] : '?';
            $description = preg_match('/^Description\s*:\s*(.*)$/m', $block, $dm) ? trim($dm[1]) : '';

            $found[] = [
                'file'        => basename($file),
                'name'        => $name,
                'header'      => trim($header),
                'baseValue'   => $baseValue,
                'description' => $description,
            ];
        }
    }
}

if (empty($found)) {
    echo "No cooldown/charge modifiers found for \"{$target}\" in the given file(s).\n";
    exit(0);
}

foreach ($found as $f) {
    $seconds = is_numeric($f['baseValue']) ? (' (' . ($f['baseValue'] / 1000) . ' sec)') : '';
    echo "[{$f['file']}] {$f['name']}\n";
    echo "  Effect: {$f['header']}\n";
    echo "  Base Value: {$f['baseValue']}{$seconds}\n";
    if ($f['description']) {
        echo "  {$f['description']}\n";
    }
    echo "\n";
}
