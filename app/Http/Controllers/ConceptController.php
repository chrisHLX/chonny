<?php

namespace App\Http\Controllers;

use App\Models\Concept;
use Illuminate\Http\Request;

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
}
