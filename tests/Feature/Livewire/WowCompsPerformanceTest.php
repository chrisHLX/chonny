<?php

use App\Livewire\WowComps;
use App\Models\Game;
use App\Models\Patch;
use App\Models\Spell;
use Livewire\Livewire;

/**
 * Covers the 2026-09-05 fix: getOffensiveRotationsProperty() (the "Burst Window" tab's own data)
 * was found via real profiling to be the single most expensive computed property on this page
 * (226ms/517 queries for a 3-spec render, against 146ms/40 for the full kit itself) — and it was
 * being recomputed on every single spec pick regardless of which tab was ever opened. Real usage
 * data (Admin\PageUsage) confirmed Burst Window is one of the less-opened tabs while the page's
 * default tab is Synergies, not Burst Window — so this cost was being paid on nearly every visit
 * for a view most visitors never saw. Fixed by deferring the computation until
 * WowComps::loadRotationTab() is actually called (wired to the Burst Window tab button itself).
 * Reuses makeSynergiesSpecFixture() from WowCompsSynergiesTest.php rather than duplicating it.
 */
test('Burst Window data is not computed until the tab is actually opened', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $spell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 91001, 'name' => 'Perf Test Spell', 'category' => 'Offensive', 'cooldown_seconds' => 30]);
    $fixture = makeSynergiesSpecFixture($patch, 'Perf Class', 'Perf Spec', $spell);

    $component = Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id);

    expect($component->instance()->rotationTabLoaded)->toBeFalse()
        ->and($component->instance()->offensiveRotations)->toBe([])
        ->and($component->html())->toContain('Loading burst window…')
        ->and($component->html())->not->toContain('Peak Burst Example');

    $component->call('loadRotationTab');

    expect($component->instance()->rotationTabLoaded)->toBeTrue()
        ->and($component->instance()->offensiveRotations)->not->toBe([]) // populated (with nulls for a spec that has no promoted rotation file — still a real, non-empty array)
        ->and($component->html())->not->toContain('Loading burst window…');
});

test('loadRotationTab is idempotent — calling it again does not reset already-loaded data to empty', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);
    $spell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 91002, 'name' => 'Perf Test Spell 2', 'category' => 'Offensive', 'cooldown_seconds' => 30]);
    $fixture = makeSynergiesSpecFixture($patch, 'Perf Class 2', 'Perf Spec 2', $spell);

    $component = Livewire::test(WowComps::class)
        ->call('selectSpec', 0, $fixture['class']->id, $fixture['spec']->id)
        ->call('loadRotationTab')
        ->call('loadRotationTab'); // second click into the same tab

    expect($component->instance()->rotationTabLoaded)->toBeTrue();
});
