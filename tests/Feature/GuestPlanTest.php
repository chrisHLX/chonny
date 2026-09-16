<?php

use App\Enums\UserGuideSectionKind;
use App\Http\Services\GuestPlanService;
use App\Livewire\Guides\Builder;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\TalentBuild;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideMember;
use Livewire\Livewire;

/**
 * Trying the planner without an account: a guest plan lives on a cookie, only that browser can open
 * it, it cannot be published or shared, and signing up or logging in moves it onto the account.
 */
function guestPlanCookie(\Illuminate\Testing\TestResponse $response): string
{
    $cookie = $response->getCookie(GuestPlanService::COOKIE, decrypt: true);
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

beforeEach(function () {
    $game = Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    Patch::firstOrCreate(['build_version' => '1.0.0-guest'], ['game_id' => $game->id, 'is_current' => true]);
});

test('a guest can start a plan and open it, and nobody else can', function () {
    $response = $this->post('/try/comp');

    $guide = UserGuide::sole();
    expect($guide->user_id)->toBeNull()
        ->and($guide->guest_token)->toHaveLength(64)
        ->and($guide->slug)->toStartWith('plan-');
    $response->assertRedirect(route('guides.edit', ['guide' => $guide->slug]));

    $token = guestPlanCookie($response);
    expect($token)->toBe($guide->guest_token);

    $this->withCookie(GuestPlanService::COOKIE, $token)
        ->get(route('guides.edit', ['guide' => $guide->slug]))
        ->assertOk()
        ->assertSee("You're trying the planner", false);

    // A different browser (no cookie, or another token) gets a 403, signed in or not.
    $this->flushSession();
    $this->withCookie(GuestPlanService::COOKIE, str_repeat('x', 64))
        ->get(route('guides.edit', ['guide' => $guide->slug]))->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->withCookie(GuestPlanService::COOKIE, str_repeat('y', 64))
        ->get(route('guides.edit', ['guide' => $guide->slug]))->assertForbidden();

    expect(PageViewEvent::where('page', 'guide_try')->count())->toBe(1);
});

test('a guest plan cannot be published, shared, or duplicated', function () {
    $guide = UserGuide::startDraft(null, \App\Enums\UserGuideType::Comp, [], str_repeat('a', 64));
    $class = GameClass::create(['game_id' => Game::first()->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']);
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $spec->id]);

    request()->cookies->set(GuestPlanService::COOKIE, str_repeat('a', 64));

    Livewire::withCookie(GuestPlanService::COOKIE, str_repeat('a', 64))
        ->test(Builder::class, ['guide' => $guide->fresh()])
        ->call('publish')
        ->call('setVisibility', 'public')
        ->call('setFriendsCanEdit', true)
        ->call('duplicate')
        ->call('addSection', 'text');

    $guide->refresh();
    expect($guide->status->value)->toBe('draft')
        ->and($guide->visibility->value)->toBe('invited')
        ->and($guide->friends_can_edit)->toBeFalse()
        ->and(UserGuide::count())->toBe(1)
        // Content edits still work: that is the point of trying it.
        ->and($guide->sections()->where('kind', UserGuideSectionKind::Text)->exists())->toBeTrue();
});

test('logging in moves the guest plan onto the account and opens it', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $guide = UserGuide::startDraft(null, \App\Enums\UserGuideType::Comp, [], str_repeat('b', 64));
    $guide->update(['title' => 'RMP into Disc']);

    // Login needs reCAPTCHA outside local, so claim through the same shared hook every
    // sign-in and sign-up path calls.
    request()->cookies->set(GuestPlanService::COOKIE, str_repeat('b', 64));
    $redirect = app(\App\Http\Services\IntendedCompService::class)->redirectAfterAuth($user);

    $guide->refresh();
    expect($guide->user_id)->toBe($user->id)
        ->and($guide->guest_token)->toBeNull()
        ->and($guide->slug)->toBe('rmp-into-disc')
        ->and($redirect->getTargetUrl())->toBe(route('guides.edit', ['guide' => 'rmp-into-disc']))
        ->and($guide->sections()->first()->created_by_user_id)->toBe($user->id)
        ->and(PageViewEvent::where('page', 'guide_try_claimed')->count())->toBe(1);

    $this->actingAs($user)->get(route('guides.edit', ['guide' => 'rmp-into-disc']))->assertOk();
});

test('a browser with no guest plans claims nothing', function () {
    $user = User::factory()->create();
    UserGuide::startDraft(null, \App\Enums\UserGuideType::Comp, [], str_repeat('c', 64));

    request()->cookies->set(GuestPlanService::COOKIE, str_repeat('d', 64));

    expect(app(GuestPlanService::class)->claim($user))->toBeNull()
        ->and(UserGuide::whereNull('user_id')->count())->toBe(1);
});

test('stale guest plans are pruned with their talent builds, and a browser keeps at most three', function () {
    $class = GameClass::create(['game_id' => Game::first()->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety']);

    $old = UserGuide::startDraft(null, \App\Enums\UserGuideType::Comp, [], str_repeat('e', 64));
    $build = TalentBuild::create(['spec_id' => $spec->id, 'name' => 'Guide build', 'share_slug' => 'x-1', 'patch_id' => Patch::first()->id]);
    UserGuideMember::create(['user_guide_id' => $old->id, 'position' => 0, 'spec_id' => $spec->id, 'talent_build_id' => $build->id]);
    UserGuide::whereKey($old->id)->update(['updated_at' => now()->subDays(GuestPlanService::KEEP_DAYS + 1)]);

    $owned = UserGuide::startDraft(User::factory()->create(), \App\Enums\UserGuideType::Comp);
    UserGuide::whereKey($owned->id)->update(['updated_at' => now()->subYear()]);

    expect(app(GuestPlanService::class)->pruneStale())->toBe(1)
        ->and(UserGuide::find($old->id))->toBeNull()
        ->and(TalentBuild::find($build->id))->toBeNull()
        ->and(UserGuide::find($owned->id))->not->toBeNull();

    request()->cookies->set(GuestPlanService::COOKIE, str_repeat('f', 64));
    foreach (range(1, 5) as $i) {
        app(GuestPlanService::class)->start(\App\Enums\UserGuideType::Comp);
    }
    expect(UserGuide::where('guest_token', str_repeat('f', 64))->count())->toBe(GuestPlanService::MAX_PER_VISITOR);
});

test('a signed-in player using the try route just gets a normal draft', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/try/class')->assertRedirect();

    expect(UserGuide::sole()->user_id)->toBe($user->id)
        ->and(UserGuide::sole()->guest_token)->toBeNull();
});
