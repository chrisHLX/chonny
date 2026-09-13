<?php

namespace App\Http\Services;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates and signs in an account that came from Google or Battle.net rather than the email form.
 *
 * Mirrors RegisteredUserController::store() step for step — the Registered event (welcome email,
 * new-user notification), sign-in, and the guest carry-over — so an account's first minutes do not
 * depend on which button made it.
 *
 * THE PASSWORD IS RANDOM AND NEVER SHOWN. Nobody can sign in with it; it exists because the column
 * is required and an empty hash would be a worse default. A player who later wants a password uses
 * "Forgot password", which proves they own the email first.
 *
 * Terms: the sign-in buttons sit under "By continuing you agree to the Terms and Privacy Policy", so
 * acceptance is recorded the same way the checkbox records it.
 */
class SocialSignupService
{
    public function __construct(private GuestResultsClaimService $guestResults) {}

    /** @param  array<string, mixed>  $attributes  extra columns, e.g. google_id */
    public function create(string $name, string $email, bool $emailVerified, array $attributes = []): User
    {
        $user = new User;
        $user->forceFill(array_merge([
            'name' => mb_substr(trim($name) ?: Str::before($email, '@'), 0, 255),
            'email' => Str::lower($email),
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => $emailVerified ? now() : null,
            'tos_accepted_at' => now(),
            'tos_version' => RegisteredUserController::TOS_VERSION,
        ], $attributes))->save();

        event(new Registered($user));

        Auth::login($user, remember: true);

        $this->guestResults->claim($user);

        return $user;
    }
}
