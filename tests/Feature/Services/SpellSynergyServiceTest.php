<?php

use App\Http\Services\SpellSynergyService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Spell;

/**
 * The data behind a guide's Synergy section.
 *
 * candidatesFor()'s first source is modifiersFor(), which needs real imported spell_relationships
 * to say anything — it was checked by hand against the dev database when this was built (Penance:
 * 24 modifiers after dedupe, down from 49 raw, and Power Word: Shield 22 from 34). What is
 * asserted here is the behaviour that has to hold whatever data is loaded.
 */
function synergyWorld(): Patch
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);

    return Patch::create(['game_id' => $game->id, 'build_version' => '12.1.0', 'is_current' => true]);
}

test('the talents a damage formula multiplies by are found, and only real ones', function () {
    $patch = synergyWorld();

    Spell::create(['patch_id' => $patch->id, 'spell_id' => 198069, 'name' => 'Power of the Dark Side']);
    Spell::create(['patch_id' => $patch->id, 'spell_id' => 193134, 'name' => 'Castigation']);

    // Penance's real Variables block, plus a reference to a spell we do not have.
    $penance = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 47540, 'name' => 'Penance',
        'variables' => implode("\n", [
            '$castigation=$?a193134[${1}][${0}]',
            '$darkside=$?a198069[${1+($198069s1/100)}][${1}]',
            '$ghost=$?a999999[${1}][${0}]',
            '$penancedamage=${$47666s1*$<darkside>*(3+$<castigation>)}',
        ]),
    ]);

    $names = app(SpellSynergyService::class)->formulaTalents($penance)->pluck('display_name');

    // Power of the Dark Side is only ever visible this way — it is a term in the arithmetic,
    // not a spell_relationships row.
    expect($names->all())->toBe(['Castigation', 'Power of the Dark Side']);
});

test('a spell with no formula has no formula talents', function () {
    $patch = synergyWorld();
    $plain = Spell::create(['patch_id' => $patch->id, 'spell_id' => 17, 'name' => 'Power Word: Shield']);

    expect(app(SpellSynergyService::class)->formulaTalents($plain)->all())->toBe([]);
});

test('a modifier describes what it changes, and says nothing when the size is unknown', function () {
    $patch = synergyWorld();
    $talent = Spell::create(['patch_id' => $patch->id, 'spell_id' => 1, 'name' => 'Waste No Time']);
    $service = app(SpellSynergyService::class);

    $withValue = $service->describe([
        'spell' => $talent, 'relationship_type' => 'modifies_cooldown',
        'modifier_value' => -1.5, 'modifier_unit' => 'seconds',
    ]);

    expect($withValue['effect'])->toBe('Changes its cooldown')
        ->and($withValue['magnitude'])->toBe('-1.5 seconds');

    $positive = $service->describe([
        'spell' => $talent, 'relationship_type' => 'modifies_charges',
        'modifier_value' => 1, 'modifier_unit' => 'charges',
    ]);

    expect($positive['magnitude'])->toBe('+1 charges');

    // The dump records plenty of relationships with no magnitude at all. That prints nothing —
    // never a zero, which would read as "this talent changes it by nothing".
    $bare = $service->describe([
        'spell' => $talent, 'relationship_type' => 'modifies',
        'modifier_value' => null, 'modifier_unit' => null,
    ]);

    expect($bare['effect'])->toBe('Modifies it')
        ->and($bare['magnitude'])->toBeNull();
});
