<?php

use App\Livewire\CcReview;
use App\Livewire\SpellDetailModal;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\Specialization;
use Livewire\Livewire;

/**
 * Written 2026-08-11 after building SpellDetailModal — four real bugs surfaced getting this
 * working (Collection::offsetGet() doesn't support chained nested mutation; render() must
 * explicitly pass computed properties and public properties both, neither auto-injects
 * reliably; a Livewire component's root Blade element must always render even when its content
 * is conditionally empty, or Livewire throws "missing root tag"; modifiersFor()'s raw output
 * has no description/category/cooldown per modifier — WowComps' own enrichModifiers() step had
 * to be replicated, not just its blade template). Locking all four in.
 *
 * Uses its own fixtures rather than real imported game data — the test DB is a fresh
 * RefreshDatabase schema with no spells at all (those come from `import:spelldata`, never a
 * seeder), a mistake the first version of this file made and had to fix.
 */
function makeModalFixtureSpell(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Outlaw', 'slug' => 'outlaw']);

    $spell = Spell::create([
        'patch_id' => $patch->id,
        'spell_id' => 408,
        'name' => 'Kidney Shot',
        'dr_category' => 'Stun',
        'cast_type' => 'instant',
        'cooldown_seconds' => 30,
    ]);

    SpellClassAvailability::create([
        'spell_id' => $spell->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'baseline',
    ]);

    return compact('spell', 'class', 'spec');
}

test('CC review page loads and renders spells grouped by class and spec', function () {
    $this->get('/cc-review')->assertOk();
});

test('SpellDetailModal computes a full entry with spec context, including enriched modifiers', function () {
    $fixture = makeModalFixtureSpell();

    Livewire::test(SpellDetailModal::class)
        ->call('show', $fixture['spell']->id, $fixture['class']->id, $fixture['spec']->id)
        ->assertSet('spellId', $fixture['spell']->id)
        ->assertSee('Kidney Shot')
        ->assertOk();
});

test('SpellDetailModal renders without a spec context — shows base values, no crash', function () {
    $fixture = makeModalFixtureSpell();

    Livewire::test(SpellDetailModal::class)
        ->call('show', $fixture['spell']->id, null, null)
        ->assertSee('Kidney Shot')
        ->assertSee('No spec context')
        ->assertOk();
});

test('SpellDetailModal renders its root element even when closed, so Livewire never loses its wire:id', function () {
    Livewire::test(SpellDetailModal::class)
        ->assertSet('spellId', null)
        ->assertOk();
});

test('closing the modal clears spellId, classId, and specId together', function () {
    $fixture = makeModalFixtureSpell();

    Livewire::test(SpellDetailModal::class)
        ->call('show', $fixture['spell']->id, $fixture['class']->id, $fixture['spec']->id)
        ->call('close')
        ->assertSet('spellId', null)
        ->assertSet('classId', null)
        ->assertSet('specId', null);
});
