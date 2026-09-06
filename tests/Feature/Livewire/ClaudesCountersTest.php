<?php

use App\Livewire\ClaudesCounters;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellEffect;
use Livewire\Livewire;

/**
 * Covers the 2026-09-04 rewrite — the two-spec matchup picker (auto-pairing, closest-equivalent,
 * the DuelSimulatorService pressure bar) was removed entirely; this page is now a plain per-class
 * list of every real CC ability ("counterable spell") and its real counters, found GLOBALLY
 * across every class rather than scoped to one chosen opponent. Replaces the prior matchup-era
 * test file — see git history for what that covered.
 */
function makeCounterCcSpell(Patch $patch, int $spellId, string $name, string $drCategory, ?string $school = 'Physical', bool $bypassesActiveDefense = false): Spell
{
    return Spell::create([
        'patch_id' => $patch->id, 'spell_id' => $spellId, 'name' => $name,
        'dr_category' => $drCategory, 'school' => $school, 'is_passive' => false,
        'bypasses_active_defense' => $bypassesActiveDefense,
    ]);
}

function attachToClass(Spell $spell, GameClass $class, ?Specialization $spec = null, string $source = 'talent'): void
{
    SpellClassAvailability::create([
        'spell_id' => $spell->id, 'class_id' => $class->id, 'spec_id' => $spec?->id, 'source' => $source,
    ]);
}

test('a Stun with a usable-while-stunned ability elsewhere shows it as a counter', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);
    $classB = GameClass::create(['game_id' => $game->id, 'name' => 'Class B', 'slug' => 'class-b']);

    $stun = makeCounterCcSpell($patch, 9101, 'Test Stun', 'Stun');
    attachToClass($stun, $classA);

    $counterSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9102, 'name' => 'Usable While Stunned', 'usable_while_cc' => 'stun,flee']);
    attachToClass($counterSpell, $classB);

    $overview = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass;

    $row = $overview['Class A']->firstWhere('spell.name', 'Test Stun');
    expect($row['hasAnyCounter'])->toBeTrue()
        ->and($row['usableWhileThis']->pluck('name'))->toContain('Usable While Stunned');
});

test('a Stun with an immunity-granting cooldown elsewhere shows it as a counter', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);
    $classB = GameClass::create(['game_id' => $game->id, 'name' => 'Class B', 'slug' => 'class-b']);

    $stun = makeCounterCcSpell($patch, 9201, 'Test Stun', 'Stun');
    attachToClass($stun, $classA);

    $immunitySpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9202, 'name' => 'Stun Ward']);
    attachToClass($immunitySpell, $classB);
    SpellEffect::create(['spell_id' => $immunitySpell->id, 'effect_index' => 1, 'type' => 'Mechanic Immunity', 'misc_value' => 12]); // 12 = Stun, per MECHANIC_IMMUNITY_CODE_MAP

    $overview = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass;

    $row = $overview['Class A']->firstWhere('spell.name', 'Test Stun');
    expect($row['hasAnyCounter'])->toBeTrue()
        ->and($row['grantsImmunity']->pluck('name'))->toContain('Stun Ward');
});

test('a Physical CC is countered by a School Immunity effect covering All or Physical, but not one covering only Shadow', function () {
    // Real gap found and closed 2026-09-04: Cloak of Shadows/Divine Shield/Blessing of Protection
    // all use School Immunity (a completely different effect type from Mechanic Immunity above),
    // and none showed up as a counter to a Physical-school Stun like Kidney Shot because
    // spell_effects.affected_schools was never captured at all.
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);
    $classB = GameClass::create(['game_id' => $game->id, 'name' => 'Class B', 'slug' => 'class-b']);

    $stun = makeCounterCcSpell($patch, 9251, 'Physical Stun', 'Stun', school: 'Physical');
    attachToClass($stun, $classA);

    $allSchoolImmune = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9252, 'name' => 'Cloak Clone']);
    attachToClass($allSchoolImmune, $classB);
    SpellEffect::create(['spell_id' => $allSchoolImmune->id, 'effect_index' => 1, 'type' => 'School Immunity', 'affected_schools' => 'All']);

    $physicalOnlyImmune = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9253, 'name' => 'BoP Clone']);
    attachToClass($physicalOnlyImmune, $classB);
    SpellEffect::create(['spell_id' => $physicalOnlyImmune->id, 'effect_index' => 1, 'type' => 'School Immunity', 'affected_schools' => 'Physical']);

    $shadowOnlyImmune = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9254, 'name' => 'Shadow Ward Clone']);
    attachToClass($shadowOnlyImmune, $classB);
    SpellEffect::create(['spell_id' => $shadowOnlyImmune->id, 'effect_index' => 1, 'type' => 'School Immunity', 'affected_schools' => 'Arcane, Fire, Frost, Holy, Nature, Shadow']);

    $overview = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass;

    $row = $overview['Class A']->firstWhere('spell.name', 'Physical Stun');
    expect($row['hasAnyCounter'])->toBeTrue()
        ->and($row['schoolImmunity']->pluck('name'))->toContain('Cloak Clone', 'BoP Clone')
        ->and($row['schoolImmunity']->pluck('name'))->not->toContain('Shadow Ward Clone');
});

test('a dodgeable Physical-school Stun shows a real dodge/parry buff as a counter', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);
    $classB = GameClass::create(['game_id' => $game->id, 'name' => 'Class B', 'slug' => 'class-b']);

    $stun = makeCounterCcSpell($patch, 9301, 'Dodgeable Stun', 'Stun', school: 'Physical', bypassesActiveDefense: false);
    attachToClass($stun, $classA);

    $dodgeSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9302, 'name' => 'Evasion Clone']);
    attachToClass($dodgeSpell, $classB);
    SpellEffect::create(['spell_id' => $dodgeSpell->id, 'effect_index' => 1, 'type' => 'Modify Dodge%', 'base_value' => 200]);

    $overview = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass;

    $row = $overview['Class A']->firstWhere('spell.name', 'Dodgeable Stun');
    expect($row['hasAnyCounter'])->toBeTrue()
        ->and($row['dodgeParryBoost']->pluck('name'))->toContain('Evasion Clone');
});

test('a magic-school CC never gets a dodge/parry counter, even when a dodge buff exists elsewhere', function () {
    // Real bug found and fixed 2026-09-04: Evasion was showing as a counter to Fear/Howl of
    // Terror (both School: Shadow) purely because bypasses_active_defense was false on them too
    // — true, but meaningless, since dodge/parry/block is a physical-combat-only mechanic that a
    // magic-school cast never enters regardless of that flag.
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);
    $classB = GameClass::create(['game_id' => $game->id, 'name' => 'Class B', 'slug' => 'class-b']);

    $fear = makeCounterCcSpell($patch, 9401, 'Test Fear', 'Disorient', school: 'Shadow', bypassesActiveDefense: false);
    attachToClass($fear, $classA);

    $dodgeSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9402, 'name' => 'Evasion Clone']);
    attachToClass($dodgeSpell, $classB);
    SpellEffect::create(['spell_id' => $dodgeSpell->id, 'effect_index' => 1, 'type' => 'Modify Dodge%', 'base_value' => 200]);

    $overview = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass;

    $row = $overview['Class A']->firstWhere('spell.name', 'Test Fear');
    expect($row['dodgeParryBoost'])->toBeEmpty()
        ->and($row['hasAnyCounter'])->toBeFalse();
});

test('a CC type with no verified dr_category mapping (Root) shows no known counter, never a guess', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);
    $classB = GameClass::create(['game_id' => $game->id, 'name' => 'Class B', 'slug' => 'class-b']);

    $root = makeCounterCcSpell($patch, 9501, 'Test Root', 'Root');
    attachToClass($root, $classA);

    // Even a spell explicitly usable-while-rooted (a token that doesn't exist for Root at all)
    // must not surface — there is no verified DR_CATEGORY_TO_CC_TOKEN entry for Root.
    $unrelated = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9502, 'name' => 'Unrelated', 'usable_while_cc' => 'stun']);
    attachToClass($unrelated, $classB);

    $overview = Livewire::test(ClaudesCounters::class)->instance()->counterableByClass;

    $row = $overview['Class A']->firstWhere('spell.name', 'Test Root');
    expect($row['hasAnyCounter'])->toBeFalse()
        ->and($row['usableWhileThis'])->toBeEmpty()
        ->and($row['grantsImmunity'])->toBeEmpty();
});

test('renders without a picker and shows the class-grouped list directly on load', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $classA = GameClass::create(['game_id' => $game->id, 'name' => 'Render Class', 'slug' => 'render-class']);

    $stun = makeCounterCcSpell($patch, 9601, 'Render Stun', 'Stun');
    attachToClass($stun, $classA);

    $html = Livewire::test(ClaudesCounters::class)->html();

    expect($html)->toContain('Render Class')
        ->and($html)->toContain('Render Stun')
        ->and($html)->not->toContain('Simulated Pressure') // the old matchup sim is gone
        ->and($html)->not->toContain('<select'); // no class/spec picker anywhere on the page
});
