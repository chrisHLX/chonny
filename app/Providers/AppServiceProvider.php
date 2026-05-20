<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
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
        View::composer('*', function ($view) {
            $credits = Auth::check() ? Auth::user()->credits : null;

            $view->with([
                'nav_ai_credits'     => $credits->ai_credits ?? 0,
                'nav_learned_credits' => $credits->learned_credits ?? 0,
            ]);
        });
    }


}
