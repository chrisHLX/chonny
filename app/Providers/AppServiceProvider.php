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
        // make credits available in all views for navigation display
        View::composer('*', function ($view) {
            if (Auth::check()) {
                $view->with('nav_ai_credits', Auth::user()->credits()->firstOrcreate()->ai_credits);
                $view->with('nav_learned_credits', Auth::user()->credits()->firstOrcreate()->learned_credits);
            }
        });


    }
}
