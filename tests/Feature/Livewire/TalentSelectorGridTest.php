<?php

use App\Livewire\TalentSelector;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentBuildChoice;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\User;
use Livewire\Livewire;

/**
 * Covers the grid-layout addition to TalentSelector (the "Personal talent picker" feature,
 * 2026-08-10) — the positional tree rendering mode used by the WowComps/SpellExplorer modal, and
 * cycleNode(), the new click handler cycleNode() exists for: a multi-rank, non-CHOICE node (one
 * talent, several rank tiers sharing one node, e.g. Improved Fade). toggleEntry()'s own behavior
 * (single-rank nodes, CHOICE nodes) is unchanged and already covered indirectly by
 * TalentSelectionServiceTest — this file only covers what's new.
 */
function makeGridFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    $tree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id,
        'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Discipline', 'external_tree_id' => 1,
    ]);

    // A multi-rank, non-CHOICE node — the exact shape cycleNode() exists for.
    $node = TalentNode::create([
        'talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'ACTIVE',
        'max_ranks' => 2, 'pos_x' => 100, 'pos_y' => 100,
    ]);
    $spell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 601, 'name' => 'Multi Rank Talent']);
    $rank1 = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 2]);
    $rank2 = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 2, 'max_rank' => 2]);

    return compact('game', 'patch', 'class', 'spec', 'tree', 'node', 'spell', 'rank1', 'rank2');
}

test('cycleNode advances a multi-rank node through rank 1, rank 2, then clears it, persisting each step', function () {
    $fixture = makeGridFixture();
    $user = User::create(['name' => 'Cycler', 'email' => 'cycler@example.com', 'password' => bcrypt('secret')]);

    $component = Livewire::actingAs($user)
        ->test(TalentSelector::class, ['specId' => $fixture['spec']->id, 'layout' => 'grid']);

    $component->call('cycleNode', $fixture['node']->id);
    expect($component->get('chosenEntries')[$fixture['node']->id])->toBe($fixture['rank1']->id);

    $build = TalentBuild::where('user_id', $user->id)->where('spec_id', $fixture['spec']->id)->first();
    expect($build)->not->toBeNull();
    expect(
        TalentBuildChoice::where('talent_build_id', $build->id)->where('talent_node_id', $fixture['node']->id)->first()->chosen_entry_id
    )->toBe($fixture['rank1']->id);

    $component->call('cycleNode', $fixture['node']->id);
    expect($component->get('chosenEntries')[$fixture['node']->id])->toBe($fixture['rank2']->id);
    expect(
        TalentBuildChoice::where('talent_build_id', $build->id)->where('talent_node_id', $fixture['node']->id)->first()->chosen_entry_id
    )->toBe($fixture['rank2']->id);

    $component->call('cycleNode', $fixture['node']->id);
    expect($component->get('chosenEntries'))->not->toHaveKey($fixture['node']->id);
    expect(
        TalentBuildChoice::where('talent_build_id', $build->id)->where('talent_node_id', $fixture['node']->id)->exists()
    )->toBeFalse();
});

test('cycleNode does not persist anything for a guest, same as toggleEntry', function () {
    $fixture = makeGridFixture();

    Livewire::test(TalentSelector::class, ['specId' => $fixture['spec']->id, 'layout' => 'grid'])
        ->call('cycleNode', $fixture['node']->id);

    expect(TalentBuild::count())->toBe(0);
});

test('grid layout renders the positional tree partial instead of the flat list', function () {
    $fixture = makeGridFixture();

    Livewire::test(TalentSelector::class, ['specId' => $fixture['spec']->id, 'layout' => 'grid'])
        ->assertSee('Multi Rank Talent')
        // The grid partial's fixed-pixel positioning container — absent from the flat-list layout.
        ->assertSeeHtml('overflow-x-auto');

    Livewire::test(TalentSelector::class, ['specId' => $fixture['spec']->id])
        ->assertSee('Multi Rank Talent')
        ->assertDontSeeHtml('overflow-x-auto');
});

/**
 * Covers the render-time defensive filter added to getClassTalentNodesProperty() after a real
 * report (2026-08-10): the "class tree API response echoes nearly every spec node" bug
 * (CLAUDE.md's "class-tree bloat" note, 2026-08-02) had regressed in the currently-imported
 * dataset — confirmed against real data, e.g. Priest's class tree was back to its exact pre-fix
 * count of 226. This filter excludes any class-tree node whose external_node_id also appears in
 * ANY of that class's spec/hero trees (not just the one currently being viewed — the bloated
 * response bundles every spec's duplicates together, confirmed by hand against real Druid data).
 */
test('class talent nodes exclude anything duplicated from any of the class\'s spec or hero trees', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Druid', 'slug' => 'druid']);
    $viewedSpec = Specialization::create(['class_id' => $class->id, 'name' => 'Restoration', 'slug' => 'restoration']);
    $otherSpec = Specialization::create(['class_id' => $class->id, 'name' => 'Feral', 'slug' => 'feral']);

    $classTree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id, 'type' => 'class', 'name' => 'Druid', 'external_tree_id' => 10,
    ]);
    $viewedSpecTree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $viewedSpec->id,
        'type' => 'spec', 'name' => 'Restoration', 'external_tree_id' => 11,
    ]);
    $otherSpecTree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $otherSpec->id,
        'type' => 'spec', 'name' => 'Feral', 'external_tree_id' => 12,
    ]);

    // Genuinely class-wide node — must survive the filter.
    TalentNode::create(['talent_tree_id' => $classTree->id, 'external_node_id' => 900, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    // Bloated duplicate of a node from the currently-viewed spec's own tree.
    TalentNode::create(['talent_tree_id' => $classTree->id, 'external_node_id' => 901, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    TalentNode::create(['talent_tree_id' => $viewedSpecTree->id, 'external_node_id' => 901, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    // Bloated duplicate of a node from a DIFFERENT spec's tree — the case the broader,
    // all-specs comparison exists for (a same-spec-only comparison would have missed this).
    TalentNode::create(['talent_tree_id' => $classTree->id, 'external_node_id' => 902, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    TalentNode::create(['talent_tree_id' => $otherSpecTree->id, 'external_node_id' => 902, 'type' => 'ACTIVE', 'max_ranks' => 1]);

    $component = Livewire::test(TalentSelector::class, ['specId' => $viewedSpec->id, 'layout' => 'grid']);
    $shownExternalIds = $component->get('classTalentNodes')->pluck('external_node_id')->sort()->values()->all();

    expect($shownExternalIds)->toBe([900]);
});
