<?php

use App\Livewire\Admin\PageUsage;
use App\Livewire\SpellExplorer;
use App\Livewire\TopDamageRotations;
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

test('top damage rotations mount records a bare view and selectSpec attributes it', function () {
    $fixture = makePageUsageFixture(); // Priest sorts before Warrior alphabetically — the default

    Livewire::test(TopDamageRotations::class)
        ->call('selectSpec', $fixture['warrior']->id, $fixture['arms']->id);

    expect(PageViewEvent::where('page', 'top_damage_rotations')->whereNull('class_id')->count())->toBe(1);

    $selection = PageViewEvent::where('page', 'top_damage_rotations')->whereNotNull('class_id')->first();
    expect($selection)->not->toBeNull()
        ->and($selection->class_id)->toBe($fixture['warrior']->id)
        ->and($selection->spec_id)->toBe($fixture['arms']->id);
});

function makeRmpPresetFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);

    $spec = function (string $classSlug, string $specSlug) use ($game) {
        $class = GameClass::firstOrCreate(
            ['game_id' => $game->id, 'slug' => $classSlug],
            ['name' => ucfirst($classSlug)],
        );

        return Specialization::create([
            'class_id' => $class->id,
            'name' => ucfirst($specSlug),
            'slug' => $specSlug,
        ]);
    };

    return [
        'rdruid' => $spec('druid', 'restoration'),
        'sub'    => $spec('rogue', 'subtlety'),
        'frost'  => $spec('mage', 'frost'),
    ];
}

test('wow comps mount does not pre-select any slot', function () {
    makeRmpPresetFixture();

    Livewire::test(WowComps::class)
        ->assertSet('slots.0.specId', null)
        ->assertSet('slots.1.specId', null)
        ->assertSet('slots.2.specId', null);

    // Only the bare page view — nothing attributed.
    expect(PageViewEvent::where('page', 'wow_comps')->count())->toBe(1)
        ->and(PageViewEvent::where('page', 'wow_comps')->whereNotNull('class_id')->count())->toBe(0);
});

test('applyPreset loads all three slots and logs it as a real, attributed pick', function () {
    $f = makeRmpPresetFixture();

    Livewire::test(WowComps::class)
        ->call('applyPreset', 'rmp')
        ->assertSet('slots.0.specId', $f['rdruid']->id)
        ->assertSet('slots.1.specId', $f['sub']->id)
        ->assertSet('slots.2.specId', $f['frost']->id);

    // One attributed row per slot (feeds top classes/specs/slot breakdown)...
    $picks = PageViewEvent::where('page', 'wow_comps')->whereNotNull('class_id')->get();
    expect($picks)->toHaveCount(3)
        ->and($picks->pluck('slot')->sort()->values()->all())->toBe(['0', '1', '2'])
        ->and($picks->firstWhere('slot', '0')->spec_id)->toBe($f['rdruid']->id);

    // ...plus one 'wow_comps_preset' row for per-preset popularity.
    $presetRows = PageViewEvent::where('page', 'wow_comps_preset')->get();
    expect($presetRows)->toHaveCount(1)
        ->and($presetRows->first()->slot)->toBe('rmp')
        ->and($presetRows->first()->class_id)->toBeNull();

    expect(Livewire::test(PageUsage::class)->instance()->presetBreakdown->firstWhere('preset', 'rmp')->count)->toBe(1);
});

test('applyPreset is a silent no-op when the comp specs are not all present', function () {
    $fixture = makePageUsageFixture(); // Priest/Discipline + Warrior/Arms — not a full RMP

    Livewire::test(WowComps::class)
        ->call('applyPreset', 'rmp')
        ->assertSet('slots.0.specId', null)
        ->assertSet('slots.1.specId', null);

    expect(PageViewEvent::where('page', 'wow_comps')->whereNotNull('class_id')->count())->toBe(0)
        ->and(PageViewEvent::where('page', 'wow_comps_preset')->count())->toBe(0);
});

test('wow comps tab beacon records an allowlisted tab and rejects anything else', function () {
    makePageUsageFixture();

    $this->post('/track/wow-comps-tab', ['tab' => 'rotation'])->assertNoContent();
    $this->post('/track/wow-comps-tab', ['tab' => 'rotation'])->assertNoContent();
    $this->post('/track/wow-comps-tab', ['tab' => 'synergies'])->assertNoContent();
    $this->post('/track/wow-comps-tab', ['tab' => 'not-a-real-tab'])->assertNoContent();
    $this->post('/track/wow-comps-tab', [])->assertNoContent();

    // Only the 3 valid ones are written, each as a wow_comps_tab row with the tab in `slot`.
    expect(PageViewEvent::where('page', 'wow_comps_tab')->count())->toBe(3);
    expect(PageViewEvent::where('page', 'wow_comps')->count())->toBe(0);

    $breakdown = Livewire::test(PageUsage::class)->instance()->tabBreakdown;
    expect($breakdown->firstWhere('tab', 'rotation'))->not->toBeNull()
        ->and($breakdown->firstWhere('tab', 'rotation')->count)->toBe(2)
        ->and($breakdown->firstWhere('tab', 'rotation')->label)->toBe('Burst Window')
        ->and($breakdown->firstWhere('tab', 'synergies')->count)->toBe(1)
        ->and($breakdown->pluck('tab')->contains('not-a-real-tab'))->toBeFalse();
});

test('admin page usage includes Burst Windows alongside WoW Comps and Spell Explorer', function () {
    // Regression test for the real gap found 2026-08-23: TopDamageRotations was already calling
    // PageViewEvent::log('top_damage_rotations', ...) from day one, but Admin\PageUsage's PAGES
    // list never learned about the new page, so those events had nowhere to be seen. Adding a
    // new tracked route/page must always update BOTH — see CLAUDE.md.
    $fixture = makePageUsageFixture();

    PageViewEvent::log('top_damage_rotations');
    PageViewEvent::log('top_damage_rotations', $fixture['warrior']->id, $fixture['arms']->id);

    $component = Livewire::test(PageUsage::class)->instance();

    expect($component->summary['top_damage_rotations']['views'])->toBe(1)
        ->and($component->summary['top_damage_rotations']['selections'])->toBe(1);

    $topClasses = $component->topClasses['top_damage_rotations'];
    expect($topClasses->first()->name)->toBe('Warrior');

    Livewire::test(PageUsage::class)->assertSee('Burst Windows');
});
