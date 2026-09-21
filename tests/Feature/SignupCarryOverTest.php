<?php

use App\Enums\FriendshipStatus;
use App\Mail\NewUserRegistered;
use App\Models\Friendship;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Quiz\QuizService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * What a new account starts with: the quizzes its owner took as a guest, and the site's welcome
 * friend. Driven through the real Google callback, because signing in replaces the session id —
 * that is exactly what lost the first guest's quiz scores (2026-09-20).
 */
beforeEach(function () {
    config([
        'services.google.client_id' => 'g-client',
        'services.google.client_secret' => 'g-secret',
        'mail.admin_address' => 'admin@example.com',
        'app.welcome_friend_email' => 'christian@mindcollector.com',
    ]);

    Mail::fake();
    Notification::fake();
});

function signUpWithGoogle(string $id = 'g-new', string $email = 'new@example.com'): User
{
    $social = (new SocialiteUser)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => true, 'name' => 'New Player'])
        ->map(['id' => $id, 'name' => 'New Player', 'email' => $email]);
    Socialite::shouldReceive('driver->user')->andReturn($social);

    test()->get(route('google.callback'));

    return User::where('google_id', $id)->firstOrFail();
}

function guestAttempt(string $sessionId, int $answered = 8, int $score = 8): QuizAttempt
{
    return QuizAttempt::create([
        'user_id' => null, 'session_id' => $sessionId, 'game' => 'wow', 'subject' => 'spec:1',
        'level' => 1, 'questions' => [], 'answered' => $answered, 'score' => $score, 'total' => 8,
        'completed_at' => now(),
    ]);
}

test('signing up carries a guest\'s quizzes onto the new account and onto the leaderboard', function () {
    $mine = guestAttempt('my-guest-session');
    $someoneElses = guestAttempt('another-visitor');

    $this->withSession([QuizService::GUEST_SESSIONS_KEY => ['my-guest-session']]);
    $user = signUpWithGoogle();

    expect($mine->fresh()->user_id)->toBe($user->id)
        ->and($mine->fresh()->session_id)->toBeNull()
        ->and($someoneElses->fresh()->user_id)->toBeNull()
        ->and(session(QuizService::GUEST_SESSIONS_KEY))->toBeNull();

    $board = app(QuizService::class)->leaderboard('wow');
    expect($board->pluck('user.id')->all())->toContain($user->id);
});

test('logging in to an existing account also claims that browser\'s guest quizzes', function () {
    $user = User::factory()->create();
    $attempt = guestAttempt('earlier-visit');

    session([QuizService::GUEST_SESSIONS_KEY => ['earlier-visit']]);
    Auth::login($user);

    expect($attempt->fresh()->user_id)->toBe($user->id);
});

test('a new account is friends with the welcome account straight away', function () {
    $welcome = User::factory()->create(['email' => 'christian@mindcollector.com']);

    $user = signUpWithGoogle();

    $row = Friendship::between($welcome->id, $user->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(FriendshipStatus::Accepted)
        ->and($row->accepted_at)->not->toBeNull()
        ->and($user->friendIds()->all())->toBe([$welcome->id]);
});

test('sign-up still works when the welcome account does not exist', function () {
    $user = signUpWithGoogle();

    expect(Auth::id())->toBe($user->id)
        ->and(Friendship::count())->toBe(0);
});

test('befriending turns a pending request into a friendship instead of adding a second row', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::create(['requester_id' => $b->id, 'addressee_id' => $a->id, 'status' => FriendshipStatus::Pending]);

    app(\App\Http\Services\FriendshipService::class)->befriend($a, $b);

    expect(Friendship::count())->toBe(1)
        ->and(Friendship::first()->status)->toBe(FriendshipStatus::Accepted);
});

test('the admin gets one new-user email per sign-up, not two', function () {
    signUpWithGoogle();

    Mail::assertSent(NewUserRegistered::class, 1);
});
