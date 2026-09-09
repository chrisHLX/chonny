<?php

use App\Livewire\TopCcChains;
use App\Models\Game;
use App\Models\Patch;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * Covers TopCcChains — the flat top-10-by-duration CC chain page (2026-08-31, direct
 * instruction: "Go with option C except instead of gating just show the top 10 CC chains we
 * have on file by duration").
 *
 * Deliberately tests INVARIANTS against whatever's actually on disk in data/arena-logs/
 * cc-chains/ rather than writing isolated fixture files there: getChainsProperty() globs
 * base_path('data/arena-logs/cc-chains/*\/*.json') directly (no injectable path), and
 * RefreshDatabase only resets the DB, not the filesystem — this repo's own real, committed
 * cc-chains output is always present during a test run. Temporarily moving/replacing that real
 * data to force isolation was judged a worse risk than testing against it directly; these
 * assertions (ordering, no name-leak, real spell resolution) hold true regardless of exactly
 * what's on disk at run time.
 */
test('orders chains by duration descending, caps at TOP_N, and leaks no real character name', function () {
    if (File::glob(base_path('data/arena-logs/cc-chains/*/*.json')) === []) {
        $this->markTestSkipped('No cc-chains data on disk for this environment.');
    }

    // getChainsProperty() bails to [] with no current patch at all — RefreshDatabase gives an
    // empty schema, so a minimal patch just needs to exist and be current; it doesn't need to
    // match the real, on-disk chain files' own patch (spell resolution for those real
    // background chains is expected to legitimately miss in this minimal test DB — real spell
    // rows only ever come from import:spelldata, never a seeder, same rule this project's other
    // description/service tests already follow).
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '0.0.0-test', 'is_current' => true]);

    // Every real healerName currently on disk, across every healer spec's file — none of these
    // may ever appear in the rendered page (see PlayerMatchAnalysisService/ArenaLogService's own
    // "no real character name" fix this same session).
    $realNames = collect(File::glob(base_path('data/arena-logs/cc-chains/*/*.json')))
        ->flatMap(fn ($f) => collect(json_decode(File::get($f), true) ?? [])->pluck('healerName'))
        ->filter()
        ->unique();

    $component = Livewire::test(TopCcChains::class);
    $chains = $component->instance()->chains;
    $html = $component->html();

    expect(count($chains))->toBeLessThanOrEqual(TopCcChains::TOP_N);

    // Strictly descending (ties allowed) — the whole point of "top N by duration".
    for ($i = 1; $i < count($chains); $i++) {
        expect($chains[$i - 1]['durationSeconds'])->toBeGreaterThanOrEqual($chains[$i]['durationSeconds']);
    }

    foreach ($realNames as $name) {
        expect($html)->not->toContain($name);
    }
});

/**
 * The production regression this page actually shipped with (fixed 2026-09-09).
 *
 * data/arena-logs/metadata/*.json is GITIGNORED, so a live server has none of it. The comp used
 * to be resolved at render time from that metadata, which meant on production every chain
 * resolved to no comp at all — so the page fell back to "whoever cast a step in this one chain"
 * (as few as ONE spec) and the unique-comp dedupe silently did nothing, because an empty comp key
 * is deliberately never deduped on. Locally it looked perfect, because the archive IS present
 * here — which is exactly why this test forces the archive to be missing rather than trusting a
 * normal dev run.
 *
 * Reproduced before the fix under these conditions: 5 of 10 chains showed fewer than 3 specs and
 * 3 rows were the same comp. The comp now comes from the `attackingComp` field persisted into the
 * corpus at generation time by wow:find-cc-chains, so it does not need the archive at all.
 */
test('resolves a full 3-spec comp and unique comps with NO arena log archive present', function () {
    if (File::glob(base_path('data/arena-logs/cc-chains/*/*.json')) === []) {
        $this->markTestSkipped('No cc-chains data on disk for this environment.');
    }

    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '0.0.0-test', 'is_current' => true]);

    // Point the archive at a directory that genuinely has no metadata in it — this is what
    // production looks like, not an artificial edge case.
    $empty = storage_path('framework/testing/empty-archive-'.uniqid());
    File::ensureDirectoryExists($empty);
    config(['arena_logs.archive_path' => $empty]);

    $chains = Livewire::test(TopCcChains::class)->instance()->chains;

    expect($chains)->not->toBeEmpty();

    $compKeys = [];

    foreach ($chains as $chain) {
        // A real 3v3 comp, every time — never "whoever happened to cast a step in this chain".
        expect($chain['casters'])->toHaveCount(3);

        $compKeys[] = collect($chain['casters'])
            ->map(fn ($c) => $c['classSlug'].'/'.$c['specSlug'])
            ->sort()
            ->implode('|');
    }

    // Order-independent, so two chains by the same three specs collapse regardless of who cast
    // first. Duplicates of the same spec within one comp are preserved on purpose (a real 3v3 can
    // run two of the same spec), which is why this compares full lists rather than a set.
    expect($compKeys)->toEqual(array_values(array_unique($compKeys)));

    File::deleteDirectory($empty);
});
