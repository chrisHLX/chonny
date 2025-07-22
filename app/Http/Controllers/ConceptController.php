<?php

namespace App\Http\Controllers;

use App\Models\Concept;
use Illuminate\Http\Request;

class ConceptController extends Controller
{
    public function create()
    {
        $types = Concept::getTypeOptions(); // We'll add this to the model
        return view('concepts.create', compact('types'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:concepts,name',
            'type' => 'required|string|in:' . implode(',', Concept::getTypeOptions()),
            'description' => 'nullable|string',
        ]);

        Concept::create($validated);

        return redirect()->route('concepts.create')->with('success', 'Concept added!');
    }
}
