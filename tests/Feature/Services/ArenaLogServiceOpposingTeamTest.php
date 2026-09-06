<?php

use App\Http\Services\ArenaLogService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Covers ArenaLogService::resolveOpposingTeamSpecs() — added 2026-09-06 for Top 10 CC Chains'
 * "show unique comps, always the real 3-person team" fix. Fully isolated: points
 * config('arena_logs.archive_path') at a throwaway temp directory (never the real, external
 * wow-arena-archive folder) and writes a synthetic metadata/{matchId}.json with known
 * reaction/spec values, matching the real shape confirmed live against an actual match on
 * 2026-09-06 (units[] entries with id/name/spec/class/reaction/affiliation).
 */
function makeOpposingTeamFixtureArchive(): string
{
    $dir = sys_get_temp_dir().'/mc-arena-test-'.uniqid();
    File::ensureDirectoryExists("{$dir}/metadata");
    Config::set('arena_logs.archive_path', $dir);

    return $dir;
}

test('resolves the real opposing team roster via reaction, never the healer\'s own side', function () {
    $dir = makeOpposingTeamFixtureArchive();

    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $rogueClass = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $rogueSpec = Specialization::create(['class_id' => $rogueClass->id, 'name' => 'Subtlety', 'slug' => 'subtlety', 'external_spec_id' => 261]);
    $druidClass = GameClass::create(['game_id' => $game->id, 'name' => 'Druid', 'slug' => 'druid']);
    $druidSpec = Specialization::create(['class_id' => $druidClass->id, 'name' => 'Restoration', 'slug' => 'restoration', 'external_spec_id' => 105]);
    $priestClass = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $priestSpec = Specialization::create(['class_id' => $priestClass->id, 'name' => 'Discipline', 'slug' => 'discipline', 'external_spec_id' => 256]);
    $mageClass = GameClass::create(['game_id' => $game->id, 'name' => 'Mage', 'slug' => 'mage']);
    $mageSpec = Specialization::create(['class_id' => $mageClass->id, 'name' => 'Frost', 'slug' => 'frost', 'external_spec_id' => 64]);

    $meta = [
        'units' => [
            // The healer being CC'd — its own team (reaction=2) has one real teammate.
            ['id' => 'Player-1-AAAA', 'name' => 'HealerName-Realm-US', 'spec' => (string) $priestSpec->external_spec_id, 'class' => 5, 'reaction' => 2, 'affiliation' => 4],
            ['id' => 'Player-2-BBBB', 'name' => 'HealerTeammate-Realm-US', 'spec' => (string) $mageSpec->external_spec_id, 'class' => 8, 'reaction' => 2, 'affiliation' => 4],
            // The opposing (attacking) team — reaction=1, the one this method should return.
            ['id' => 'Player-3-CCCC', 'name' => 'AttackerRogue-Realm-US', 'spec' => (string) $rogueSpec->external_spec_id, 'class' => 4, 'reaction' => 1, 'affiliation' => 2],
            ['id' => 'Player-4-DDDD', 'name' => 'AttackerDruid-Realm-US', 'spec' => (string) $druidSpec->external_spec_id, 'class' => 11, 'reaction' => 1, 'affiliation' => 2],
            // A non-player unit (pet/totem) — must never be included in the resolved roster.
            ['id' => 'Creature-0-1234', 'name' => 'Water Elemental', 'spec' => '0', 'class' => 0, 'reaction' => 1, 'affiliation' => 2],
        ],
    ];
    File::put("{$dir}/metadata/test-match-1.json", json_encode($meta));

    $service = app(ArenaLogService::class);
    $roster = $service->resolveOpposingTeamSpecs('test-match-1', 'HealerName-Realm-US');

    expect($roster)->toHaveCount(2);
    expect($roster)->toContain(['classSlug' => 'rogue', 'specSlug' => 'subtlety']);
    expect($roster)->toContain(['classSlug' => 'druid', 'specSlug' => 'restoration']);
    // Neither member of the healer's own side leaked in.
    foreach ($roster as $r) {
        expect($r)->not->toBe(['classSlug' => 'priest', 'specSlug' => 'discipline']);
        expect($r)->not->toBe(['classSlug' => 'mage', 'specSlug' => 'frost']);
    }

    File::deleteDirectory($dir);
});

test('returns empty when the healer name is not found in the match metadata', function () {
    $dir = makeOpposingTeamFixtureArchive();
    File::put("{$dir}/metadata/test-match-2.json", json_encode(['units' => [
        ['id' => 'Player-1-AAAA', 'name' => 'SomeoneElse-Realm-US', 'spec' => '256', 'class' => 5, 'reaction' => 2, 'affiliation' => 4],
    ]]));

    $service = app(ArenaLogService::class);
    $roster = $service->resolveOpposingTeamSpecs('test-match-2', 'NotInThisMatch-Realm-US');

    expect($roster)->toBe([]);

    File::deleteDirectory($dir);
});

test('returns empty (not an exception) when the metadata file does not exist at all', function () {
    makeOpposingTeamFixtureArchive();

    $service = app(ArenaLogService::class);
    $roster = $service->resolveOpposingTeamSpecs('no-such-match', 'Anyone-Realm-US');

    expect($roster)->toBe([]);
});
