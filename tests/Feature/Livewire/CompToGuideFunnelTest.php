<?php

use App\Enums\UserGuideMemberSide;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\IntendedCompService;
use App\Livewire\WowComps;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\User;
use App\Models\UserGuide;
use Livewire\Livewire;

/**
 * The route from /wow-comps into authoring — the site's only one, and until 2026-09-16 it did not
 * exist.
 *
 * The bug this covers was not a crash: "player guides for this comp" was wrapped in
 * `@if ($this->guides->isNotEmpty())`, so a comp with no guide rendered NOTHING and the page simply
 * ended. With 40 specs there are 9,880 three-spec combinations against a handful of guides, so that
 * was virtually every comp — on the most-visited page on the site by an order of magnitude (6,402
 * views in the 30 days to 2026-09-16, against 143 for every guide page combined). The comp picker
 * and the builder read as two unrelated products because nothing joined them.
 *
 * So the assertions to keep are the two that sound least like bugs: the block renders when the
 * listing is EMPTY, and a guest gets somewhere useful rather than a login wall.
 */
function makeCompFunnelFixture(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $specs = [];
    foreach ([['Druid', 'Restoration'], ['Rogue', 'Subtlety'], ['Mage', 'Frost'], ['Priest', 'Discipline']] as [$className, $specName]) {
        $class = GameClass::create(['game_id' => $game->id, 'name' => $className, 'slug' => Str::slug($className)]);
        $specs[strtolower($specName)] = Specialization::create([
            'class_id' => $class->id, 'name' => $specName, 'slug' => Str::slug($specName),
        ]);
    }

    return ['patch' => $patch, 'specs' => $specs];
}

/** Fill all three slots with real specs, the way a viewer does. */
function pickComp($component, array $specs)
{
    foreach (array_values($specs) as $i => $spec) {
        $component->call('selectSpec', $i, $spec->class_id, $spec->id);
    }

    return $component;
}

test('a signed-in player turns the comp in the slots into a guide with its roster already filled', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $user = User::factory()->create();

    $comp = [$specs['restoration'], $specs['subtlety'], $specs['frost']];

    $component = pickComp(Livewire::actingAs($user)->test(WowComps::class), $comp);
    expect($component->instance()->compIsComplete())->toBeTrue();

    $component->call('startGuideFromComp');

    $guide = UserGuide::where('user_id', $user->id)->latest('id')->firstOrFail();
    $component->assertRedirect(route('guides.edit', ['guide' => $guide->slug]));

    expect($guide->type)->toBe(UserGuideType::Comp)
        // A new guide is private until its author decides otherwise — starting one from a public
        // page must not change that.
        ->and($guide->status)->toBe(UserGuideStatus::Draft)
        ->and($guide->visibility)->toBe(UserGuideVisibility::Invited)
        // comp_key is what makes it findable from the comp it came from, so the round trip closes.
        ->and($guide->comp_key)->toBe(UserGuide::compKeyFor(collect($comp)->pluck('id')->all()));

    $roster = $guide->members()->where('side', UserGuideMemberSide::Team->value)->orderBy('position')->get();
    expect($roster->pluck('spec_id')->all())->toBe(collect($comp)->pluck('id')->all())
        ->and($roster->pluck('position')->all())->toBe([0, 1, 2]);

    // The starter section still exists, so the builder does not open on a blank page.
    expect($guide->sections()->count())->toBe(1);
});

test('the guides block renders for a comp with no guides — the empty state IS the funnel', function () {
    ['specs' => $specs] = makeCompFunnelFixture();

    $html = pickComp(
        Livewire::test(WowComps::class),
        [$specs['restoration'], $specs['subtlety'], $specs['frost']]
    )->html();

    expect($html)->toContain('Player guides for this comp')
        ->toContain('No plan yet for')
        // Named back to the reader, so it reads as being about the comp they just built.
        ->toContain('Frost Mage')
        ->toContain('Write the first plan');
});

test('a partial comp offers nothing and cannot start a guide', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(WowComps::class)
        ->call('selectSpec', 0, $specs['restoration']->class_id, $specs['restoration']->id);

    expect($component->instance()->compIsComplete())->toBeFalse()
        ->and($component->html())->not->toContain('Player guides for this comp');

    $component->call('startGuideFromComp');

    expect(UserGuide::count())->toBe(0);
});

test('a published public guide shows in the listing; a draft by the same author does not', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $user = User::factory()->create();
    $comp = [$specs['restoration'], $specs['subtlety'], $specs['frost']];

    $guide = UserGuide::startDraft($user, UserGuideType::Comp, collect($comp)->pluck('id')->all());

    $render = fn () => pickComp(Livewire::test(WowComps::class), $comp);

    // A draft is nobody else's business, even though its comp_key matches.
    expect($render()->instance()->guides)->toHaveCount(0);

    $guide->forceFill([
        'status' => UserGuideStatus::Published->value,
        'visibility' => UserGuideVisibility::Public->value,
        'published_at' => now(),
    ])->save();

    $listed = $render();
    expect($listed->instance()->guides->pluck('id')->all())->toBe([$guide->id]);
    // Once a guide exists the empty state is gone, but the way to write another is not.
    expect($listed->html())->not->toContain('No one has written a plan for')
        ->and($listed->html())->toContain('Write your own for this comp');
});

test('a guest goes straight into a guest plan for the comp, and signing up keeps it', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $comp = [$specs['restoration'], $specs['subtlety'], $specs['frost']];
    $specIds = collect($comp)->pluck('id')->all();

    $component = pickComp(Livewire::test(WowComps::class), $comp);
    expect($component->html())->toContain('No account needed to try it');

    // No sign-up wall: a guest plan (no owner) is created with the comp filled in. See GuestPlanService.
    $component->call('startGuideFromComp');
    $guide = \App\Models\UserGuide::sole();
    $component->assertRedirect(route('guides.edit', ['guide' => $guide->slug]));

    expect($guide->user_id)->toBeNull()
        ->and($guide->members()->orderBy('position')->pluck('spec_id')->all())->toBe($specIds);

    // What the register/login/Google/Battle.net controllers all call, from the same browser.
    request()->cookies->set(\App\Http\Services\GuestPlanService::COOKIE, $guide->guest_token);
    $user = User::factory()->create();
    $redirect = app(IntendedCompService::class)->redirectAfterAuth($user);

    expect($guide->fresh()->user_id)->toBe($user->id)
        ->and($redirect->getTargetUrl())->toBe(route('guides.edit', ['guide' => $guide->fresh()->slug]));
});

test('a remembered comp of specs that no longer exist yields no guide rather than an empty one', function () {
    makeCompFunnelFixture();
    $user = User::factory()->create();

    session(['intended_comp' => [999001, 999002, 999003]]);

    expect(app(IntendedCompService::class)->resume($user))->toBeNull();
});

test('registering after picking a comp lands in the builder, not the dashboard', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $specIds = [$specs['restoration']->id, $specs['subtlety']->id, $specs['frost']->id];

    session(['intended_comp' => $specIds]);

    // Recaptcha is bypassed outside production only for 'local'; the suite runs as 'testing', so
    // the HTTP register route cannot be exercised here (see CLAUDE.md's test-suite baseline note).
    // The controller's decision is the part this covers, and it is one call.
    $user = User::factory()->create();
    $redirect = app(IntendedCompService::class)->redirectAfterAuth($user);

    expect($redirect)->not->toBeNull();

    $guide = UserGuide::where('user_id', $user->id)->latest('id')->firstOrFail();
    expect($redirect->getTargetUrl())->toBe(route('guides.edit', ['guide' => $guide->slug]));
});

/**
 * game_id (2026-09-16). The interesting case is the SECOND one: a guide's game used to be
 * derivable only through its roster, so a guide without one had no game at all — which is the
 * normal state of a fresh draft and the permanent state of a prose-only strategy document.
 */
test('a new guide knows its game, roster or no roster', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $user = User::factory()->create();
    $wow = App\Models\Game::where('slug', 'wow')->firstOrFail();

    $withRoster = UserGuide::startDraft($user, UserGuideType::Comp, [
        $specs['restoration']->id, $specs['subtlety']->id, $specs['frost']->id,
    ]);
    expect($withRoster->game_id)->toBe($wow->id);

    $withoutRoster = UserGuide::startDraft($user, UserGuideType::Comp);
    expect($withoutRoster->members()->count())->toBe(0)
        ->and($withoutRoster->game_id)->toBe($wow->id);
});

test('a guide created before it had a game picks one up from its roster, and never changes it after', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $user = User::factory()->create();
    $wow = App\Models\Game::where('slug', 'wow')->firstOrFail();

    $guide = UserGuide::startDraft($user, UserGuideType::Comp);
    $guide->forceFill(['game_id' => null])->save();

    $guide->fillTeamRoster([$specs['restoration']->id]);
    expect($guide->fresh()->game_id)->toBe($wow->id);

    // A second game must not be able to silently steal an existing guide.
    $other = App\Models\Game::create(['slug' => 'sc2', 'name' => 'StarCraft II']);
    $guide->forceFill(['game_id' => $other->id])->save();
    $guide->syncGameFromRoster();
    expect($guide->fresh()->game_id)->toBe($other->id);
});

test('with more than one game seeded, a roster-less guide is left unattributed rather than guessed', function () {
    makeCompFunnelFixture();
    App\Models\Game::create(['slug' => 'sc2', 'name' => 'StarCraft II']);

    expect(UserGuide::defaultGameId())->toBeNull();
});

test('a class guide cannot be given a three-spec comp roster', function () {
    ['specs' => $specs] = makeCompFunnelFixture();
    $user = User::factory()->create();

    $guide = UserGuide::startDraft($user, UserGuideType::ClassGuide, [
        $specs['restoration']->id, $specs['subtlety']->id, $specs['frost']->id,
    ]);

    // maxSlotsFor() caps it at the type's own single slot — a tampered comp cannot build a team
    // inside a guide that has no concept of one.
    expect($guide->members()->count())->toBe(1)
        ->and($guide->members()->first()->spec_id)->toBe($specs['restoration']->id);
});
