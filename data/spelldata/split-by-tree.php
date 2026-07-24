<?php

/**
 * Mechanical regroup of a SimulationCraft class spell dump into per-talent-tree files.
 * Copies every spell record verbatim (all fields, all Effects, full Description/Tooltip
 * text) into one file per tree it belongs to — no summarizing, no field-dropping.
 *
 * Classification is driven by each record's "Talent Entry" line(s):
 *   - none                         -> baseline.txt (this includes PvP-talent records —
 *                                      see note below)
 *   - tree=class                   -> class-talents.txt (regardless of the name prefix —
 *                                      some class-tree entries are labeled with a spec
 *                                      name instead of "Generic", e.g. Priest's Angel's
 *                                      Mercy; tree= is the reliable signal, not the name)
 *   - tree=spec,  name starts with one of --specs  -> <spec>.txt
 *   - tree=hero,  name starts with one of --heroes -> hero-<hero>.txt
 * A record can have more than one Talent Entry line (a continuation line containing
 * "tree=", not to be confused with per-rank effect-scaling continuation lines like
 * "Effect#2 [op=set, values=(1, 2)]", which are metadata on the same entry, not a
 * second tree assignment) — such records are copied verbatim into every file they
 * belong to.
 *
 * PvP talents were previously split into their own pvp-talents.txt category (detected via
 * "(desc=PvP Talent)" on the Name line, since those records never carry a Talent Entry
 * line and would otherwise land in baseline.txt undifferentiated from ordinary spells).
 * That split existed purely to audit whether SimC's PvP-talent coverage was complete —
 * which is how the missing Mage PvP talent Snowdrift was originally found. That audit
 * question is now answered a better way: data/talenttrees/fetch-talent-trees.php pulls
 * Blizzard's official PvP talent list directly, and data/pvptalents/diff-against-simc.php
 * diffs it against this project's SimC-derived data — confirmed, across every class run so
 * far, that SimC's PvP-talent coverage is broadly incomplete (e.g. Hunter: 1 SimC record
 * vs. 26 from Blizzard), not just missing the occasional talent. With Blizzard's data now
 * the authoritative source for PvP talents, the split-out category no longer pulls its
 * weight — PvP-talent records fall back to baseline.txt like any other Talent-Entry-less
 * record, same as before that fix was added.
 *
 * Usage:
 *   php split-by-tree.php <input-file> <output-dir> <class-label> --specs=A,B,C --heroes=X,Y,Z
 *
 * Spec/hero tree names are class-specific and not baked in here on purpose — verify
 * them for the class you're splitting (same diligence as confirming any other spell
 * fact) rather than assuming names carried over from another class.
 */

$input      = $argv[1] ?? null;
$outputDir  = $argv[2] ?? null;
$classLabel = $argv[3] ?? null;

$specNames = [];
$heroNames = [];
foreach (array_slice($argv, 4) as $arg) {
    if (preg_match('/^--specs=(.+)$/', $arg, $m)) {
        $specNames = array_map('trim', explode(',', $m[1]));
    } elseif (preg_match('/^--heroes=(.+)$/', $arg, $m)) {
        $heroNames = array_map('trim', explode(',', $m[1]));
    }
}

if (!$input || !$outputDir || !$classLabel || !is_file($input) || empty($specNames) || empty($heroNames)) {
    fwrite(STDERR, "Usage: php split-by-tree.php <input-file> <output-dir> <class-label> --specs=A,B,C --heroes=X,Y,Z\n");
    exit(1);
}

// Filesystem-safe key for names that may contain spaces/apostrophes (hero tree names
// especially — e.g. "Elune's Chosen", "Druid of the Claw" — unlike single-word spec names).
function slug(string $name): string
{
    $s = strtolower($name);
    $s = preg_replace("/['\x{2019}]/u", '', $s);   // drop apostrophes rather than hyphenate them
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);     // everything else non-alphanumeric -> hyphen
    return trim($s, '-');
}

$categoryFiles = ['baseline' => 'baseline.txt', 'class-talents' => 'class-talents.txt'];
$titles        = ['baseline' => 'Baseline (no talent tree)', 'class-talents' => 'Class Talents'];
foreach ($specNames as $spec) {
    $key = slug($spec);
    $categoryFiles[$key] = "{$key}.txt";
    $titles[$key]        = "{$spec} Spec Talents";
}
foreach ($heroNames as $hero) {
    $key = 'hero-' . slug($hero);
    $categoryFiles[$key] = "{$key}.txt";
    $titles[$key]        = "{$hero} Hero Talents";
}

$raw   = file_get_contents($input);
$lines = explode("\n", $raw);

// First line is the SimC header banner, not part of any record.
$headerLine = array_shift($lines);

// Split remaining lines into blank-line-separated blocks. Each non-empty block is one
// spell record (starts with a "Name             : ..." line).
$blocks = [];
$current = [];
foreach ($lines as $line) {
    if (trim($line) === '') {
        if (!empty($current)) {
            $blocks[] = $current;
            $current  = [];
        }
        continue;
    }
    $current[] = $line;
}
if (!empty($current)) {
    $blocks[] = $current;
}

$grouped   = array_fill_keys(array_keys($categoryFiles), []);
$index     = []; // name => [categories]
$anomalies = [];

foreach ($blocks as $block) {
    if (!preg_match('/^Name\s*:\s*(.*)$/', $block[0], $m)) {
        // Not a spell record (shouldn't happen after the header line is removed).
        continue;
    }
    $name = trim($m[1]);

    // Walk the block collecting every Talent Entry value, including continuation
    // lines (lines starting with whitespace immediately after a Talent Entry line —
    // same continuation style the dump uses for Labels/Resource/etc).
    $talentEntryLines = [];
    $lastFieldIsTalentEntry = false;

    foreach ($block as $line) {
        if (preg_match('/^\s/', $line)) {
            // Continuation of whatever field preceded it. Under Talent Entry, a
            // continuation is only a genuine second tree assignment if it contains
            // "tree=" — otherwise it's per-rank effect scaling metadata attached to
            // the same entry (e.g. "Effect#2 [op=set, values=(1, 2)]" on a multi-rank
            // talent), not a separate tree/path assignment, and must not be classified.
            if ($lastFieldIsTalentEntry && preg_match('/^\s*:\s*(.*)$/', $line, $cm)) {
                $value = trim($cm[1]);
                if (str_contains($value, 'tree=')) {
                    $talentEntryLines[] = $value;
                }
            }
            continue;
        }

        if (preg_match('/^Talent Entry\s*:\s*(.*)$/', $line, $tm)) {
            $lastFieldIsTalentEntry = true;
            $talentEntryLines[] = trim($tm[1]);
        } else {
            $lastFieldIsTalentEntry = false;
        }
    }

    $recordText = implode("\n", $block);
    $destinations = [];

    if (empty($talentEntryLines)) {
        $destinations[] = 'baseline';
    } else {
        foreach ($talentEntryLines as $entry) {
            if (!preg_match('/^(.*)\s\[([^\]]*)\]$/', $entry, $em)) {
                $anomalies[] = "$name — unparsable Talent Entry: \"$entry\"";
                continue;
            }
            $namePart = trim($em[1]);
            $attrs    = $em[2];

            if (!preg_match('/tree=(\w+)/', $attrs, $trm)) {
                $anomalies[] = "$name — no tree= attribute found: \"$entry\"";
                continue;
            }
            $tree = $trm[1];

            if ($tree === 'class') {
                $destinations[] = 'class-talents';
                continue;
            }

            if ($tree === 'spec') {
                $matched = false;
                foreach ($specNames as $spec) {
                    if (str_starts_with($namePart, $spec)) {
                        $destinations[] = slug($spec);
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $anomalies[] = "$name — tree=spec but unrecognized name: \"$entry\"";
                }
                continue;
            }

            if ($tree === 'hero') {
                $matched = false;
                foreach ($heroNames as $hero) {
                    if (str_starts_with($namePart, $hero)) {
                        $destinations[] = 'hero-' . slug($hero);
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $anomalies[] = "$name — tree=hero but unrecognized name: \"$entry\"";
                }
                continue;
            }

            $anomalies[] = "$name — unrecognized tree value: \"$entry\"";
        }
    }

    $destinations = array_values(array_unique($destinations));

    if (empty($destinations)) {
        // Every Talent Entry line failed to classify — still must not lose the record.
        $destinations[] = 'baseline';
        $anomalies[] = "$name — no destination resolved, fell back to baseline.txt";
    }

    foreach ($destinations as $dest) {
        $grouped[$dest][] = $recordText;
    }
    $index[$name] = $destinations;
}

@mkdir($outputDir, 0777, true);

$counts = [];
foreach ($categoryFiles as $key => $filename) {
    $records = $grouped[$key];
    $counts[$key] = count($records);

    $banner = "# {$classLabel} — {$titles[$key]}\n"
        . "# Extracted verbatim from " . basename($input) . " ({$headerLine})\n"
        . "# {$counts[$key]} spell record(s). Mechanical regroup only — no content altered.\n\n";

    file_put_contents($outputDir . '/' . $filename, $banner . implode("\n\n", $records) . "\n");
}

// INDEX.md — one line per spell name, alphabetical, listing destination file(s).
ksort($index, SORT_FLAG_CASE | SORT_STRING);
$indexLines = ["# {$classLabel} Spell Index\n", "One line per spell record; each lists every file it appears in.\n"];
foreach ($index as $name => $dests) {
    $files = implode(', ', array_map(fn($d) => "`{$categoryFiles[$d]}`", $dests));
    $indexLines[] = "- **{$name}** — {$files}";
}
file_put_contents($outputDir . '/INDEX.md', implode("\n", $indexLines) . "\n");

// Report.
fwrite(STDOUT, "Counts per file:\n");
foreach ($categoryFiles as $key => $filename) {
    fwrite(STDOUT, "  {$filename}: {$counts[$key]}\n");
}
fwrite(STDOUT, "\nTotal spell records: " . count($index) . "\n");

if (!empty($anomalies)) {
    fwrite(STDOUT, "\nAnomalies (" . count($anomalies) . "):\n");
    foreach ($anomalies as $a) {
        fwrite(STDOUT, "  - {$a}\n");
    }
} else {
    fwrite(STDOUT, "\nNo anomalies — every Talent Entry line classified cleanly.\n");
}
