<?php

use App\Models\GameClass;
use App\Models\Game;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;

/**
 * Covers app/Console/Commands/ApplyIconManifest.php — populates icon_name columns from a
 * committed JSON manifest with zero Blizzard API calls. See that command's docblock for why
 * this exists (committing the image files alone doesn't survive a migrate:fresh; the DB's
 * icon_name columns still need something to repopulate them without real credentials).
 *
 * Uses --path to point at a fixture file rather than the real committed
 * data/spelldata/icon-manifest.json, so the test suite never reads or writes that real file.
 */
function manifestFixturePath(): string
{
    return sys_get_temp_dir() . '/apply_icon_manifest_test_fixture.json';
}

afterEach(function () {
    @unlink(manifestFixturePath());
});

test('applies spell, class, and spec icons from the manifest, only where icon_name is null', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '1.0.0', 'is_current' => true]);

    $priest = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $discipline = Specialization::create(['class_id' => $priest->id, 'name' => 'Discipline', 'slug' => 'discipline', 'external_spec_id' => 256]);

    $spellUntouched = Spell::create(['patch_id' => $patch->id, 'spell_id' => 17, 'name' => 'Power Word: Shield']);
    $spellAlreadySet = Spell::create(['patch_id' => $patch->id, 'spell_id' => 47540, 'name' => 'Penance', 'icon_name' => 'existing.jpg']);

    file_put_contents(manifestFixturePath(), json_encode([
        'spells' => ['17' => 'spell_holy_powerwordshield.jpg', '47540' => 'spell_holy_penance.jpg'],
        'classes' => ['priest' => 'classicon_priest.jpg'],
        'specs' => ['256' => 'spell_holy_powerwordshield.jpg'],
    ]));

    $this->artisan('wow:apply-icon-manifest', ['--path' => manifestFixturePath()])->assertSuccessful();

    expect($spellUntouched->fresh()->icon_name)->toBe('spell_holy_powerwordshield.jpg')
        // Manifest carries an entry for this spell_id too, but its icon_name was already set —
        // the command must not overwrite an existing value.
        ->and($spellAlreadySet->fresh()->icon_name)->toBe('existing.jpg')
        ->and($priest->fresh()->icon_name)->toBe('classicon_priest.jpg')
        ->and($discipline->fresh()->icon_name)->toBe('spell_holy_powerwordshield.jpg');
});

test('fails loudly when the manifest file is missing', function () {
    $this->artisan('wow:apply-icon-manifest', ['--path' => manifestFixturePath()])
        ->assertFailed();
});
