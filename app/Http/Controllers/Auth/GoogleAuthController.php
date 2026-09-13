<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Services\SocialSignupService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * "Continue with Google" — one button that signs in, or signs up, in a single step.
 *
 * WHICH ACCOUNT A GOOGLE SIGN-IN LANDS ON, in order:
 *  1. The account already carrying this google_id (Google's stable `sub`, never the email — see the
 *     add_google_id_to_users migration).
 *  2. An existing account with the same email, BUT ONLY WHEN GOOGLE SAYS THE EMAIL IS VERIFIED.
 *     That flag is Google vouching the person controls the address; without it, anyone could make
 *     a Google account showing somebody else's email and walk into their MindCollector account.
 *     An unverified match is refused with a message, never linked.
 *  3. Otherwise a new account.
 *
 * No reCAPTCHA here: getting through Google's own sign-in is a stronger bot barrier than a score.
 */
class GoogleAuthController extends Controller
{
    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirect(): RedirectResponse
    {
        abort_unless(self::isConfigured(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, SocialSignupService $signup): RedirectResponse
    {
        abort_unless(self::isConfigured(), 404);

        $toLogin = fn (string $message) => redirect()->route('login')->withErrors(['email' => $message]);

        if ($request->filled('error')) {
            return $toLogin('Google sign-in was cancelled.');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $toLogin('That Google sign-in expired. Please try again.');
        } catch (\Throwable $e) {
            report($e);

            return $toLogin('Google sign-in failed. Please try again, or use your email and password.');
        }

        $googleId = (string) $google->getId();
        $email = Str::lower((string) $google->getEmail());
        $verified = (bool) ($google->user['email_verified'] ?? false);

        if ($googleId === '' || $email === '') {
            return $toLogin('Google did not share an email address, so we could not sign you in.');
        }

        $user = User::where('google_id', $googleId)->first();

        if (! $user && ($existing = User::where('email', $email)->first())) {
            if (! $verified) {
                return $toLogin('An account already uses this email. Log in with your password instead.');
            }

            $existing->forceFill([
                'google_id' => $googleId,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            $user = $existing;
        }

        if ($user) {
            Auth::login($user, remember: true);
        } else {
            $signup->create((string) $google->getName(), $email, $verified, ['google_id' => $googleId]);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
