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

function firstRunSpec(): \App\Models\Specialization
{
    $game = \App\Models\Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    \App\Models\Patch::firstOrCreate(['game_id' => $game->id, 'build_version' => '1.0.0-test'], ['is_current' => true]);
    $class = \App\Models\GameClass::firstOrCreate(['slug' => 'druid'], ['game_id' => $game->id, 'name' => 'Druid']);

    return \App\Models\Specialization::firstOrCreate(
        ['class_id' => $class->id, 'slug' => 'feral'],
        ['name' => 'Feral', 'external_spec_id' => 103],
    );
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
        // The hero comes first, then the feed of other players' plans.
        ->assertSeeInOrder(['Build your first game plan', 'Everyone', 'RMP into TSG'])
        // The hero's buttons replace the header's rather than repeating them.
        ->assertDontSee('+ New comp guide')
        ->assertSee('aria-label="Main"', false);
});

test('a player who has written something gets the regular home back', function () {
    $user = firstRunUser();
    firstRunGuide($user, ['title' => 'My opener']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, Newbie')
        ->assertSee('+ New comp guide')
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

test('an empty guide asks for the comp and shows nothing else', function () {
    $user = firstRunUser();
    $guide = firstRunGuide($user);

    // Sections, sharing and the enemy team all wait for a comp: until there is one, the palette
    // behind "+ Add an ability" can only report that the comp is empty, so offering it (and seven
    // other controls) is offering choices that do nothing. Real visitors got stuck exactly here —
    // five picked a full comp on 2026-09-17 and only one ever added an ability.
    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])
        ->assertSee('Start here')
        ->assertSee('Pick a spec above to start')
        ->assertDontSee('Sharing &amp; credit', false)
        ->assertDontSee('Add a section')
        ->assertDontSee('Playing against');
});

test('once a spec is picked the rest of the page arrives, comp first and sharing after', function () {
    $user = firstRunUser();
    $guide = firstRunGuide($user);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])
        ->set('pickingSlot', 0)
        ->call('setMember', firstRunSpec()->id)
        ->assertSeeInOrder(['The comp', 'Sharing &amp; credit', 'Who can edit this', 'Written as'], false)
        ->assertSee('Add a section')
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

// ------------------------------------------------------------------ slugs shared across authors

test('two players whose first guides share a slug each open their own, and a stranger is still refused', function () {
    // Found by the launch round trip: every new guide starts as "Untitled class guide", slugs are
    // unique per author only, and the edit route used to find whichever guide came first.
    [$first, $second, $stranger] = [firstRunUser('first'), firstRunUser('second'), firstRunUser('stranger')];
    $theirs = firstRunGuide($first, ['title' => 'Untitled class guide']);
    $mine = firstRunGuide($second, ['title' => 'Untitled class guide', 'summary' => 'second player summary']);

    expect($mine->slug)->toBe($theirs->slug);

    $this->actingAs($second)->get(route('guides.edit', $mine->slug))
        ->assertOk()
        ->assertSee('second player summary');

    $this->actingAs($stranger)->get(route('guides.edit', $mine->slug))->assertForbidden();
});

test('the public URL serves the named author\'s guide when another author has the same slug', function () {
    [$alice, $bob] = [firstRunUser('alice'), firstRunUser('bob')];
    $published = ['status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public];
    firstRunGuide($alice, $published + ['title' => 'RMP opener', 'summary' => 'alice version']);
    firstRunGuide($bob, $published + ['title' => 'RMP opener', 'summary' => 'bob version']);

    $this->get('/g/bob/rmp-opener')->assertOk()->assertSee('bob version')->assertDontSee('alice version');
    $this->get('/g/alice/rmp-opener')->assertOk()->assertSee('alice version');
    $this->get('/g/nobody/rmp-opener')->assertNotFound();
});

// ------------------------------------------------------------------ the sidebar

test('the sidebar puts your own pages in one block, separate from the public site', function () {
    $user = firstRunUser();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        // Your space, then the public site, social, class data, and Training last.
        ->assertSeeInOrder([
            'Your space', 'Newbie', 'Home', 'My Guides', 'My Characters', 'Profile &amp; settings',
            'Explore', 'Guides', 'Claude', '3v3 Comps',
            'Social', 'Friends', 'Guilds',
            'Class data', 'Class Guides', 'Training', 'Class quizzes', 'The Brain',
        ], false)
        ->assertDontSee('Your Profile')
        // Credits/XP live in the account menu now, which comes after everything above.
        ->assertSeeInOrder(['Training', 'Feedback', 'Discord', 'Credits'], false);
});

test('a signed-out visitor gets only the public side of the sidebar', function () {
    $this->get(route('guides.browse'))
        ->assertOk()
        ->assertSee('Explore')
        ->assertSee('Guides')
        ->assertDontSee('Your space')
        ->assertDontSee('My Guides');
});

// ------------------------------------------------------------------ signed-out visitors

test('a signed-out reader of a public guide is invited to build their own; a signed-in one is not', function () {
    $author = firstRunUser('author');
    $guide = firstRunGuide($author, [
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
    ]);
    $url = route('guides.show', ['username' => 'author', 'guide' => $guide->slug]);

    $this->get($url)->assertOk()->assertSee('Build your own game plan')->assertSee('Try the planner');

    $this->actingAs(firstRunUser('reader'))->get($url)->assertOk()->assertDontSee('Build your own game plan');
});

test('the landing page tells a signed-out visitor they can turn a comp into a plan', function () {
    // The comp builder moved to /wow/comps on 2026-09-16 ('/' now 301s there) — see
    // GameScopedUrlsTest. Addressed by route name so a future move does not break this again.
    $comps = route('wow-comps', absolute: false);

    $this->get($comps)->assertOk()->assertSee('Turn a comp into a game plan')->assertSee('Try building one, no sign-up');

    $this->actingAs(firstRunUser())->get($comps)->assertOk()->assertDontSee('Try building one, no sign-up');
});

test('the register page says what the account is for, and no longer warns that things may reset', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Plan your arena games')
        ->assertDontSee('change or reset');
});
