<?php

use App\Http\Services\SpellDataFileParser;

/**
 * Covers the 2026-08-12 fix to SpellDataFileParser::parseSpellRefs()'s effect-index capture —
 * see that method's docblock for the full trace. Blizzard's raw data uses two different
 * annotation shapes when a ref names which effect(s) on the source spell are involved:
 *   - singular: "Name (id effect#N)"
 *   - plural:   "Name (id effects: #N, #M, ...)" — a source affecting the same target via more
 *     than one of its own effects at once (confirmed real: Anti-Magic Barrier's cooldown
 *     reduction (#1) and duration increase (#2) both target Anti-Magic Shell).
 * The old regex only recognized the singular form; the plural form silently produced
 * effect_index=null, which is what caused Anti-Magic Shell's cooldown to only ever show as
 * increased (Unyielding Will's singular ref) and never reduced (Anti-Magic Barrier's plural ref
 * never resolved at all). Quantified at ~27,000 occurrences dataset-wide before fixing — the
 * plural form is the MORE common shape, not a rare edge case.
 */
function parseAffectingSpellsFixture(string $affectingSpellsLine): array
{
    $parser = new SpellDataFileParser();

    $content = <<<TXT
Name             : Anti-Magic Shell (id=48707) [Spell Family (15)]
Class            : Death Knight
{$affectingSpellsLine}
Cooldown         : 60 seconds
Description      : Test fixture.

TXT;

    $records = $parser->parseContent($content);

    return $records[0]['affecting_spells'];
}

test('parseSpellRefs captures a single effect_index from the singular "effect#N" annotation', function () {
    $refs = parseAffectingSpellsFixture('Affecting Spells : Unyielding Will (457574 effect#2)');

    expect($refs)->toHaveKey(457574)
        ->and($refs[457574]['effect_index'])->toBe(2)
        ->and($refs[457574]['effect_indexes'])->toBe([2]);
});

test('parseSpellRefs captures ALL effect indices from the plural "effects: #N, #M" annotation', function () {
    $refs = parseAffectingSpellsFixture('Affecting Spells : Anti-Magic Barrier (205727 effects: #1, #2)');

    expect($refs)->toHaveKey(205727)
        ->and($refs[205727]['effect_index'])->toBe(1) // first index, for backward-compat callers
        ->and($refs[205727]['effect_indexes'])->toBe([1, 2]);
});

test('parseSpellRefs handles a mixed line with both singular and plural refs, plus a ref with no index at all', function () {
    $refs = parseAffectingSpellsFixture(
        'Affecting Spells : Anti-Magic Barrier (205727 effects: #1, #2), Unyielding Will (457574 effect#2), Volatile Shielding (207188)'
    );

    expect($refs[205727]['effect_indexes'])->toBe([1, 2])
        ->and($refs[457574]['effect_indexes'])->toBe([2])
        ->and($refs[207188]['effect_indexes'])->toBe([])
        ->and($refs[207188]['effect_index'])->toBeNull();
});

test('parseSpellRefs handles three or more indices in the plural form', function () {
    $refs = parseAffectingSpellsFixture('Affecting Spells : Some Spell (1218601 effects: #1, #2, #4, #5)');

    expect($refs[1218601]['effect_indexes'])->toBe([1, 2, 4, 5]);
});
