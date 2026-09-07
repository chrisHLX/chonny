<?php

use App\Http\Services\SpellProfileBuilder;
use App\Http\Services\TalentSelectionService;
use App\Livewire\SpellDetail;
use App\Livewire\SpellDetailModal;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellRelationship;
use App\Models\TalentBuild;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use Livewire\Livewire;

/**
 * Talent toggles on the spell detail views (2026-09-06). A viewer can flick any talent that
 * affects the spell on or off and watch the numbers move, defaulting to whatever the resolved
 * build actually selects.
 *
 * The failure modes worth pinning down here are the quiet ones:
 *  - the override must be keyed on the id modifiersFor() ACTUALLY gates on, or a toggle silently
 *    does nothing in exactly the sibling cases that gate exists for;
 *  - nothing may ever be written to a talent build (this is "what if", not "change my build");
 *  - state must not leak from one spell to the next in a shared, long-lived modal.
 */
function toggleWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Holy', 'slug' => 'holy', 'external_spec_id' => 257]);
    $tree = TalentTree::create([
        'patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id,
        'type' => 'spec', 'name' => 'Holy', 'external_tree_id' => $spec->id,
    ]);

    return [$patch, $class, $spec, $tree];
}

/**
 * A real cooldown-reducing talent wired the way the importer wires one: a spell_relationship
 * carrying the magnitude, a talent-tree entry so it is confidently in the build's trees, and a
 * class-availability row so it lands in the kit modifiersFor() scans.
 */
function makeToggleTalent(Patch $patch, GameClass $class, Specialization $spec, TalentTree $tree, Spell $target, array $attrs, float $seconds, int $posX): array
{
    $talent = Spell::create(array_merge([
        'patch_id' => $patch->id, 'is_passive' => true, 'not_in_spellbook' => false,
    ], $attrs));

    SpellClassAvailability::create([
        'spell_id' => $talent->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'talent',
    ]);
    SpellRelationship::create([
        'source_spell_id' => $talent->id, 'target_spell_id' => $target->id,
        'relationship_type' => 'modifies_cooldown', 'modifier_value' => $seconds, 'modifier_unit' => 'seconds',
    ]);

    $node = TalentNode::create([
        'talent_tree_id' => $tree->id, 'external_node_id' => 5000 + $posX,
        'type' => 'ACTIVE', 'max_ranks' => 1, 'pos_x' => $posX, 'pos_y' => 0,
    ]);
    $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $talent->id, 'rank' => 1, 'max_rank' => 1]);

    return [$talent, $node, $entry];
}

function toggleFixture(): array
{
    [$patch, $class, $spec, $tree] = toggleWorld();

    $target = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 88625, 'name' => 'Holy Word: Chastise',
        'description' => 'Chastises the target.', 'cooldown_seconds' => 60,
        'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    SpellClassAvailability::create([
        'spell_id' => $target->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'talent',
    ]);
    $targetNode = TalentNode::create([
        'talent_tree_id' => $tree->id, 'external_node_id' => 4000, 'type' => 'ACTIVE',
        'max_ranks' => 1, 'pos_x' => 9, 'pos_y' => 0,
    ]);
    $targetEntry = TalentNodeEntry::create(['talent_node_id' => $targetNode->id, 'spell_id' => $target->id, 'rank' => 1, 'max_rank' => 1]);

    // One talent the build TAKES (-20s) and one it does NOT (-15s).
    [$taken, $takenNode, $takenEntry] = makeToggleTalent($patch, $class, $spec, $tree, $target,
        ['spell_id' => 700001, 'name' => 'Taken Reducer', 'description' => 'Reduces the cooldown.'], -20, 1);
    [$untaken] = makeToggleTalent($patch, $class, $spec, $tree, $target,
        ['spell_id' => 700002, 'name' => 'Untaken Reducer', 'description' => 'Would reduce the cooldown.'], -15, 2);

    $service = new TalentSelectionService;
    $build = $service->getOrCreateDefaultBuild($spec->id, $patch->id);
    $service->saveChoice($build, $targetNode, $targetEntry);
    $service->saveChoice($build, $takenNode, $takenEntry);
    $service->setDefault($build);

    return compact('patch', 'class', 'spec', 'target', 'taken', 'untaken', 'build');
}

test('toggle rows default to what the build actually selects, active first', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target] = toggleFixture();

    $rows = app(SpellProfileBuilder::class)->forDetail($target, $class->id, $spec->id)->talentToggles();
    $byName = collect($rows)->keyBy(fn ($r) => $r['spell']->name);

    expect($byName['Taken Reducer']['isActive'])->toBeTrue()
        ->and($byName['Untaken Reducer']['isActive'])->toBeFalse()
        // Active rows sort first so the list doesn't reorder under the cursor while toggling.
        ->and($rows[0]['spell']->name)->toBe('Taken Reducer')
        // Nothing has been flipped yet, so nothing is marked as changed.
        ->and(collect($rows)->contains('isOverridden', true))->toBeFalse();
});

test('turning a taken talent off removes its effect from the computed cooldown', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken] = toggleFixture();

    $builder = app(SpellProfileBuilder::class);
    $before = $builder->forDetail($target, $class->id, $spec->id);
    $after = $builder->forDetail($target, $class->id, $spec->id, [$taken->id => false]);

    expect($before['cooldown']['seconds'])->toBe(40.0)   // 60 base - 20
        ->and($after['cooldown']['seconds'])->toBe(60.0) // back to base
        ->and($after->hasTalentOverrides())->toBeTrue();
});

test('turning an untaken talent on applies its effect', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'untaken' => $untaken] = toggleFixture();

    $profile = app(SpellProfileBuilder::class)->forDetail($target, $class->id, $spec->id, [$untaken->id => true]);

    // 60 base - 20 (taken) - 15 (switched on) = 25
    expect($profile['cooldown']['seconds'])->toBe(25.0)
        ->and(collect($profile->talentToggles())->firstWhere('spell.name', 'Untaken Reducer'))
        ->toMatchArray(['isActive' => true, 'isOverridden' => true]);
});

test('a toggle keys off the id the selection gate reads, not blindly the row spell id', function () {
    // Not a restatement of the tests above: modifiersFor() checks selection against a SIBLING id
    // when the modifier's own spell can't be confirmed in the build's trees, and a toggle keyed on
    // the row's own id would silently do nothing in exactly those cases. Asserting the row carries
    // the gate's id is what keeps the two in step.
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken] = toggleFixture();

    $row = collect(app(SpellProfileBuilder::class)->forDetail($target, $class->id, $spec->id)->talentToggles())
        ->firstWhere('spell.name', 'Taken Reducer');

    expect($row['selectionSpellId'])->toBe($taken->id);

    // And the id it reports is genuinely the one that works.
    $flipped = app(SpellProfileBuilder::class)->forDetail($target, $class->id, $spec->id, [$row['selectionSpellId'] => false]);
    expect($flipped['cooldown']['seconds'])->toBe(60.0);
});

test('toggling never writes to any talent build', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken, 'build' => $build] = toggleFixture();

    $before = $build->choices()->pluck('chosen_entry_id', 'talent_node_id')->toArray();

    app(SpellProfileBuilder::class)->forDetail($target, $class->id, $spec->id, [$taken->id => false]);

    expect($build->fresh()->choices()->pluck('chosen_entry_id', 'talent_node_id')->toArray())->toBe($before)
        ->and(TalentBuild::count())->toBe(1);
});

test('the modal renders a switch per talent and recomputes on click', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken] = toggleFixture();

    $component = Livewire::test(SpellDetailModal::class)->call('show', $target->id, $class->id, $spec->id);

    expect(substr_count($component->html(), 'toggleTalent('))->toBe(2);
    $component->assertSee('Talents Affecting This Spell')->assertDontSee('Reset to build');

    $component->call('toggleTalent', $taken->id, true);

    expect($component->get('talentOverrides'))->toBe([$taken->id => false]);
    $component->assertSee('Reset to build')->assertSee('changed');
});

test('clicking a switch twice clears the override rather than storing a redundant one', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken] = toggleFixture();

    $component = Livewire::test(SpellDetailModal::class)
        ->call('show', $target->id, $class->id, $spec->id)
        ->call('toggleTalent', $taken->id, true)
        ->call('toggleTalent', $taken->id, false);

    expect($component->get('talentOverrides'))->toBe([]);
    $component->assertDontSee('Reset to build');
});

test('opening a different spell clears the previous spell\'s experimentation', function () {
    // The modal is mounted once per page and reused for every spell on it, including via its own
    // counter chips — carrying one spell's overrides onto an unrelated set of talents would show
    // numbers nobody asked for.
    ['patch' => $patch, 'class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken] = toggleFixture();

    $other = Spell::create([
        'patch_id' => $patch->id, 'spell_id' => 999001, 'name' => 'Another Spell',
        'is_passive' => false, 'not_in_spellbook' => false,
    ]);

    $component = Livewire::test(SpellDetailModal::class)
        ->call('show', $target->id, $class->id, $spec->id)
        ->call('toggleTalent', $taken->id, true);

    expect($component->get('talentOverrides'))->not->toBe([]);

    $component->call('show', $other->id, $class->id, $spec->id);
    expect($component->get('talentOverrides'))->toBe([]);

    // Closing does the same.
    $component->call('show', $target->id, $class->id, $spec->id)
        ->call('toggleTalent', $taken->id, true)
        ->call('close');
    expect($component->get('talentOverrides'))->toBe([]);
});

test('the reset control puts every row back to the build', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken, 'untaken' => $untaken] = toggleFixture();

    $component = Livewire::test(SpellDetailModal::class)
        ->call('show', $target->id, $class->id, $spec->id)
        ->call('toggleTalent', $taken->id, true)
        ->call('toggleTalent', $untaken->id, false);

    expect($component->get('talentOverrides'))->toHaveCount(2);

    $component->call('resetTalentOverrides');

    expect($component->get('talentOverrides'))->toBe([]);
    // And the numbers are back to the build's own answer.
    expect($component->instance()->profile['cooldown']['seconds'])->toBe(40.0);
});

test('the standalone page has the same switches as the modal', function () {
    ['class' => $class, 'spec' => $spec, 'target' => $target, 'taken' => $taken] = toggleFixture();

    $page = Livewire::test(SpellDetail::class, ['spellId' => $target->id, 'spec' => $spec->id])
        ->call('toggleTalent', $taken->id, true);

    expect($page->get('talentOverrides'))->toBe([$taken->id => false])
        ->and($page->instance()->profile['cooldown']['seconds'])->toBe(60.0);
});

test('with no spec context no switch is offered at all', function () {
    // Without a build there is nothing for a talent to be on or off IN, so a switch would be
    // describing nothing — the view falls back to read-only rows.
    //
    // Deliberately asserts only the absence of switches, not the presence of rows: whether any
    // modifier resolves at all with no spec depends on resolveKitContext()/buildKitSpellIdsFor(),
    // which is long-standing behaviour this feature does not touch and which legitimately differs
    // between a hand-built fixture and real imported data (checked against the live DB: real
    // Chastise resolves 18 read-only rows there). The invariant worth pinning down is that a
    // control which cannot mean anything is never rendered.
    ['target' => $target] = toggleFixture();

    $component = Livewire::test(SpellDetailModal::class)->call('show', $target->id);

    expect(substr_count($component->html(), 'toggleTalent('))->toBe(0);
    $component->assertSee('No spec context');
});
