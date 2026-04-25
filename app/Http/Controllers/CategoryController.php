<?php
// app/Http/Controllers/CategoryController.php

namespace App\Http\Controllers;

use App\Models\Category;

class CategoryController extends Controller
{
    public function show(Category $category)
    {
        $subjects = $category->subjects()->get();

        return view('categories.show', compact('category', 'subjects'));
    }

    public function subjectsByCategory(Category $category)
    {
        return response()->json($category->subjects()->orderBy('name')->get(['id', 'name']));
    }
}
