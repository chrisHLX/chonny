<?php

use App\Http\Services\TalentSelectionService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Guards the per-request memoisation added 2026-09-07.
 *
 * These four lookups are pure functions of a spec id, but nothing cached them, so every caller
 * re-ran the whole chain. preferTalentLinkedCopy() calls two of them per spell and
 * ArenaLogService::resolveWindowSteps() calls that once per step, so one Burst Windows render
 * issued ~4,250 queries and took ~1,850ms. With memoisation: ~135 queries, ~170ms, byte-identical
 * HTML.
 *
 * The assertions below are deliberately "repeated calls add no queries" rather than an absolute
 * count — an absolute number would break on any unrelated schema change, while the property that
 * actually matters (asking twice costs the same as asking once) is stable.
 */
beforeEach(function () {
    $game = Game::create(['name' => 'World of Warcraft', 'slug' => 'wow']);
    $this->patch = Patch::create([
        'game_id' => $game->id,
        'build_version' => '12.0.7.00000',
        'is_current' => true,
    ]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $this->spec = Specialization::create([
        'class_id' => $class->id,
        'name' => 'Discipline',
        'slug' => 'discipline',
        'external_spec_id' => 256,
    ]);
});

it('does not re-query on a repeated allTalentSpellIds call for the same spec', function () {
    $service = app(TalentSelectionService::class);

    $service->allTalentSpellIds($this->spec->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second = $service->allTalentSpellIds($this->spec->id);
    $queries = count(DB::getQueryLog());

    expect($queries)->toBe(0)
        ->and($second)->toEqual($service->allTalentSpellIds($this->spec->id));
});

it('does not re-query on a repeated allPvpTalentSpellIds call for the same spec', function () {
    $service = app(TalentSelectionService::class);

    $service->allPvpTalentSpellIds($this->spec->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->allPvpTalentSpellIds($this->spec->id);

    expect(count(DB::getQueryLog()))->toBe(0);
});

it('memoises per spec rather than globally, so a second spec is still resolved on its own', function () {
    $other = Specialization::create([
        'class_id' => $this->spec->class_id,
        'name' => 'Shadow',
        'slug' => 'shadow',
        'external_spec_id' => 258,
    ]);

    $service = app(TalentSelectionService::class);
    $service->allTalentSpellIds($this->spec->id);

    // A different spec must NOT be served the first spec's memo — it has to do real work.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->allTalentSpellIds($other->id);

    expect(count(DB::getQueryLog()))->toBeGreaterThan(0);
});

it('keeps the memo scoped to one service instance', function () {
    $first = app(TalentSelectionService::class);
    $first->allTalentSpellIds($this->spec->id);

    // A freshly constructed instance starts cold — the memo is per-request state on the object,
    // never a static or a shared cache, so nothing leaks across instances or requests.
    $second = new TalentSelectionService;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second->allTalentSpellIds($this->spec->id);

    expect(count(DB::getQueryLog()))->toBeGreaterThan(0);
});
