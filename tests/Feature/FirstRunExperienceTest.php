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

// Home reads each player's Your games folder under storage/app/coach/{id}. A test's second user has
// the same id as a real local account, so the folders live in a scratch storage path here, never
// the real one.
beforeEach(function () {
    $this->scratchStorage = sys_get_temp_dir().'/mc-home-test-'.uniqid();
    \Illuminate\Support\Facades\File::ensureDirectoryExists($this->scratchStorage.'/app');
    $this->app->useStoragePath($this->scratchStorage);
});

afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory($this->scratchStorage);
});

/** Writes a Your games index for $user, as BuildCoachPages would. */
function firstRunIndex(User $user, array $index): void
{
    $dir = \App\Jobs\BuildCoachPages::dir($user->id);
    \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
    \Illuminate\Support\Facades\File::put($dir.'/index.json', json_encode($index));
}

test('a brand-new account gets the app first: three steps, then other players\' guides', function () {
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
        ->assertSee('Get every arena game you play reviewed')
        ->assertSee('Connect it with the key from Your Games')
        ->assertSee('Your characters')
        // The app comes first, then other players' guides.
        ->assertSeeInOrder(['Get every arena game you play reviewed', 'Player Guides', 'RMP into TSG'])
        // Starting a guide is in the side column, no longer the page's main button.
        ->assertDontSee('Build your first game plan')
        ->assertSee('+ New comp guide')
        ->assertSee('aria-label="Main"', false);
});

test('an account with the app connected and no games yet is told to play one', function () {
    $user = firstRunUser();
    $user->forceFill(['coach_token_at' => now()])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertSee('The app is connected')
        ->assertDontSee('Get every arena game you play reviewed');
});

test('games that arrived before their pages are built are said to be on their way', function () {
    $user = firstRunUser();
    \App\Models\ArenaRound::create(['user_id' => $user->id, 'lobby_id' => 'l1', 'match_id' => 'm1', 'roster_key' => 'r', 'bracket' => '3v3', 'played_at' => now(), 'payload' => []]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertSee('1 round arrived')
        ->assertSee('Welcome back, Newbie');
});

test('a player with games gets their latest session: record, MMR, the games, the focus and the last loss', function () {
    $user = firstRunUser();
    $game = fn (string $at, array $record, ?int $mmr, array $against = ['Arms Warrior', 'Frost Mage', 'Holy Paladin']) => [
        'playedAt' => $at, 'bracket' => '3v3', 'you' => 'Healbot', 'record' => $record, 'against' => $against, 'mmr' => $mmr,
    ];
    firstRunIndex($user, [
        'generatedAt' => now()->toIso8601String(),
        'games' => [
            // An earlier session, more than three hours before the latest: not in it.
            'old1' => $game('2026-11-01 18:00:00', [1, 0], 1900),
            'g1' => $game('2026-11-02 13:00:00', [1, 0], 2050),
            'g2' => $game('2026-11-02 13:05:00', [0, 1], 2062, ['Affliction Warlock', 'Feral Druid', 'Restoration Shaman']),
            'g3' => $game('2026-11-02 13:10:00', [1, 0], 2081),
            'sh' => ['playedAt' => '2026-11-02 12:30:00', 'bracket' => 'Rated Solo Shuffle', 'you' => 'Healbot', 'record' => [4, 2], 'against' => []],
        ],
        'characters' => ['Healbot-Realm-US' => ['name' => 'Healbot', 'spec' => 'Discipline Priest', 'games' => 40, 'file' => 'improve-abc.html',
            'focus' => ['title' => 'Crowd control on the enemy healer', 'what' => 'Your goes with your crowd control on their healer.', 'you' => '40%', 'others' => '59%']]],
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertSee('Your Latest Session')
        ->assertSee('All 5 games')
        // 3v3: two won, one lost; the shuffle's 4-2 counts as won.
        ->assertSeeInOrder(['3', '&ndash;', '1'], false)
        ->assertSee('4 games on Monday 2 Nov, 12:30 to 13:10')
        ->assertSee('team MMR 2050')
        ->assertSee('2081')
        ->assertSee('Crowd control on the enemy healer')
        ->assertSee('You 40%, other Discipline Priests 59%')
        ->assertSee('#improve=improve-abc.html', false)
        // The last loss opens that game on Your Games.
        ->assertSee('Your Last Loss')
        ->assertSee('#game=g2.html', false)
        ->assertSee('against Affliction Warlock, Feral Druid, Restoration Shaman')
        ->assertSeeInOrder(['Your Latest Session', 'Player Guides'])
        ->assertDontSee('Get every arena game you play reviewed');
});

test('the phone tab bar\'s middle button is Your Games, signed in or out', function () {
    $bar = fn ($response) => \Illuminate\Support\Str::between($response->getContent(), 'aria-label="Main"', '</nav>');

    foreach ([firstRunUser(), null] as $user) {
        $response = ($user ? $this->actingAs($user) : $this)->get($user ? route('dashboard') : route('wow-comps'))->assertOk();
        expect($bar($response))->toContain('href="'.route('coach').'"')->toContain('Your Games')
            ->not->toContain('Build')->not->toContain(route('guides.try', ['type' => 'comp']));
        auth()->logout();
    }
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
        ->assertSee('confirm your email')
        ->assertSee('Send it again');

    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('confirm your email');
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
        // Spotify's layout (2026-10-11), the app first (2026-10-12): your games, the app and the
        // planning tools in the top panel, then the library: your space, social, more data, Training.
        // The spec guides joined the planning tools as "PvP Guides" (2026-10-16), and the kit pages
        // are "Arena Kit" there, as their titles are. Grouped Plan, Guides, Learn on 2026-10-18,
        // with Claude's guides and the archive's top lists under Lab in the library panel.
        // 2026-10-27: Leaderboards up beside Your games; Arena Kit out of the menu; the Lab, Training
        // and the library's name block gone for players (the Lab and Training stay for admins).
        ->assertSeeInOrder([
            'Home', 'Your Games', 'Get the App', 'Leaderboards', 'Player Lookup', 'Plan', '3v3 Comps', 'Matchup Lab',
            'Guides', 'PvP Guides', 'Player Guides', 'Learn', 'Class Quizzes', 'Arena Basics',
            'Your library', 'My Guides', 'My Characters', 'Profile &amp; settings',
            'Social', 'Friends', 'Guilds',
        ], false)
        ->assertDontSee('Your Profile')
        ->assertDontSee('Top 10 CC Chains')
        // Credits/XP live in the account menu now, which comes after everything above.
        ->assertSeeInOrder(['Guilds', 'Feedback', 'Discord', 'Newbie', 'Credits'], false);
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

test('a signed-out reader is invited to copy the plan or try one with no account; a signed-in one copies directly', function () {
    $author = firstRunUser('author');
    $guide = firstRunGuide($author, [
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
    ]);
    $url = route('guides.show', ['username' => 'author', 'guide' => $guide->slug]);

    // Merged 2026-09-29 into one "Make this plan yours" card for every reader: copying needs an
    // account, and a guest keeps the no-account way in (a blank plan).
    $this->get($url)->assertOk()
        ->assertSee('Make this plan yours')
        ->assertSee('Sign up free to copy it')
        ->assertSee('Or try a blank plan, no account');

    $this->actingAs(firstRunUser('reader'))->get($url)->assertOk()
        ->assertSee('Copy into my planner')
        ->assertDontSee('Or try a blank plan, no account');
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
        ->assertSee('Create Your Free Account')
        ->assertDontSee('change or reset');
});
