<?php

use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\UserGuideChainService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;

/**
 * Two findings from writing a real Feral guide in the builder (2026-09-15).
 *
 * 1. Ferocious Bite showed a 180s cooldown. It has none — its description mentions Incarnation
 *    (the extra Energy it can spend), and the description-reference fallback borrowed
 *    Incarnation's cooldown. That fallback exists for a record of the SAME ability under another
 *    internal name (Axe Toss), and must stay restricted to that.
 *
 * 2. Rake is one button that also stuns from stealth, and the guide needs to be able to say
 *    which. The plain version is marked so a reader knows it applies no stun.
 */
function cooldownFallbackWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Druid', 'slug' => 'druid']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Feral', 'slug' => 'feral']);
    $build = new ModuleGameBuild(['class_id' => $class->id, 'specialization_id' => $spec->id, 'hero_talent_tree_id' => null]);

    return compact('patch', 'build');
}

test('a description that mentions a different ability does not lend it that ability\'s cooldown', function () {
    $w = cooldownFallbackWorld();
    Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 102543, 'name' => 'Incarnation: Avatar of Ashamane (desc=Shapeshift)', 'cooldown_seconds' => 180]);
    $bite = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 22568, 'name' => 'Ferocious Bite',
        'description' => 'Finishing move that consumes up to $?a102543[${$s2*(1+$102543s3/100)}][$s2] additional Energy.']);

    expect((new ModuleSpellReferenceService)->effectiveCooldown($bite, $w['build'], collect())['seconds'])->toBeNull();
});

test('a description pointing at another record of the SAME ability still recovers its cooldown', function () {
    $w = cooldownFallbackWorld();
    Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 89766, 'name' => 'Axe Toss (desc=Special Ability)', 'cooldown_seconds' => 30]);
    $shown = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 119914, 'name' => 'Axe Toss (desc=Command Demon Ability)',
        'description' => 'Your Felguard hurls its axe, stunning the target for $89766d.']);

    expect((new ModuleSpellReferenceService)->effectiveCooldown($shown, $w['build'], collect())['seconds'])->toBe(30.0);
});

test('the plain version of a stealth-only CC ability is marked as applying no CC', function () {
    $w = cooldownFallbackWorld();
    $stun = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 163505, 'name' => 'Rake', 'dr_category' => 'Stun', 'requires_stealth' => true]);
    $plain = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 1822, 'name' => 'Rake']);
    $unrelated = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 1079, 'name' => 'Rip']);

    $chains = app(UserGuideChainService::class);

    expect($chains->stealthTwinCategory($plain))->toBe('Stun')
        ->and($chains->stealthTwinCategory($stun))->toBeNull()     // the stealth copy is the stun
        ->and($chains->stealthTwinCategory($unrelated))->toBeNull()
        ->and($chains->stealthTwinCategory(null))->toBeNull();
});
