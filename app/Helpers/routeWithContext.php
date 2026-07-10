<?php

function route_with_context($routeName, $params = [])
{
    // Grab the current category/subject from the request, falling back to the last explicitly
    // selected context remembered in session (see DashboardController) — otherwise a link built
    // on a page that itself has no category_id/subject_id in its URL would silently point back
    // to Category::first(), undoing the "remember last context" fix everywhere else.
    $currentCategory = request('category_id') ?? session('context.category_id');
    $currentSubject = request('subject_id') ?? session('context.subject_id');

    if (!$currentCategory) {
        $currentCategory = \App\Models\Category::first()?->id; // default to first category if not in request
    }

    // Merge only if not already provided in $params
    $params = array_merge([
        'category_id' => $params['category_id'] ?? $currentCategory,
        'subject_id' => $params['subject_id'] ?? $currentSubject,
    ], $params);

    // Remove nulls
    $params = array_filter($params, fn($v) => !is_null($v));

    // Get route instance
    $route = \Route::getRoutes()->getByName($routeName);

    
    
    if (!$route) {
        // fallback: route doesn't exist
        return '#';
    }

    // Get required route parameters
    $requiredParams = collect($route->parameterNames)
        ->filter(fn($param) => !array_key_exists($param, $params));

    // If required parameters are missing, just return '#' or dashboard
    if ($requiredParams->isNotEmpty()) {
        // Optionally you could log or throw an exception
        return route('dashboard'); // safe fallback
    }

    return route($routeName, $params);
}
// Helper functions are accessible globally, so you can call route_with_context() from anywhere in your app, and it will automatically include the current category_id and subject_id from the request if they are not explicitly provided. This keeps your links consistent with the user's current context without having to manually pass those parameters every time.
function testGlobal()
{
    dd('this is a test to see if the helper function is working');
}
