<?php

use App\Enums\FriendshipStatus;
use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\FriendshipService;
use App\Livewire\Friends;
use App\Livewire\Guides\Builder;
use App\Livewire\Guides\Index;
use App\Livewire\Guides\Show;
use App\Livewire\Home;
use App\Models\Friendship;
use App\Models\Guild;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideSection;
use Livewire\Livewire;

/**
 * Friends, guild/friend editing of guides, attribution, and the signed-in home page.
 *
 * Every edit-access rule is asserted in both directions — who gets in AND who stays out — because
 * a friendship is now a permission, and the failure that matters is somebody editing a guide they
 * were never let into.
 */
function collabUser(string $username): User
{
    $user = User::factory()->create(['name' => ucfirst($username)]);
    $user->forceFill(['username' => $username])->save();

    return $user;
}

function collabSpec(): \App\Models\Specialization
{
    $game = \App\Models\Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
    $class = \App\Models\GameClass::firstOrCreate(['slug' => 'druid'], ['game_id' => $game->id, 'name' => 'Druid']);

    return \App\Models\Specialization::firstOrCreate(
        ['class_id' => $class->id, 'slug' => 'feral'],
        ['name' => 'Feral', 'external_spec_id' => 103],
    );
}

function befriend(User $a, User $b): void
{
    Friendship::create([
        'requester_id' => $a->id,
        'addressee_id' => $b->id,
        'status' => FriendshipStatus::Accepted,
        'accepted_at' => now(),
    ]);
}

function collabGuide(User $owner, array $attrs = []): UserGuide
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

/*
 * Friend requests.
 */

test('a request is pending until the other player accepts, and only they can accept it', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    $svc = app(FriendshipService::class);

    expect($svc->request($chris, $bob))->toBe('sent');
    $request = Friendship::first();

    expect($chris->fresh()->isFriendsWith($bob))->toBeFalse();
    expect($svc->accept($chris, $request->id))->toBeFalse();   // the sender cannot accept their own
    expect($svc->accept($bob, $request->id))->toBeTrue();

    expect($chris->fresh()->isFriendsWith($bob))->toBeTrue()
        ->and($bob->fresh()->isFriendsWith($chris))->toBeTrue();
});

test('asking someone who already asked you accepts their request instead of adding a second row', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    $svc = app(FriendshipService::class);

    $svc->request($bob, $chris);

    expect($svc->request($chris, $bob))->toBe('accepted')
        ->and(Friendship::count())->toBe(1)
        ->and(Friendship::first()->status)->toBe(FriendshipStatus::Accepted);
});

test('duplicate and self requests are refused', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    $svc = app(FriendshipService::class);

    expect($svc->request($chris, $chris))->toBe('self');
    $svc->request($chris, $bob);
    expect($svc->request($chris, $bob))->toBe('already_sent')
        ->and(Friendship::count())->toBe(1);
});

test('a handle is found case-insensitively with or without an @, and never by email', function () {
    $bob = collabUser('bob');
    $svc = app(FriendshipService::class);

    expect($svc->findByHandle('@BOB')?->id)->toBe($bob->id)
        ->and($svc->findByHandle('bob')?->id)->toBe($bob->id)
        ->and($svc->findByHandle($bob->email))->toBeNull();
});

test('declining deletes the request so either player can ask again later', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    $svc = app(FriendshipService::class);

    $svc->request($chris, $bob);
    expect($svc->decline($bob, Friendship::first()->id))->toBeTrue()
        ->and(Friendship::count())->toBe(0)
        ->and($svc->request($chris, $bob))->toBe('sent');
});

/*
 * Who may edit.
 */

test('a friend can edit only when the author switched friends-can-edit on', function () {
    [$chris, $bob, $stranger] = [collabUser('chris'), collabUser('bob'), collabUser('stranger')];
    befriend($chris, $bob);
    $guide = collabGuide($chris);

    expect($guide->isEditableBy($bob))->toBeFalse();

    $guide->update(['friends_can_edit' => true]);

    expect($guide->fresh()->isEditableBy($bob))->toBeTrue()
        ->and($guide->fresh()->isEditableBy($stranger))->toBeFalse()
        ->and($guide->fresh()->isEditableBy(null))->toBeFalse();
});

test('a pending friend request grants nothing', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    app(FriendshipService::class)->request($bob, $chris);
    $guide = collabGuide($chris, ['friends_can_edit' => true]);

    expect($guide->isEditableBy($bob))->toBeFalse();
});

test('unfriending ends edit access immediately', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, ['friends_can_edit' => true]);

    expect($guide->isEditableBy($bob))->toBeTrue();

    app(FriendshipService::class)->remove($chris, $bob->id);

    expect($guide->fresh()->isEditableBy($bob->fresh()))->toBeFalse();
});

test('a guildmate can edit when the guild switch is on, and nobody outside the guild can', function () {
    [$chris, $mate, $outsider] = [collabUser('chris'), collabUser('mate'), collabUser('outsider')];
    $guild = Guild::create(['owner_id' => $chris->id, 'name' => 'Team']);
    $guild->join($chris);
    $guild->join($mate);

    $guide = collabGuide($chris, ['guild_id' => $guild->id]);
    expect($guide->isEditableBy($mate))->toBeFalse();

    $guide->update(['guild_can_edit' => true]);

    expect($guide->fresh()->isEditableBy($mate))->toBeTrue()
        ->and($guide->fresh()->isEditableBy($outsider))->toBeFalse();
});

test('a collaborator can read a draft; everyone else still gets a 404', function () {
    [$chris, $bob, $stranger] = [collabUser('chris'), collabUser('bob'), collabUser('stranger')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, ['friends_can_edit' => true, 'status' => UserGuideStatus::Draft]);

    $url = route('guides.show', ['username' => 'chris', 'guide' => $guide->slug]);

    $this->actingAs($bob)->get($url)->assertOk();
    $this->actingAs($stranger)->get($url)->assertNotFound();
});

/*
 * The builder, as a collaborator.
 */

test('a collaborator opens the builder; a stranger still gets a 403', function () {
    [$chris, $bob, $stranger] = [collabUser('chris'), collabUser('bob'), collabUser('stranger')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, ['friends_can_edit' => true]);

    $this->actingAs($bob)->get(route('guides.edit', $guide->slug))
        ->assertOk()
        ->assertSee('helping edit', false);

    Livewire::actingAs($stranger)->test(Builder::class, ['guide' => $guide])->assertStatus(403);
});

test('a collaborator\'s sections and edits are credited to them, not the author', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, ['friends_can_edit' => true]);
    $opener = $guide->sections()->first();

    Livewire::actingAs($bob)->test(Builder::class, ['guide' => $guide])
        ->call('addSection', UserGuideSectionKind::Sequence->value)
        ->call('renameSection', $opener->id, 'Opener (Bob’s version)');

    $added = $guide->sections()->where('id', '!=', $opener->id)->first();

    expect($added->created_by_user_id)->toBe($bob->id)
        ->and($added->updated_by_user_id)->toBe($bob->id)
        ->and($opener->fresh()->created_by_user_id)->toBe($chris->id)   // still Chris's section
        ->and($opener->fresh()->updated_by_user_id)->toBe($bob->id)     // but Bob last changed it
        ->and($guide->fresh()->last_edited_by_user_id)->toBe($bob->id)
        ->and($guide->fresh()->contributors()->pluck('id')->all())->toEqual([$chris->id, $bob->id]);
});

test('a collaborator removing or annotating a step credits the section to them', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, ['friends_can_edit' => true]);
    $section = $guide->sections()->first();

    $block = UserGuideBlock::create([
        'user_guide_section_id' => $section->id,
        'position' => 0,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => ['external_spell_id' => 408],
        'added_by_user_id' => $chris->id,
    ]);

    Livewire::actingAs($bob)->test(Builder::class, ['guide' => $guide])
        ->call('setNote', $block->id, 'only if they trinket');

    expect($section->fresh()->updated_by_user_id)->toBe($bob->id)
        ->and($block->fresh()->added_by_user_id)->toBe($chris->id);   // a note is not re-adding it
});

test('a reorder that changes nothing credits nobody', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, ['friends_can_edit' => true]);
    $section = $guide->sections()->first();

    $ids = collect([0, 1])->map(fn ($p) => UserGuideBlock::create([
        'user_guide_section_id' => $section->id,
        'position' => $p,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => ['external_spell_id' => 408 + $p],
    ])->id)->all();

    Livewire::actingAs($bob)->test(Builder::class, ['guide' => $guide])->call('reorder', $section->id, $ids);

    expect($section->fresh()->updated_by_user_id)->toBe($chris->id);
});

test('publishing, reading access, edit access and signing stay with the author', function () {
    [$chris, $bob, $third] = [collabUser('chris'), collabUser('bob'), collabUser('third')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, ['friends_can_edit' => true, 'status' => UserGuideStatus::Draft]);
    $guide->members()->create(['side' => 'team', 'position' => 0, 'spec_id' => collabSpec()->id]);

    Livewire::actingAs($bob)->test(Builder::class, ['guide' => $guide])
        ->call('publish')
        ->call('setVisibility', UserGuideVisibility::Public->value)
        ->call('setFriendsCanEdit', false)
        ->set('shareEmail', $third->email)
        ->call('shareWith');

    $guide->refresh();

    expect($guide->status)->toBe(UserGuideStatus::Draft)
        ->and($guide->visibility)->not->toBe(UserGuideVisibility::Public)
        ->and($guide->friends_can_edit)->toBeTrue()
        ->and($guide->viewers()->count())->toBe(0);
});

test('the author can switch friend and guild editing on and off', function () {
    $chris = collabUser('chris');
    $guild = Guild::create(['owner_id' => $chris->id, 'name' => 'Team']);
    $guild->join($chris);
    $guide = collabGuide($chris);

    Livewire::actingAs($chris)->test(Builder::class, ['guide' => $guide])
        ->call('setFriendsCanEdit', true)
        ->call('setGuildCanEdit', true);

    $guide->refresh();
    expect($guide->friends_can_edit)->toBeTrue()
        ->and($guide->guild_can_edit)->toBeTrue()
        ->and($guide->guild_id)->toBe($guild->id);   // adopted the author's only guild
});

test('guild editing cannot be switched on with no guild to grant it to', function () {
    $chris = collabUser('chris');
    $guide = collabGuide($chris);

    Livewire::actingAs($chris)->test(Builder::class, ['guide' => $guide])->call('setGuildCanEdit', true);

    expect($guide->fresh()->guild_can_edit)->toBeFalse();
});

test('a collaborator\'s edit is credited on the read page', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    befriend($chris, $bob);
    $guide = collabGuide($chris, [
        'friends_can_edit' => true,
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
    ]);
    $guide->sections()->first()->update(['updated_by_user_id' => $bob->id]);

    $this->get(route('guides.show', ['username' => 'chris', 'guide' => $guide->slug]))
        ->assertOk()
        ->assertSee('Last edited by', false)
        ->assertSee('@bob', false)
        ->assertSee('With help from', false);
});

test('My Guides lists guides a friend opened to you, and not ones they did not', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    befriend($chris, $bob);
    $open = collabGuide($chris, ['friends_can_edit' => true, 'title' => 'Open one']);
    collabGuide($chris, ['title' => 'Closed one']);

    $listed = Livewire::actingAs($bob)->test(Index::class)->get('collaborating');

    expect($listed->pluck('id')->all())->toEqual([$open->id]);
});

/*
 * Friends from a guide page, and the friends page.
 */

test('a reader can ask the author to be friends from the guide page', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    $guide = collabGuide($chris, ['status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public]);

    Livewire::actingAs($bob)
        ->test(Show::class, ['username' => 'chris', 'guide' => $guide])
        ->call('addAuthorAsFriend')
        ->assertSet('friendRequestSent', 'Friend request sent');

    expect(Friendship::where('requester_id', $bob->id)->where('addressee_id', $chris->id)->exists())->toBeTrue();
});

test('the friends page adds by handle and gives every visitor a handle to share', function () {
    $bob = collabUser('bob');
    $chris = User::factory()->create(['name' => 'Chris Lee']);   // no handle yet

    Livewire::actingAs($chris)->test(Friends::class)
        ->set('handle', '@bob')
        ->call('add')
        ->assertSet('messageIsError', false);

    expect($chris->fresh()->username)->not->toBeNull()
        ->and(Friendship::where('addressee_id', $bob->id)->exists())->toBeTrue();
});

test('guildmates who are not friends yet are suggested on the friends page', function () {
    [$chris, $mate, $friend] = [collabUser('chris'), collabUser('mate'), collabUser('friend')];
    $guild = Guild::create(['owner_id' => $chris->id, 'name' => 'Team']);
    collect([$chris, $mate, $friend])->each(fn ($u) => $guild->join($u));
    befriend($chris, $friend);

    $suggested = Livewire::actingAs($chris)->test(Friends::class)->get('suggestions');

    expect($suggested->pluck('id')->all())->toEqual([$mate->id]);
});

/*
 * Home, Training, and starting a guide.
 */

test('signing in lands on the arena home with the three main actions', function () {
    // A player with a guide of their own; a brand-new account gets the first-run home instead
    // (see FirstRunExperienceTest).
    $user = User::factory()->create();
    collabGuide($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('+ New comp guide')
        ->assertSee('Your characters')
        ->assertSee('Friends &amp; guilds', false)
        // The phone tab bar is on the page — without it a phone had no way to reach anything.
        ->assertSee('aria-label="Main"', false);
});

test('the old learning profile still renders, at /training', function () {
    // DashboardController assumes at least one category exists (Category::first()->id), which is
    // always true in a real environment and never in an empty test database.
    \App\Models\Category::create(['name' => 'Games']);

    $this->actingAs(User::factory()->create())->get(route('training'))->assertOk();
});

test('home starts a guide and goes to the builder', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Home::class)
        ->call('createGuide', UserGuideType::Comp->value)
        ->assertRedirect();

    expect($user->guides()->count())->toBe(1)
        ->and($user->guides()->first()->sections()->first()->created_by_user_id)->toBe($user->id);
});

test('the Build button posts to start a guide, and a GET cannot create one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('guides.create', 'class'))->assertRedirect();
    $this->actingAs($user)->get('/guides/new/class')->assertStatus(405);
    $this->actingAs($user)->post('/guides/new/nonsense')->assertNotFound();

    expect($user->guides()->where('type', UserGuideType::ClassGuide->value)->count())->toBe(1);
});

test('home shows a friend request and accepts it', function () {
    [$chris, $bob] = [collabUser('chris'), collabUser('bob')];
    app(FriendshipService::class)->request($bob, $chris);

    Livewire::actingAs($chris)->test(Home::class)
        ->assertSee('wants to be friends')
        ->call('acceptFriend', Friendship::first()->id);

    expect($chris->fresh()->isFriendsWith($bob))->toBeTrue();
});
