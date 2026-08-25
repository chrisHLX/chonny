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
 * Covers the Synergies tab, redesigned several times on 2026-08-16 — most recently (this file's
 * current shape) unified to a single `WowComps::GROUP_CATEGORIES`-driven design: two plain
 * groupings, "Diminishing Returns Groups" (Stun/Silence/Incapacitate/Disorient) and "Utility"
 * (Knockback/Disarm/Slow/Root), neither run through CcChainBuilder — no sequencing, no
 * dr_applied/dr_percentage/dr_immune at all. `getSynergiesProperty()` returns
 * `groups: array<string label, Collection<Spell>>`, each spell ordered by GROUP_CATEGORIES's own
 * fixed category order then alphabetically by name — the category itself renders as a badge on
 * each spell's own card in the blade, not a group sub-heading. Same fixture shape as
 * PersonalTalentBuildDisplayTest's makePersonalPickerFixture(), reused rather than duplicated
 * logic.
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

test('Synergies tab groups CC under "Diminishing Returns Groups" and "Utility", ordered by category, with no chain/DR-percentage data', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    // Disorient created/selected before Stun, to prove ordering follows GROUP_CATEGORIES's fixed
    // category order (Stun, Silence, Incapacitate, Disorient) rather than selection order.
    $disorient = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9002, 'name' => 'Test Disorient',
        'dr_category' => 'Disorient', 'cast_type' => 'instant', 'cooldown_seconds' => 45,
    ]);
    $stun = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9001, 'name' => 'Test Stun',
        'dr_category' => 'Stun', 'cast_type' => 'instant', 'cooldown_seconds' => 30,
    ]);
    // Root — one of the four "Utility" categories, must land in the separate Utility group.
    $root = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9003, 'name' => 'Test Root',
        'dr_category' => 'Root', 'cast_type' => 'instant', 'cooldown_seconds' => 20,
    ]);

    $fixtureA = makeSynergiesSpecFixture($patch, 'Test Class A', 'Test Spec A', $stun);
    $fixtureB = makeSynergiesSpecFixture($patch, 'Test Class B', 'Test Spec B', $disorient);

    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Test Class C', 'slug' => 'test-class-c']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Test Spec C', 'slug' => 'test-spec-c']);
    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Test Spec C', 'external_tree_id' => $spec->id]);
    $nodeRoot = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => $spec->id * 10 + 2, 'type' => 'ACTIVE', 'max_ranks' => 1, 'pos_x' => 1, 'pos_y' => 0]);
    $entryRoot = TalentNodeEntry::create(['talent_node_id' => $nodeRoot->id, 'spell_id' => $root->id, 'rank' => 1, 'max_rank' => 1]);
    $service = new TalentSelectionService();
    $buildC = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    $service->saveChoice($buildC, $nodeRoot, $entryRoot);
    $service->setDefault($buildC);

    $component = Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixtureA['class']->id, $fixtureA['spec']->id)
        ->call('selectSpec', 1, $fixtureB['class']->id, $fixtureB['spec']->id)
        ->call('selectSpec', 2, $class->id, $spec->id);

    $synergies = $component->get('synergies');
    $groups = $synergies['groups'];

    expect(array_keys($groups))->toBe(['Diminishing Returns Groups', 'Utility'])
        // Stun before Disorient — GROUP_CATEGORIES order, not selection/creation order.
        ->and($groups['Diminishing Returns Groups']->pluck('name')->all())->toBe(['Test Stun', 'Test Disorient'])
        ->and($groups['Utility']->pluck('name')->all())->toBe(['Test Root'])
        ->and($groups['Diminishing Returns Groups']->first())->toBeInstanceOf(Spell::class);

    $component->assertSee('Test Stun')
        ->assertSee('Test Disorient')
        ->assertSee('Test Root')
        ->assertSee('Diminishing Returns Groups')
        ->assertSee('Utility')
        ->assertDontSee('DR Immune')
        ->assertOk();
});

test('Synergies tab shows an empty state for both groups when no comp member has any classified CC', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $plainSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9101, 'name' => 'Plain Ability']);
    $fixture = makeSynergiesSpecFixture($patch, 'Test Class D', 'Test Spec D', $plainSpell);

    $synergies = Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id)
        ->get('synergies');

    expect($synergies['groups']['Diminishing Returns Groups'])->toHaveCount(0)
        ->and($synergies['groups']['Utility'])->toHaveCount(0);
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
        'dr_category' => 'Knockback', 'cast_type' => 'instant', 'is_peel' => true,
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
        ->and($synergies['groups']['Utility']->pluck('name')->all())->toBe(['Test Vortex'])
        ->and($synergies['groups']['Diminishing Returns Groups'])->toHaveCount(0);

    $component->assertSee('Peels')->assertSee('Test Peel Root')->assertSee('Test Vortex')
        ->assertSee('Interrupts')->assertSee('Test Kick')
        ->assertOk();
});

test('a dr_category not covered by either known bucket still gets its own trailing group, not silently dropped', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $novel = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9401, 'name' => 'Test Novel CC',
        'dr_category' => 'Banish', 'cast_type' => 'instant', 'cooldown_seconds' => 30,
    ]);
    $fixture = makeSynergiesSpecFixture($patch, 'Test Class G', 'Test Spec G', $novel);

    $component = Livewire::test(WowComps::class)->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id);
    $synergies = $component->get('synergies');

    expect(array_keys($synergies['groups']))->toBe(['Diminishing Returns Groups', 'Utility', 'Banish'])
        ->and($synergies['groups']['Banish']->pluck('name')->all())->toBe(['Test Novel CC']);

    $component->assertSee('Test Novel CC')->assertSee('Banish')->assertOk();
});

test('a spell owned by a comp member renders that member\'s class name colored with the class\'s real color', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $stun = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9501, 'name' => 'Test Owner Stun',
        'dr_category' => 'Stun', 'cast_type' => 'instant', 'cooldown_seconds' => 30,
    ]);
    // Use a real class slug so config('wow_classes.colors') actually resolves a color.
    $fixture = makeSynergiesSpecFixture($patch, 'Rogue', 'Test Owner Spec', $stun);

    $rogueColor = config('wow_classes.colors')['rogue'] ?? null;
    expect($rogueColor)->not->toBeNull();

    Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id)
        ->assertSee('color: '.$rogueColor, false);
});

test('a Synergies-tab CC card and a peel/interrupt entry both wire up the same click-to-detail modal trigger used on Active Abilities', function () {
    // The spell-detail modal block near the bottom of wow-comps.blade.php already iterates every
    // $comp member's full entry list (Active Abilities, Main Cooldowns, and Synergies-tab spells
    // alike) and keys each hidden content block "m{memberIndex}-s{spellId}" — this test locks in
    // that the Synergies tab's own cards/badges set openSpellId to that exact same key on click,
    // rather than needing separate modal content of their own.
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $stun = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 9601, 'name' => 'Test Clickable Stun',
        'dr_category' => 'Stun', 'cast_type' => 'instant', 'cooldown_seconds' => 30,
    ]);
    $interrupt = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9602, 'name' => 'Test Clickable Kick', 'is_interrupt' => true]);

    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Test Class H', 'slug' => 'test-class-h']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Test Spec H', 'slug' => 'test-spec-h']);
    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Test Spec H', 'external_tree_id' => $spec->id]);
    $service = new TalentSelectionService();
    $build = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    foreach ([$stun, $interrupt] as $i => $spell) {
        $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 300 + $i, 'type' => 'ACTIVE', 'max_ranks' => 1, 'pos_x' => $i, 'pos_y' => 0]);
        $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1]);
        $service->saveChoice($build, $node, $entry);
    }
    $service->setDefault($build);

    Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $class->id, $spec->id)
        ->assertSeeHtml("openSpellId = 'm0-s{$stun->id}'")
        ->assertSeeHtml("openSpellId = 'm0-s{$interrupt->id}'")
        // The bottom modal block's own hidden-content x-show for that same key must also be
        // present — proves no separate modal markup was needed for the Synergies tab.
        ->assertSeeHtml("openSpellId === 'm0-s{$stun->id}'");
});

test('the DR-category icon legend shows Blizzard\'s real Stun icon (Concussive Shot), not Cheap Shot', function () {
    // Regression test for the 2026-08-24 fix — the original 2026-08-22 legend picked "most
    // recognizable curated spell per category" (Cheap Shot for Stun) rather than what Blizzard's
    // own client actually displays. Confirmed via an official Blizzard forums thread and the
    // user's own in-game check: the real Stun DR icon is Concussive Shot's (spell_id 5116).
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $concussiveShot = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 5116, 'name' => 'Concussive Shot',
        'icon_name' => 'spell_frost_stun.jpg', 'dr_category' => 'Slow',
    ]);
    // A same-name-but-wrong-id "Cheap Shot" row proves the legend isn't just matching by name.
    Spell::create(['patch_id' => $patch->id, 'spell_id' => 1833, 'name' => 'Cheap Shot', 'icon_name' => 'ability_cheapshot.jpg', 'dr_category' => 'Stun']);

    $component = new WowComps();
    $legend = collect($component->getDrCategoryLegendProperty());

    $stunEntry = $legend->firstWhere('category', 'Stun');
    expect($stunEntry['spell'])->not->toBeNull()
        ->and($stunEntry['spell']->id)->toBe($concussiveShot->id)
        ->and($stunEntry['spell']->spell_id)->toBe(5116);

    $rootEntry = $legend->firstWhere('category', 'Root');
    expect($rootEntry['spell'])->toBeNull(); // Entangling Roots (339) not seeded in this fixture — legend degrades gracefully, not an error.

    // Disorient (2026-08-24) — a raw self-hosted icon filename, no backing spell exists at all.
    $disorientEntry = $legend->firstWhere('category', 'Disorient');
    expect($disorientEntry['spell'])->toBeNull()
        ->and($disorientEntry['iconUrl'])->toBe('/storage/spell-icons/spell_holy_dizzy.jpg');
});

test('getSynergiesProperty lists an unchosen CHOICE-node CC sibling under "excluded", not the chosen one', function () {
    // Added 2026-08-25, direct request — a "Not Selected" block on the Crowd Control tab
    // showing which CC a comp is passing up. Uses a real CHOICE node (two dr_category-tagged
    // spells, one entry picked) so the excluded entry comes from the app's real "always show
    // every talent, isSelected flags which one" pipeline, not a hand-built fixture shortcut.
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Test Class I', 'slug' => 'test-class-i']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Test Spec I', 'slug' => 'test-spec-i']);

    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Test Spec I', 'external_tree_id' => $spec->id]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 900, 'type' => 'CHOICE', 'max_ranks' => 1]);

    $chosen = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9701, 'name' => 'Chosen Stun', 'dr_category' => 'Stun']);
    $notChosen = Spell::create(['patch_id' => $patch->id, 'spell_id' => 9702, 'name' => 'Passed-Over Silence', 'dr_category' => 'Silence']);
    $chosenEntry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $chosen->id, 'rank' => 1, 'max_rank' => 1]);
    TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $notChosen->id, 'rank' => 1, 'max_rank' => 1]);

    $service = new TalentSelectionService();
    $build = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    $service->saveChoice($build, $node, $chosenEntry);
    $service->setDefault($build);

    $component = Livewire::test(WowComps::class)->call('selectSpec', 0, $class->id, $spec->id);
    $synergies = $component->get('synergies');

    $excludedNames = $synergies['excluded']->pluck('spell.name');
    expect($excludedNames->contains('Passed-Over Silence'))->toBeTrue()
        ->and($excludedNames->contains('Chosen Stun'))->toBeFalse();

    // The chosen spell is in the normal DR groups, not excluded.
    expect($synergies['groups']['Diminishing Returns Groups']->pluck('name')->contains('Chosen Stun'))->toBeTrue();

    $excludedRow = $synergies['excluded']->firstWhere('spell.name', 'Passed-Over Silence');
    expect($excludedRow['label'])->toBe('Test Spec I Test Class I')
        ->and($excludedRow['mi'])->toBe(0);

    $component->assertSee('Not Selected')->assertSee('Passed-Over Silence');
});
