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
 * A literal-text Description can span multiple paragraphs, separated by a blank line, before the
 * next recognized field or spell record (e.g. Protector of the Frail's Description has a second
 * paragraph describing a Power Word: Shield -> Pain Suppression cooldown interaction with no
 * other structural representation anywhere in this data). Every following non-blank line is
 * appended (space-joined) onto the same 'description' value until a recognized field, "Effects:",
 * or the next "Name:" record ends it — see $inDescriptionContinuation below. This only works
 * because split-by-tree.php (2026-08-01) stopped treating an internal blank line as a record
 * boundary; before that fix such continuation paragraphs were dropped before ever reaching this
 * parser. Deliberately scoped to literal-text descriptions only — a pointer ($@spelldesc<id>) has
 * no local text yet to append onto (resolved later, cross-record, by
 * ImportSpellData::resolveDescriptionReferences()).
 *
 * A third relationship kind: "Talent Entry" lines can carry a replace="<name>" (id=<id>)
 * annotation (e.g. Beacon of Virtue's own Talent Entry line names Beacon of Light) — captured
 * into replaces_refs. Unlike category_refs/affects_category, both ends live on the *same*
 * record (the talent's own spell_id is self-evidently the source), so ImportSpellData's
 * importReplacesRelationships() pass is closer to importRelationships()'s single-record pattern
 * than importCategoryRelationships()'s two-record one.
 *
 * Two more per-record fields, added 2026-08-01 after a real cross-check against the in-game
 * Spellbook UI found both gaps: "Talent Entry" lines can also carry a free=(SpecA, SpecB)
 * annotation on a tree=class entry, meaning that specific class-tree talent is auto-granted to
 * only those specs, not the whole class (e.g. Mind Blast is free=(Discipline, Shadow) — never
 * Holy — but was being imported as class-wide because nothing read this annotation at all).
 * Captured into free_specs. Separately, "Attributes" lines are checked for "Not In Spellbook"
 * (captured into not_in_spellbook) — several player-facing abilities (Penance, Ultimate
 * Penitence) turned out to actually be multiple spell_id records sharing one display name, where
 * only one is the real talent and the rest are internal damage-bolt/heal-bolt/visual-effect
 * sub-spells never meant to be shown on their own.
 *
 * `cast_type` (added 2026-08-11 for the Synergies/CC-chain feature — see CLAUDE.md) — presence
 * or absence of a "Cast Time : X seconds" line, the same instant-vs-cast signal a real player
 * sees in-game. Deliberately discards the numeric value itself; `spells.cast_type` only stores
 * `instant`/`cast` (see the 2026-08-11 migration), and nothing downstream needs the exact cast
 * time. Defaults to 'instant' in the record initializer, same "don't leave a boolean-shaped
 * field ambiguous" precedent as `is_passive`/`not_in_spellbook`.
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
     *     variables: ?string,
     *     class_field: ?string,
     *     charges: ?int, cooldown_seconds: ?float, duration_seconds: ?float, mechanic: ?string, cast_type: string,
     *     affecting_spells: array<int, array{name: string, effect_index: ?int}>,
     *     category_refs: array<int, array{name: string, effect_index: ?int}>,
     *     replaces_refs: array<int, string>,
     *     free_specs: array<int, string>,
     *     not_in_spellbook: bool,
     *     is_passive: bool,
     *     effects: array<int, array{effect_index: int, type: ?string, base_value: ?float, scaled_value: ?float, sp_coefficient: ?float, pvp_coefficient: ?float, rank_op: ?string, rank_values: ?array<int, float>, modified_by: array<int, array{name: string, effect_index: ?int}>, affects_category: array<int, array{name: string, effect_index: ?int}>}>,
     * }>
     */
    public function parseContent(string $content): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        $records = [];
        $current = null;
        $inEffects = false;
        $inDescriptionContinuation = false;
        $inVariablesContinuation = false;
        $currentEffect = null;

        $flushEffect = function () use (&$current, &$currentEffect) {
            if ($current !== null && $currentEffect !== null) {
                // rank_scaling is parsed off the Talent Entry line, which always precedes the
                // Effects: block in this file format — by the time any effect is flushed, the
                // full per-record rank_scaling map (keyed by effect_index) is already complete.
                $scaling = $current['rank_scaling'][$currentEffect['effect_index']] ?? null;
                $currentEffect['rank_op'] = $scaling['op'] ?? null;
                $currentEffect['rank_values'] = $scaling['values'] ?? null;
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
                    'variables' => null,
                    'class_field' => null,
                    'charges' => null,
                    'cooldown_seconds' => null,
                    'duration_seconds' => null,
                    'mechanic' => null,
                    'cast_type' => 'instant',
                    'affecting_spells' => [],
                    'category_refs' => [],
                    'replaces_refs' => [],
                    'free_specs' => [],
                    'not_in_spellbook' => false,
                    'is_passive' => false,
                    'rank_scaling' => [],
                    'effects' => [],
                ];
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;
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

            // "Talent Entry : Discipline, Shadow [free=(Discipline, Shadow), tree=class, ...]" —
            // a tree=class entry can carry a free=(SpecA, SpecB) annotation meaning this
            // class-tree talent is auto-granted to only those specs, not the whole class (e.g.
            // Mind Blast: Discipline and Shadow, never Holy). Matched independent of line prefix,
            // same trick the replace= handler above already uses (both only ever appear within
            // Talent Entry line content, primary or continuation — confirmed empirically:
            // "free=" never appears outside a Talent Entry line anywhere in this dataset, across
            // every class). Captured into 'free_specs' — a flat, deduped list of spec names,
            // used by ImportSpellData::resolveFreeSpecIds() to narrow spell_class_availability
            // for class-tree talents the same way resolveBaselineSpecIds() narrows baseline.txt
            // records via their own Class: field. Only meaningful alongside tree=class — a
            // tree=spec/tree=hero entry is already narrowed by its own tree, so free= is checked
            // for on the same line rather than trusted in isolation.
            if (str_contains($line, 'tree=class') && preg_match('/free=\(([^)]*)\)/', $line, $m)) {
                foreach (explode(',', $m[1]) as $specName) {
                    $specName = trim($specName);
                    if ($specName !== '' && !in_array($specName, $current['free_specs'], true)) {
                        $current['free_specs'][] = $specName;
                    }
                }
            }

            // "Talent Entry : Generic [tree=class, row=8, col=8, max_rank=2, req_points=23]"
            // followed by a continuation line "Effect#1 [op=set, values=(-5000, -10000)]" — a
            // multi-rank talent (max_rank > 1) whose effect's real value differs per rank, e.g.
            // Improved Fade: rank 1 reduces Fade's cooldown by 5s, rank 2 by 10s, both sharing
            // the same spell_id and effect_index — the single "Base Value" captured in the
            // Effects: block below only ever reflects ONE of those ranks (found 2026-08-06,
            // reported by the player: Improved Fade showed -5s regardless of whether 1 or 2
            // points were invested). Two ops confirmed to occur in this dataset: 'set' (the
            // listed value IS the effect's value at that rank, replacing base_value entirely)
            // and 'mul' (base_value is multiplied by the listed value at that rank — e.g.
            // "[op=mul, values=(1, 2)]"). Matched independent of line prefix, same trick as
            // replace=/free= above — confirmed empirically this exact "Effect#N [op=...]" shape
            // never appears outside a Talent Entry line's own primary/continuation content.
            // Stored per effect_index (a talent can have more than one rank-scaled effect)
            // rather than applied immediately — the actual value can only be resolved once we
            // know which rank a given build has selected, which isn't known until render time
            // (see ImportSpellData::modifiesRelationshipMapping()/categoryRelationshipMapping(),
            // which now defer magnitude computation for these instead of baking in one rank's
            // number, and ModuleSpellReferenceService, which resolves it per-build).
            if (preg_match('/Effect#(\d+)\s*\[op=(set|mul),\s*values=\(([^)]+)\)\]/', $line, $m)) {
                $values = array_map(fn ($v) => (float) trim($v), explode(',', $m[3]));
                $current['rank_scaling'][(int) $m[1]] = ['op' => $m[2], 'values' => $values];
            }

            if (preg_match('/^School\s*:\s*(.+)$/', $line, $m)) {
                $current['school'] = trim($m[1]);
                $inEffects = false;

                continue;
            }

            // "Mechanic : Charm" — Blizzard's own single-value classification of what kind of
            // control/defensive mechanic this spell applies (Stun, Root, Silence, Charm, Shield,
            // Bleed, etc. — see ModuleSpellReferenceService::categorize(), which checks this
            // before falling back to its effect-type-string regex heuristic). Sparse — most
            // spells (plain damage/heal, most passives) have no Mechanic line at all, left null.
            if (preg_match('/^Mechanic\s*:\s*(.+)$/', $line, $m)) {
                $current['mechanic'] = trim($m[1]);
                $inEffects = false;

                continue;
            }

            // "Attributes : Not Shapeshifted (16), ..., Not In Spellbook (143), ..." — captures
            // only whether a genuine "hide this spell" marker is present, not the full attribute
            // list (nothing else in this schema currently needs the rest). Distinguishes a real,
            // player-facing ability from an internal SimC sub-spell (a channeled ability's own
            // separate damage-bolt/heal-bolt/visual helper spell_id, sharing the parent ability's
            // display name — confirmed on Penance and Ultimate Penitence, both of which are
            // actually several spell_id records sharing one name, only one of which is the real,
            // player-facing talent). See ModuleSpellReferenceService::resolveSpellByName(), which
            // uses this to avoid resolving a curated module spell reference to one of these hidden
            // duplicates.
            //
            // Two genuine "hide this" markers, matched by their exact numeric attribute code:
            // "Not In Spellbook (143)" and "Do Not Display (Spellbook, ...)" (found 2026-08-02,
            // via a real Penance internal-duplicate spell_id that used the second phrasing and
            // slipped past a prior version of this check that only recognized the first).
            //
            // FIXED 2026-08-02 — real false-positive bug, not just a missed phrasing: the
            // previous check was `str_contains($m[1], 'Not In Spellbook')`, a bare substring
            // match that also matched "Not In Spellbook Until Learned (269)" — a completely
            // different, common, and entirely normal attribute (attribute code 269, not 143)
            // meaning "this talent isn't in your spellbook until you've actually learned it" —
            // true of nearly every real, legitimately player-facing talent. Confirmed via the
            // in-game Spellbook cross-check pattern this file already follows: Hunter's
            // "Intimidation" (spell_id 19577 — a real talent with `Cooldown: 60 seconds` and its
            // own Talent Entry) was wrongly marked not_in_spellbook=true purely because its
            // Attributes line happened to also contain the word sequence "Not In Spellbook",
            // causing ModuleSpellReferenceService::preferVisible() to incorrectly exclude it in
            // favor of an actual internal pet-stun-effect duplicate with no cooldown data at all.
            // Quantified across the full dataset: 646 occurrences of "Not In Spellbook Until
            // Learned (269)" were being conflated with 2777 genuine "Not In Spellbook (143)"
            // occurrences — a real, systemic false-positive affecting every class, not an edge
            // case. Now matched by exact numeric code, not substring.
            if (preg_match('/^Attributes\s*:\s*(.*)$/', $line, $m)) {
                $current['not_in_spellbook'] = str_contains($m[1], 'Not In Spellbook (143)')
                    || str_contains($m[1], 'Do Not Display (Spellbook');
                // "Passive (6)" — Blizzard's own marker for "this is a passive aura/buff, not
                // something a player casts". Matched by exact code, same discipline as the
                // not_in_spellbook check just above (a bare substring match on "Passive" would
                // risk false-positiving on some other attribute whose name happens to contain
                // it — none confirmed to exist in this dataset, but the exact-code form costs
                // nothing and avoids relearning the same false-positive lesson twice).
                $current['is_passive'] = str_contains($m[1], 'Passive (6)');
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
                    $inDescriptionContinuation = false;
                } else {
                    $current['description'] = $value ?: null;
                    $current['description_ref'] = null;
                    // A Description can continue onto further paragraphs, separated by a blank
                    // line, before the next recognized field or spell record — see
                    // split-by-tree.php's matching fix (2026-08-01). Every following non-blank
                    // line is space-joined onto $current['description'] until a recognized
                    // field, "Effects:", or the next "Name:" record ends it (the catch-all
                    // right before the "if (!$inEffects)" gate below). Only the literal-text
                    // branch continues — a pointer ($@spelldesc<id>) has no local text of its
                    // own to append onto yet (resolved later by
                    // ImportSpellData::resolveDescriptionReferences()), so continuation capture
                    // for that case is a deliberately deferred, smaller residual gap.
                    $inDescriptionContinuation = $current['description'] !== null;
                }

                $inEffects = false;
                $inVariablesContinuation = false;
                $flushEffect();

                continue;
            }

            // "Tooltip : ..." immediately follows Description (no blank line) in this dataset's
            // usual field order and is not captured into any field (unused elsewhere in this
            // parser, same as before this change) — but it MUST still end description-continuation
            // mode, or its own text would otherwise be wrongly appended onto 'description' by the
            // continuation catch-all below.
            if (preg_match('/^Tooltip\s*:/', $line)) {
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            // "Variables : $castigation=$?a193134[${1}][${0}]" followed by further "$var=..."
            // lines with no field prefix — the raw formula backing a description's substitution
            // tokens (e.g. Penance's $<penancedamage>). Added 2026-08-02 alongside sp_coefficient
            // capture: this used to fall through to the Description-continuation catch-all below
            // and get silently appended onto the end of 'description' as unreadable formula text
            // — a real parsing bug, not just a missing feature (confirmed on Penance's own
            // imported description, which used to end with the full raw "Variables: ..." block
            // glued on after "Castable while moving."). Stored newline-joined (not space-joined
            // like Description) so each "$var=expression" stays on its own line for
            // ModuleSpellReferenceService's later regex extraction of $?a<id>/$?s<id> references.
            if (preg_match('/^Variables\s*:\s*(.*)$/', $line, $m)) {
                $current['variables'] = trim($m[1]) ?: null;
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = true;

                continue;
            }

            if (preg_match('/^Affecting Spells\s*:\s*(.*)$/', $line, $m)) {
                $current['affecting_spells'] = $this->parseSpellRefs($m[1]);
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            // "Charges : 2 (20 seconds cooldown)" — must be checked before the standalone
            // Cooldown line below, since a charges line embeds its own cooldown value.
            if (preg_match('/^Charges\s*:\s*(\d+)\s*\(([\d.]+)\s*seconds?\s*cooldown\)/i', $line, $m)) {
                $current['charges'] = (int) $m[1];
                $current['cooldown_seconds'] = (float) $m[2];
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            // Standalone "Cooldown : 7.5 seconds" (no charge count — the common case). The `\s*:`
            // anchor deliberately excludes "Category Cooldown:" / "Category Flags:", which have
            // non-whitespace text before their colon.
            if (preg_match('/^Cooldown\s*:\s*([\d.]+)\s*seconds?/i', $line, $m)) {
                $current['cooldown_seconds'] = (float) $m[1];
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            // "Duration : 8 seconds" — needed to resolve the "$d" tooltip token in descriptions
            // (see ModuleSpellReferenceService). "Duration : Aura (infinite)" (~979 occurrences)
            // deliberately doesn't match — infinite isn't a finite seconds value to substitute.
            if (preg_match('/^Duration\s*:\s*([\d.]+)\s*seconds?/i', $line, $m)) {
                $current['duration_seconds'] = (float) $m[1];
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            // "Cast Time : 1.5 seconds" — present only when a spell actually has a cast time;
            // absent entirely for instant-cast spells (confirmed directly: Kidney Shot's raw
            // entry has no Cast Time line at all). cast_type defaults to 'instant' in the
            // initializer above, same "boolean-style field defaults, don't leave ambiguous"
            // precedent as is_passive/not_in_spellbook — only the numeric value itself is
            // discarded (spells.cast_type is instant/cast only, see the 2026-08-11 migration;
            // no seconds column exists to store it in).
            if (preg_match('/^Cast Time\s*:\s*([\d.]+)\s*seconds?/i', $line, $m)) {
                $current['cast_type'] = 'cast';
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            // "Category : 2105 (Type 0x1): Protector of the Frail (373035 effect#3)" — the
            // trailing ref list (present on ~half of Category lines) is who grants this spell
            // extra charges. Same `\s*:` anchor exclusion as Cooldown above.
            if (preg_match('/^Category\s*:\s*\d+\s*\(Type[^)]*\)(?::\s*(.*))?$/', $line, $m)) {
                $current['category_refs'] = $this->parseSpellRefs($m[1] ?? '');
                $inEffects = false;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            if (preg_match('/^Effects\s*:/', $line)) {
                $inEffects = true;
                $inDescriptionContinuation = false;
                $inVariablesContinuation = false;

                continue;
            }

            // Continuation of a multi-paragraph Description (see the Description branch above)
            // — reaches here only once every recognized-field check above has already failed to
            // match. A blank line is a paragraph break within the same continuation (skipped,
            // not appended, continuation stays open); a non-blank line is genuine prose, appended
            // space-joined onto the description built so far.
            if ($inDescriptionContinuation) {
                $continuationText = trim($line);
                if ($continuationText !== '') {
                    $current['description'] = trim(($current['description'] ?? '').' '.$continuationText);
                }

                continue;
            }

            // Continuation of a Variables block — each further line is another "$var=expression"
            // with no field prefix. Newline-joined, same blank-line-tolerant style as Description
            // continuation above (stays open across a blank line rather than treating it as the
            // end — no confirmed case of an internal blank line in this dataset, but erring
            // toward the already-proven-safe Description behavior rather than a new, untested
            // "blank line ends it" rule).
            if ($inVariablesContinuation) {
                $continuationText = trim($line);
                if ($continuationText !== '') {
                    $current['variables'] = trim(($current['variables'] ?? '')."\n".$continuationText);
                }

                continue;
            }

            if (!$inEffects) {
                continue;
            }

            if (preg_match('/^\s*#(\d+)\s*\(id=\d+\)\s*:\s*(.+)$/', $line, $m)) {
                $flushEffect();
                $parts = array_map('trim', explode('|', $m[2]));
                $type = $parts[0] !== '' ? $parts[0] : null;

                // "Apply Aura (6) | Modify Recharge Time (Category) (453)" — found 2026-08-01
                // while debugging why ImportSpellData::categoryRelationshipMapping() never
                // matched anything: segment 0 ("Apply Aura (N)"/"Apply Aura Raid (N)") is only a
                // generic wrapper meaning "this effect applies a continuous aura" — it's
                // identical across hundreds of unrelated effects and carries no distinguishing
                // information on its own. The specific AuraType name that actually distinguishes
                // e.g. "Modify Cooldown Charge (Category)" from "Modify Recharge Time (Category)"
                // lives in segment 1, when present — confirmed against real data (Protector of
                // the Frail effect #3, Discipline's Mind Blast cooldown passive). Redirect to it,
                // stripping the trailing "(NNN)" internal aura-effect id so the stored value
                // matches the bare descriptive name categoryRelationshipMapping() matches
                // against. This was a pre-existing bug, not introduced by that method — the
                // original single-type-string check it replaced ('Modify Recharge Time
                // (Category)' for the Mind Blast conversion) had exactly the same never-matches
                // problem, it just had no visible symptom until something depended on it firing.
                // Effects with no aura wrapper at all (e.g. "School Damage (2): physical",
                // "Direct Heal (10)") have no segment 1 and are untouched — segment 0 already
                // was, and remains, their real type.
                if ($type !== null
                    && preg_match('/^Apply Aura(?:\s+\w+)?\s*\(\d+\)$/', $type)
                    && ($parts[1] ?? '') !== ''
                ) {
                    $type = trim(preg_replace('/\s*\(\d+\)\s*$/', '', $parts[1]));
                }

                $currentEffect = [
                    'effect_index' => (int) $m[1],
                    'type' => $type !== '' ? $type : null,
                    'base_value' => null,
                    'scaled_value' => null,
                    'sp_coefficient' => null,
                    'pvp_coefficient' => null,
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

            // "SP Coefficient: 0.8109 | PvP Coefficient: 1.059" — the game's own real damage/
            // healing multiplier against the caster's Spell Power, present on the same detail
            // line as Base/Scaled Value for spell-power-scaled effects. Confirmed as a genuine
            // field in SimC's own structured spell model (spelleffect_data_t.sp_coefficient),
            // not something specific to this text-dump format — see game-data.md. Captured
            // 2026-08-02 so ModuleSpellReferenceService can show "≈X% of Spell Power" instead of
            // an unresolved formula when Base/Scaled Value are both 0 (which they always are for
            // a purely SP-scaled effect — the real number only exists once multiplied by a real
            // character's actual Spell Power, which this system doesn't have).
            if (preg_match('/SP Coefficient:\s*(-?[\d.]+)/', $line, $m)) {
                $currentEffect['sp_coefficient'] = (float) $m[1];
            }

            if (preg_match('/PvP Coefficient:\s*(-?[\d.]+)/', $line, $m)) {
                $currentEffect['pvp_coefficient'] = (float) $m[1];
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
     * Parses a "Name (id ...), Name2 (id2 effect#1)" list into unique
     * [spell_id => {name, effect_index}] pairs. Most of the parenthetical body is still treated
     * as opaque (ranks etc. are discarded) — only the leading numeric id and an "effect#N"
     * annotation, when present, are structural for our purposes. effect_index is what lets
     * ImportSpellData::modifiesRelationshipMapping() look up the *specific* effect on the source
     * spell that a ref points at (e.g. "Fist of Justice (234299 effect#1)"), rather than just
     * knowing a relationship exists with no way to find its magnitude — this was the gap CLAUDE.md
     * flagged as "needs SpellDataFileParser::parseSpellRefs() extended to retain effect-number
     * annotations it currently discards by design", closed 2026-08-05.
     *
     * @return array<int, array{name: string, effect_index: ?int}>
     */
    private function parseSpellRefs(string $text): array
    {
        $refs = [];

        if (preg_match_all('/([^,]+?)\s*\((\d+)([^)]*)\)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $effectIndex = null;
                if (preg_match('/effect#(\d+)/', $match[3], $em)) {
                    $effectIndex = (int) $em[1];
                }

                $refs[(int) $match[2]] = ['name' => trim($match[1]), 'effect_index' => $effectIndex];
            }
        }

        return $refs;
    }
}
