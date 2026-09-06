<?php

use App\Livewire\BurstGuideClassBlock;
use App\Livewire\BurstGuides;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * Covers BurstGuides (the thin parent shell) and BurstGuideClassBlock (the real, lazy-loaded
 * per-class content) — split 2026-09-04 after a real, direct user report that the original
 * single-component design ("64MB to read a block with 10 spells?!") was rendering all 38 specs
 * synchronously on every page view. See BurstGuideClassBlock's own docblock for the full
 * reasoning on the per-class #[Lazy] split this test file now covers.
 *
 * Same two-tier convention as ModuleSpellReferenceServiceDescriptionTest.php for this class of
 * page (content depends entirely on real Spell/GameClass/Specialization rows that only ever come
 * from import:spelldata, never a seeder): a bare-DB degrade check, then a focused fixture test
 * against one real, committed burst-guide file.
 */
test('the parent page paints instantly with placeholders only — no real spec content until a child actually loads', function () {
    $dir = base_path('data/claudes-guides/burst-guides');
    if (!File::exists($dir) || File::glob("{$dir}/*/*.json") === []) {
        $this->markTestSkipped('No burst-guide files on disk — run php artisan wow:build-burst-guides first.');
    }

    // Deliberately NOT calling Livewire::withoutLazyLoading() here — this test simulates a real
    // browser's first paint, where every #[Lazy] child renders only its placeholder() output
    // until its own deferred follow-up request completes.
    $component = Livewire::test(BurstGuides::class);
    $html = $component->html();

    expect($html)->toContain('Burst Guides');
    // A real spec name should NOT appear on first paint — only after a child's deferred load.
    expect($html)->not->toContain('Assassination');
});

test('with no real spelldata in the DB, the parent shows the empty state rather than crashing', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '0.0.0-test', 'is_current' => true]);

    $component = Livewire::test(BurstGuides::class);

    expect($component->instance()->availableClassSlugs)->toBe([]);
    expect($component->html())->toContain('No burst guides on file yet');
});

test('a real, deferred child block resolves one committed burst-guide file to a correctly ordered, live-resolved sequence', function () {
    $path = base_path('data/claudes-guides/burst-guides/rogue/assassination.json');
    if (!File::exists($path)) {
        $this->markTestSkipped('No committed rogue/assassination burst guide on disk.');
    }

    $decoded = json_decode(File::get($path), true);
    $spellIds = $decoded['spellIds'];
    expect($spellIds)->not->toBeEmpty();

    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '0.0.0-test', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    Specialization::create(['class_id' => $class->id, 'name' => 'Assassination', 'slug' => 'assassination']);

    foreach (array_unique($spellIds) as $i => $id) {
        Spell::create([
            'patch_id' => $patch->id,
            'spell_id' => $id,
            'name' => "Test Spell {$id}",
            'cooldown_seconds' => $i % 2 === 0 ? 30 : null,
        ]);
    }

    // #[Lazy] short-circuits mount()/render() to a placeholder by default, even when the
    // component is tested directly — Livewire::withoutLazyLoading() is the documented way to
    // get the real content in a test, simulating the deferred follow-up request completing.
    Livewire::withoutLazyLoading();

    $component = Livewire::test(BurstGuideClassBlock::class, ['classSlug' => 'rogue']);
    $guide = $component->instance()->guide;

    expect($guide['class'])->not->toBeNull();
    expect($guide['specs'])->toHaveCount(1);

    $resolvedIds = collect($guide['specs'][0]['steps'])->pluck('spell.spell_id')->all();
    expect($resolvedIds)->toBe($spellIds);

    $html = $component->html();
    expect($html)->toContain('Assassination');
    expect($html)->toContain('show-spell-detail');
});

test('a class with no resolvable data renders an empty (but valid, single-root) block', function () {
    Livewire::withoutLazyLoading();

    $component = Livewire::test(BurstGuideClassBlock::class, ['classSlug' => 'does-not-exist']);

    expect($component->instance()->guide)->toBe(['class' => null, 'specs' => []]);
    // Must not throw "missing root tag" — confirms the always-present wrapping <div> works.
    expect($component->html())->toBeString();
});
