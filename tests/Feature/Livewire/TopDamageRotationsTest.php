<?php

use App\Livewire\TopDamageRotations;
use App\Models\GameClass;
use App\Models\Game;
use App\Models\Specialization;
use Livewire\Livewire;

/**
 * Sub Rogue's slugs (rogue/subtlety) intentionally match the REAL committed
 * data/arena-logs/rotations/rogue/subtlety.json — ArenaLogService::rotationForSpec() reads
 * straight off disk by classSlug/specSlug, so creating GameClass/Specialization rows with these
 * exact slugs lets this test read the real, already-verified rotation data without needing a
 * fixture file (same "the file is committed, real repo data, not DB-dependent" reasoning
 * WowComps' own rotation tab relies on in production).
 */
function makeSubRogueFixture(): array
{
    $game = Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']);

    return compact('class', 'spec');
}

test('mounts with a default class/spec selected', function () {
    Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    $class = GameClass::create(['game_id' => Game::where('slug', 'wow')->value('id'), 'name' => 'Warrior', 'slug' => 'warrior']);
    Specialization::create(['class_id' => $class->id, 'name' => 'Arms', 'slug' => 'arms']);

    Livewire::test(TopDamageRotations::class)
        ->assertOk()
        ->assertSet('classId', $class->id)
        ->assertSet('length', 12);
});

test('selecting a spec and length renders the real Peak Burst Example for that length', function () {
    ['class' => $class, 'spec' => $spec] = makeSubRogueFixture();

    $component = Livewire::test(TopDamageRotations::class)
        ->call('selectSpec', $class->id, $spec->id)
        ->call('selectLength', 6)
        ->assertOk()
        ->assertSet('length', 6);

    $html = $component->html();
    expect($html)->toContain('Peak Burst Example');
    // 6s bracket's real damage figure from the committed data file — see the export's own
    // topDpsWindowsByLength.6.damage. Updated 2026-08-27 after a fresh all-spec-rotations.php
    // regeneration (more archived matches processed since this was last written) legitimately
    // moved the real number — re-check this value directly from the committed JSON any time the
    // rotation data is regenerated, don't assume it's stable across reruns.
    expect($html)->toContain('1,158,787');
});

test('selectLength ignores a value outside the allowed set', function () {
    Livewire::test(TopDamageRotations::class)
        ->call('selectLength', 15)
        ->assertSet('length', 12); // unchanged from the default
});

test('a spec with no rotation data on disk shows the empty state, not an error', function () {
    $game = Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'NoDataClass', 'slug' => 'no-data-class']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'NoDataSpec', 'slug' => 'no-data-spec']);

    $component = Livewire::test(TopDamageRotations::class)
        ->call('selectSpec', $class->id, $spec->id)
        ->assertOk();

    expect($component->html())->toContain('No arena-log data for this spec yet.');
});
