<?php

use App\Livewire\Admin\PageUsage;
use App\Livewire\SpellExplorer;
use App\Livewire\WowComps;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use Livewire\Livewire;

/**
 * Covers PageViewEvent tracking added to SpellExplorer/WowComps and its aggregation in
 * Admin\PageUsage — see CLAUDE.md and this session's diagnostic-stats fix for why "landed here
 * by default" and "actually picked this" are kept as two distinct counts throughout.
 */
function makePageUsageFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);

    $priest = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $discipline = Specialization::create(['class_id' => $priest->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    $warrior = GameClass::create(['game_id' => $game->id, 'name' => 'Warrior', 'slug' => 'warrior']);
    $arms = Specialization::create(['class_id' => $warrior->id, 'name' => 'Arms', 'slug' => 'arms']);

    return compact('priest', 'discipline', 'warrior', 'arms');
}

test('spell explorer mount records a bare view, not attributed to the default class', function () {
    $fixture = makePageUsageFixture(); // Priest sorts before Warrior alphabetically — the default

    Livewire::test(SpellExplorer::class);

    expect(PageViewEvent::where('page', 'spell_explorer')->count())->toBe(1);
    $event = PageViewEvent::where('page', 'spell_explorer')->first();
    expect($event->class_id)->toBeNull()
        ->and($event->spec_id)->toBeNull();
});

test('spell explorer records a real selection when the class is explicitly changed', function () {
    $fixture = makePageUsageFixture();

    Livewire::test(SpellExplorer::class)
        ->set('classId', $fixture['warrior']->id);

    $selection = PageViewEvent::where('page', 'spell_explorer')->whereNotNull('class_id')->first();

    expect($selection)->not->toBeNull()
        ->and($selection->class_id)->toBe($fixture['warrior']->id)
        ->and($selection->spec_id)->toBe($fixture['arms']->id);

    // mount()'s bare view + the explicit class change = 2 rows, only 1 attributed.
    expect(PageViewEvent::where('page', 'spell_explorer')->count())->toBe(2);
});

test('spell explorer dispatches spell-list-refreshed after every change that swaps the visible spell rows', function () {
    // Regression test for the 2026-08-12 fix: the blade's client-side filter logic
    // (applyFilters()/hasResults) only ever ran once on first paint (x-init), so switching
    // class/spec via wire:model.live could re-render fresh spell rows server-side while the
    // page kept showing a stale "No spells match your filters" banner from before.
    // SpellExplorer must dispatch 'spell-list-refreshed' on every such transition so the
    // blade's x-on listener can re-run applyFilters() against the real, freshly-morphed DOM.
    // The former third case here (opening/closing the talent-picker modal) was removed
    // 2026-08-16 along with the picker itself — see SpellExplorer's class docblock: every
    // talent is always shown now, so there's no picker whose close event needs covering.
    $fixture = makePageUsageFixture();

    Livewire::test(SpellExplorer::class)
        ->set('classId', $fixture['warrior']->id)
        ->assertDispatched('spell-list-refreshed');

    Livewire::test(SpellExplorer::class)
        ->set('classId', $fixture['priest']->id)
        ->set('specId', $fixture['discipline']->id)
        ->assertDispatched('spell-list-refreshed');
});

test('wow comps mount records a bare view and slot picks are attributed to their slot', function () {
    $fixture = makePageUsageFixture();

    Livewire::test(WowComps::class)
        ->set('slots.0.classId', $fixture['priest']->id);

    expect(PageViewEvent::where('page', 'wow_comps')->whereNull('class_id')->count())->toBe(1);

    $selection = PageViewEvent::where('page', 'wow_comps')->whereNotNull('class_id')->first();
    expect($selection->slot)->toBe('0')
        ->and($selection->class_id)->toBe($fixture['priest']->id)
        ->and($selection->spec_id)->toBe($fixture['discipline']->id);
});

test('admin page usage aggregates top classes and specs correctly', function () {
    $fixture = makePageUsageFixture();

    // 2 real Priest/Discipline selections, 1 real Warrior/Arms selection, plus bare views that
    // must NOT be counted toward "top classes".
    PageViewEvent::log('spell_explorer');
    PageViewEvent::log('spell_explorer', $fixture['priest']->id, $fixture['discipline']->id);
    PageViewEvent::log('spell_explorer', $fixture['priest']->id, $fixture['discipline']->id);
    PageViewEvent::log('spell_explorer', $fixture['warrior']->id, $fixture['arms']->id);

    $component = Livewire::test(PageUsage::class)->instance();

    expect($component->summary['spell_explorer']['views'])->toBe(1)
        ->and($component->summary['spell_explorer']['selections'])->toBe(3);

    $topClasses = $component->topClasses['spell_explorer'];
    expect($topClasses->first()->name)->toBe('Priest')
        ->and((int) $topClasses->first()->count)->toBe(2);
});
