<?php

namespace App\Http\Controllers;


use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use App\Models\ModulePage;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Concept;
use App\Models\Proficiency;

use App\Http\Services\HtmlFormatter;
use App\Http\Services\AiService;
use App\Http\Services\UserModuleService;
use App\Http\Services\SuggestionsService;

use App\Jobs\GenerateQuestions;

class ModuleController extends Controller
{

    protected AiService $aiService;
    protected HtmlFormatter $formatter;
    protected UserModuleService $userModuleService;
    protected SuggestionsService $suggestionsService;

    public function __construct(AiService $aiService, HtmlFormatter $formatter, UserModuleService $userModuleService, SuggestionsService $suggestionsService)
    {
        $this->aiService = $aiService;
        $this->formatter = $formatter;
        $this->userModuleService = $userModuleService;
        $this->suggestionsService = $suggestionsService;
    }

    public function index() 
    {
        $modules = Module::with('users', 'questions')->get();
        return view('modules.index', compact('modules'));
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
        // will add proficiency here to be attached as well once I figure out how to do that in the blade
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
        $subjectID = $module->subject_id;
        $conceptsList = Concept::where('subject_id', $subjectID)->get();
        
        \Log::info('Concepts List:', ['concepts' => $conceptsList]);
        return view('modules.edit', compact('module', 'allQuestions', 'modulePages', 'conceptsList'));
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

    public function nextModule(int $moduleId)
    {
        $user = auth()->user();
        $module = Module::findOrFail($moduleId);

        // 1. Get hash & suggestions
        $hashKey = $this->userModuleService->getHash($user, $module);
        $response = $this->suggestionsService->getSuggestions($module, $hashKey);
        $parent_id = $response->module_id;
        $suggestions = $response->suggestions_json['recommendations'];

        // 2. Find matching modules already in the DB
        $existingModules = Module::whereIn('name', collect($suggestions)->pluck('name'))
                                ->pluck('id', 'name'); // ['Module A' => 4, 'Module B' => 7]
        // check if user has already assigned any modules 
        $existingIds = $existingModules->values(); // [4,7,10,...]
        $userAssignedIds = $user->modules()
            ->whereIn('modules.id', $existingIds)
            ->pluck('modules.id')
            ->toArray();

        // 3. Merge suggestions with existence info
        $suggestions = collect($suggestions)->map(function ($s) use ($existingModules, $parent_id, $userAssignedIds) {

            $moduleId = $existingModules[$s['name']] ?? null;

            $s['parent_id'] = $parent_id;
            $s['exists'] = $moduleId !== null;
            $s['module_id'] = $moduleId;

            // Only check among existing modules
            $s['assigned'] = in_array($moduleId, $userAssignedIds);

            return $s;
        });



        return view('modules.next-module', compact('suggestions'));
    }

    public function createSuggested(Request $request)
    {
        $data = $request->validate([
            'suggestion' => 'required|string',
        ]);

        // Decode the JSON coming from the blade form
        $suggestion = json_decode($data['suggestion'], true);
        
        if (! $suggestion) {
            return back()->with('error', 'Invalid suggestion data.');
        }
        
        // Prevent duplicate modules
        $existing = Module::where('name', $suggestion['name'])->first();

        if ($existing) {
            // Module already exists → assign to user instead
            auth()->user()->modules()->syncWithoutDetaching([
                $existing->id => ['status' => 'not_started']
            ]);

            return redirect()
                ->route('modules.index')
                ->with('success', "Module '{$existing->name}' already existed and has been added to your list.");
        }

        $subjectID = Subject::where("name", $suggestion["subject"])->first()->id;

        $proficiencyId = Proficiency::where('subject_id', $subjectID)
                                    ->where('name', $suggestion["proficiency"])
                                    ->first()
                                    ->id;
        
        
        // need to add all this to a create module job
        $module = Module::create([
            'name' => $suggestion["name"],
            'description' => $suggestion["description"],
            'parent_id' => $suggestion["parent_id"],
            'subject_id' => $subjectID,
            'published' => true,
        ]);

        $module->proficiencies()->attach($proficiencyId);

        $types = ['mcq', 'true_false', 'matching_pairs', 'ordering'];
        foreach ($types as $selectedType) {
            GenerateQuestions::dispatch($selectedType, $module);
        }
    }
}
