<?php

use App\Http\Services\TalentSelectionService;
use App\Livewire\WowComps;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use Livewire\Livewire;

/**
 * Covers the Synergies tab (2026-08-11) — WowComps::getSynergiesProperty() + the corresponding
 * blade panel. Builds two specs each with one selected, dr_category+chain_target-tagged CC spell
 * (same fixture shape as PersonalTalentBuildDisplayTest's makePersonalPickerFixture(), reused
 * rather than duplicated logic) so CcChainBuilder has real input to sequence, plus a third
 * selected spell with dr_category set but chain_target null — the "not yet classified" case that
 * must surface honestly rather than being silently dropped or guessed into a chain.
 */
function makeSynergiesSpecFixture(Patch $patch, string $className, string $specName, Spell $spell): array
{
    $class = GameClass::create(['game_id' => $patch->game_id, 'name' => $className, 'slug' => Str::slug($className)]);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => $specName, 'slug' => Str::slug($specName)]);

    $tree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id,
        'spec_id' => $spec->id, 'type' => 'spec', 'name' => $specName, 'external_tree_id' => $spec->id,
    ]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => $spec->id, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1]);

    $service = new TalentSelectionService();
    $build = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    $service->saveChoice($build, $node, $entry);
    $service->setDefault($build);

    return compact('class', 'spec');
}

test('Synergies tab sequences a kill-target chain across two comp members and flags an unclassified spell separately', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $stun = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9001, 'name' => 'Test Stun',
        'dr_category' => 'Stun', 'chain_target' => 'kill_target', 'cast_type' => 'instant', 'cooldown_seconds' => 30,
    ]);
    $disorient = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9002, 'name' => 'Test Disorient',
        'dr_category' => 'Disorient', 'chain_target' => 'kill_target', 'cast_type' => 'instant', 'cooldown_seconds' => 45,
    ]);
    $unclassified = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9003, 'name' => 'Test Unclassified Root',
        'dr_category' => 'Root', 'chain_target' => null, 'cast_type' => 'instant', 'cooldown_seconds' => 20,
    ]);

    $fixtureA = makeSynergiesSpecFixture($patch, 'Test Class A', 'Test Spec A', $stun);
    $fixtureB = makeSynergiesSpecFixture($patch, 'Test Class B', 'Test Spec B', $disorient);
    // The third slot carries both the unclassified Root AND its own stun so the kill-target
    // chain has a real reason to include this member too — proves unclassified spells are
    // filtered OUT of the chain without blocking the rest of that member's classified CC.
    $stunC = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9004, 'name' => 'Test Stun C',
        'dr_category' => 'Stun', 'chain_target' => 'kill_target', 'cast_type' => 'instant', 'cooldown_seconds' => 60,
    ]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Test Class C', 'slug' => 'test-class-c']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Test Spec C', 'slug' => 'test-spec-c']);
    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Test Spec C', 'external_tree_id' => $spec->id]);
    // Distinct pos_x so saveChoice()'s same-position-collision guard (see
    // TalentSelectionService::samePositionSiblingNodeIds()) doesn't treat these two real,
    // independent picks as a mutually-exclusive pair and clear one when the other is saved —
    // both default to null/null otherwise, which reads as "same position."
    $nodeStun = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => $spec->id * 10 + 1, 'type' => 'ACTIVE', 'max_ranks' => 1, 'pos_x' => 0, 'pos_y' => 0]);
    $entryStun = TalentNodeEntry::create(['talent_node_id' => $nodeStun->id, 'spell_id' => $stunC->id, 'rank' => 1, 'max_rank' => 1]);
    $nodeRoot = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => $spec->id * 10 + 2, 'type' => 'ACTIVE', 'max_ranks' => 1, 'pos_x' => 1, 'pos_y' => 0]);
    $entryRoot = TalentNodeEntry::create(['talent_node_id' => $nodeRoot->id, 'spell_id' => $unclassified->id, 'rank' => 1, 'max_rank' => 1]);
    $service = new TalentSelectionService();
    $buildC = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    $service->saveChoice($buildC, $nodeStun, $entryStun);
    $service->saveChoice($buildC, $nodeRoot, $entryRoot);
    $service->setDefault($buildC);

    $component = Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixtureA['class']->id, $fixtureA['spec']->id)
        ->call('selectSpec', 1, $fixtureB['class']->id, $fixtureB['spec']->id)
        ->call('selectSpec', 2, $class->id, $spec->id);

    $synergies = $component->get('synergies');

    expect($synergies['kill_target_chain'])->toHaveCount(3)
        ->and($synergies['kill_target_chain'][0]['spell']->name)->toBe('Test Stun')
        ->and($synergies['healer_chain'])->toBe([])
        ->and($synergies['unclassified']->pluck('name')->all())->toBe(['Test Unclassified Root']);

    $component->assertSee('Test Stun')
        ->assertSee('Test Disorient')
        ->assertSee('Not Yet Classified for Chaining')
        ->assertSee('Test Unclassified Root')
        ->assertOk();
});

test('Synergies tab shows an empty state for both chains when no comp member has any classified CC', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $plainSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9101, 'name' => 'Plain Ability']);
    $fixture = makeSynergiesSpecFixture($patch, 'Test Class D', 'Test Spec D', $plainSpell);

    $synergies = Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id)
        ->get('synergies');

    expect($synergies['kill_target_chain'])->toBe([])
        ->and($synergies['healer_chain'])->toBe([])
        ->and($synergies['unclassified'])->toHaveCount(0);
});

test('Synergies tab pools is_peel and is_interrupt spells independently of dr_category and renders both sections', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    // Deliberately has NO dr_category — proves peels/interrupts pooling doesn't depend on the
    // CC-chain classification at all, per WowComps::getSynergiesProperty()'s docblock.
    $peelSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9201, 'name' => 'Test Peel Root', 'is_peel' => true]);
    $interruptSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9202, 'name' => 'Test Kick', 'is_interrupt' => true]);
    // A spell that is BOTH a dr_category CC entry and a peel — Ursol's Vortex/Typhoon's real shape.
    $bothSpell = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9203, 'name' => 'Test Vortex',
        'dr_category' => 'Knockback', 'chain_target' => 'kill_target', 'cast_type' => 'instant', 'is_peel' => true,
    ]);

    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Test Class E', 'slug' => 'test-class-e']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Test Spec E', 'slug' => 'test-spec-e']);
    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Test Spec E', 'external_tree_id' => $spec->id]);
    $service = new TalentSelectionService();
    $build = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    foreach ([$peelSpell, $interruptSpell, $bothSpell] as $i => $spell) {
        $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 100 + $i, 'type' => 'ACTIVE', 'max_ranks' => 1, 'pos_x' => $i, 'pos_y' => 0]);
        $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1]);
        $service->saveChoice($build, $node, $entry);
    }
    $service->setDefault($build);

    $component = Livewire::test(WowComps::class)->call('selectSpec', 0, $class->id, $spec->id);
    $synergies = $component->get('synergies');

    expect($synergies['peels']->pluck('name')->sort()->values()->all())->toBe(['Test Peel Root', 'Test Vortex'])
        ->and($synergies['interrupts']->pluck('name')->all())->toBe(['Test Kick'])
        ->and($synergies['kill_target_chain'])->toHaveCount(1)
        ->and($synergies['kill_target_chain'][0]['spell']->name)->toBe('Test Vortex');

    $component->assertSee('Peels')->assertSee('Test Peel Root')->assertSee('Test Vortex')
        ->assertSee('Interrupts')->assertSee('Test Kick')
        ->assertOk();
});

test('chain_target=both makes a spell an independent candidate for BOTH chains at once, not a single pick between them', function () {
    // Locks in the 2026-08-11 correction: the original chain_target migration docblock claimed
    // "both" spells are "never auto-duplicated into both simultaneously" — untested at the time,
    // since no real both-tagged spell existed yet. Verified wrong once Stuns (Kidney Shot, Cheap
    // Shot, etc.) were reclassified to chain_target=both: a both spell genuinely appears in BOTH
    // chains' final output, each sequenced independently. This test proves that's real, not a bug.
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $stunA = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9301, 'name' => 'Test Stun A', 'dr_category' => 'Stun', 'chain_target' => 'both', 'cast_type' => 'instant', 'cooldown_seconds' => 20]);
    $stunB = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9302, 'name' => 'Test Stun B', 'dr_category' => 'Stun', 'chain_target' => 'both', 'cast_type' => 'instant', 'cooldown_seconds' => 40]);

    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Test Class F', 'slug' => 'test-class-f']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Test Spec F', 'slug' => 'test-spec-f']);
    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Test Spec F', 'external_tree_id' => $spec->id]);
    $service = new TalentSelectionService();
    $build = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    foreach ([$stunA, $stunB] as $i => $spell) {
        $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 200 + $i, 'type' => 'ACTIVE', 'max_ranks' => 1, 'pos_x' => $i, 'pos_y' => 0]);
        $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1]);
        $service->saveChoice($build, $node, $entry);
    }
    $service->setDefault($build);

    $synergies = Livewire::test(WowComps::class)->call('selectSpec', 0, $class->id, $spec->id)->get('synergies');

    // Both spells appear in BOTH chains, each independently sequenced (same Stun-priority/DR
    // rules CcChainBuilder always applies) — not split so each spell only lands in one chain.
    expect(collect($synergies['kill_target_chain'])->pluck('spell.name')->all())->toBe(['Test Stun A', 'Test Stun B'])
        ->and(collect($synergies['healer_chain'])->pluck('spell.name')->all())->toBe(['Test Stun A', 'Test Stun B'])
        ->and($synergies['kill_target_chain'][1]['dr_applied'])->toBeTrue()
        ->and($synergies['healer_chain'][1]['dr_applied'])->toBeTrue();
});
