<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // for adding user credits to all requests
        $middleware->web(append: [
            \App\Http\Middleware\LoadUserCredits::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhook/stripe', // your webhook route
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Scanner-tampered /livewire/update payloads: a 419 and one warning line instead of a
        // 500 and a stack trace. See App\Support\LivewireTampering for exactly what is matched.
        $exceptions->map(fn (\Throwable $e) => \App\Support\LivewireTampering::matches($e)
            ? \App\Support\LivewireTampering::from($e)
            : $e);

        // An expired sign-in, sign-up or reset form (2026-10-26): a second press of "Sign in" a second
        // after the first had already signed in carried the replaced token and showed "419 Page
        // Expired" over a sign-in that had worked, and going back resubmitted the same stale form.
        // Signed in: on to where they were going. Not: back to the form, with a fresh token.
        // Laravel turns the mismatch into a 419 HttpException before render callbacks run.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException
                || ! $request->isMethod('post') || ! $request->is('login', 'register', 'forgot-password')) {
                return null;
            }
            if (auth()->check()) {
                return redirect()->intended(route('dashboard'));
            }

            // The POST routes have no names (routes/auth.php): back to each form's own page.
            return redirect()->route(['login' => 'login', 'register' => 'register', 'forgot-password' => 'password.request'][$request->path()])
                ->withInput($request->except('password', 'password_confirmation', '_token', 'g-recaptcha-response'))
                ->withErrors(['email' => 'The page had been open a while, so it needed a refresh. Please try again.']);
        });
    })->create();
