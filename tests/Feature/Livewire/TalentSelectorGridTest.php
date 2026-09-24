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

test('a guest on a read-only talent view cannot turn it into the admin default-build editor', function () {
    // The request a scanner (or anyone) could craft on the public Burst Window / signed-guide
    // talent views before 2026-09-13: flip readOnly off and isDefaultEditor on, then click a node.
    // persistIfAuthenticated() skips its sign-in check for the default editor, so this used to
    // write into the spec's admin default build — the one WoW Comps and Spell Explorer show
    // everyone. Each of these flags is now #[Locked], so the first write is refused outright.
    $fixture = makeGridFixture();

    $component = Livewire::test(TalentSelector::class, [
        'specId' => $fixture['spec']->id, 'layout' => 'grid', 'readOnly' => true,
    ]);

    foreach (['readOnly' => false, 'isDefaultEditor' => true] as $prop => $value) {
        try {
            $component->set($prop, $value);
        } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
            // expected
        }
    }

    $component->call('toggleEntry', $fixture['node']->id, $fixture['rank1']->id);

    expect(TalentBuild::count())->toBe(0)
        ->and(TalentBuildChoice::count())->toBe(0)
        ->and($component->get('readOnly'))->toBeTrue()
        ->and($component->get('isDefaultEditor'))->toBeFalse();
});

/*
 * The points-spent counter was one click behind — 2026-09-25, from a real report of "an incorrect
 * amount of talents selected".
 *
 * toggleEntry() asks isNodeLocked() whether a fresh pick is allowed BEFORE it writes the pick.
 * That populated rankByNodeId()'s per-instance cache from the pre-click state, and render() then
 * counted points and evaluated gate locks off the same stale map. The counter only caught up on
 * the NEXT interaction.
 */
test('the points-spent counter reflects the click that just happened, not the one before it', function () {
    $fixture = makeGridFixture();

    $component = Livewire::test(TalentSelector::class, ['specId' => $fixture['spec']->id, 'layout' => 'grid']);

    expect($component->instance()->specPointsSpent)->toBe(0);

    $component->call('toggleEntry', $fixture['node']->id, $fixture['rank1']->id);
    expect($component->instance()->specPointsSpent)->toBe(1);

    // Rank 2 is worth two points in the tree's own accounting, and it must land on this call.
    $component->call('cycleNode', $fixture['node']->id);
    expect($component->instance()->specPointsSpent)->toBe(2);

    // Clearing has to come back down on the same interaction too.
    $component->call('toggleEntry', $fixture['node']->id, $fixture['rank2']->id);
    expect($component->instance()->specPointsSpent)->toBe(0);
});

/*
 * The spec tree's point budget — 2026-09-25.
 *
 * Nothing used to cap or even show a budget, so a guide author could pour forty points into a
 * thirty-four point tree and the counter just counted up. 34 is observed, not looked up: every
 * level-90 character synced to the site shows exactly that many summed ranks in its spec tree,
 * across all twelve classes. The class tree deliberately has no budget — see
 * config/talent_gates.php for why one cannot be derived honestly.
 */
test('the spec tree shows its budget, and says so when a build goes past it', function () {
    $fixture = makeGridFixture();

    config(['talent_gates.budgets.spec' => 1]);

    $component = Livewire::test(TalentSelector::class, ['specId' => $fixture['spec']->id, 'layout' => 'grid']);
    $component->assertSee('0 / 1 points spent');

    $component->call('toggleEntry', $fixture['node']->id, $fixture['rank1']->id)
        ->assertSee('1 / 1 point spent')
        ->assertDontSee('(over)');

    // Rank 2 is two points in a one-point budget: shown, not blocked. The picker does not model
    // auto-granted talents yet, so refusing the click could refuse a legal one.
    $component->call('cycleNode', $fixture['node']->id)
        ->assertSee('2 / 1 points spent')
        ->assertSee('(over)');
});

test('a tree with no budget prints a bare count, with nothing to compare it against', function () {
    $fixture = makeGridFixture();

    // The class tree is the real case: no source states its total, and the ranks we can observe
    // include auto-granted talents by a class-specific amount this schema does not record.
    expect(config('talent_gates.budgets.class'))->toBeNull();

    config(['talent_gates.budgets.spec' => null]);

    Livewire::test(TalentSelector::class, ['specId' => $fixture['spec']->id, 'layout' => 'grid'])
        // "0 points spent", not "0 / N points spent" — the label is built in one piece, so the
        // bare form appearing at all is what proves no budget was printed for this tree.
        ->assertSee('0 points spent');
});

test('the final class-tree gate is the Midnight number, not the retired one', function () {
    // Blizzard's own Midnight announcement: "the point requirement to unlock the final node will
    // be increasing from twenty to twenty-three". The 20 carried over from the prior expansion
    // and was flagged unverified in config the whole time.
    expect(collect(config('talent_gates.gates'))->firstWhere('display_row', 8)['points_required'])->toBe(23);
});
