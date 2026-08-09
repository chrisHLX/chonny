<?php

use App\Models\Spell;

/**
 * Covers Spell::getDisplayNameAttribute() — strips the "(desc=Color)" suffix some spells
 * (overwhelmingly Evoker's) carry as a literal part of their raw name. The raw `name` column
 * itself must never be touched (it's load-bearing for exact-name matching elsewhere), so this
 * only covers the accessor.
 */
test('display_name strips a trailing (desc=Color) suffix', function () {
    $spell = new Spell(['name' => 'Pyre (desc=Red)']);

    expect($spell->display_name)->toBe('Pyre');
});

test('display_name leaves a plain name untouched', function () {
    $spell = new Spell(['name' => 'Kidney Shot']);

    expect($spell->display_name)->toBe('Kidney Shot');
});

test('display_name only strips a trailing suffix, not one mid-string', function () {
    $spell = new Spell(['name' => 'Living Flame (desc=Red)']);

    expect($spell->display_name)->toBe('Living Flame');
});
