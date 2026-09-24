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
 * The 2026-09-24 pass over unresolved description tokens.
 *
 * Every case here was a real "(varies)" in finished, shipped output — counted in the committed
 * spell kits before the fix, and traced to a value the SimC dump carries and this schema did not
 * store. See docs/learning/ for the audit that started this and the migration's docblock for the
 * per-token counts.
 */
function tokenWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.1.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Shaman', 'slug' => 'shaman']);
    $spec = Specialization::create([
        'class_id' => $class->id, 'name' => 'Elemental', 'slug' => 'elemental', 'external_spec_id' => 262,
    ]);

    return [$patch, $class, $spec];
}

function tokenSpell(Patch $patch, GameClass $class, array $attributes, array $effects = []): Spell
{
    $spell = Spell::create(array_merge([
        'patch_id' => $patch->id,
        'name' => 'Test Spell',
        'is_passive' => false,
        'not_in_spellbook' => false,
    ], $attributes));

    SpellClassAvailability::create([
        'spell_id' => $spell->id,
        'class_id' => $class->id,
        'source' => 'baseline',
    ]);

    foreach ($effects as $index => $effect) {
        SpellEffect::create(array_merge([
            'spell_id' => $spell->id,
            'effect_index' => $index + 1,
        ], $effect));
    }

    return $spell->fresh('effects');
}

function resolveText(Spell $spell, GameClass $class, Specialization $spec): string
{
    $build = new ModuleGameBuild([
        'class_id' => $class->id,
        'specialization_id' => $spec->id,
        'hero_talent_tree_id' => null,
    ]);

    return app(ModuleSpellReferenceService::class)->resolveDescription($spell, $build)['text'];
}

test('the stack cap resolves instead of rendering (varies)', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 194879,
        'name' => 'Icy Talons',
        'max_stacks' => 3,
        'description' => 'Increases your melee attack speed, stacking up to $u times.',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Increases your melee attack speed, stacking up to 3 times.');
});

test('a proc chance resolves, with the percent sign left to the prose', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 335274,
        'name' => 'Battlelord',
        'proc_chance' => 40,
        'description' => 'Your Overpower has a $h% chance to reset the cooldown of Mortal Strike.',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Your Overpower has a 40% chance to reset the cooldown of Mortal Strike.');
});

test('a radius and a chain target count resolve from their own effect', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 188443,
        'name' => 'Chain Lightning',
        'description' => 'Strikes all enemies within $A1 yards. Affects $x1 total targets.',
    ], [
        ['type' => 'School Damage', 'radius_yards' => 12, 'chain_targets' => 3],
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Strikes all enemies within 12 yards. Affects 3 total targets.');
});

test('a cross-spell reference resolves the same three values', function () {
    [$patch, $class, $spec] = tokenWorld();

    tokenSpell($patch, $class, [
        'spell_id' => 51714,
        'name' => 'Razorice',
        'max_stacks' => 5,
    ], [
        ['type' => 'School Damage', 'radius_yards' => 8, 'chain_targets' => 4],
    ]);

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 999001,
        'name' => 'Referencing Spell',
        'description' => 'Up to $51714u stacks, within $51714A1 yards, hitting $51714x1 targets.',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Up to 5 stacks, within 8 yards, hitting 4 targets.');
});

test('a missing value still renders (varies) rather than a confident zero', function () {
    [$patch, $class, $spec] = tokenWorld();

    // No max_stacks, and an effect with no radius: both are genuinely absent from the dump for
    // this spell, so the honest answer is still the placeholder.
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 999002,
        'name' => 'Unknown Values',
        'description' => 'Stacks up to $u times within $A1 yards.',
    ], [
        ['type' => 'School Damage'],
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Stacks up to (varies) times within (varies) yards.');
});

test('pluralisation picks its form from the number before it, and never leaks the raw token', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 999003,
        'name' => 'Plural Spell',
        'description' => 'Refund $s1 $Lrune:runes; and gain $s2 additional $Lcharge:charges;.',
    ], [
        ['type' => 'Energize', 'base_value' => 1, 'scaled_value' => 1],
        ['type' => 'Dummy', 'base_value' => 2, 'scaled_value' => 2],
    ]);

    // One rune, two charges — the singular is used only for an exact 1.
    expect(resolveText($spell, $class, $spec))->toBe('Refund 1 rune and gain 2 additional charges.');
});

test('pluralisation falls back to the plural when the count ahead of it is unresolved', function () {
    [$patch, $class, $spec] = tokenWorld();

    // This is the shape that shipped as "(varies):stacks;" — 1,028 times across the committed
    // kits. The count is still honestly unresolved; the word beside it is no longer broken.
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 999004,
        'name' => 'Unresolved Plural',
        'description' => 'Consumes up to $s9 $Lstack:stacks;.',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Consumes up to (varies) stacks.');
});

test('a conditional with a stray bracket still picks a branch', function () {
    [$patch, $class, $spec] = tokenWorld();

    // The aura is not in this class's kit, so the second branch is the one that applies.
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 439843,
        'name' => "Reaper's Mark",
        'description' => 'Grants $?a137008][3 charges of Bone Shield][Killing Machine].',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Grants Killing Machine.');
});

test('a nested arithmetic expression resolves instead of leaking braces', function () {
    [$patch, $class, $spec] = tokenWorld();

    // Pass 1.5 inlines the variable, which puts one ${...} inside another. Before the loop this
    // left a literal "${...}" on the page — 25 of them across the kits, one of them "${10+20}".
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 390378,
        'name' => 'Orbital Strike',
        'variables' => '$mastery=${$s2/100}',
        'description' => 'Blasts for ${$s1*$<mastery>} Astral damage.',
    ], [
        ['type' => 'School Damage', 'base_value' => 100, 'scaled_value' => 100],
        ['type' => 'Dummy', 'base_value' => 250, 'scaled_value' => 250],
    ]);

    $text = resolveText($spell, $class, $spec);

    expect($text)->not->toContain('${')
        ->and($text)->toBe('Blasts for 250 Astral damage.');
});

test('the semicolon form of a plural is read the same way', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 387638,
        'name' => 'Shadowboxing Treads',
        'description' => 'Blackout Kick strikes an additional $s1 $ltarget;targets.',
    ], [
        ['type' => 'Dummy', 'base_value' => 2, 'scaled_value' => 2],
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Blackout Kick strikes an additional 2 targets.');
});

test('a conditional whose branches are separated by a space still resolves', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 424058,
        'name' => 'Boundless Moonlight',
        'description' => 'Grants $?a137010[Lunar Beam] [Fury of Elune].',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Grants Fury of Elune.');
});

test('a one-branch conditional inserts its branch or nothing, without disturbing a two-branch one', function () {
    [$patch, $class, $spec] = tokenWorld();

    // The aura is not in this kit, so the one-branch token contributes nothing and the
    // two-branch token in the same sentence still picks its else.
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 441829,
        'name' => 'Aggravate Wounds',
        'description' => 'Every $?a137010[Maul or Swipe]attack extends Dreadful Wounds by $?a137010[2][4] sec.',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Every attack extends Dreadful Wounds by 4 sec.');
});

test('a gender token renders a word rather than half a token', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 89808,
        'name' => 'Singe Magic',
        'description' => 'Cast upon master when $ghe:she; is unable to cast spells.',
    ]);

    // Shipped as "(varies):she;" before this.
    expect(resolveText($spell, $class, $spec))->toBe('Cast upon master when he is unable to cast spells.');
});

test('an inline description pointer is spliced in, resolved against its own spell', function () {
    [$patch, $class, $spec] = tokenWorld();

    tokenSpell($patch, $class, [
        'spell_id' => 55078,
        'name' => 'Blood Plague',
        'description' => 'Deals $s1 Shadow damage every few seconds.',
    ], [
        ['type' => 'School Damage', 'base_value' => 77, 'scaled_value' => 77],
    ]);

    // The host's own effect #1 is a different number. If the pointer were spliced in raw, the
    // inlined "$s1" would resolve against the host and confidently print 5.
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 55050,
        'name' => 'Heart Strike',
        'description' => 'Infects all enemies with Blood Plague. Blood Plague $@spelldesc55078',
    ], [
        ['type' => 'School Damage', 'base_value' => 5, 'scaled_value' => 5],
    ]);

    expect(resolveText($spell, $class, $spec))
        ->toBe('Infects all enemies with Blood Plague. Blood Plague Deals 77 Shadow damage every few seconds.');
});

test('a description pointer cycle terminates instead of leaking the token', function () {
    [$patch, $class, $spec] = tokenWorld();

    tokenSpell($patch, $class, [
        'spell_id' => 900001,
        'name' => 'Ping',
        'description' => 'Ping says $@spelldesc900002',
    ]);

    $pong = tokenSpell($patch, $class, [
        'spell_id' => 900002,
        'name' => 'Pong',
        'description' => 'Pong says $@spelldesc900001',
    ]);

    $text = resolveText($pong, $class, $spec);

    expect($text)->not->toContain('$@spelldesc')
        ->and($text)->toStartWith('Pong says Ping says');
});

test('the uppercase form keeps its own capitalisation', function () {
    [$patch, $class, $spec] = tokenWorld();

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 999005,
        'name' => 'Capital Plural',
        'description' => 'Costs $s1 $LRune:Runes;.',
    ], [
        ['type' => 'Power Cost', 'base_value' => 2, 'scaled_value' => 2],
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Costs 2 Runes.');
});

/*
 * Conditional Variables blocks — 2026-09-24. Penance is the worked example: Blizzard writes its
 * damage out in full as
 *
 *   $penancedamage=${$47666s1*$<darkside>*$<balanceofthings>*(3+$<castigation>+$<harsh>)}
 *
 * and the page said "(varies) Holy damage", because one conditional definition in the block made
 * all five untrusted.
 */
test('a conditional formula is reported as both of its readings, not one of them', function () {
    [$patch, $class, $spec] = tokenWorld();

    // The bolt: no flat value at all, its whole magnitude in the coefficient.
    tokenSpell($patch, $class, ['spell_id' => 47666, 'name' => 'Penance Bolt'], [
        ['type' => 'School Damage', 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 0.932],
    ]);
    tokenSpell($patch, $class, ['spell_id' => 198069, 'name' => 'Power of the Dark Side'], [
        ['type' => 'Dummy', 'base_value' => 30, 'scaled_value' => 30],
    ]);
    tokenSpell($patch, $class, ['spell_id' => 373180, 'name' => 'Harsh Discipline'], [
        ['type' => 'Dummy', 'base_value' => 30, 'scaled_value' => 30],
        ['type' => 'None', 'base_value' => 3, 'scaled_value' => 3],
    ]);

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 47540,
        'name' => 'Penance',
        'variables' => implode("\n", [
            '$castigation=$?a193134[${1}][${0}]',
            '$harsh=$?a373183[${$373180s2}][${0}]',
            '$darkside=$?a198069[${1+($198069s1/100)}][${1}]',
            '$penancedamage=${$47666s1*$<darkside>*(3+$<castigation>+$<harsh>)}',
        ]),
        'description' => 'Causing $<penancedamage> Holy damage.',
    ]);

    // Floor: three bolts at 93.2%. Ceiling: seven bolts at 93.2%, multiplied by 1.3.
    // 0.932 * 3 = 2.796;  0.932 * 1.3 * 7 = 8.4812.
    expect(resolveText($spell, $class, $spec))
        ->toBe('Causing ≈279.6–848.1% of Spell Power Holy damage.');
});

test('an unconditional formula resolves to a single figure', function () {
    [$patch, $class, $spec] = tokenWorld();

    tokenSpell($patch, $class, ['spell_id' => 700001, 'name' => 'Bolt'], [
        ['type' => 'School Damage', 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 0.5],
    ]);

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 700002,
        'name' => 'Steady Spell',
        'variables' => '$dmg=${$700001s1*4}',
        'description' => 'Deals $<dmg> damage.',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Deals ≈200% of Spell Power damage.');
});

test('a coefficient added to a flat amount is refused rather than mislabelled', function () {
    [$patch, $class, $spec] = tokenWorld();

    // 0.4 of Spell Power PLUS a flat 500 is not a share of anything, so there is no honest
    // percentage to print. The zero-substitution test is what catches it: with the coefficient
    // set to 0 the expression still evaluates to 500, not 0.
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 700003,
        'name' => 'Mixed Spell',
        'description' => 'Deals ${$s1+$s2} damage.',
    ], [
        ['type' => 'School Damage', 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 0.4],
        ['type' => 'Dummy', 'base_value' => 500, 'scaled_value' => 500],
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Deals (varies) damage.');
});

test('a coefficient is read off the effect named, never off a same-named sibling', function () {
    [$patch, $class, $spec] = tokenWorld();

    // The sibling carries an unrelated flat 120 on effect #1. Sibling recovery finds it and
    // would build the whole formula on a number that means nothing — this rendered Penance as
    // "≈360–1,310 Holy damage" before the ordering was fixed.
    tokenSpell($patch, $class, ['spell_id' => 700004, 'name' => 'Twin'], [
        ['type' => 'Dummy', 'base_value' => 120, 'scaled_value' => 120],
    ]);
    tokenSpell($patch, $class, ['spell_id' => 700005, 'name' => 'Twin'], [
        ['type' => 'School Damage', 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 0.932],
    ]);

    $spell = tokenSpell($patch, $class, [
        'spell_id' => 700006,
        'name' => 'Caller',
        'description' => 'Deals ${$700005s1*3} damage.',
    ]);

    expect(resolveText($spell, $class, $spec))->toBe('Deals ≈279.6% of Spell Power damage.');
});

test('a coefficient on an effect that is not damage or healing is ignored', function () {
    [$patch, $class, $spec] = tokenWorld();

    // The dump populates sp_coefficient on Taunt, Shapeshift, Change Model and Charge among
    // others. An Evoker breath read "healing ≈53.5% of Spell Power injured allies in a ≈92.2% of
    // Spell Power yd cone" off exactly this: the two tokens are a target count and a cone angle.
    $spell = tokenSpell($patch, $class, [
        'spell_id' => 700007,
        'name' => 'Breath',
        'description' => 'Hits $s1 allies in a $s2 yd cone for $s3 damage.',
    ], [
        ['type' => 'Dummy', 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 0.535],
        ['type' => 'Taunt (114)', 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 0.922],
        ['type' => 'School Damage (2): fire', 'base_value' => 0, 'scaled_value' => 0, 'sp_coefficient' => 1.5],
    ]);

    // Only the third token is a real share of Spell Power; the other two stay honest holes.
    expect(resolveText($spell, $class, $spec))
        ->toBe('Hits (varies) allies in a (varies) yd cone for ≈150% of Spell Power damage.');
});
