<?php

use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideType;
use App\Http\Services\TalentSelectionService;
use App\Http\Services\UserGuideChainService;
use App\Livewire\Guides\Builder;
use App\Livewire\Guides\Index;
use App\Livewire\TalentSelector;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\TalentBuild;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Covers the 2026-09-08 guide changes: the merged Sequence section kind, the per-member talent
 * build, and the guide-health signal.
 *
 * The palette's own grouping is exercised against real imported spell data by hand (it needs a
 * talent tree, arena-log priority flags and a curated dr_category to say anything meaningful,
 * none of which a fixture has). What is asserted here is the behaviour that must hold regardless
 * of what data is loaded: that the retired kinds are gone, that a guide's build is private to
 * that guide, and that a step which stops resolving is reported rather than dropped.
 */
function guideFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '1.0.0-test', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']);
    $user = User::factory()->create();

    $guide = UserGuide::create([
        'user_id' => $user->id,
        'status' => 'draft',
        'visibility' => 'invited',
        'title' => 'Test guide',
    ]);

    $member = UserGuideMember::create([
        'user_guide_id' => $guide->id,
        'position' => 0,
        'spec_id' => $spec->id,
    ]);

    return compact('game', 'patch', 'class', 'spec', 'user', 'guide', 'member');
}

/*
 * The merged Sequence kind.
 */

test('the retired chain and go kinds no longer exist', function () {
    expect(UserGuideSectionKind::tryFrom('chain'))->toBeNull()
        ->and(UserGuideSectionKind::tryFrom('go'))->toBeNull()
        ->and(UserGuideSectionKind::tryFrom('sequence'))->toBe(UserGuideSectionKind::Sequence);

    // Three kinds, and only one of them is an ability sequence.
    expect(collect(UserGuideSectionKind::cases())->map->value->all())
        ->toBe(['sequence', 'defensives', 'text']);
});

test('a request naming a retired kind creates nothing rather than falling back to a default', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])
        ->call('addSection', 'chain')
        ->call('addSection', 'go');

    expect($f['guide']->sections()->count())->toBe(0);
});

test('the index creates a guide of each type, and both start with one sequence section', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Index::class)->call('create', 'comp')->assertRedirect();
    Livewire::actingAs($f['user'])->test(Index::class)->call('create', 'class')->assertRedirect();

    $comp = UserGuide::where('title', 'Untitled comp guide')->firstOrFail();
    $class = UserGuide::where('title', 'Untitled class guide')->firstOrFail();

    expect($comp->type)->toBe(UserGuideType::Comp)
        ->and($class->type)->toBe(UserGuideType::ClassGuide)
        ->and($comp->sections()->first()->kind)->toBe(UserGuideSectionKind::Sequence)
        ->and($class->sections()->first()->kind)->toBe(UserGuideSectionKind::Sequence);

    // A section kind is not a guide type — passing one creates nothing.
    Livewire::actingAs($f['user'])->test(Index::class)->call('create', 'sequence');
    expect(UserGuide::count())->toBe(3); // the fixture's own guide, plus the two above
});

/*
 * Per-member talent builds.
 */

test('a comp slot has no build until the author opens its talents', function () {
    $f = guideFixture();

    expect($f['member']->talent_build_id)->toBeNull();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])
        ->call('openTalents', 0);

    $build = $f['member']->fresh()->talentBuild;

    expect($build)->not->toBeNull()
        ->and($build->spec_id)->toBe($f['spec']->id);
});

test('a guide own build is invisible to the rest of the site', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])->call('openTalents', 0);
    $guideBuild = $f['member']->fresh()->talentBuild;

    // user_id NULL + is_default FALSE is the combination that keeps it out of both lookups —
    // otherwise it would surface on WoW Comps and Spell Explorer for every visitor.
    expect($guideBuild->user_id)->toBeNull()
        ->and((bool) $guideBuild->is_default)->toBeFalse();

    $service = app(TalentSelectionService::class);

    expect($service->resolveActiveBuild(null, $f['spec']->id)->id)->not->toBe($guideBuild->id)
        ->and($service->resolveActiveBuild($f['user'], $f['spec']->id)->id)->not->toBe($guideBuild->id);
});

test('resetting talents deletes the build rather than emptying it', function () {
    $f = guideFixture();

    $c = Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']]);
    $c->call('openTalents', 0);
    $buildId = $f['member']->fresh()->talent_build_id;

    $c->call('resetTalents', 0);

    // An empty build and no build are different states: an empty one would resolve every ability
    // to its untalented numbers, where "reset" has to mean "back to the spec's default".
    expect($f['member']->fresh()->talent_build_id)->toBeNull()
        ->and(TalentBuild::find($buildId))->toBeNull();
});

test('another author cannot open or reset talents on a guide they do not own', function () {
    $f = guideFixture();
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)->test(Builder::class, ['guide' => $f['guide']])->assertForbidden();

    expect($f['member']->fresh()->talent_build_id)->toBeNull();
});

test('the talent build id is locked, so it cannot be repointed from the client', function () {
    $f = guideFixture();

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])->call('openTalents', 0);
    $ours = $f['member']->fresh()->talentBuild;

    // The shape a tampered request would take: point the selector at somebody else's build.
    $victim = TalentBuild::create([
        'spec_id' => $f['spec']->id,
        'patch_id' => $f['patch']->id,
        'is_default' => true,
        'name' => 'Admin default',
        'share_slug' => 'victim-slug',
    ]);

    $component = Livewire::actingAs($f['user'])
        ->test(TalentSelector::class, ['specId' => $f['spec']->id, 'buildId' => $ours->id]);

    expect(fn () => $component->set('buildId', $victim->id))->toThrow(Exception::class);
    expect($component->instance()->buildId)->toBe($ours->id);
});

/*
 * Guide health — what a patch does to a saved guide.
 */

test('a step whose spell no longer exists is reported, not dropped', function () {
    $f = guideFixture();

    $section = UserGuideSection::create([
        'user_guide_id' => $f['guide']->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'Opener',
        'row' => 0,
        'column' => 0,
    ]);

    // 999999 exists in no patch — the shape of an ability removed by a later patch.
    UserGuideBlock::create([
        'user_guide_section_id' => $section->id,
        'position' => 0,
        'block_type' => 'spell',
        'payload' => ['external_spell_id' => 999999, 'source_spec_id' => $f['spec']->id],
    ]);

    $svc = app(UserGuideChainService::class);
    $steps = $svc->resolve($section);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['unresolved'])->toBeTrue()
        ->and($steps[0]['block']->externalSpellId())->toBe(999999);

    $health = $svc->health($f['guide']->fresh(), [$section->id => ['steps' => $steps, 'metrics' => []]]);

    expect($health['unresolved'])->toBe(1)
        ->and($health['sections'])->toBe([$section->id => 'Opener']);
});

test('a guide authored on an older patch is flagged, and one with no patch is not', function () {
    $f = guideFixture();
    $svc = app(UserGuideChainService::class);

    // No patch recorded — the guide was never really edited, so there is nothing to be stale.
    expect($svc->health($f['guide'], [])['patch_changed'])->toBeFalse();

    $old = Patch::create(['game_id' => $f['game']->id, 'build_version' => '0.9.0-old', 'is_current' => false]);
    $f['guide']->update(['patch_id' => $old->id]);

    $health = $svc->health($f['guide']->fresh()->load('patch'), []);

    expect($health['patch_changed'])->toBeTrue()
        ->and($health['authored_patch'])->toBe('0.9.0-old')
        ->and($health['current_patch'])->toBe('1.0.0-test');
});

/*
 * The two guide types.
 */

test('a comp guide holds three specs and a class guide holds one', function () {
    $f = guideFixture();
    $second = Specialization::create(['class_id' => $f['class']->id, 'name' => 'Outlaw', 'slug' => 'outlaw']);

    expect($f['guide']->type)->toBe(UserGuideType::Comp) // the column default
        ->and($f['guide']->maxMembers())->toBe(3);

    $f['guide']->update(['type' => UserGuideType::ClassGuide]);
    $guide = $f['guide']->fresh();

    expect($guide->maxMembers())->toBe(1);

    // Slot 1 is out of range for a class guide, so a request naming it must do nothing rather
    // than quietly building a comp inside a single-spec guide.
    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $guide])
        ->call('openMemberPicker', 1)
        ->call('setMember', $second->id);

    expect($guide->members()->count())->toBe(1);
});

test('a class guide never reports a bracket, however many rows it has', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::ClassGuide, 'opponent_spec_id' => $f['spec']->id]);

    expect($f['guide']->fresh()->bracket())->toBeNull();

    // The same roster in a comp guide is a real bracket, so this is the type deciding, not the count.
    $f['guide']->update(['type' => UserGuideType::Comp]);
    UserGuideMember::create(['user_guide_id' => $f['guide']->id, 'position' => 1, 'spec_id' => $f['spec']->id]);

    expect($f['guide']->fresh()->bracket())->toBe('2v2');
});

test('only a class guide names a guide-level opponent', function () {
    $f = guideFixture();

    // A comp guide's opponents are per-section, so the guide-level setter must refuse.
    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']])
        ->call('openGuideOpponentPicker')
        ->call('setGuideOpponent', $f['spec']->id);

    expect($f['guide']->fresh()->opponent_spec_id)->toBeNull();

    $f['guide']->update(['type' => UserGuideType::ClassGuide]);

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('openGuideOpponentPicker')
        ->call('setGuideOpponent', $f['spec']->id);

    expect($f['guide']->fresh()->opponent_spec_id)->toBe($f['spec']->id);
});

test('a defensives section in a class guide inherits the guide opponent, and in a comp guide does not', function () {
    $f = guideFixture();
    $f['guide']->update(['type' => UserGuideType::ClassGuide, 'opponent_spec_id' => $f['spec']->id]);

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $f['guide']->fresh()])
        ->call('addSection', 'defensives');

    expect($f['guide']->sections()->latest('id')->first()->opponent_spec_id)->toBe($f['spec']->id);

    // A comp guide's VS columns are per-section on purpose — nothing to inherit.
    $comp = UserGuide::create([
        'user_id' => $f['user']->id,
        'type' => UserGuideType::Comp,
        'status' => 'draft',
        'visibility' => 'invited',
        'title' => 'Comp',
    ]);

    Livewire::actingAs($f['user'])->test(Builder::class, ['guide' => $comp])
        ->call('addSection', 'defensives');

    expect($comp->sections()->latest('id')->first()->opponent_spec_id)->toBeNull();
});
