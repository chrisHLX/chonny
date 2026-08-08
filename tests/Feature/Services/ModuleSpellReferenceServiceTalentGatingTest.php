<?php

use App\Http\Services\ModuleSpellReferenceService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellRelationship;

/**
 * Covers the motivating example from the "Talent-aware spell data" plan: the Discipline PvP
 * talent Ultimate Radiance reduces Evangelism's cooldown by 45s. Before this feature, this
 * relationship wasn't captured anywhere and, even once captured, wasn't selection-aware — every
 * possible modifier applied unconditionally. These tests assert both: the relationship is only
 * treated as applying when its source talent is actually selected, and the computed cooldown
 * reflects it correctly when it is.
 */
function makeDisciplineFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    $evangelism = Spell::create([
        'patch_id' => $patch->id,
        'spell_id' => 472433,
        'name' => 'Evangelism',
        'cooldown_seconds' => 90,
    ]);

    $ultimateRadiance = Spell::create([
        'patch_id' => $patch->id,
        'spell_id' => 236499,
        'name' => 'Ultimate Radiance',
        'description' => 'Evangelism cooldown is reduced by 45 sec and Power Word: Radiance healing is increased by 15%.',
    ]);

    SpellClassAvailability::create([
        'spell_id' => $evangelism->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'baseline',
    ]);
    SpellClassAvailability::create([
        'spell_id' => $ultimateRadiance->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'pvp_talent',
    ]);

    PvpTalent::create([
        'spec_id' => $spec->id, 'patch_id' => $patch->id, 'spell_id' => $ultimateRadiance->id,
        'unlock_level' => 23, 'external_pvp_talent_id' => 114,
    ]);

    SpellRelationship::create([
        'source_spell_id' => $ultimateRadiance->id,
        'target_spell_id' => $evangelism->id,
        'relationship_type' => 'modifies_cooldown',
        'modifier_value' => -45,
        'modifier_unit' => 'seconds',
    ]);

    $build = new ModuleGameBuild([
        'class_id' => $class->id,
        'specialization_id' => $spec->id,
        'hero_talent_tree_id' => null,
    ]);

    return compact('evangelism', 'ultimateRadiance', 'build');
}

test('effective cooldown stays at base when the modifying pvp talent is not selected', function () {
    $fixture = makeDisciplineFixture();
    $service = new ModuleSpellReferenceService();

    $result = $service->effectiveCooldown($fixture['evangelism'], $fixture['build'], collect());

    expect($result['seconds'])->toBe(90.0)
        ->and($result['base_seconds'])->toBe(90.0)
        ->and($result['applied'])->toBeEmpty();
});

test('effective cooldown applies the pvp talent modifier once it is selected', function () {
    $fixture = makeDisciplineFixture();
    $service = new ModuleSpellReferenceService();

    $selected = collect([$fixture['ultimateRadiance']->id]);
    $result = $service->effectiveCooldown($fixture['evangelism'], $fixture['build'], $selected);

    expect($result['seconds'])->toBe(45.0)
        ->and($result['base_seconds'])->toBe(90.0)
        ->and($result['applied'])->toHaveCount(1);
});

test('modifiersFor only lists the pvp talent as named when selected, never when not', function () {
    $fixture = makeDisciplineFixture();
    $service = new ModuleSpellReferenceService();

    $unselected = $service->modifiersFor($fixture['evangelism'], $fixture['build'], collect());
    expect($unselected['named'])->toBeEmpty();

    $selected = $service->modifiersFor($fixture['evangelism'], $fixture['build'], collect([$fixture['ultimateRadiance']->id]));
    expect($selected['named'])->toHaveCount(1)
        ->and($selected['named']->first()['spell']->name)->toBe('Ultimate Radiance')
        ->and($selected['named']->first()['relationship_type'])->toBe('modifies_cooldown');
});

/**
 * Covers a real report (2026-08-09): "Improved Traps doesn't give a number, but Freezing
 * Trap's cooldown IS correctly reduced" — same shape independently reported for Territorial
 * Instincts -> Intimidation. Root cause: a source spell commonly has TWO separate
 * spell_relationships rows to the same target — a generic 'modifies' (no magnitude, from the
 * Affecting-Spells text pass) alongside a specific 'modifies_cooldown' (real magnitude, from
 * the Category-effect pass). Both used to render as separate list rows for the same source,
 * one confusingly numberless. modifiersFor() should show only the specific one.
 */
test('modifiersFor drops a redundant bare "modifies" entry when a specific one exists for the same source', function () {
    $fixture = makeDisciplineFixture();
    $service = new ModuleSpellReferenceService();

    SpellRelationship::create([
        'source_spell_id' => $fixture['ultimateRadiance']->id,
        'target_spell_id' => $fixture['evangelism']->id,
        'relationship_type' => 'modifies',
        'modifier_value' => null,
        'modifier_unit' => null,
    ]);

    $selected = $service->modifiersFor($fixture['evangelism'], $fixture['build'], collect([$fixture['ultimateRadiance']->id]));

    expect($selected['named'])->toHaveCount(1)
        ->and($selected['named']->first()['relationship_type'])->toBe('modifies_cooldown')
        ->and($selected['named']->first()['modifier_value'])->toBe(-45.0);
});

test('modifiersFor keeps a bare "modifies" entry when no more specific relationship exists for that source', function () {
    $fixture = makeDisciplineFixture();
    $service = new ModuleSpellReferenceService();

    // Replace the fixture's specific modifies_cooldown row with a bare 'modifies' one — the
    // only signal we have for this source is "it affects the target", no known number.
    SpellRelationship::where('source_spell_id', $fixture['ultimateRadiance']->id)->delete();
    SpellRelationship::create([
        'source_spell_id' => $fixture['ultimateRadiance']->id,
        'target_spell_id' => $fixture['evangelism']->id,
        'relationship_type' => 'modifies',
        'modifier_value' => null,
        'modifier_unit' => null,
    ]);

    $selected = $service->modifiersFor($fixture['evangelism'], $fixture['build'], collect([$fixture['ultimateRadiance']->id]));

    expect($selected['named'])->toHaveCount(1)
        ->and($selected['named']->first()['relationship_type'])->toBe('modifies');
});
