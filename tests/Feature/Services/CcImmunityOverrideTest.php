<?php

use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\SpellCounterIndexer;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellCounter;
use App\Models\SpellEffect;

/**
 * Covers the hand-curated CC-immunity override added 2026-09-07.
 *
 * The bug this closes: 220 of 250 PvP talents have zero spell_effects rows, so
 * SpellCounterIndexer::buildPools()'s whereHas('effects', 'Mechanic Immunity') could never match
 * them — Phase Shift, Nullifying Shroud, Zen Focus Tea and friends were structurally invisible as
 * counters no matter what was written about them. cc_immunity_note existed for exactly this gap
 * but was free prose, so nothing could query it: Fade was curated for five days and still produced
 * ZERO rows in spell_counters. These tests pin the query change, not just the data.
 */
function immunityWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Class A', 'slug' => 'class-a']);

    return [$patch, $class];
}

function immunitySpell(Patch $patch, int $spellId, string $name, array $extra = []): Spell
{
    return Spell::create(array_merge([
        'patch_id' => $patch->id, 'spell_id' => $spellId, 'name' => $name,
        'is_passive' => false, 'not_in_spellbook' => false,
    ], $extra));
}

function makeAvailable(Spell $spell, GameClass $class): void
{
    SpellClassAvailability::create([
        'spell_id' => $spell->id, 'class_id' => $class->id, 'spec_id' => null, 'source' => 'talent',
    ]);
}

it('makes an effect-less PvP talent ability a real counter via the curated override', function () {
    [$patch, $class] = immunityWorld();

    $stun = immunitySpell($patch, 408, 'Kidney Shot', ['dr_category' => 'Stun', 'school' => 'Physical', 'cooldown_seconds' => 20]);
    makeAvailable($stun, $class);

    // Fade's shape in production: a real cooldown, but NO effects at all that grant immunity —
    // the immunity comes entirely from the Phase Shift PvP talent, which itself has zero effects.
    $fade = immunitySpell($patch, 586, 'Fade', [
        'cooldown_seconds' => 30,
        'grants_cc_immunity_override' => ['Stun', 'Silence', 'Incapacitate', 'Fear'],
        'cc_immunity_gating_spell_id' => 408557,
    ]);
    makeAvailable($fade, $class);

    app(SpellCounterIndexer::class)->rebuild($patch);

    $row = SpellCounter::where('countered_spell_id', $stun->id)
        ->where('counter_spell_id', $fade->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->detail)->toBe('Stun');
});

it('marks a talent-gated immunity with its own mechanism, not the unconditional one', function () {
    [$patch, $class] = immunityWorld();

    $stun = immunitySpell($patch, 408, 'Kidney Shot', ['dr_category' => 'Stun', 'school' => 'Physical', 'cooldown_seconds' => 20]);
    makeAvailable($stun, $class);

    $gated = immunitySpell($patch, 586, 'Fade', [
        'cooldown_seconds' => 30,
        'grants_cc_immunity_override' => ['Stun'],
        'cc_immunity_gating_spell_id' => 408557,
    ]);
    $ungated = immunitySpell($patch, 354540, 'Nimble Brew', [
        'cooldown_seconds' => 120,
        'grants_cc_immunity_override' => ['Stun'],
    ]);
    makeAvailable($gated, $class);
    makeAvailable($ungated, $class);

    app(SpellCounterIndexer::class)->rebuild($patch);

    expect(SpellCounter::where('counter_spell_id', $gated->id)->value('mechanism'))
        ->toBe(SpellCounter::MECHANISM_IMMUNITY_TALENT)
        ->and(SpellCounter::where('counter_spell_id', $ungated->id)->value('mechanism'))
        ->toBe(SpellCounter::MECHANISM_IMMUNITY_MECHANIC);
});

it('unions curated mechanics with the spell\'s own effect-derived ones rather than replacing them', function () {
    [$patch] = immunityWorld();

    $spell = immunitySpell($patch, 1, 'Both Sources', [
        'grants_cc_immunity_override' => ['Fear'],
    ]);
    // misc_value 12 is Stun in MECHANIC_IMMUNITY_CODE_MAP.
    SpellEffect::create([
        'spell_id' => $spell->id, 'effect_index' => 1,
        'type' => 'Mechanic Immunity', 'misc_value' => 12,
    ]);
    $spell->load('effects');

    expect(app(ModuleSpellReferenceService::class)->ccImmunityFor($spell)->sort()->values()->all())
        ->toBe(['Fear', 'Stun']);
});

it('does not let the (desc=...) hygiene filter drop a curated Evoker ability', function () {
    // Real, load-bearing case: Evoker uses "(desc=Colour)" as legitimate per-dragonflight naming,
    // not as a duplicate marker, so buildPools()'s hygiene filter would otherwise silently discard
    // Obsidian Scales (desc=Black) and Verdant Embrace (desc=Green) even when curated by hand.
    [$patch, $class] = immunityWorld();

    $silence = immunitySpell($patch, 47476, 'Strangulate', ['dr_category' => 'Silence', 'school' => 'Shadow', 'cooldown_seconds' => 45]);
    makeAvailable($silence, $class);

    $scales = immunitySpell($patch, 363916, 'Obsidian Scales (desc=Black)', [
        'cooldown_seconds' => 90,
        'grants_cc_immunity_override' => ['Silence', 'Interrupt'],
        'cc_immunity_gating_spell_id' => 378444,
    ]);
    makeAvailable($scales, $class);

    app(SpellCounterIndexer::class)->rebuild($patch);

    expect(SpellCounter::where('countered_spell_id', $silence->id)
        ->where('counter_spell_id', $scales->id)->exists())->toBeTrue();
});

it('still requires a curated counter to be pressable', function () {
    // The override asserts what a spell grants; it does NOT exempt it from narrowToPressable().
    // A passive with no cooldown and no kit link is still not something a player can press in
    // response to being stunned.
    [$patch, $class] = immunityWorld();

    $stun = immunitySpell($patch, 408, 'Kidney Shot', ['dr_category' => 'Stun', 'school' => 'Physical', 'cooldown_seconds' => 20]);
    makeAvailable($stun, $class);

    $notPressable = immunitySpell($patch, 999, 'Lingering Aura', [
        'grants_cc_immunity_override' => ['Stun'],
    ]);
    makeAvailable($notPressable, $class);

    app(SpellCounterIndexer::class)->rebuild($patch);

    expect(SpellCounter::where('counter_spell_id', $notPressable->id)->exists())->toBeFalse();
});

it('leaves a spell with no override exactly as it was', function () {
    [$patch, $class] = immunityWorld();

    $stun = immunitySpell($patch, 408, 'Kidney Shot', ['dr_category' => 'Stun', 'school' => 'Physical', 'cooldown_seconds' => 20]);
    makeAvailable($stun, $class);

    $plain = immunitySpell($patch, 586, 'Fade', ['cooldown_seconds' => 30]);
    makeAvailable($plain, $class);

    app(SpellCounterIndexer::class)->rebuild($patch);

    expect(SpellCounter::where('counter_spell_id', $plain->id)->exists())->toBeFalse();
});
