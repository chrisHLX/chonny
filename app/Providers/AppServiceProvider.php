<?php

namespace App\Providers;

use App\Listeners\SendNewUserNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;
use App\Models\UserCredit;
use App\Models\User;


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

        View::composer('*', function ($view) {
            $credits = Auth::check() ? Auth::user()->credits : null;

            $view->with([
                'nav_ai_credits'     => $credits->ai_credits ?? 0,
                'nav_learned_credits' => $credits->learned_credits ?? 0,
            ]);
        });
    }


}
