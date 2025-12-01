<?php

function route_with_context($routeName, $params = [])
{
    $categoryId = request('category_id');

    // Merge category automatically, but let $params override subject or category
    $query = array_merge(['category_id' => $categoryId], $params);

    // Remove nulls
    $query = array_filter($query, fn($v) => !is_null($v));

    return route($routeName, $query);
}

