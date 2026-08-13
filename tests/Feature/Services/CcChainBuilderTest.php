<?php

use App\Http\Services\CcChainBuilder;
use App\Models\Game;
use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Support\Collection;

/**
 * Covers the two worked examples from the Synergies-tab plan itself (see CLAUDE.md's "Synergies
 * tab" section, step 3) — written 2026-08-11 specifically so these can't silently regress later.
 * `buildChain()` only needs plain Spell rows with dr_category/cast_type/cooldown_seconds set;
 * no class/spec/patch context is read by the builder itself, so fixtures stay minimal.
 */
function makeCcSpell(Patch $patch, int $spellId, string $name, string $drCategory, string $castType, ?float $cooldown): Spell
{
    return Spell::create([
        'patch_id' => $patch->id,
        'spell_id' => $spellId,
        'name' => $name,
        'dr_category' => $drCategory,
        'cast_type' => $castType,
        'cooldown_seconds' => $cooldown,
    ]);
}

test('RMP kill-target chain opens with Kidney Shot then Dragon\'s Breath, Polymorph before Fear, Fear last and DR\'d', function () {
    $patch = Patch::create(['game_id' => Game::create(['slug' => 'wow', 'name' => 'World of Warcraft'])->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $kidneyShot = makeCcSpell($patch, 408, 'Kidney Shot', 'Stun', 'instant', 30);
    $dragonsBreath = makeCcSpell($patch, 31661, "Dragon's Breath", 'Disorient', 'instant', 45);
    $polymorph = makeCcSpell($patch, 118, 'Polymorph', 'Incapacitate', 'instant', null);
    $fear = makeCcSpell($patch, 5782, 'Fear', 'Disorient', 'cast', null);

    $chain = (new CcChainBuilder)->buildChain(new Collection([$fear, $polymorph, $dragonsBreath, $kidneyShot]));

    expect($chain[0]['spell']->name)->toBe('Kidney Shot')
        ->and($chain[1]['spell']->name)->toBe("Dragon's Breath")
        ->and($chain[2]['spell']->name)->toBe('Polymorph')
        ->and($chain[3]['spell']->name)->toBe('Fear')
        ->and($chain[3]['dr_applied'])->toBeTrue()
        ->and($chain[3]['dr_reason'])->toContain('Disorient')
        ->and($chain[0]['dr_applied'])->toBeFalse()
        ->and($chain[1]['dr_applied'])->toBeFalse()
        ->and($chain[2]['dr_applied'])->toBeFalse()
        // DR percentage math (2026-08-11) — Kidney Shot/Dragon's Breath/Polymorph are each the
        // first occurrence of their own category (100%); Fear is the SECOND Disorient (Dragon's
        // Breath was first), so it lands at 50%, not fully immune.
        ->and($chain[0]['dr_percentage'])->toBe(100)
        ->and($chain[1]['dr_percentage'])->toBe(100)
        ->and($chain[2]['dr_percentage'])->toBe(100)
        ->and($chain[3]['dr_percentage'])->toBe(50)
        ->and($chain[3]['dr_immune'])->toBeFalse();
});

test('Hunter chain opens with Intimidation (Stun priority), then Freezing Trap, then Binding Shot DR\'d as a repeated Stun', function () {
    $patch = Patch::create(['game_id' => Game::create(['slug' => 'wow', 'name' => 'World of Warcraft'])->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $intimidation = makeCcSpell($patch, 19577, 'Intimidation', 'Stun', 'instant', 60);
    $freezingTrap = makeCcSpell($patch, 187650, 'Freezing Trap', 'Incapacitate', 'instant', 30);
    $bindingShot = makeCcSpell($patch, 109248, 'Binding Shot', 'Stun', 'instant', 45);

    // Freezing Trap (the shortest-cooldown spell) is deliberately first in the input order —
    // regression guard against the original bug (shortest-cooldown-wins-regardless-of-category
    // opener logic), which picked it as the opener instead of Intimidation. Intimidation precedes
    // Binding Shot in the input so the still-unresolved Stun/Stun opener tie (see
    // CcChainBuilder::pickOpener()'s docblock — "first in given order wins," no real rule found
    // yet) resolves the way this worked example needs; that specific tiebreak is not itself
    // being asserted as correct, only that the category-priority fix produces the right answer
    // once the tie happens to break this way.
    $chain = (new CcChainBuilder)->buildChain(new Collection([$freezingTrap, $intimidation, $bindingShot]));

    expect($chain[0]['spell']->name)->toBe('Intimidation')
        ->and($chain[1]['spell']->name)->toBe('Freezing Trap')
        ->and($chain[2]['spell']->name)->toBe('Binding Shot')
        ->and($chain[2]['dr_applied'])->toBeTrue()
        ->and($chain[2]['dr_reason'])->toContain('Stun')
        ->and($chain[0]['dr_percentage'])->toBe(100)
        ->and($chain[1]['dr_percentage'])->toBe(100)
        ->and($chain[2]['dr_percentage'])->toBe(50)
        ->and($chain[2]['dr_immune'])->toBeFalse();
});

test('DR percentage falls off 100/50 and reaches full immunity on a 3rd same-category repeat (patch 12.1: two diminished steps, not three)', function () {
    $patch = Patch::create(['game_id' => Game::create(['slug' => 'wow', 'name' => 'World of Warcraft'])->id, 'build_version' => '12.1.0', 'is_current' => true]);
    // Three Stuns and nothing else — no spacer category available, so every one after the first
    // lands back-to-back in the same category by necessity (rule 3's fallback path).
    $stuns = collect(range(1, 3))->map(fn ($i) => makeCcSpell($patch, 900 + $i, "Stun {$i}", 'Stun', 'instant', 10 * $i));

    $chain = (new CcChainBuilder)->buildChain(new Collection($stuns->all()));

    expect($chain[0]['dr_percentage'])->toBe(100)->and($chain[0]['dr_immune'])->toBeFalse()
        ->and($chain[1]['dr_percentage'])->toBe(50)->and($chain[1]['dr_immune'])->toBeFalse()
        ->and($chain[2]['dr_percentage'])->toBe(0)->and($chain[2]['dr_immune'])->toBeTrue();
});
