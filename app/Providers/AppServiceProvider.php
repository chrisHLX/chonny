<?php

namespace App\Providers;

use App\Listeners\SendNewUserNotification;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Registered::class, SendNewUserNotification::class);

        Gate::define('admin', fn (User $user) => $user->is_admin);

        // Signup, password reset and password change all validate against Password::defaults().
        // In production a password must also not appear in a known breach (Have I Been Pwned's
        // range API: only the first 5 characters of the SHA-1 leave the server, never the
        // password). If that API is unreachable Laravel lets the password through rather than
        // blocking signups.
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(8)->uncompromised()
            : Password::min(8));

        View::composer('*', function ($view) {
            $credits = Auth::check() ? Auth::user()->credits : null;

            $view->with([
                'nav_ai_credits' => $credits->ai_credits ?? 0,
                'nav_learned_credits' => $credits->learned_credits ?? 0,
            ]);
        });
    }
}
