<?php

use App\Jobs\SyncBattlenetCharacter;
use App\Models\BattlenetAccount;
use App\Models\BattlenetCharacter;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

/**
 * Signing in and signing up with Google or Battle.net.
 *
 * What is asserted is who ends up signed in as whom — the one place a mistake here is quiet. A
 * Google sign-in must never walk into an account on an email Google has not verified, and a
 * Battle.net sign-up must never be merged into an existing account by an email somebody typed.
 */
beforeEach(function () {
    config([
        'services.google.client_id' => 'g-client',
        'services.google.client_secret' => 'g-secret',
        'services.battlenet.client_id' => 'test-client',
        'services.battlenet.client_secret' => 'test-secret',
        'services.battlenet.regions' => ['us'],
    ]);

    Mail::fake();
    Notification::fake();
});

function googleReturns(string $id, string $email, bool $verified, string $name = 'Gamer Person'): void
{
    $user = (new SocialiteUser)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified, 'name' => $name])
        ->map(['id' => $id, 'name' => $name, 'email' => $email]);

    Socialite::shouldReceive('driver->user')->andReturn($user);
}

function signupWorld(): void
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '1.0.0-test', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    Specialization::create(['class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety', 'external_spec_id' => 261]);
}

function fakeBattlenetAccount(int $accountId, string $battletag = 'Newbie#1111'): void
{
    app()->instance('test.social.bnet', ['id' => $accountId, 'tag' => $battletag]);

    Http::fake(function (Request $request) {
        $url = $request->url();
        $fake = app('test.social.bnet');

        return match (true) {
            str_contains($url, 'oauth.battle.net/token') => Http::response(['access_token' => 'user-token']),
            str_contains($url, 'oauth.battle.net/userinfo') => Http::response(['id' => $fake['id'], 'battletag' => $fake['tag']]),
            str_contains($url, 'us.api.blizzard.com/profile/user/wow') => Http::response([
                'wow_accounts' => [['id' => 1, 'characters' => [[
                    'name' => 'Stabby', 'id' => 77, 'level' => 90,
                    'realm' => ['name' => 'Test Realm', 'id' => 1, 'slug' => 'test-realm'],
                    'playable_class' => ['id' => 4], 'playable_race' => ['name' => 'Human'], 'faction' => ['name' => 'Alliance'],
                ]]]],
            ]),
            default => Http::response(['code' => 404], 404),
        };
    });
}

// ------------------------------------------------------------------ passwords

test('a password is no longer refused for appearing in a breach list', function () {
    Http::fake();

    // "password123" is in every breach corpus. The rule that refused it turned away a real person
    // on every password he tried; length is the only requirement now.
    expect(Validator::make(['p' => 'password123'], ['p' => Password::defaults()])->passes())->toBeTrue()
        ->and(Validator::make(['p' => 'short'], ['p' => Password::defaults()])->passes())->toBeFalse();

    Http::assertNothingSent();
});

// ------------------------------------------------------------------ Google

test('the Google buttons only appear when Google is configured', function () {
    $this->get(route('register'))->assertSee('Continue with Google');

    config(['services.google.client_id' => null]);
    $this->get(route('register'))->assertDontSee('Continue with Google');
    $this->get(route('google.redirect'))->assertNotFound();
});

test('a new Google user gets a verified account, signed in, with terms recorded', function () {
    googleReturns('g-123', 'New.Player@Example.com', true);

    $this->get(route('google.callback'))->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('google_id', 'g-123')->first();
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('new.player@example.com')
        ->and($user->name)->toBe('Gamer Person')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->tos_accepted_at)->not->toBeNull()
        ->and(Auth::id())->toBe($user->id);
});

test('a returning Google user signs in to the same account even after changing their email', function () {
    $existing = User::factory()->create(['email' => 'old@example.com', 'google_id' => 'g-123']);
    googleReturns('g-123', 'changed@example.com', true);

    $this->get(route('google.callback'));

    expect(Auth::id())->toBe($existing->id)->and(User::count())->toBe(1);
});

test('Google links to an existing email account only when Google verified the email', function () {
    $existing = User::factory()->create(['email' => 'owner@example.com', 'google_id' => null]);
    googleReturns('g-999', 'owner@example.com', true);

    $this->get(route('google.callback'));

    expect(Auth::id())->toBe($existing->id)
        ->and($existing->fresh()->google_id)->toBe('g-999')
        ->and(User::count())->toBe(1);
});

test('an unverified Google email never gets into an existing account', function () {
    $existing = User::factory()->create(['email' => 'owner@example.com', 'google_id' => null]);
    googleReturns('g-attacker', 'owner@example.com', false);

    $this->get(route('google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(Auth::check())->toBeFalse()
        ->and($existing->fresh()->google_id)->toBeNull()
        ->and(User::count())->toBe(1);
});

test('an expired Google state sends the player back to the login page', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
    expect(Auth::check())->toBeFalse();
});

// ------------------------------------------------------------------ Battle.net as a guest

test('a guest can start Battle.net sign-in', function () {
    $response = $this->get(route('battlenet.redirect'));

    expect($response->headers->get('Location'))->toStartWith('https://oauth.battle.net/authorize?')
        ->and(session('battlenet_oauth_state'))->toBeString();
});

test('a guest whose Battle.net account is already linked is signed in to it', function () {
    signupWorld();
    Queue::fake();
    $owner = User::factory()->create();
    BattlenetAccount::create(['user_id' => $owner->id, 'battlenet_id' => 555, 'battletag' => 'Owner#1']);
    fakeBattlenetAccount(555, 'Owner#1');

    $this->withSession(['battlenet_oauth_state' => 'st'])
        ->get(route('battlenet.callback', ['state' => 'st', 'code' => 'c']))
        ->assertRedirect(route('characters.index'));

    expect(Auth::id())->toBe($owner->id)
        ->and(User::count())->toBe(1)
        ->and(BattlenetCharacter::where('name', 'Stabby')->exists())->toBeTrue();
});

test('a new Battle.net player finishes sign-up with an email and lands with characters attached', function () {
    signupWorld();
    Queue::fake();
    fakeBattlenetAccount(777, 'Newbie#1111');

    $this->withSession(['battlenet_oauth_state' => 'st'])
        ->get(route('battlenet.callback', ['state' => 'st', 'code' => 'c']))
        ->assertRedirect(route('battlenet.finish'));

    expect(User::count())->toBe(0)->and(Auth::check())->toBeFalse();

    $this->get(route('battlenet.finish'))->assertOk()->assertSee('Newbie#1111')->assertSee('1 character');

    $this->post(route('battlenet.finish.store'), ['email' => 'Newbie@Example.com', 'terms' => '1'])
        ->assertRedirect(route('characters.index'));

    $user = User::where('email', 'newbie@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Newbie')
        ->and($user->email_verified_at)->toBeNull()
        ->and(Auth::id())->toBe($user->id)
        ->and($user->battlenetAccount->battlenet_id)->toBe(777)
        ->and($user->battlenetAccount->characters()->pluck('name')->all())->toBe(['Stabby']);

    Queue::assertPushed(SyncBattlenetCharacter::class, 1);

    // The pending sign-up is spent: it cannot be finished a second time.
    $this->post(route('battlenet.finish.store'), ['email' => 'second@example.com', 'terms' => '1']);
    expect(User::count())->toBe(1);
});

test('a Battle.net sign-up is never merged into an existing account by a typed email', function () {
    signupWorld();
    Queue::fake();
    $existing = User::factory()->create(['email' => 'taken@example.com']);
    fakeBattlenetAccount(777);

    $this->withSession(['battlenet_oauth_state' => 'st'])
        ->get(route('battlenet.callback', ['state' => 'st', 'code' => 'c']));

    $this->from(route('battlenet.finish'))
        ->post(route('battlenet.finish.store'), ['email' => 'TAKEN@example.com', 'terms' => '1'])
        ->assertRedirect(route('battlenet.finish'))
        ->assertSessionHasErrors('email');

    expect(Auth::check())->toBeFalse()
        ->and($existing->fresh()->battlenetAccount)->toBeNull()
        ->and(BattlenetAccount::count())->toBe(0);
});

test('the finish step requires the terms to be accepted', function () {
    signupWorld();
    Queue::fake();
    fakeBattlenetAccount(777);

    $this->withSession(['battlenet_oauth_state' => 'st'])
        ->get(route('battlenet.callback', ['state' => 'st', 'code' => 'c']));

    $this->post(route('battlenet.finish.store'), ['email' => 'new@example.com'])
        ->assertSessionHasErrors('terms');

    expect(User::count())->toBe(0);
});

test('an expired or missing Battle.net sign-up sends the player back to login', function () {
    $this->get(route('battlenet.finish'))->assertRedirect(route('login'));

    $this->withSession(['battlenet_pending_signup' => [
        'id' => 1, 'battletag' => 'Old#1', 'characters' => [], 'expires_at' => now()->subMinute()->timestamp,
    ]])->post(route('battlenet.finish.store'), ['email' => 'late@example.com', 'terms' => '1'])
        ->assertRedirect(route('login'));

    expect(User::count())->toBe(0);
});

test('a guest callback with a forged state signs nobody in', function () {
    Http::fake();

    $this->withSession(['battlenet_oauth_state' => 'expected'])
        ->get(route('battlenet.callback', ['state' => 'forged', 'code' => 'abc']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(Auth::check())->toBeFalse();
    Http::assertNothingSent();
});
