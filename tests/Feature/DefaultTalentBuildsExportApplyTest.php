<?php

use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;

/**
 * Covers app/Console/Commands/ExportDefaultTalents.php + ApplyDefaultTalents.php — the
 * committed-file round trip for admin-default TalentBuild picks, added 2026-08-19 to close the
 * last "one command on a fresh machine" gap: TalentBuild selections had never been captured in
 * any committed file, unlike baseline-spec-overrides.txt/cc-synergies-overrides.txt for spell
 * data. See ExportDefaultTalents's own docblock for the full incident that surfaced this.
 *
 * Uses --path on both commands to point at fixture files rather than the real committed
 * data/spelldata/default-talent-builds.txt, so the test suite never reads or writes that file.
 */
function defaultTalentsFixturePath(): string
{
    return sys_get_temp_dir().'/default_talent_builds_test_fixture.txt';
}

afterEach(function () {
    @unlink(defaultTalentsFixturePath());
});

function buildDefaultTalentsFixtureWorld(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '1.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Druid', 'slug' => 'druid']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Restoration', 'slug' => 'restoration', 'external_spec_id' => 105]);

    $tree = TalentTree::create(['patch_id' => $patch->id, 'class_id' => $class->id, 'spec_id' => $spec->id, 'type' => 'spec', 'name' => 'Restoration', 'external_tree_id' => 1]);

    // A multi-rank node (two entries sharing one spell_id, disambiguated by rank) — the exact
    // shape rank-based matching exists for (e.g. real Improved Fade).
    $rankSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 111, 'name' => 'Improved Barkskin']);
    $rankNode = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'ACTIVE', 'pos_x' => 0, 'pos_y' => 0, 'max_ranks' => 2]);
    TalentNodeEntry::create(['talent_node_id' => $rankNode->id, 'spell_id' => $rankSpell->id, 'rank' => 1, 'max_rank' => 2, 'external_talent_id' => 1]);
    $rank2Entry = TalentNodeEntry::create(['talent_node_id' => $rankNode->id, 'spell_id' => $rankSpell->id, 'rank' => 2, 'max_rank' => 2, 'external_talent_id' => 2]);

    $pvpSpell = Spell::create(['patch_id' => $patch->id, 'spell_id' => 222, 'name' => 'Nature\'s Grasp']);
    $pvpTalent = PvpTalent::create(['spec_id' => $spec->id, 'patch_id' => $patch->id, 'spell_id' => $pvpSpell->id, 'external_pvp_talent_id' => 1]);

    $build = TalentBuild::create(['spec_id' => $spec->id, 'patch_id' => $patch->id, 'is_default' => true, 'name' => 'Default Build', 'share_slug' => 'test-slug']);

    return compact('game', 'patch', 'class', 'spec', 'tree', 'rankSpell', 'rankNode', 'rank2Entry', 'pvpSpell', 'pvpTalent', 'build');
}

test('exports and re-applies an admin-default TalentBuild round-trip correctly, including rank disambiguation', function () {
    $world = buildDefaultTalentsFixtureWorld();

    app(App\Http\Services\TalentSelectionService::class)->saveChoice($world['build'], $world['rankNode'], $world['rank2Entry']);
    app(App\Http\Services\TalentSelectionService::class)->syncPvpChoices($world['build'], [$world['pvpTalent']->id]);

    $this->artisan('wow:export-default-talents', ['--path' => defaultTalentsFixturePath()])->assertSuccessful();

    $exported = file_get_contents(defaultTalentsFixturePath());
    expect($exported)->toContain('druid | restoration | pve | 111 | 2 | Improved Barkskin')
        ->and($exported)->toContain('druid | restoration | pvp | 222 | | Nature\'s Grasp');

    // Wipe the build's picks, then apply from the exported file — should reproduce exactly.
    $world['build']->choices()->delete();
    $world['build']->pvpChoices()->delete();

    $this->artisan('wow:apply-default-talents', ['--path' => defaultTalentsFixturePath()])
        ->assertSuccessful();

    $restored = $world['build']->fresh(['choices', 'pvpChoices']);
    expect($restored->choices)->toHaveCount(1)
        ->and($restored->choices->first()->chosen_entry_id)->toBe($world['rank2Entry']->id)
        ->and($restored->choices->first()->rank)->toBe(2)
        ->and($restored->pvpChoices)->toHaveCount(1)
        ->and($restored->pvpChoices->first()->pvp_talent_id)->toBe($world['pvpTalent']->id);
});

test('apply skips a line whose spell no longer exists for the current patch, without crashing', function () {
    $world = buildDefaultTalentsFixtureWorld();

    file_put_contents(defaultTalentsFixturePath(), implode("\n", [
        '# fixture',
        'druid | restoration | pve | 999999 | 1 | Nonexistent Spell',
        "druid | restoration | pve | 111 | 2 | Improved Barkskin",
    ]));

    $this->artisan('wow:apply-default-talents', ['--path' => defaultTalentsFixturePath()])
        ->assertSuccessful();

    $restored = $world['build']->fresh('choices');
    expect($restored->choices)->toHaveCount(1)
        ->and($restored->choices->first()->chosen_entry_id)->toBe($world['rank2Entry']->id);
});

test('apply fails loudly when the file is missing', function () {
    $this->artisan('wow:apply-default-talents', ['--path' => defaultTalentsFixturePath()])
        ->assertFailed();
});
