<?php

use App\Http\Services\TalentSelectionService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\TalentBuild;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

function makeSpecFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '11.0.0', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    return compact('game', 'patch', 'class', 'spec');
}

test('resolveActiveBuild prefers the users saved build over the default', function () {
    $fixture = makeSpecFixture();
    $user = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('secret')]);

    TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id,
        'name' => 'Default Build', 'share_slug' => 'default-1', 'is_default' => true,
    ]);

    $userBuild = TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id, 'user_id' => $user->id,
        'name' => 'My Build', 'share_slug' => 'mine-1',
    ]);

    $service = new TalentSelectionService();
    $resolved = $service->resolveActiveBuild($user, $fixture['spec']->id);

    expect($resolved->id)->toBe($userBuild->id);
});

test('resolveActiveBuild falls back to the default build when the user has none', function () {
    $fixture = makeSpecFixture();
    $user = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => bcrypt('secret')]);

    $default = TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id,
        'name' => 'Default Build', 'share_slug' => 'default-2', 'is_default' => true,
    ]);

    $service = new TalentSelectionService();
    $resolved = $service->resolveActiveBuild($user, $fixture['spec']->id);

    expect($resolved->id)->toBe($default->id);
});

test('resolveActiveBuild returns an unsaved shell when nothing exists for a guest', function () {
    $fixture = makeSpecFixture();

    $service = new TalentSelectionService();
    $resolved = $service->resolveActiveBuild(null, $fixture['spec']->id);

    expect($resolved->exists)->toBeFalse();
});

test('a user cannot have two saved builds for the same spec', function () {
    $fixture = makeSpecFixture();
    $user = User::create(['name' => 'C', 'email' => 'c@example.com', 'password' => bcrypt('secret')]);

    TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id, 'user_id' => $user->id,
        'name' => 'First', 'share_slug' => 'first-1',
    ]);

    expect(fn () => TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id, 'user_id' => $user->id,
        'name' => 'Second', 'share_slug' => 'second-1',
    ]))->toThrow(QueryException::class);
});

test('setDefault deactivates any prior default for the same spec and patch', function () {
    $fixture = makeSpecFixture();

    $service = new TalentSelectionService();
    $first = $service->getOrCreateDefaultBuild($fixture['spec']->id, $fixture['patch']->id);

    $second = TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id,
        'name' => 'New Meta', 'share_slug' => 'new-meta-1',
    ]);

    $service->setDefault($second);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

test('selectedSpellIds flattens PvE tree picks and PvP talent picks together', function () {
    $fixture = makeSpecFixture();

    $tree = TalentTree::create([
        'patch_id' => $fixture['patch']->id, 'class_id' => $fixture['class']->id,
        'spec_id' => $fixture['spec']->id, 'type' => 'spec', 'name' => 'Discipline', 'external_tree_id' => 1,
    ]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'ACTIVE', 'max_ranks' => 1]);

    $peSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 111, 'name' => 'PE Spell']);
    $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $peSpell->id, 'rank' => 1, 'max_rank' => 1]);

    $pvpSpell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 222, 'name' => 'PvP Spell']);
    $pvpTalent = PvpTalent::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id, 'spell_id' => $pvpSpell->id,
        'unlock_level' => 20, 'external_pvp_talent_id' => 5,
    ]);

    $build = TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id,
        'name' => 'Build', 'share_slug' => 'flatten-1',
    ]);

    $service = new TalentSelectionService();
    $service->saveChoice($build, $node, $entry);
    $service->syncPvpChoices($build, [$pvpTalent->id]);

    $ids = $service->selectedSpellIds($build->fresh());

    expect($ids->sort()->values()->all())->toBe(collect([$peSpell->id, $pvpSpell->id])->sort()->values()->all());
});

test('syncPvpChoices replaces the whole selection rather than appending', function () {
    $fixture = makeSpecFixture();

    $spellA = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 301, 'name' => 'A']);
    $spellB = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 302, 'name' => 'B']);
    $talentA = PvpTalent::create(['spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id, 'spell_id' => $spellA->id, 'unlock_level' => 1, 'external_pvp_talent_id' => 1]);
    $talentB = PvpTalent::create(['spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id, 'spell_id' => $spellB->id, 'unlock_level' => 1, 'external_pvp_talent_id' => 2]);

    $build = TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id,
        'name' => 'Build', 'share_slug' => 'sync-1',
    ]);

    $service = new TalentSelectionService();
    $service->syncPvpChoices($build, [$talentA->id, $talentB->id]);
    expect($build->pvpChoices()->count())->toBe(2);

    $service->syncPvpChoices($build, [$talentB->id]);
    expect($build->pvpChoices()->count())->toBe(1)
        ->and($build->pvpChoices()->first()->pvp_talent_id)->toBe($talentB->id);
});

test('explicitBaselineCooldownAbilityIds only surfaces explicit spec_id baseline rows meeting the cooldown/mechanic bar', function () {
    $fixture = makeSpecFixture();

    $longCooldown = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 401, 'name' => 'Real Cooldown Ability',
        'cooldown_seconds' => 20, 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    SpellClassAvailability::create([
        'spell_id' => $longCooldown->id, 'class_id' => $fixture['class']->id,
        'spec_id' => $fixture['spec']->id, 'source' => 'baseline',
    ]);

    $shortCooldown = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 402, 'name' => 'Short Filler Ability',
        'cooldown_seconds' => 3, 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    SpellClassAvailability::create([
        'spell_id' => $shortCooldown->id, 'class_id' => $fixture['class']->id,
        'spec_id' => $fixture['spec']->id, 'source' => 'baseline',
    ]);

    $sleepCc = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 403, 'name' => 'Short Sleep Effect',
        'cooldown_seconds' => 5, 'mechanic' => 'Sleep', 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    SpellClassAvailability::create([
        'spell_id' => $sleepCc->id, 'class_id' => $fixture['class']->id,
        'spec_id' => $fixture['spec']->id, 'source' => 'baseline',
    ]);

    // Ambiguous spec_id = NULL — even with a qualifying cooldown, must NEVER be surfaced
    // by this method (that's alwaysAvailableAbilityIds()'s job, DO NOT WIRE IN).
    $ambiguousSpec = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 404, 'name' => 'Ambiguous Spec Ability',
        'cooldown_seconds' => 30, 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    SpellClassAvailability::create([
        'spell_id' => $ambiguousSpec->id, 'class_id' => $fixture['class']->id,
        'spec_id' => null, 'source' => 'baseline',
    ]);

    // Explicit spec_id but a talent pick, not baseline — must be excluded (different source).
    $talentPick = Spell::create([
        'patch_id' => $fixture['patch']->id, 'spell_id' => 405, 'name' => 'Talent Pick Ability',
        'cooldown_seconds' => 45, 'is_passive' => false, 'not_in_spellbook' => false,
    ]);
    SpellClassAvailability::create([
        'spell_id' => $talentPick->id, 'class_id' => $fixture['class']->id,
        'spec_id' => $fixture['spec']->id, 'source' => 'talent',
    ]);

    $service = new TalentSelectionService();
    $ids = $service->explicitBaselineCooldownAbilityIds($fixture['class']->id, $fixture['spec']->id);

    expect($ids->sort()->values()->all())->toBe([$longCooldown->id, $sleepCc->id]);
});

test('saveChoice, deleteChoice, syncPvpChoices, and pruneNodeChoices all touch the build', function () {
    // WowComps/SpellExplorer key their per-viewer spell-reference cache off a personal build's
    // own updated_at (see the "Personal talent picker" change, 2026-08-10) — a save that doesn't
    // touch the parent build would silently serve stale cached spell data to that one viewer
    // forever (or until the unrelated global spellCacheVersion counter happens to bump).
    $fixture = makeSpecFixture();

    $tree = TalentTree::create([
        'patch_id' => $fixture['patch']->id, 'class_id' => $fixture['class']->id,
        'spec_id' => $fixture['spec']->id, 'type' => 'spec', 'name' => 'Discipline', 'external_tree_id' => 1,
    ]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'ACTIVE', 'max_ranks' => 1]);
    $spell = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 501, 'name' => 'Touch Test Spell']);
    $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $spell->id, 'rank' => 1, 'max_rank' => 1]);

    $build = TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id,
        'name' => 'Touch Build', 'share_slug' => 'touch-1',
    ]);

    $service = new TalentSelectionService();

    Carbon::setTestNow('2026-01-01 00:05:00');
    $service->saveChoice($build, $node, $entry);
    expect($build->fresh()->updated_at->equalTo(Carbon::parse('2026-01-01 00:05:00')))->toBeTrue();

    Carbon::setTestNow('2026-01-01 00:10:00');
    $service->deleteChoice($build, $node->id);
    expect($build->fresh()->updated_at->equalTo(Carbon::parse('2026-01-01 00:10:00')))->toBeTrue();

    Carbon::setTestNow('2026-01-01 00:15:00');
    $service->syncPvpChoices($build, []);
    expect($build->fresh()->updated_at->equalTo(Carbon::parse('2026-01-01 00:15:00')))->toBeTrue();

    Carbon::setTestNow('2026-01-01 00:20:00');
    $service->pruneNodeChoices($build, [$node->id]);
    expect($build->fresh()->updated_at->equalTo(Carbon::parse('2026-01-01 00:20:00')))->toBeTrue();

    Carbon::setTestNow();
});

test('spellCacheVersion defaults to 1 and bumpSpellCacheVersion increments it', function () {
    $service = new TalentSelectionService();

    expect($service->spellCacheVersion())->toBe(1);

    $service->bumpSpellCacheVersion();
    expect($service->spellCacheVersion())->toBe(2);

    $service->bumpSpellCacheVersion();
    expect($service->spellCacheVersion())->toBe(3);
});

test('spellCacheVersion survives a full cache flush', function () {
    // The whole point of moving this counter off the Cache facade (2026-08-23, see CLAUDE.md) —
    // a plain `php artisan cache:clear` (Cache::flush() under the hood) used to silently reset
    // this back to its default of 1, defeating every prior bump with no error or warning.
    $service = new TalentSelectionService();

    $service->bumpSpellCacheVersion();
    $service->bumpSpellCacheVersion();
    expect($service->spellCacheVersion())->toBe(3);

    \Illuminate\Support\Facades\Cache::flush();

    expect($service->spellCacheVersion())->toBe(3);
});

test('bumpSpellCacheVersion re-creates the seed row if it was deleted', function () {
    $service = new TalentSelectionService();

    \Illuminate\Support\Facades\DB::table('wow_spell_cache_state')->where('id', 1)->delete();
    expect($service->spellCacheVersion())->toBe(1);

    $service->bumpSpellCacheVersion();
    expect($service->spellCacheVersion())->toBe(2);
});

test('selectedSpellIds excludes Asphyxiate when Strangulate is selected (PVP_TALENT_REPLACES)', function () {
    // Regression test for the 2026-08-25 fix — a Death Knight build could have Asphyxiate
    // (a PvE class-tree CHOICE-node pick) AND Strangulate (a PvP talent) both technically
    // "selected" in our data at once, even though real gameplay means Strangulate replaces
    // Asphyxiate entirely (confirmed directly by the user). Real Blizzard spell_ids used here
    // (47476 / 221562) since PVP_TALENT_REPLACES is keyed by them, not internal spells.id.
    $fixture = makeSpecFixture();

    $tree = TalentTree::create([
        'patch_id' => $fixture['patch']->id, 'class_id' => $fixture['class']->id,
        'spec_id' => $fixture['spec']->id, 'type' => 'spec', 'name' => 'Test Tree', 'external_tree_id' => 1,
    ]);
    $node = TalentNode::create(['talent_tree_id' => $tree->id, 'external_node_id' => 1, 'type' => 'CHOICE', 'max_ranks' => 1]);
    $asphyxiate = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 221562, 'name' => 'Asphyxiate']);
    $entry = TalentNodeEntry::create(['talent_node_id' => $node->id, 'spell_id' => $asphyxiate->id, 'rank' => 1, 'max_rank' => 1]);

    $strangulate = Spell::create(['patch_id' => $fixture['patch']->id, 'spell_id' => 47476, 'name' => 'Strangulate']);
    $pvpTalent = PvpTalent::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id, 'spell_id' => $strangulate->id,
        'unlock_level' => 45, 'external_pvp_talent_id' => 206,
    ]);

    $build = TalentBuild::create([
        'spec_id' => $fixture['spec']->id, 'patch_id' => $fixture['patch']->id,
        'name' => 'DK Build', 'share_slug' => 'dk-strangulate-1',
    ]);

    $service = new TalentSelectionService();
    $service->saveChoice($build, $node, $entry);

    // Asphyxiate chosen, Strangulate not yet selected — Asphyxiate is normally selected.
    expect($service->selectedSpellIds($build->fresh())->all())->toBe([$asphyxiate->id]);

    // Selecting Strangulate suppresses Asphyxiate from the selected set entirely, even though
    // its own CHOICE-node pick was never undone.
    $service->syncPvpChoices($build, [$pvpTalent->id]);
    $ids = $service->selectedSpellIds($build->fresh());
    expect($ids->contains($strangulate->id))->toBeTrue()
        ->and($ids->contains($asphyxiate->id))->toBeFalse();
});
