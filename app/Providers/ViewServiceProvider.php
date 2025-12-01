<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Models\Category;

class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Load categories for the navbar only
        View::composer('layouts.navigation', function ($view) {
            $categories = cache()->rememberForever('nav_categories', function () {
                return Category::orderBy('name')->get();
            });

            $view->with('nav_categories', $categories);
        });
    }
}
