<?php
// app/Http/Controllers/CategoryController.php

namespace App\Http\Controllers;

use App\Models\Category;

class CategoryController extends Controller
{
    public function show(Category $category)
    {
        // Load subjects or modules for this category
        $subjects = $category->subjects()->get();

        return view('categories.show', compact('category', 'subjects'));
    }
}
