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
 */
class SpellDataFileParser
{
    public function parseFile(string $path): array
    {
        return $this->parseContent(file_get_contents($path) ?: '');
    }

    /**
     * @return array<int, array{
     *     spell_id: int, name: string, school: ?string, description: ?string,
     *     affecting_spells: array<int, string>,
     *     effects: array<int, array{effect_index: int, type: ?string, base_value: ?float, scaled_value: ?float, modified_by: array<int, string>}>,
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
                    'affecting_spells' => [],
                    'effects' => [],
                ];
                $inEffects = false;
                $currentEffect = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^School\s*:\s*(.+)$/', $line, $m)) {
                $current['school'] = trim($m[1]);
                $inEffects = false;

                continue;
            }

            if (preg_match('/^Description\s*:\s*(.*)$/', $line, $m)) {
                $current['description'] = trim($m[1]) ?: null;
                $inEffects = false;
                $flushEffect();

                continue;
            }

            if (preg_match('/^Affecting Spells\s*:\s*(.*)$/', $line, $m)) {
                $current['affecting_spells'] = $this->parseSpellRefs($m[1]);
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
