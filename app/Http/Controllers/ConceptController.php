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
        $categoryId = request('category_id');
        $currentSubjectId = request('subject_id');
        $user = auth()->user();
        // Default category if none selected
        if (!$categoryId) {
            $categoryId = Category::first()->id;
        }

        // Load subjects for this category (for the toggle)
        $subjects = Subject::where('category_id', $categoryId)->get();

        // Default subject if none picked
        if (!$currentSubjectId && $subjects->count()) {
            $currentSubjectId = $subjects->first()->id;
        }

        // Filter concepts by the selected subject
        $concepts = Concept::when($currentSubjectId, function ($query, $subjectId) {
            $query->where('subject_id', $subjectId);
        })
        ->with(['questions'])
        ->orderBy('name')->get();

        return view('concepts.index', compact('concepts', 'subjects', 'currentSubjectId', 'categoryId', 'user'));
    }

}
