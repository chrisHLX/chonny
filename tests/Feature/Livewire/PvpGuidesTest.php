<?php

use App\Livewire\ClassGuide;
use App\Livewire\ClaudesCounters;
use App\Livewire\PvpGuides;
use App\Livewire\SpellExplorer;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Specialization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Covers App\Livewire\PvpGuides — the page that gathers Class Kits / Burst Guides / Spells /
 * Spell Counters behind one class-and-spec picker.
 *
 * The properties worth locking in are the ones that would regress silently: that this page owns
 * selection and nothing else (no panel logic leaked into it), that each panel still works
 * standalone at its own URL, and that exactly one of the four panels is mounted at a time. The
 * panels' own behaviour is covered by their own suites and is deliberately not re-asserted here.
 */
function pvpGuidesFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '0.0.0-test', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']);
    $other = Specialization::create(['class_id' => $class->id, 'name' => 'Outlaw', 'slug' => 'outlaw']);

    return [$class, $spec, $other];
}

test('it renders one header, one spec, and the four tabs', function () {
    [$class, $spec] = pvpGuidesFixture();

    $html = Livewire::test(PvpGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'subtlety'])->html();

    // Exactly one <h1>: a panel rendering its own page header too would give this page two.
    expect(substr_count($html, '<h1'))->toBe(1);
    expect($html)->toContain('Subtlety')->toContain('Rogue');

    foreach (['Class Kit', 'Offensive Kit', 'Spells', 'Counters'] as $label) {
        expect($html)->toContain($label);
    }
});

test('only the active tab mounts a panel, and switching swaps which one', function () {
    pvpGuidesFixture();

    $component = Livewire::test(PvpGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'subtlety']);

    // Livewire names each child component in the rendered markup, so "which panel is mounted"
    // is directly observable rather than inferred.
    expect($component->html())->toContain('class-guide')->not->toContain('spell-explorer');

    $component->call('selectTab', 'spells');

    expect($component->get('tab'))->toBe('spells');
    expect($component->html())->toContain('spell-explorer')->not->toContain('class-guide');
});

test('an unknown tab is rejected rather than rendering an empty page', function () {
    pvpGuidesFixture();

    $component = Livewire::test(PvpGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'subtlety'])
        ->call('selectTab', 'not-a-real-tab');

    expect($component->get('tab'))->toBe(PvpGuides::DEFAULT_TAB);
});

test('a tab switch is logged separately from the page view, and never inflates the selection count', function () {
    [$class, $spec] = pvpGuidesFixture();

    $component = Livewire::test(PvpGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'subtlety']);

    // Landing is one attributed page view; the tab it landed on is deliberately NOT logged.
    expect(PageViewEvent::where('page', 'pvp_guides')->count())->toBe(1);
    expect(PageViewEvent::where('page', 'pvp_guides')->first()->spec_id)->toBe($spec->id);
    expect(PageViewEvent::where('page', 'pvp_guides_tab')->count())->toBe(0);

    $component->call('selectTab', 'counters');
    // A re-click of the tab already open must not log a second time.
    $component->call('selectTab', 'counters');

    $rows = PageViewEvent::where('page', 'pvp_guides_tab')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->slot)->toBe('counters');
    expect($rows->first()->spec_id)->toBe($spec->id);

    // Tab rows live under their own page slug, so the page's own view count is untouched.
    expect(PageViewEvent::where('page', 'pvp_guides')->count())->toBe(1);
});

test('picking a spec redirects to that spec\'s own URL, keeping the open tab', function () {
    [$class, $spec, $other] = pvpGuidesFixture();

    Livewire::test(PvpGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'subtlety'])
        ->call('selectTab', 'spells')
        ->call('selectSpec', $class->id, $other->id)
        ->assertRedirect(route('pvp-guides', [
            'classSlug' => 'rogue',
            'specSlug' => 'outlaw',
            'tab' => 'spells',
        ]));
});

test('a class/spec pair that does not match is ignored rather than redirected to a 404', function () {
    [$class, $spec] = pvpGuidesFixture();
    $otherClass = GameClass::create(['game_id' => $class->game_id, 'name' => 'Mage', 'slug' => 'mage']);

    Livewire::test(PvpGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'subtlety'])
        ->call('selectSpec', $otherClass->id, $spec->id)
        ->assertNoRedirect();
});

test('an unknown spec 404s rather than silently showing a different one', function () {
    pvpGuidesFixture();

    Livewire::test(PvpGuides::class, ['classSlug' => 'rogue', 'specSlug' => 'nope'])
        ->assertStatus(404);
});

test('every panel still renders standalone at its own route, unchanged by being embeddable', function () {
    pvpGuidesFixture();

    // The four routes were deliberately kept rather than redirected into the new page — an
    // existing bookmark, the sitemap and every in-page link still resolve to them.
    foreach (['class-guide', 'burst-guides', 'claudes-counters', 'spells.explore', 'pvp-guides'] as $name) {
        expect(app('router')->has($name))->toBeTrue("route {$name} must still exist");
    }

    // $embedded defaults false, so a standalone mount keeps its own page header.
    expect(Livewire::test(ClassGuide::class, ['classSlug' => 'rogue', 'specSlug' => 'subtlety'])->html())
        ->toContain('<h1');
    expect(Livewire::test(ClaudesCounters::class)->html())->toContain('<h1');
    expect(Livewire::test(SpellExplorer::class)->html())->toContain('<h1');
});

test('an embedded panel drops its own header, picker and copy of the shared spell modal', function () {
    [$class, $spec] = pvpGuidesFixture();

    $kit = Livewire::test(ClassGuide::class, [
        'classSlug' => 'rogue', 'specSlug' => 'subtlety', 'embedded' => true,
    ])->html();
    $spells = Livewire::test(SpellExplorer::class, [
        'classId' => $class->id, 'specId' => $spec->id, 'embedded' => true,
    ])->html();
    $counters = Livewire::test(ClaudesCounters::class, [
        'onlyClassName' => 'Rogue', 'embedded' => true,
    ])->html();

    foreach (['kit' => $kit, 'spells' => $spells, 'counters' => $counters] as $name => $html) {
        expect($html)->not->toContain('<h1', "{$name} panel must not render its own page header");
        // Two shared modals on one page would both answer show-spell-detail and stack.
        expect($html)->not->toContain('spell-detail-modal', "{$name} panel must not mount its own modal");
    }

    // The spec's own picker is the parent's job; the panel must not render a second one.
    expect($spells)->not->toContain('Search a class or spec');
});

test('an embedded SpellExplorer uses the spec it was handed, not the alphabetically-first one', function () {
    [$class, $spec, $other] = pvpGuidesFixture();

    // 'Outlaw' sorts before 'Subtlety', so a mount that re-derived its own default would land
    // on the wrong spec — the exact bug the explicit-spec branch in mount() exists to prevent.
    $component = Livewire::test(SpellExplorer::class, [
        'classId' => $class->id, 'specId' => $spec->id, 'embedded' => true,
    ]);

    expect($component->get('specId'))->toBe($spec->id);

    // ...and it must not log a second bare 'spell_explorer' view: the parent logs its own.
    expect(PageViewEvent::where('page', 'spell_explorer')->count())->toBe(0);
});

test('an embedded counters panel shows only the selected class', function () {
    [$class] = pvpGuidesFixture();
    $mage = GameClass::create(['game_id' => $class->game_id, 'name' => 'Mage', 'slug' => 'mage']);
    Specialization::create(['class_id' => $mage->id, 'name' => 'Frost', 'slug' => 'frost']);

    $scoped = Livewire::test(ClaudesCounters::class, ['onlyClassName' => 'Rogue', 'embedded' => true])
        ->instance();

    // Whatever the index holds, the panel may only ever surface the one class it was scoped to.
    expect(array_diff(
        collect($scoped->render()->getData()['counterableByClass'])->keys()->all(),
        ['Rogue']
    ))->toBe([]);
});
