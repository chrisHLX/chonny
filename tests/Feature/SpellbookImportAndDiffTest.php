<?php

use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellbookSnapshot;
use App\Models\SpellbookSnapshotEntry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function spellbookFixturePath(): string
{
    return __DIR__.'/../fixtures/MindCollectorExport.sample.lua';
}

function spellbookMultiFixturePath(): string
{
    return __DIR__.'/../fixtures/MindCollectorExport.multi.sample.lua';
}

function makeSpellbookDiscipline(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.7.57689', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline', 'external_spec_id' => 256]);

    // Mind Blast — present in the fixture, seeded here so it resolves cleanly in the diff.
    $mindBlast = Spell::create(['patch_id' => $patch->id, 'spell_id' => 8092, 'name' => 'Mind Blast']);
    SpellClassAvailability::create(['spell_id' => $mindBlast->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'source' => 'baseline']);

    // Power Word: Shield — present in the fixture and in spells, but deliberately given no
    // spell_class_availability row, to exercise MISSING_AVAILABILITY.
    Spell::create(['patch_id' => $patch->id, 'spell_id' => 17, 'name' => 'Power Word: Shield']);

    // Penance (47540) is in the fixture but deliberately NOT seeded into spells at all — this is
    // what should surface as MISSING_SPELL.

    return compact('game', 'patch', 'class', 'spec');
}

test('import creates a snapshot with entries from spellbook, selected talents, and pvp talents', function () {
    $this->artisan('wow:import-spellbook', ['path' => spellbookFixturePath()])
        ->expectsOutputToContain('Created snapshot')
        ->assertSuccessful();

    expect(SpellbookSnapshot::count())->toBe(1);

    $snapshot = SpellbookSnapshot::first();
    expect($snapshot->class)->toBe('PRIEST')
        ->and($snapshot->spec_id)->toBe(256)
        ->and($snapshot->client_build)->toBe('11.0.7.57689')
        ->and($snapshot->loadout_string)->not->toBeEmpty();

    expect(SpellbookSnapshotEntry::where('snapshot_id', $snapshot->id)->count())->toBe(7);

    $mindBlast = SpellbookSnapshotEntry::where('snapshot_id', $snapshot->id)->where('spell_id', 8092)->first();
    expect($mindBlast->kind)->toBe('spellbook')
        ->and($mindBlast->resolved_description)->toBe('Blasts the target for Shadow damage and reduces movement speed.');

    $penance = SpellbookSnapshotEntry::where('snapshot_id', $snapshot->id)->where('spell_id', 47540)->first();
    expect($penance->resolved_description)->toBeNull();

    $rapture = SpellbookSnapshotEntry::where('snapshot_id', $snapshot->id)->where('spell_id', 47536)->first();
    expect($rapture->kind)->toBe('talent');
});

test('re-importing the same file skips as a duplicate and writes no new rows', function () {
    $this->artisan('wow:import-spellbook', ['path' => spellbookFixturePath()])->assertSuccessful();
    expect(SpellbookSnapshot::count())->toBe(1);

    $this->artisan('wow:import-spellbook', ['path' => spellbookFixturePath()])
        ->expectsOutputToContain('duplicate hash')
        ->assertSuccessful();

    expect(SpellbookSnapshot::count())->toBe(1);
    expect(SpellbookSnapshotEntry::count())->toBe(7);
});

test('diff flags a snapshot spell absent from spells as MISSING_SPELL', function () {
    makeSpellbookDiscipline();
    $this->artisan('wow:import-spellbook', ['path' => spellbookFixturePath()])->assertSuccessful();

    $this->artisan('wow:diff-spellbook')
        ->expectsOutputToContain('MISSING_SPELL: ')
        ->assertSuccessful();
});

test('import handles a multi-export file (accumulated across characters, 2026-08-12 fix) — one snapshot per export, not one for the whole file', function () {
    $this->artisan('wow:import-spellbook', ['path' => spellbookMultiFixturePath()])
        ->expectsOutputToContain("Done: 2 snapshot(s) created, 0 skipped")
        ->assertSuccessful();

    expect(SpellbookSnapshot::count())->toBe(2);

    $priest = SpellbookSnapshot::where('class', 'PRIEST')->first();
    expect($priest->spec_id)->toBe(256)
        ->and($priest->client_build)->toBe('12.0.7.68887');
    expect(SpellbookSnapshotEntry::where('snapshot_id', $priest->id)->count())->toBe(1);

    $warrior = SpellbookSnapshot::where('class', 'WARRIOR')->first();
    expect($warrior->spec_id)->toBe(71);
    expect(SpellbookSnapshotEntry::where('snapshot_id', $warrior->id)->count())->toBe(1);
});

test('re-importing a multi-export file skips already-imported exports individually, not the whole file', function () {
    $this->artisan('wow:import-spellbook', ['path' => spellbookMultiFixturePath()])->assertSuccessful();
    expect(SpellbookSnapshot::count())->toBe(2);

    // Simulates logging into a THIRD character and exporting again — the file now contains the
    // same two exports as before plus one new one. Re-importing must skip the two already-known
    // exports individually (per-export hash) and only create the one truly new snapshot — this
    // is exactly the bug the whole-file hash would have caused (see ImportSpellbook's docblock).
    // Built as a fresh literal rather than string-surgery on the base fixture — the base fixture
    // has an identical "}, -- [1]\n}\n" closing sequence after BOTH exports (Priest and Warrior
    // each have exactly one spellbook entry), so a str_replace() there would silently corrupt
    // both occurrences instead of appending once at the end.
    $original = rtrim(File::get(spellbookMultiFixturePath()));
    $withThird = rtrim($original, "}\n")
        .'	["MAGE_62_1785600200"] = { ["exported_at"] = 1785600200, ["build"] = "12.0.7.68887", '
        .'["class"] = "MAGE", ["spec_id"] = 62, ["spec_name"] = "Arcane", ["loadout_string"] = "abc", '
        .'["spellbook"] = { { ["id"] = 118, ["name"] = "Polymorph", ["tab"] = "Mage" }, }, '
        ."[\"talents\"] = { [\"selected\"] = {}, [\"known_pvp\"] = {} }, }\n}\n";
    $tmpPath = sys_get_temp_dir().'/mc-export-with-third.lua';
    File::put($tmpPath, $withThird);

    $this->artisan('wow:import-spellbook', ['path' => $tmpPath])
        ->expectsOutputToContain('Done: 1 snapshot(s) created, 2 skipped')
        ->assertSuccessful();

    expect(SpellbookSnapshot::count())->toBe(3)
        ->and(SpellbookSnapshot::where('class', 'MAGE')->exists())->toBeTrue();

    File::delete($tmpPath);
});

test('diff flags a spell present but with no matching spell_class_availability row as MISSING_AVAILABILITY', function () {
    makeSpellbookDiscipline();
    $this->artisan('wow:import-spellbook', ['path' => spellbookFixturePath()])->assertSuccessful();

    $snapshot = SpellbookSnapshot::first();

    $result = Artisan::call('wow:diff-spellbook', ['snapshot_id' => $snapshot->id]);
    expect($result)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('MISSING_AVAILABILITY: ')
        ->and($output)->toContain('Power Word: Shield');
});
