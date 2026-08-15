<?php

use App\Http\Services\TalentSelectionService;
use App\Livewire\TalentSelector;
use App\Livewire\WowComps;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\User;
use Livewire\Livewire;

/**
 * PersonalTalentBuildDisplayTest covers "given an already-saved build, does the page display it
 * correctly" — it never actually drives TalentSelector::toggleEntry() itself. This test covers
 * the gap: does clicking a talent in the picker modal actually persist, and does WowComps's own
 * Spells table (spellReferencesFor()) pick it up afterward — the literal flow a real user drives
 * through the modal. Added 2026-08-15 after a live report that picker changes "don't seem to
 * register."
 */
test('clicking a talent in TalentSelector persists to the same build resolveActiveBuild() finds, not a new one', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    $tree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id,
        'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Discipline', 'external_tree_id' => 1,
    ]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    $spellA = Spell::create(['patch_id' => $patch->id, 'spell_id' => 701, 'name' => 'Pick A']);
    $entryA = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spellA->id, 'rank' => 1, 'max_rank' => 1]);

    $user = User::create(['name' => 'Clicker', 'email' => 'clicker@example.com', 'password' => bcrypt('secret')]);

    $service = app(TalentSelectionService::class);

    // 1. Pre-existing build for this user+spec, created BEFORE the picker is ever opened —
    //    mirrors a real returning user who already has a saved build (e.g. seeded from the
    //    admin default the first time they visited).
    $existingBuild = $service->getOrCreateUserBuild($user, $spec->id);
    expect($existingBuild->exists)->toBeTrue();
    $existingBuildId = $existingBuild->id;

    // 2. Drive the actual click, authenticated as that same user — this is what the modal does.
    Livewire::actingAs($user)
        ->test(TalentSelector::class, ['specId' => $spec->id, 'layout' => 'grid'])
        ->call('toggleEntry', $node->id, $entryA->id);

    // 3. The click must have landed on the SAME build resolveActiveBuild() already knew about —
    //    not a second, orphaned build for the same (user, spec) pair.
    $resolvedAfter = $service->resolveActiveBuild($user, $spec->id);
    expect($resolvedAfter->id)->toBe($existingBuildId);

    $choice = $resolvedAfter->choices()->where('talent_node_id', $node->id)->first();
    expect($choice)->not->toBeNull()
        ->and($choice->chosen_entry_id)->toBe($entryA->id);

    // 4. And WowComps's own Spells table — the thing the user is actually looking at — must
    //    reflect it too.
    $entries = Livewire::actingAs($user)
        ->test(WowComps::class)
        ->call('selectSpec', 0, $class->id, $spec->id)
        ->get('comp')[0]['entries'];

    $entry = collect($entries)->firstWhere('spell.name', 'Pick A');
    expect($entry)->not->toBeNull()
        ->and($entry['isSelected'])->toBeTrue();
});
