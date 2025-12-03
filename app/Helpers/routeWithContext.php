<?php

function route_with_context($routeName, $params = [])
{
    // Grab the current category/subject from the request
    $currentCategory = request('category_id');
    $currentSubject = request('subject_id');

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
