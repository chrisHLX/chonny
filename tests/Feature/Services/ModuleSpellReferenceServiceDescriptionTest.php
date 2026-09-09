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

test('resolveDescription strips uppercase WoW color-code markup too', function () {
    // Real shape from Master Shapeshifter (spell_id 411143): "|CFFFFFFFFBear Form|R" — the
    // original stripping pass was lowercase-only ("|c...|r") and left 97 spells' uppercase
    // "|C...|R" markers passing through raw.
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 11, 'Deals damage. |CFFFFFFFFGrants a Rune at the end.|R');

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

/*
 * ${...} unresolved-token handling — added 2026-09-06 after Eviscerate rendered
 * "1 point : 0 damage 2 points: 0 damage ..." from ${$m1*N}: an unresolved token was being
 * substituted as literal 0 and the arithmetic evaluated anyway, producing a confidently-wrong
 * number. Now: a null token in a MULTIPLICAND/DIVISOR position discards the whole ${...} to
 * "(varies)"; a null token in an ADDITIVE/DIVIDEND position ("no modifier applied") keeps 0.
 */
test('resolveDescription discards a ${...} whose unresolved token is a multiplicand', function () {
    $fixture = makeDescriptionFixture();
    // $m1 points at an effect that does not exist on this spell — must NOT become "0 damage".
    $spell = makeTestSpell($fixture, 20, 'Causes ${$m1*3} damage per combo point.');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Causes (varies) damage per combo point.')
        ->and($result['uncertain'])->toBeTrue();
});

test('resolveDescription keeps a computed ${...} value when the missing token is a pure additive term', function () {
    // Alter Time shape: "${$110909d+$s3}" — $d of a real referenced spell resolves, $s3 does
    // not exist. "+$s3" is a "no extension talent" term; 0 is its arithmetic identity.
    $fixture = makeDescriptionFixture();
    Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 110909, 'name' => 'Alter Time Aura', 'duration_seconds' => 10]);
    $spell = makeTestSpell($fixture, 21, 'Returns you to your location after ${$110909d+$s3} seconds.');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Returns you to your location after 10 seconds.');
});

test('resolveDescription treats a missing token as 0 when it is a dividend inside (1 + $x/100)', function () {
    // Nature's Guardian shape: "${$Xs1*(1+$s2/100)}" — $s2 is a talent-gated percent modifier,
    // 0 when not talented. It divides 100 (dividend), so 0 is safe and the base value stands.
    $fixture = makeDescriptionFixture();
    $other = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 31616, 'name' => 'Heal Effect']);
    SpellEffect::create(['spell_id' => $other->id, 'effect_index' => 1, 'base_value' => 40, 'scaled_value' => 40]);
    $spell = makeTestSpell($fixture, 22, 'Heal for ${$31616s1*(1+$s2/100)}% of your health.');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Heal for 40% of your health.');
});

test('resolveDescription resolves $mN against the spell\'s own effect', function () {
    // Prosperity shape: "Swiftmend now has ${$m1+1} charges." — own effect #1 Base Value 1.
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 23, 'Swiftmend now has ${$m1+1} charges.');
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => 1, 'scaled_value' => 1]);

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Swiftmend now has 2 charges.')
        ->and($result['uncertain'])->toBeFalse();
});

test('resolveDescription discards a ${...} whose unresolved token is a divisor', function () {
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 24, 'Restores ${$s1/$s2} per second.');
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => 100, 'scaled_value' => 100]);
    // effect #2 absent -> $s2 null, and it is the divisor.

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Restores (varies) per second.')
        ->and($result['uncertain'])->toBeTrue();
});

/*
 * $<varname> Variables-block resolution + ".N" precision-suffix handling — added 2026-09-06
 * after Shield Discipline (spell_id 47755) rendered "restore (varies)% of your maximum mana"
 * for "$mana=${$47755s1/100}.1", a conditional-free single ${...} formula that Pass 2 can
 * evaluate exactly.
 */
test('resolveDescription evaluates a $<var> whose Variables definition is one unconditional ${...}', function () {
    $fixture = makeDescriptionFixture();
    $spell = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 30,
        'name' => 'Test Spell',
        'description' => 'Restore $<mana>% of your maximum mana.',
        'variables' => '$mana=${$s1/100}.1',
    ]);
    SpellClassAvailability::create(['spell_id' => $spell->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => 50, 'scaled_value' => 50]);

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Restore 0.5% of your maximum mana.')
        ->and($result['uncertain'])->toBeFalse();
});

test('resolveDescription keeps $<var> as (varies) when the Variables block has any $? conditional', function () {
    // Penance shape: a conditional-free var sits in the SAME block as a conditional one — the
    // whole block is untrusted, nothing is inlined.
    $fixture = makeDescriptionFixture();
    $spell = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 31,
        'name' => 'Test Spell',
        'description' => 'Deals $<dmg> damage.',
        'variables' => "\$mult=\$?a100[\${2}][\${1}]\n\$dmg=\${\$s1}",
    ]);
    SpellClassAvailability::create(['spell_id' => $spell->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline']);
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => 500, 'scaled_value' => 500]);

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Deals (varies) damage.')
        ->and($result['uncertain'])->toBeTrue();
});

test('resolveDescription consumes a trailing ".N" precision suffix after ${...}', function () {
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 32, 'Reduces the cooldown by ${$s1/1000}.1 sec.');
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => 3000, 'scaled_value' => 3000]);

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    // Before: "by 3.1 sec" (${3000/1000}=3, then literal ".1"). Now the suffix is dropped and
    // formatNumber() renders the whole value.
    expect($result['text'])->toBe('Reduces the cooldown by 3 sec.');
});

test('a bare token in prose renders its magnitude, so a stored reduction is not double-signed', function () {
    // Blizzard stores a reduction as a negative base value and carries the direction in the prose
    // around the token, so a signed render duplicates it ("by -40%"). Reported live 2026-09-10 on
    // Bladestorm; measured at 1,069 spells across the current patch, Shield Wall / Barkskin /
    // Pain Suppression / Divine Protection among them.
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 40, 'Reduces all damage you take by $s1%.');
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => -40, 'scaled_value' => -40]);

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Reduces all damage you take by 40%.');
});

test('the magnitude rule holds when the prose inverts the framing', function () {
    // Curse of Tongues' real shape: "Modify Casting Speed%: -30" rendered as "increasing the
    // casting time ... by 30%". The sign lives in the prose, not in the word "reduces".
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 41, 'Increasing the casting time of all spells by $s1%.');
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => -30, 'scaled_value' => -30]);

    expect(app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build'])['text'])
        ->toBe('Increasing the casting time of all spells by 30%.');
});

test('a prose range written as $sN-$sM is not corrupted into a double minus', function () {
    // Chaos Theory / Flurry Strikes shape — 12 spells write a range this way, and a signed second
    // token rendered "14--30%".
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 42, 'Gains a $s1-$s2% increased critical strike chance.');
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => 14, 'scaled_value' => 14]);
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 2, 'base_value' => -30, 'scaled_value' => -30]);

    expect(app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build'])['text'])
        ->toBe('Gains a 14-30% increased critical strike chance.');
});

test('${...} arithmetic keeps the sign, because Blizzard flips it explicitly', function () {
    // 392 expressions in the current patch flip the sign themselves ("${$s1*-1}",
    // "${$m1/-1000}"). Dropping the sign inside the arithmetic would invert every one of them,
    // which is why the magnitude rule above is deliberately bare-token-only.
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 43, 'Reduces the cooldown by ${$s1/-1000} sec.');
    SpellEffect::create(['spell_id' => $spell->id, 'effect_index' => 1, 'base_value' => -5000, 'scaled_value' => -5000]);

    expect(app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build'])['text'])
        ->toBe('Reduces the cooldown by 5 sec.');
});

test('an unresolvable token used as a minuend is (varies), not a confident negative', function () {
    // "$x1-1" collapsed to "0-1" and rendered "jumping to -1 additional nearby enemies"
    // (Avenger's Shield). $x/$u/$i aren't captured in this schema, so there is nothing to resolve
    // them to and a negative count of targets is the confidently-wrong answer.
    $fixture = makeDescriptionFixture();
    $spell = makeTestSpell($fixture, 44, 'Jumping to ${$x1-1} additional nearby enemies.');

    $result = app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build']);

    expect($result['text'])->toBe('Jumping to (varies) additional nearby enemies.')
        ->and($result['uncertain'])->toBeTrue();
});

test('an unresolvable token used as a subtrahend stays safe and still resolves', function () {
    // The other side of the same expression is genuinely identity-safe: "$d-$s1" with a missing
    // $s1 is still the duration, so it must not be poisoned along with the minuend case.
    $fixture = makeDescriptionFixture();
    $spell = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 45,
        'name' => 'Test Spell', 'description' => 'Lasts ${$d-$s9} sec.', 'duration_seconds' => 12,
    ]);
    SpellClassAvailability::create([
        'spell_id' => $spell->id, 'class_id' => $fixture['class']->id, 'spec_id' => $fixture['spec']->id, 'source' => 'baseline',
    ]);

    expect(app(ModuleSpellReferenceService::class)->resolveDescription($spell, $fixture['build'])['text'])
        ->toBe('Lasts 12 sec.');
});
