<?php

use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Livewire\Guides\Builder;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideSection;
use Livewire\Livewire;

/**
 * What a new player sees in their first few minutes: where each way of signing up lands, the
 * first-run version of Home, the builder's first screen, and the prompts a signed-out visitor
 * gets on the two pages they most often arrive on (a shared guide, and "/").
 *
 * Several of these guard the ORDER of things on a page rather than their presence, because the
 * problem each one fixed was ordering: the right content was there, just behind the wrong thing.
 */
function firstRunUser(string $username = 'newbie'): User
{
    $user = User::factory()->create(['name' => ucfirst($username)]);
    $user->forceFill(['username' => $username])->save();

    return $user;
}

function firstRunGuide(User $owner, array $attrs = []): UserGuide
{
    $guide = UserGuide::create(array_merge(['user_id' => $owner->id, 'title' => 'Jungle opener'], $attrs));

    UserGuideSection::create([
        'user_guide_id' => $guide->id,
        'kind' => UserGuideSectionKind::Sequence,
        'title' => 'The opener',
        'row' => 0,
        'column' => 0,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);

    return $guide;
}

// ------------------------------------------------------------------ Home

test('a brand-new account gets the first-run home: one thing to do, and a real plan to look at', function () {
    $author = firstRunUser('author');
    firstRunGuide($author, [
        'title' => 'RMP into TSG',
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
    ]);

    $this->actingAs(firstRunUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome, Newbie')
        ->assertDontSee('Welcome back')
        ->assertSee('Build your first game plan')
        ->assertSee('See one another player wrote')
        ->assertSee('RMP into TSG')
        ->assertSee('Your characters')
        ->assertSee('Browse 3v3 comps')
        // Other players' plans come before the (empty, for a new account) friends block.
        ->assertSeeInOrder(['Plans other players have written', 'From your friends'])
        // The hero replaces the small "Build a guide" card rather than repeating it.
        ->assertDontSee('Build a guide')
        ->assertSee('aria-label="Main"', false);
});

test('a player who has written something gets the regular home back', function () {
    $user = firstRunUser();
    firstRunGuide($user, ['title' => 'My opener']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, Newbie')
        ->assertSee('Build a guide')
        ->assertSee('My opener')
        ->assertDontSee('Build your first game plan');
});

test('the first-run hero starts a guide and goes to the builder', function () {
    $user = firstRunUser();

    Livewire::actingAs($user)->test(\App\Livewire\Home::class)
        ->call('createGuide', UserGuideType::Comp->value)
        ->assertRedirect();

    expect($user->guides()->count())->toBe(1);
});

// ------------------------------------------------------------------ email confirmation

test('an unconfirmed account reaches Home on production, with a banner instead of a wall', function () {
    // User::hasVerifiedEmail() only enforces anything on production, so the old wall was
    // invisible in every other environment — which is exactly how it went unnoticed.
    app()->detectEnvironment(fn () => 'production');

    $user = firstRunUser();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Confirm your email')
        ->assertSee('Send it again');

    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Confirm your email');
});

// ------------------------------------------------------------------ the builder's first screen

test('the builder shows the comp before the sharing settings, and says where to start', function () {
    $user = firstRunUser();
    $guide = firstRunGuide($user);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])
        ->assertSeeInOrder(['The comp', 'Sharing &amp; credit', 'Who can edit this', 'Written as'], false)
        ->assertSee('Start here')
        // The collapsed summary says what is set without opening the panel.
        ->assertSee('Only you can edit')
        ->assertSee('Not signed');
});

test('a collaborator never sees the author-only sharing panel', function () {
    $author = firstRunUser('author');
    $bob = firstRunUser('bob');
    \App\Models\Friendship::create([
        'requester_id' => $author->id,
        'addressee_id' => $bob->id,
        'status' => \App\Enums\FriendshipStatus::Accepted,
        'accepted_at' => now(),
    ]);
    $guide = firstRunGuide($author, ['friends_can_edit' => true]);

    Livewire::actingAs($bob)->test(Builder::class, ['guide' => $guide])
        ->assertOk()
        ->assertDontSee('Sharing &amp; credit', false);
});

// ------------------------------------------------------------------ signed-out visitors

test('a signed-out reader of a public guide is invited to build their own; a signed-in one is not', function () {
    $author = firstRunUser('author');
    $guide = firstRunGuide($author, [
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
    ]);
    $url = route('guides.show', ['username' => 'author', 'guide' => $guide->slug]);

    $this->get($url)->assertOk()->assertSee('Build your own game plan')->assertSee('Create a free account');

    $this->actingAs(firstRunUser('reader'))->get($url)->assertOk()->assertDontSee('Build your own game plan');
});

test('the landing page tells a signed-out visitor they can turn a comp into a plan', function () {
    $this->get('/')->assertOk()->assertSee('Turn a comp into a game plan')->assertSee('Build your own, free');

    $this->actingAs(firstRunUser())->get('/')->assertOk()->assertDontSee('Build your own, free');
});

test('the register page says what the account is for, and no longer warns that things may reset', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Plan your arena games')
        ->assertDontSee('change or reset');
});
