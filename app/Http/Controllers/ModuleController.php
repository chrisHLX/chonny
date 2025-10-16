<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Question;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Http\Services\AiService;
use App\Models\ModulePage;
use Illuminate\Support\Facades\Log;
use App\Http\Services\HtmlFormatter;
use App\Models\Subject;

class ModuleController extends Controller
{

    protected AiService $aiService;
    protected HtmlFormatter $formatter;

    public function __construct(AiService $aiService, HtmlFormatter $formatter)
    {
        $this->aiService = $aiService;
        $this->formatter = $formatter;
    }

    public function assign(Module $module)
    {
        $user = Auth::user();

        if (! $user->modules->contains($module->id)) {
            $user->modules()->attach($module->id, [
                'status' => 'in_progress',
                'score' => 0,
                'current_difficulty' => 'beginner',
                'last_activity_at' => now(),
                'completed_at' => null
            ]);
        }

        return redirect()->back()->with('success', 'Module added!');
    }

    public function create()
    {
        $subjects = Subject::all();
        return view('modules.create', compact('subjects'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'subject_id' => 'required|exists:subjects,id',
        ]);
        
        $module = Module::create([
            'name' => $request->name,
            'description' => $request->description,
            'created_by' => auth()->id(),
            'subject_id' => $request->subject_id,
        ]);

        return redirect()->route('modules.edit', $module); // Or a success view      
    }

    public function destroy(Module $module)
    {
        // Optional: Confirm user owns this module
        if ($module->created_by !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $module->users()->detach(); // Detach all users
        $module->questions()->detach(); // Detach all questions associated with the module
        $module->delete();

        return redirect()->route('dashboard')->with('success', 'Module deleted successfully.');
    }

    public function destroyPage(ModulePage $modulePage)
    {
        $modulePage->delete();

        return back()->with('success', 'Module page deleted successfully.');
    }


    
    public function edit(Module $module)
    {
        $allQuestions = Question::all(); // for attaching existing ones
        $modulePages = ModulePage::where('module_id', $module->id)
                   ->orderBy('page_number')
                   ->get();
        return view('modules.edit', compact('module', 'allQuestions', 'modulePages'));
    }

    public function update(Request $request, Module $module)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'question_ids' => 'array',
            'question_ids.*' => 'exists:questions,id',
        ]);

        $module->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        // sync selected questions
        $module->questions()->sync($request->question_ids);

        return redirect()->route('modules.index')->with('success', 'Module updated.');
    }

    public function createLandingPage(Request $request, Module $module)
    {
        // Log the full request data
        Log::info('Landing page request data:', $request->all());

        // Optional: log only the description if you prefer
        Log::info('Description content:', ['description' => $request->input('description')]);
        

        $request->validate([
            'description' => 'nullable|string|max:1000',
        ]);

        $description = $request->input('description', '');
        $formattedDescription = $this->formatter->format($description);

        ModulePage::create([
            'module_id'   => $module->id,
            'title'       => $module->name,
            'content'     => $formattedDescription,
            'page_number' => 1, // landing page
            'created_by'  => auth()->id(),
            'updated_by'  => auth()->id(),
        ]);

        dd($formattedDescription);
    }

    
    public function generateLandingPage(Module $module, Request $request)
    {
        
        if ($userPrompt = $request->input('description') === null) {
            $userPrompt = 'No additional context provided.';
        } else {
            $userPrompt = $request->input('description');
        };

        
        
        // Call the AI service to generate the landing page content
        $content = $this->aiService->createLandingPage($module, $userPrompt);

        // Log the AI request
        \Log::info('Landing page generated for module', [
            'module_id' => $module->id,
            'user_id' => auth()->id(),
            'content_length' => strlen($content),
        ]);

        // Return the generated content or redirect to a view
        return view('modules.landing', [
            'module' => $module,
            'content' => $content,
        ]);
    }
    
    
    public function page(Module $module)
    {
        $pages = ModulePage::where('module_id', $module->id)
                   ->orderBy('page_number')
                   ->get();

        return view('modules.page', ['pages' => $pages]);
    }
}
