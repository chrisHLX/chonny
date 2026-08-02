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
 *   php split-by-tree.php <input-file> <output-dir> [class-label] [--specs=A,B,C] [--heroes=X,Y,Z]
 *
 * --specs/--heroes are optional as of 2026-08-01 — see deriveTreeNames() below. class-label is
 * also optional, defaulting to the input filename's basename. Explicit --specs/--heroes still
 * work exactly as before and take priority when given (e.g. to deliberately narrow the set) —
 * auto-derivation only fills in whichever one is omitted.
 */

/**
 * Every "Talent Entry" value on a record's block, including continuation lines that carry their
 * own "tree=" annotation (not to be confused with per-rank effect-scaling continuation lines
 * like "Effect#2 [op=set, values=(1, 2)]", which attach to the same entry rather than declaring
 * a second one) — shared by deriveTreeNames() below and the main classification loop, so there's
 * exactly one place that knows how to walk this field.
 *
 * @return array<int, string>
 */
function collectTalentEntryLines(array $block): array
{
    $talentEntryLines = [];
    $lastFieldIsTalentEntry = false;

    foreach ($block as $line) {
        if (preg_match('/^\s/', $line)) {
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

    return $talentEntryLines;
}

/**
 * Auto-derives the --specs/--heroes name lists directly from the input when not explicitly
 * given, added 2026-08-01 so regenerating filtered files doesn't require hand-typing (and
 * risking mistyping) each class's exact spec/hero tree names before every run — the same
 * base-name recognition the classification loop below needs anyway, run once up front to build
 * an exhaustive allowlist instead of requiring one to be supplied from memory.
 *
 * tree=spec entries' name part is the spec name verbatim, no suffix (confirmed across every
 * class checked so far). tree=hero entries carry a trailing "(SpecList)" qualifier (e.g.
 * "Archon (Holy)") that must be stripped to recover just the hero tree's own name — see
 * game-data.md's "Hero-tree-to-spec mapping" section, which already relies on this exact
 * qualifier for a different purpose.
 *
 * @param  array<int, array<int, string>>  $blocks
 * @return array{specs: array<int, string>, heroes: array<int, string>}
 */
function deriveTreeNames(array $blocks): array
{
    $specs = [];
    $heroes = [];

    foreach ($blocks as $block) {
        foreach (collectTalentEntryLines($block) as $entry) {
            if (!preg_match('/^(.*)\s\[([^\]]*)\]$/', $entry, $em)) {
                continue;
            }
            if (!preg_match('/tree=(\w+)/', $em[2], $trm)) {
                continue;
            }

            $namePart = trim($em[1]);

            if ($trm[1] === 'spec') {
                $specs[] = $namePart;
            } elseif ($trm[1] === 'hero') {
                // Strip a trailing "(SpecList)" qualifier, e.g. "Archon (Holy)" -> "Archon".
                $heroes[] = trim(preg_replace('/\s*\([^)]*\)$/', '', $namePart));
            }
        }
    }

    sort($specs);
    sort($heroes);

    return ['specs' => array_values(array_unique($specs)), 'heroes' => array_values(array_unique($heroes))];
}

// Flags are recognized by their "--" prefix regardless of position, so making class-label
// optional (2026-08-01) can't accidentally swallow a "--specs=..."/"--heroes=..." flag into the
// class-label slot — every remaining, non-flag argument is positional, in order.
$positional = [];
$specNames  = [];
$heroNames  = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--specs=(.+)$/', $arg, $m)) {
        $specNames = array_map('trim', explode(',', $m[1]));
    } elseif (preg_match('/^--heroes=(.+)$/', $arg, $m)) {
        $heroNames = array_map('trim', explode(',', $m[1]));
    } else {
        $positional[] = $arg;
    }
}

$input      = $positional[0] ?? null;
$outputDir  = $positional[1] ?? null;
$classLabel = $positional[2] ?? null;

if (!$input || !$outputDir || !is_file($input)) {
    fwrite(STDERR, "Usage: php split-by-tree.php <input-file> <output-dir> [class-label] [--specs=A,B,C] [--heroes=X,Y,Z]\n");
    exit(1);
}

if ($classLabel === null) {
    // No explicit class-label given — fall back to the input filename, e.g. "priest.txt" ->
    // "Priest". Purely cosmetic (banner/title text only, never used for classification), so an
    // imperfect guess here (e.g. "Deathknight" instead of "Death Knight") is not a correctness
    // risk — pass one explicitly if the exact display casing matters.
    $classLabel = ucfirst(pathinfo($input, PATHINFO_FILENAME));
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

$raw   = file_get_contents($input);
$lines = explode("\n", $raw);

// First line is the SimC header banner, not part of any record.
$headerLine = array_shift($lines);

// Split lines into per-record blocks, delimited ONLY by a "Name             : ..." line
// starting a new record — NOT by blank lines. Fixed 2026-08-01: blank lines are also used
// *within* a single record to separate paragraphs in a multi-paragraph Description/Tooltip
// (e.g. Protector of the Frail's Description has a second paragraph — "Power Word: Shield
// reduces the cooldown of Pain Suppression by ..." — after a blank line, describing a real
// mechanic with no other structural representation anywhere in the data). The previous
// blank-line-delimited splitting treated that blank line as the record boundary, silently
// dropping the continuation paragraph as an unrecognized, non-"Name:"-prefixed orphan block
// (confirmed systemic: dozens of records per class file across every class checked, not a
// one-off). "Name :" is the same unambiguous record-start signal
// SpellDataFileParser::parseContent() itself already relies on — never used as a field-value
// prefix elsewhere in this dataset. Trailing blank lines are trimmed off each collected block
// (trimTrailingBlankLines()) purely for output tidiness; blank lines *within* a block are left
// untouched, preserving "no content altered" for the block's actual body.
function trimTrailingBlankLines(array $block): array
{
    while (!empty($block) && trim(end($block)) === '') {
        array_pop($block);
    }

    return $block;
}

$blocks = [];
$current = [];
foreach ($lines as $line) {
    if (preg_match('/^Name\s*:/', $line) && !empty($current)) {
        $blocks[] = trimTrailingBlankLines($current);
        $current  = [];
    }
    $current[] = $line;
}
if (!empty($current)) {
    $blocks[] = trimTrailingBlankLines($current);
}

if (empty($specNames) || empty($heroNames)) {
    $derived = deriveTreeNames($blocks);
    $specNames = empty($specNames) ? $derived['specs'] : $specNames;
    $heroNames = empty($heroNames) ? $derived['heroes'] : $heroNames;
    fwrite(STDOUT, "Auto-derived from data — specs: " . implode(', ', $specNames) . "\n");
    fwrite(STDOUT, "Auto-derived from data — heroes: " . implode(', ', $heroNames) . "\n\n");
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

$grouped   = array_fill_keys(array_keys($categoryFiles), []);
$index     = []; // name => [categories]
$anomalies = [];

foreach ($blocks as $block) {
    if (!preg_match('/^Name\s*:\s*(.*)$/', $block[0], $m)) {
        // Not a spell record (shouldn't happen after the header line is removed).
        continue;
    }
    $name = trim($m[1]);

    $talentEntryLines = collectTalentEntryLines($block);

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
