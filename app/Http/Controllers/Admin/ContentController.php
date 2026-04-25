<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Concept;
use App\Models\Proficiency;
use App\Models\Subject;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    // ─── Categories ───────────────────────────────────────────────────────────

    public function categories()
    {
        $categories = Category::withCount('subjects')->orderBy('name')->get();
        return view('admin.categories.index', compact('categories'));
    }

    public function storeCategory(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string|max:1000',
        ]);

        Category::create($request->only('name', 'description'));

        return back()->with('success', "Category \"{$request->name}\" created.");
    }

    public function updateCategory(Request $request, Category $category)
    {
        $request->validate([
            'name'        => "required|string|max:255|unique:categories,name,{$category->id}",
            'description' => 'nullable|string|max:1000',
        ]);

        $category->update($request->only('name', 'description'));

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(Category $category)
    {
        $category->delete();
        return back()->with('success', 'Category deleted.');
    }

    // ─── Subjects ─────────────────────────────────────────────────────────────

    public function subjects(Request $request)
    {
        $categories  = Category::orderBy('name')->get();
        $categoryId  = $request->query('category_id', $categories->first()?->id);
        $subjects    = Subject::where('category_id', $categoryId)
            ->withCount(['concepts', 'modules'])
            ->with(['proficiencies' => fn ($q) => $q->orderBy('index')])
            ->orderBy('name')
            ->get();
        $currentCategory = $categories->find($categoryId);

        return view('admin.subjects.index', compact('categories', 'subjects', 'categoryId', 'currentCategory'));
    }

    public function storeSubject(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255|unique:subjects,name',
            'description' => 'nullable|string|max:1000',
            'category_id' => 'required|exists:categories,id',
        ]);

        Subject::create($request->only('name', 'description', 'category_id'));

        return back()->with('success', "Subject \"{$request->name}\" created.");
    }

    public function updateSubject(Request $request, Subject $subject)
    {
        $request->validate([
            'name'        => "required|string|max:255|unique:subjects,name,{$subject->id}",
            'description' => 'nullable|string|max:1000',
            'category_id' => 'required|exists:categories,id',
        ]);

        $subject->update($request->only('name', 'description', 'category_id'));

        return back()->with('success', 'Subject updated.');
    }

    public function destroySubject(Subject $subject)
    {
        $subject->delete();
        return back()->with('success', 'Subject deleted.');
    }

    // ─── Proficiencies (managed within subjects page) ─────────────────────────

    public function storeProficiency(Request $request)
    {
        $request->validate([
            'subject_id'  => 'required|exists:subjects,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'index'       => 'required|integer|min:0',
        ]);

        Proficiency::create($request->only('subject_id', 'name', 'description', 'index'));

        return back()->with('success', 'Proficiency added.');
    }

    public function updateProficiency(Request $request, Proficiency $proficiency)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'index'       => 'required|integer|min:0',
        ]);

        $proficiency->update($request->only('name', 'description', 'index'));

        return back()->with('success', 'Proficiency updated.');
    }

    public function destroyProficiency(Proficiency $proficiency)
    {
        $proficiency->delete();
        return back()->with('success', 'Proficiency deleted.');
    }

    // ─── Concepts ─────────────────────────────────────────────────────────────

    public function concepts(Request $request)
    {
        $categories = Category::with(['subjects' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get();
        $categoryId = $request->query('category_id', $categories->first()?->id);
        $subjects   = Subject::where('category_id', $categoryId)->orderBy('name')->get();
        $subjectId  = $request->query('subject_id', $subjects->first()?->id);

        $concepts       = $subjectId
            ? Concept::where('subject_id', $subjectId)->withCount('questions')->orderBy('name')->get()
            : collect();
        $currentSubject = $subjects->find($subjectId);

        return view('admin.concepts.index', compact(
            'categories', 'subjects', 'concepts', 'categoryId', 'subjectId', 'currentSubject'
        ));
    }

    public function storeConcept(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'subject_id'  => 'required|exists:subjects,id',
        ]);

        Concept::create($request->only('name', 'description', 'subject_id'));

        return back()->with('success', "Concept \"{$request->name}\" created.");
    }

    public function updateConcept(Request $request, Concept $concept)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $concept->update($request->only('name', 'description'));

        return back()->with('success', 'Concept updated.');
    }

    public function destroyConcept(Concept $concept)
    {
        $concept->delete();
        return back()->with('success', 'Concept deleted.');
    }
}
