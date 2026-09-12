<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

/**
 * reCAPTCHA v3 verification for the auth forms (register, login, forgot password).
 *
 * Replaces three hand-copied closures that differed only in the IP accessor. Over those it adds:
 *
 * - THE ACTION CHECK. Each form requests its token with its own action name ('register', 'login',
 *   'forgot_password'). Without checking it, a token minted on one form — or harvested from any
 *   page carrying the site key — is accepted by another. Google returns the action it was issued
 *   for; it must match the form being submitted.
 * - A TIMEOUT, AND FAILING CLOSED. The old closures used Http's 30s default and let a connection
 *   error escape, which rendered a 500 on the signup form. Now a Google outage is a clear "try
 *   again" message, and never lets a submission through unverified.
 *
 * Skipped on local only, exactly as before.
 */
class Recaptcha implements ValidationRule
{
    public const MIN_SCORE = 0.5;

    public function __construct(private string $action) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (app()->environment('local')) {
            return;
        }

        try {
            $result = Http::asForm()->connectTimeout(5)->timeout(8)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => config('services.recaptcha.secret_key'),
                    'response' => (string) $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (\Throwable $e) {
            report($e);
            $fail('We could not complete the security check just now. Please try again in a moment.');

            return;
        }

        if (! $result->json('success')
            || (float) $result->json('score', 0) < self::MIN_SCORE
            || $result->json('action') !== $this->action) {
            $fail('reCAPTCHA verification failed. Please try again.');
        }
    }
}
