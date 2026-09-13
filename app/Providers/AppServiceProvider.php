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
        //
        // NO BREACH CHECK (->uncompromised()), deliberately. It was on for one day (2026-09-12)
        // and turned away a real person on every password he tried — each one genuinely appeared
        // in Have I Been Pwned (checked: the rule itself was working, a random strong password
        // passed on production). Most people reuse a few passwords, so on a site where an account
        // holds guides and characters rather than money, the check cost more signups than it
        // protected. Google / Battle.net sign-in is now the low-friction path instead.
        Password::defaults(fn () => Password::min(8));

        View::composer('*', function ($view) {
            $credits = Auth::check() ? Auth::user()->credits : null;

            $view->with([
                'nav_ai_credits' => $credits->ai_credits ?? 0,
                'nav_learned_credits' => $credits->learned_credits ?? 0,
            ]);
        });
    }
}
