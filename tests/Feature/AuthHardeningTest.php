<?php

use App\Rules\Recaptcha;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * The reCAPTCHA rule behind register, login and forgot-password, and the security headers every
 * web response now carries. The rule only skips on `local`, so it runs for real here against a
 * faked Google.
 */
function recaptchaPasses(string $action): bool
{
    return Validator::make(['token' => 'client-token'], ['token' => [new Recaptcha($action)]])->passes();
}

test('a token issued for this form passes', function () {
    Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => true, 'score' => 0.9, 'action' => 'register'])]);

    expect(recaptchaPasses('register'))->toBeTrue();
});

test('a token issued for a different form is rejected', function () {
    Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => true, 'score' => 0.9, 'action' => 'login'])]);

    expect(recaptchaPasses('register'))->toBeFalse();
});

test('a low score is rejected', function () {
    Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => true, 'score' => 0.2, 'action' => 'register'])]);

    expect(recaptchaPasses('register'))->toBeFalse();
});

test('Google being unreachable fails closed with a message, not a 500', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $v = Validator::make(['token' => 'x'], ['token' => [new Recaptcha('register')]]);

    expect($v->fails())->toBeTrue()
        ->and($v->errors()->first('token'))->toContain('security check');
});

test('pages carry the baseline security headers', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    // HSTS is production-over-HTTPS only; the test environment is neither.
    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});
