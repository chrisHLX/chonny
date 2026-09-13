<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
    })->create();
