<?php

use App\Http\Services\PatchResolver;
use App\Http\Services\UserGuideChainService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\User;
use App\Models\UserGuide;

/**
 * A new game build relabels the patch row in place instead of forking a new one (2026-09-16).
 *
 * Before this, every distinct build string passed to import:spelldata created a new patches row,
 * and because every game-data table and every curated talent build points at patches.id, a real
 * patch bump orphaned all of them. The workaround was importing under a frozen string forever.
 */
function patchWorld(string $label = '12.0.7.68453'): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => $label, 'is_current' => true]);

    return compact('game', 'patch');
}

test('with no argument the current row is used and relabelled to the SimC build', function () {
    $w = patchWorld();

    $d = app(PatchResolver::class)->resolve($w['game'], null, '12.1.0.69814', partial: false, newPatch: false);

    expect($d['error'])->toBeNull()
        ->and($d['patch']->id)->toBe($w['patch']->id)
        ->and($d['create'])->toBeNull()
        ->and($d['relabel'])->toBe('12.1.0.69814');
});

test('the label is left alone on an --only run or when the SimC build is unknown or unchanged', function () {
    $w = patchWorld();
    $r = app(PatchResolver::class);

    expect($r->resolve($w['game'], null, '12.1.0.69814', partial: true, newPatch: false)['relabel'])->toBeNull()
        ->and($r->resolve($w['game'], null, null, partial: false, newPatch: false)['relabel'])->toBeNull()
        ->and($r->resolve($w['game'], null, '12.0.7.68453', partial: false, newPatch: false)['relabel'])->toBeNull();
});

test('passing the current label behaves exactly like passing nothing', function () {
    // DiffArenaSpells, DiscoverCcSpells and DiscoverAllSpecs re-invoke the importer with
    // $patch->build_version, so this path must still pick up a new SimC build.
    $w = patchWorld();

    $d = app(PatchResolver::class)->resolve($w['game'], '12.0.7.68453', '12.1.0.69814', partial: false, newPatch: false);

    expect($d['patch']->id)->toBe($w['patch']->id)->and($d['relabel'])->toBe('12.1.0.69814');
});

test('an unseen build version relabels the current row and never creates one', function () {
    $w = patchWorld();

    $d = app(PatchResolver::class)->resolve($w['game'], '12.1.0.69814', '12.1.0.69814', partial: true, newPatch: false);

    expect($d['patch']->id)->toBe($w['patch']->id)
        ->and($d['create'])->toBeNull()
        ->and($d['relabel'])->toBe('12.1.0.69814')
        ->and(Patch::count())->toBe(1);
});

test('forking a new row still works, but only when explicitly asked or when there is no patch yet', function () {
    $w = patchWorld();
    $r = app(PatchResolver::class);

    expect($r->resolve($w['game'], '13.0.0.1', null, partial: false, newPatch: true)['create'])->toBe('13.0.0.1')
        ->and($r->resolve($w['game'], null, null, partial: false, newPatch: true)['error'])->not->toBeNull();

    $empty = Game::create(['slug' => 'sc2', 'name' => 'StarCraft II']);
    expect($r->resolve($empty, '5.0.0', null, partial: false, newPatch: false)['create'])->toBe('5.0.0')
        ->and($r->resolve($empty, null, '5.0.0', partial: false, newPatch: false)['error'])->toContain('No current patch');
});

test('an argument naming another existing row imports into that row with a warning', function () {
    $w = patchWorld();
    $old = Patch::create(['game_id' => $w['game']->id, 'build_version' => '11.0.0', 'is_current' => false]);

    $d = app(PatchResolver::class)->resolve($w['game'], '11.0.0', '12.1.0.69814', partial: false, newPatch: false);

    expect($d['patch']->id)->toBe($old->id)
        ->and($d['relabel'])->toBeNull()
        ->and(collect($d['messages'])->pluck(1)->implode(' '))->toContain('NOT the current patch');
});

test('relabelling keeps every relationship pointed at the same row', function () {
    $w = patchWorld();
    $class = GameClass::create(['game_id' => $w['game']->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']);
    $spell = Spell::create(['patch_id' => $w['patch']->id, 'spell_id' => 408, 'name' => 'Kidney Shot']);
    $build = TalentBuild::create(['spec_id' => $spec->id, 'patch_id' => $w['patch']->id, 'name' => 'Default', 'share_slug' => 'default-subtlety', 'is_default' => true]);

    $result = app(PatchResolver::class)->relabel($w['patch'], '12.1.0.69814');

    expect($result['applied'])->toBeTrue()
        ->and(Patch::count())->toBe(1)
        ->and($w['patch']->fresh()->build_version)->toBe('12.1.0.69814')
        ->and($w['patch']->fresh()->is_current)->toBeTrue()
        ->and($spell->fresh()->patch_id)->toBe($w['patch']->id)
        ->and($build->fresh()->patch_id)->toBe($w['patch']->id);
});

test('relabelling refuses to take a label another row already has', function () {
    $w = patchWorld();
    $stray = Patch::create(['game_id' => $w['game']->id, 'build_version' => '12.1.0.69814', 'is_current' => false]);

    $result = app(PatchResolver::class)->relabel($w['patch'], '12.1.0.69814');

    expect($result['applied'])->toBeFalse()
        ->and($result['message'])->toContain("wow:prune-patch {$stray->id}")
        ->and($w['patch']->fresh()->build_version)->toBe('12.0.7.68453');
});

test('the importer never creates a patch row for an unseen version', function () {
    $w = patchWorld();

    // --only matches no class folder, so the run stops before importing — and therefore before the
    // relabel, which only happens once an import has actually succeeded.
    $this->artisan('import:spelldata', ['game' => 'wow', 'patch' => '99.9.9.99999', '--only' => 'no-such-class'])
        ->assertSuccessful();

    expect(Patch::count())->toBe(1)
        ->and($w['patch']->fresh()->build_version)->toBe('12.0.7.68453');
});

test('guides stay flagged as written on an older build after the row is relabelled', function () {
    $w = patchWorld();
    $user = User::factory()->create();
    $svc = app(UserGuideChainService::class);

    $written = UserGuide::create(['user_id' => $user->id, 'title' => 'Opener', 'patch_id' => $w['patch']->id, 'authored_build_version' => '12.0.7.68453']);
    // Stamped before authored_build_version existed: the frozen label never described the real
    // build, so it must not start claiming the guide is out of date.
    $legacy = UserGuide::create(['user_id' => $user->id, 'title' => 'Legacy', 'patch_id' => $w['patch']->id]);

    app(PatchResolver::class)->relabel($w['patch'], '12.1.0.69814');

    $health = $svc->health($written->fresh()->load('patch'), []);
    expect($health['patch_changed'])->toBeTrue()
        ->and($health['authored_patch'])->toBe('12.0.7.68453')
        ->and($health['current_patch'])->toBe('12.1.0.69814')
        ->and($svc->health($legacy->fresh()->load('patch'), [])['patch_changed'])->toBeFalse();
});
