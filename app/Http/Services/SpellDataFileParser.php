<?php

namespace App\Http\Services;

/**
 * Parses SimulationCraft-format spell query dumps (data/spelldata/filtered/{class}/*.txt) into
 * plain arrays ready for import. Deliberately dumb/line-oriented rather than a full grammar —
 * these files are a fixed export format ("Key : Value" fields, with an "Effects:" sub-block of
 * "#N (id=X) : Type | Type2" lines each followed by indented "Key: Value | Key: Value" detail
 * lines) and only a handful of fields are needed for this schema.
 *
 * "Affecting Spells" (spell-level) and "Modified By" (effect-level) are the structural
 * relationship data referenced by ImportSpellData's spell_relationships population — both list
 * other spells as "Name (id ...)" pairs, which is what makes them structural rather than free
 * text that would need to be parsed out of a description.
 *
 * "Category" (spell-level) and "Affected Spells (Category)" (effect-level) are a second,
 * distinct kind of structural relationship — charge-count modifiers (e.g. a talent granting a
 * spell an extra charge) rather than effect-value modifiers. They're parsed the same way
 * (Name (id ...) ref lists) but kept in separate fields (category_refs / affects_category)
 * since ImportSpellData writes them as a different relationship_type ('modifies_charges' vs
 * 'modifies'). Note the two ends live on *different* records here: a target spell's own
 * "Category:" line lists the spell(s) that grant it charges, while the granting spell declares
 * the reverse ("Affected Spells (Category): <target>") on its own effect — unlike Affecting
 * Spells/Modified By, which are both self-declared on the target. ImportSpellData's category
 * pass reads both directions to be robust to either side of a pair being incomplete in the dump.
 *
 * Also captures "Charges" (e.g. "Charges : 2 (20 seconds cooldown)"), standalone "Cooldown", and
 * "Duration" lines into charges/cooldown_seconds/duration_seconds — plain scalar fields, not
 * relationship data, so they need no cross-record resolution pass.
 *
 * A third relationship kind: "Talent Entry" lines can carry a replace="<name>" (id=<id>)
 * annotation (e.g. Beacon of Virtue's own Talent Entry line names Beacon of Light) — captured
 * into replaces_refs. Unlike category_refs/affects_category, both ends live on the *same*
 * record (the talent's own spell_id is self-evidently the source), so ImportSpellData's
 * importReplacesRelationships() pass is closer to importRelationships()'s single-record pattern
 * than importCategoryRelationships()'s two-record one.
 */
class SpellDataFileParser
{
    public function parseFile(string $path): array
    {
        return $this->parseContent(file_get_contents($path) ?: '');
    }

    /**
     * @return array<int, array{
     *     spell_id: int, name: string, school: ?string, description: ?string, description_ref: ?int,
     *     class_field: ?string,
     *     charges: ?int, cooldown_seconds: ?float, duration_seconds: ?float,
     *     affecting_spells: array<int, string>,
     *     category_refs: array<int, string>,
     *     replaces_refs: array<int, string>,
     *     effects: array<int, array{effect_index: int, type: ?string, base_value: ?float, scaled_value: ?float, modified_by: array<int, string>, affects_category: array<int, string>}>,
     * }>
     */
    public function parseContent(string $content): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        $records = [];
        $current = null;
        $inEffects = false;
        $currentEffect = null;

        $flushEffect = function () use (&$current, &$currentEffect) {
            if ($current !== null && $currentEffect !== null) {
                $current['effects'][] = $currentEffect;
            }
            $currentEffect = null;
        };

        $flushRecord = function () use (&$records, &$current, &$flushEffect) {
            if ($current !== null) {
                $flushEffect();
                $records[] = $current;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^Name\s*:\s*(.*?)\s*\(id=(\d+)\)/', $line, $m)) {
                $flushRecord();
                $current = [
                    'spell_id' => (int) $m[2],
                    'name' => trim($m[1]),
                    'school' => null,
                    'description' => null,
                    'description_ref' => null,
                    'class_field' => null,
                    'charges' => null,
                    'cooldown_seconds' => null,
                    'duration_seconds' => null,
                    'affecting_spells' => [],
                    'category_refs' => [],
                    'replaces_refs' => [],
                    'effects' => [],
                ];
                $inEffects = false;
                $currentEffect = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            // "Class : Shadow Priest" (spec-restricted) vs "Class : Priest" (class-wide) vs
            // "Class : Paladin, Priest" (shared across classes) — a comma-separated list.
            // ImportSpellData::resolveBaselineSpecIds() interprets this for baseline.txt records
            // to correct spec_id tagging; kept as a raw string here since interpreting it needs
            // the current class's name and its spec-name map, neither available at parse time.
            if (preg_match('/^Class\s*:\s*(.+)$/', $line, $m)) {
                $current['class_field'] = trim($m[1]);
                $inEffects = false;

                continue;
            }

            // "Talent Entry : Holy [tree=spec, ..., replace="Beacon of Light" (id=53563)]" — this
            // talent's own spell replaces the referenced spell in the action bar when selected.
            // Matched independent of line prefix (not anchored to "^Talent Entry") because a
            // shared/multi-tree talent repeats this annotation on indented continuation lines
            // (e.g. Frostfire Bolt: "replace="Frostbolt"" on the Frost-tree line, "replace="Fireball""
            // on the Fire-tree line below it) — a record can carry more than one, hence a ref list,
            // not a scalar. Confirmed safe: "replace=" never appears anywhere else in this dataset.
            if (preg_match('/replace="([^"]+)"\s*\(id=(\d+)\)/', $line, $m)) {
                $current['replaces_refs'][(int) $m[2]] = $m[1];
                $inEffects = false;

                continue;
            }

            if (preg_match('/^School\s*:\s*(.+)$/', $line, $m)) {
                $current['school'] = trim($m[1]);
                $inEffects = false;

                continue;
            }

            if (preg_match('/^Description\s*:\s*(.*)$/', $line, $m)) {
                $value = trim($m[1]);

                // SimC prints "$@spelldesc<id>" instead of duplicating text when a spell's
                // description is identical to another spell's — a pointer, not literal text.
                // Affects ~44% of all Description lines across this dataset. Left unresolved
                // here (needs the full cross-class spell index, not available at parse time) —
                // ImportSpellData::resolveDescriptionReferences() backfills it after every
                // class's spells are loaded.
                if (preg_match('/^\$@spelldesc(\d+)$/', $value, $refMatch)) {
                    $current['description'] = null;
                    $current['description_ref'] = (int) $refMatch[1];
                } else {
                    $current['description'] = $value ?: null;
                    $current['description_ref'] = null;
                }

                $inEffects = false;
                $flushEffect();

                continue;
            }

            if (preg_match('/^Affecting Spells\s*:\s*(.*)$/', $line, $m)) {
                $current['affecting_spells'] = $this->parseSpellRefs($m[1]);
                $inEffects = false;

                continue;
            }

            // "Charges : 2 (20 seconds cooldown)" — must be checked before the standalone
            // Cooldown line below, since a charges line embeds its own cooldown value.
            if (preg_match('/^Charges\s*:\s*(\d+)\s*\(([\d.]+)\s*seconds?\s*cooldown\)/i', $line, $m)) {
                $current['charges'] = (int) $m[1];
                $current['cooldown_seconds'] = (float) $m[2];
                $inEffects = false;

                continue;
            }

            // Standalone "Cooldown : 7.5 seconds" (no charge count — the common case). The `\s*:`
            // anchor deliberately excludes "Category Cooldown:" / "Category Flags:", which have
            // non-whitespace text before their colon.
            if (preg_match('/^Cooldown\s*:\s*([\d.]+)\s*seconds?/i', $line, $m)) {
                $current['cooldown_seconds'] = (float) $m[1];
                $inEffects = false;

                continue;
            }

            // "Duration : 8 seconds" — needed to resolve the "$d" tooltip token in descriptions
            // (see ModuleSpellReferenceService). "Duration : Aura (infinite)" (~979 occurrences)
            // deliberately doesn't match — infinite isn't a finite seconds value to substitute.
            if (preg_match('/^Duration\s*:\s*([\d.]+)\s*seconds?/i', $line, $m)) {
                $current['duration_seconds'] = (float) $m[1];
                $inEffects = false;

                continue;
            }

            // "Category : 2105 (Type 0x1): Protector of the Frail (373035 effect#3)" — the
            // trailing ref list (present on ~half of Category lines) is who grants this spell
            // extra charges. Same `\s*:` anchor exclusion as Cooldown above.
            if (preg_match('/^Category\s*:\s*\d+\s*\(Type[^)]*\)(?::\s*(.*))?$/', $line, $m)) {
                $current['category_refs'] = $this->parseSpellRefs($m[1] ?? '');
                $inEffects = false;

                continue;
            }

            if (preg_match('/^Effects\s*:/', $line)) {
                $inEffects = true;

                continue;
            }

            if (!$inEffects) {
                continue;
            }

            if (preg_match('/^\s*#(\d+)\s*\(id=\d+\)\s*:\s*(.+)$/', $line, $m)) {
                $flushEffect();
                $type = trim(explode('|', $m[2])[0]);
                $currentEffect = [
                    'effect_index' => (int) $m[1],
                    'type' => $type !== '' ? $type : null,
                    'base_value' => null,
                    'scaled_value' => null,
                    'modified_by' => [],
                    'affects_category' => [],
                ];

                continue;
            }

            if ($currentEffect === null) {
                continue;
            }

            if (preg_match('/Base Value:\s*(-?[\d.]+)/', $line, $m)) {
                $currentEffect['base_value'] = (float) $m[1];
            }

            if (preg_match('/Scaled Value:\s*(-?[\d.]+)/', $line, $m)) {
                $currentEffect['scaled_value'] = (float) $m[1];
            }

            if (preg_match('/Modified By:\s*(.+)$/', $line, $m)) {
                $currentEffect['modified_by'] = $currentEffect['modified_by'] + $this->parseSpellRefs($m[1]);
            }

            // "Affected Spells (Category): Pain Suppression (33206)" — this spell's own
            // charge-granting declaration, the reverse direction of the target-side Category:
            // line above. Checked with a prefix that excludes the plain "Affecting Spells:"
            // spell-level line, which never appears indented inside an effect block.
            if (preg_match('/Affected Spells \(Category\):\s*(.+)$/', $line, $m)) {
                $currentEffect['affects_category'] = $currentEffect['affects_category'] + $this->parseSpellRefs($m[1]);
            }
        }

        $flushRecord();

        return $records;
    }

    /**
     * Parses a "Name (id ...), Name2 (id2 effects: #1, #2)" list into unique [spell_id => name]
     * pairs. The parenthetical body is intentionally treated as opaque (effect refs, ranks,
     * etc. inside it are discarded) — only the leading numeric id is structural for our purposes.
     *
     * @return array<int, string>
     */
    private function parseSpellRefs(string $text): array
    {
        $refs = [];

        if (preg_match_all('/([^,]+?)\s*\((\d+)[^)]*\)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $refs[(int) $match[2]] = trim($match[1]);
            }
        }

        return $refs;
    }
}
