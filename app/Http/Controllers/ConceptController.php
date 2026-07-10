<?php

namespace App\Http\Controllers;

use App\Models\Concept;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Subject;

class ConceptController extends Controller
{
    public function create()
    {
        return view('concepts.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:concepts,name',
            'description' => 'nullable|string',
        ]);

        Concept::create($validated);

        return redirect()->route('concepts.create')->with('success', 'Concept added!');
    }

    public function index()
    {
        // Falls back to the last explicitly selected context remembered in session before
        // Category::first() — see DashboardController for why (a contextless visit shouldn't
        // reset to whatever's first in the DB).
        $categoryId = request('category_id') ?? session('context.category_id');
        $currentSubjectId = request('subject_id') ?? session('context.subject_id');
        $user = auth()->user();
        // Default category if none selected or remembered
        if (!$categoryId) {
            $categoryId = Category::first()->id;
        }

        session(['context.category_id' => $categoryId]);

        // Load subjects for this category (for the toggle)
        $subjects = Subject::where('category_id', $categoryId)->get();

        // A remembered/URL subject may belong to a different category than the one just
        // resolved above — don't let a stale cross-category value survive.
        if (!$subjects->contains('id', $currentSubjectId) && $subjects->count()) {
            $currentSubjectId = $subjects->first()->id;
        }

        session(['context.subject_id' => $currentSubjectId]);

        // Filter concepts by the selected subject
        $concepts = Concept::when($currentSubjectId, function ($query, $subjectId) {
            $query->where('subject_id', $subjectId);
        })
        ->with(['questions'])
        ->orderBy('name')->get();

        return view('concepts.index', compact('concepts', 'subjects', 'currentSubjectId', 'categoryId', 'user'));
    }

}
