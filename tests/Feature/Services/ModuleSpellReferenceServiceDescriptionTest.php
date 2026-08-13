<?php

use App\Http\Services\ModuleSpellReferenceService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellEffect;

/**
 * Covers resolveDescription() and its helpers — previously untested despite the amount of
 * documented work built on this resolver. Written 2026-08-10 investigating a real report:
 * raw "|cFFFFFFFF...|r" color codes, "$@spellname<id>"/"$@spellicon<id>" cross-references, and
 * "$?(a<id>&a<id>)[...]?(...)[...][...]" compound chained conditionals were all leaking
 * unresolved into displayed prose (Breath of Sindragosa, Trueshot). A fourth, more serious bug
 * was found in the same investigation: safeEval()'s $peek closure was an arrow function
 * (`fn () => $tokens[$pos] ?? null`), which captures $pos BY VALUE at closure-creation time —
 * PHP arrow functions have no by-reference capture — so every peek() call kept returning the
 * FIRST token forever regardless of how far next() (a real by-reference closure) had actually
 * advanced. This silently truncated every ${...} arithmetic expression with more than one term
 * to just its first factor (e.g. safeEval('800/1000') returned 800.0, not 0.8) dataset-wide,
 * not just on the two spells originally reported.
 */
function makeDescriptionFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Death Knight', 'slug' => 'deathknight']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Frost', 'slug' => 'frost']);

    $build = new ModuleGameBuild([
        'class_id' => $class->id,
        'specialization_id' => $spec->id,
        'hero_talent_tree_id' => null,
    ]);

    return compact('game', 'patch', 'class', 'spec', 'build');
}

/**
 * Creates the spell under test AND tags it with a SpellClassAvailability row in the fixture's
 * class/spec — resolveKitContext() needs this to resolve a real class/spec context for the
 * spell itself (separate from whatever conditional-referenced spells a given test also tags),
 * without which buildKitSpellIdsFor() short-circuits to an empty kit and every $?a<id>/
 * compound-conditional check evaluates false regardless of what else is set up.
 */
function makeTestSpell(array $fixture, int $spellId, string $description): Spell
{
    $spell = Spell::create([
        'patch_id' => $fixture['patch']->id,
        'spell_id' => $spellId,
        'name' => 'Test Spell',
        'description' => $description,
    ]);

    SpellClassAvailability::create([
        'spell_id' => $spell->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline',
    ]);

    return $spell;
}

test('resolveDescription strips WoW color-code markup, keeping the wrapped text', function () {
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 1, 'Deals damage. |cFFFFFFFFGrants a Rune at the end.|r');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Deals damage. Grants a Rune at the end.');
});

test('resolveDescription resolves $@spellname to the real spell name and strips $@spellicon', function () {
    $fixture = makeDescriptionFixture();
    Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 19434, 'name' => 'Aimed Shot']);
    $spell = makeTestSpell($fixture, 2, '$@spellicon19434 $@spellname19434 cooldown reduced.');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Aimed Shot cooldown reduced.');
});

test('resolveDescription flags an unresolved $@spellname reference rather than guessing', function () {
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 3, '$@spellname999999 cooldown reduced.');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('(unknown spell) cooldown reduced.')
        ->and($result['uncertain'])->toBeTrue();
});

test('resolveDescription picks the first true branch of a compound chained conditional', function () {
    $fixture = makeDescriptionFixture();
    $known = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 100, 'name' => 'Sentinel Aura']);
    $alsoKnown = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 200, 'name' => 'Shared Aura']);
    SpellClassAvailability::create(['spell_id' => $known->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);
    SpellClassAvailability::create(['spell_id' => $alsoKnown->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);

    $spell = makeTestSpell($fixture, 4, 'Cooldown recovers 60% faster.$?(a100&a200)[ Applies Sentinel\'s Mark.]?(!a100&a200)[ Applies Spotter\'s Mark.][]');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe("Cooldown recovers 60% faster. Applies Sentinel's Mark.");
});

test('resolveDescription falls through a compound chained conditional to the second branch', function () {
    $fixture = makeDescriptionFixture();
    $alsoKnown = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 200, 'name' => 'Shared Aura']);
    SpellClassAvailability::create(['spell_id' => $alsoKnown->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);
    // spell_id 100 ("Sentinel Aura") deliberately not tagged into this fixture's kit — the
    // negated second condition (!a100&a200) should be the one that ends up true here.

    $spell = makeTestSpell($fixture, 5, 'Cooldown recovers 60% faster.$?(a100&a200)[ Applies Sentinel\'s Mark.]?(!a100&a200)[ Applies Spotter\'s Mark.][]');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe("Cooldown recovers 60% faster. Applies Spotter's Mark.");
});

test('resolveDescription falls back to the trailing bracket when no compound condition matches', function () {
    $fixture = makeDescriptionFixture();
    // Neither spell_id 100 nor 200 exists anywhere in this fixture at all — both conditions
    // resolve confidently false (the referenced spells just don't exist), not "unknown".
    $spell = makeTestSpell($fixture, 6, 'Cooldown recovers 60% faster.$?(a100&a200)[ Applies Sentinel\'s Mark.]?(!a100&a200)[ Applies Spotter\'s Mark.][]');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Cooldown recovers 60% faster.');
});

test('resolveDescription resolves an unparenthesized chained conditional with a "?" prefix term', function () {
    // Real shape from Painful Invocation (spell_id 1251030): "a137031&?s14914" paired against
    // "a137031&!s14914" as its exact logical complement — confirms "?" means the same positive
    // check as no prefix, not a distinct operation. No wrapping "(...)" around either condition.
    $fixture = makeDescriptionFixture();
    $talent = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 100, 'name' => 'Some Talent']);
    $procSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 200, 'name' => 'Some Proc']);
    SpellClassAvailability::create(['spell_id' => $talent->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);
    SpellClassAvailability::create(['spell_id' => $procSpell->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);

    $spell = makeTestSpell($fixture, 8, 'Increases the damage of $?a100&?s200[Holy Fire]?a100&!s200[Holy Fire and Shadow Word: Pain][Shadow Word: Pain].');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Increases the damage of Holy Fire.');
});

test('resolveDescription falls through an unparenthesized chained conditional to the final bracket fallback', function () {
    $fixture = makeDescriptionFixture();
    // Neither spell_id 100 nor 200 tagged into this fixture's kit — both chained conditions
    // resolve confidently false, landing on the trailing bare [fallback].
    $spell = makeTestSpell($fixture, 9, 'Increases the damage of $?a100&?s200[Holy Fire]?a100&!s200[Holy Fire and Shadow Word: Pain][Shadow Word: Pain].');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Increases the damage of Shadow Word: Pain.');
});

test('resolveDescription resolves a simple unparenthesized OR condition with no chaining', function () {
    // Real shape from Shadowflame Prism (spell_id 336143): "$?s123040|s200174[Mindbender]
    // [Shadowfiend]" — a single compound condition, single branch/fallback pair, no "?(...)"
    // continuation at all.
    $fixture = makeDescriptionFixture();
    $mindbender = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 123040, 'name' => 'Mindbender']);
    SpellClassAvailability::create(['spell_id' => $mindbender->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);

    $spell = makeTestSpell($fixture, 10, 'Your $?s123040|s200174[Mindbender][Shadowfiend] teleports behind your target.');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Your Mindbender teleports behind your target.');
});

test('resolveDescription correctly evaluates multi-term ${...} arithmetic (safeEval regression)', function () {
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 7, 'Increases the duration by ${$s3/1000} sec.');
    SpellEffect::create([
        'spell_id' => $spell->id,
        'effect_index' => 3,
        'base_value' => 800,
        'scaled_value' => 800,
    ]);

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    // Before the safeEval fix this returned "800 sec" (the division was silently never
    // applied — safeEval('800/1000') returned just the first term, 800.0).
    expect($result['text'])->toBe('Increases the duration by 0.8 sec.');
});
