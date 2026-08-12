<?php

use App\Http\Services\TalentSelectionService;
use App\Livewire\SpellExplorer;
use App\Livewire\WowComps;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\User;
use Livewire\Livewire;

/**
 * Covers the core behavioral change of the "Personal talent picker" feature (2026-08-10):
 * WowComps/SpellExplorer used to hardcode each spec's admin-curated default TalentBuild for
 * every viewer. They now resolve via TalentSelectionService::resolveActiveBuild() — a signed-in
 * viewer's own saved build for a spec, if they have one, else the same admin default as before.
 * A CHOICE node with two options (one picked in the admin default, the other picked in a
 * personal build) is the clearest way to prove which build actually drove the displayed kit.
 */
function makePersonalPickerFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    $tree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id,
        'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Discipline', 'external_tree_id' => 1,
    ]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'CHOICE', 'max_ranks' => 1]);

    $defaultSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 701, 'name' => 'Admin Default Pick']);
    $defaultEntry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $defaultSpell->id, 'rank' => 1, 'max_rank' => 1]);

    $personalSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 702, 'name' => 'Personal Build Pick']);
    $personalEntry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $personalSpell->id, 'rank' => 1, 'max_rank' => 1]);

    $service = new TalentSelectionService();
    $defaultBuild = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    $service->saveChoice($defaultBuild, $node, $defaultEntry);
    $service->setDefault($defaultBuild);

    return compact('game', 'patch', 'class', 'spec', 'node', 'defaultSpell', 'defaultEntry', 'personalSpell', 'personalEntry', 'service');
}

// Both the admin-default pick and its unpicked CHOICE-node sibling always appear in the
// display list — that's choiceSiblingSpellIds()'s existing, deliberate "road not taken" feature
// (the sibling renders greyed out via isSelected=false), not something this feature changes. So
// these assertions check which name carries isSelected=true, not which names are present at all.
function isSelectedFlagFor(iterable $entries, string $name): ?bool
{
    return collect($entries)->firstWhere('spell.name', $name)['isSelected'] ?? null;
}

test('SpellExplorer marks the admin default pick as selected for a guest', function () {
    $fixture = makePersonalPickerFixture();

    $entries = Livewire::test(SpellExplorer::class)
        ->set('classId', $fixture['class']->id)
        ->set('specId', $fixture['spec']->id)
        ->get('spellReferences');

    expect(isSelectedFlagFor($entries, 'Admin Default Pick'))->toBeTrue()
        ->and(isSelectedFlagFor($entries, 'Personal Build Pick'))->toBeFalse();
});

// Regression test, 2026-08-12 — <x-spells.table>'s :description attribute used to be inlined as
// a multi-line ternary with an escaped double-quote nested inside the tag's own double-quote
// delimiter. Confirmed via direct render output that this broke Blade's component-tag compiler
// outright: <x-spells.table> passed straight through as literal, uncompiled text instead of
// rendering, so the real table markup — and every data-role="spell-row" element the page's
// client-side filter JS looks for — never existed in the DOM. Asserting on spellReferences alone
// (as the tests above do) can't catch this class of bug, since the backend property was always
// correct; only checking the actual rendered HTML proves the component compiled and rendered.
test('SpellExplorer actually renders spell-row markup in the page HTML, not just the backend property', function () {
    $fixture = makePersonalPickerFixture();

    $component = Livewire::test(SpellExplorer::class)
        ->set('classId', $fixture['class']->id)
        ->set('specId', $fixture['spec']->id);

    $entryCount = count($component->get('spellReferences'));
    expect($entryCount)->toBeGreaterThan(0);

    $component->assertSeeHtml('data-role="spell-row"')
        ->assertSeeHtml('data-role="spell-tbody"');

    expect(substr_count($component->html(), 'data-role="spell-row"'))->toBe($entryCount)
        ->and($component->html())->not->toContain('<x-spells.table');
});

test('SpellExplorer marks a signed-in viewer\'s own saved pick as selected instead of the admin default', function () {
    $fixture = makePersonalPickerFixture();
    $user = User::create(['name' => 'Picker', 'email' => 'picker@example.com', 'password' => bcrypt('secret')]);

    $userBuild = $fixture['service']->getOrCreateUserBuild($user, $fixture['spec']->id);
    $fixture['service']->saveChoice($userBuild, $fixture['node'], $fixture['personalEntry']);

    $entries = Livewire::actingAs($user)
        ->test(SpellExplorer::class)
        ->set('classId', $fixture['class']->id)
        ->set('specId', $fixture['spec']->id)
        ->get('spellReferences');

    expect(isSelectedFlagFor($entries, 'Personal Build Pick'))->toBeTrue()
        ->and(isSelectedFlagFor($entries, 'Admin Default Pick'))->toBeFalse();
});

test('WowComps marks a signed-in viewer\'s own saved pick as selected for a slot', function () {
    $fixture = makePersonalPickerFixture();
    $user = User::create(['name' => 'Picker2', 'email' => 'picker2@example.com', 'password' => bcrypt('secret')]);

    $userBuild = $fixture['service']->getOrCreateUserBuild($user, $fixture['spec']->id);
    $fixture['service']->saveChoice($userBuild, $fixture['node'], $fixture['personalEntry']);

    $ownEntries = Livewire::actingAs($user)
        ->test(WowComps::class)
        ->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id)
        ->get('comp')[0]['entries'];

    expect(isSelectedFlagFor($ownEntries, 'Personal Build Pick'))->toBeTrue()
        ->and(isSelectedFlagFor($ownEntries, 'Admin Default Pick'))->toBeFalse();
});

// Deliberately a separate test, not a second assertion appended to the one above: Livewire's
// actingAs() sets auth state on the shared underlying test case for the rest of that test
// closure, so a second Livewire::test() call in the same closure would still run authenticated.
test('WowComps marks the admin default pick as selected for a slot when no viewer is signed in', function () {
    $fixture = makePersonalPickerFixture();

    $guestEntries = Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id)
        ->get('comp')[0]['entries'];

    expect(isSelectedFlagFor($guestEntries, 'Admin Default Pick'))->toBeTrue()
        ->and(isSelectedFlagFor($guestEntries, 'Personal Build Pick'))->toBeFalse();
});
