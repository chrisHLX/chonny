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
use App\Models\Pipeline;
use App\Models\PipelineStep;
use App\Models\Category;

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
            'proficiency_id' => 'required|exists:proficiencies,id',
        ]);
        
        // will add proficiency here to be attached as well once I figure out how to do that in the blade
        $module = Module::create([
            'name' => $request->name,
            'description' => $request->description,
            'created_by' => auth()->id(),
            'subject_id' => $request->subject_id,
            'proficiency_id' => $request->proficiency_id,
            'status' => 'need questions',
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
        $module = Module::findOrFail($moduleId); // suggestions for this module

        // 1️⃣ Fetch the latest pipeline for this user & module (however if we are still generating questions for this module it should show pending)
        $pipeline = Pipeline::where('user_id', $user->id)
                            ->where('module_id', $module->id)
                            ->with('steps')
                            ->latest('id') // ensures we get the newest pipeline
                            ->first();

        // 2️⃣ Safety check: pipeline exists
        if (!$pipeline) {
            \Log::warning("No pipeline found for user {$user->id} and module {$module->id}");
            return view('modules.pending');
        }

        // 3️⃣ Find the "Generate Suggestions" step
        $step = $pipeline->steps->firstWhere('name', 'Generate Suggestions');

        if (!$step) {
            \Log::warning("No 'Generate Suggestions' step found for pipeline {$pipeline->id}");
            return view('modules.pending');
        }

        // 4️⃣ Check if step is completed
        if ($step->status !== 'completed') {
            \Log::info("Pipeline {$pipeline->id} step '{$step->name}' not completed yet. Status: {$step->status}");
            return view('modules.pending');
        }

        // ✅ Optional: ensure all steps are completed before proceeding
        $allStepsCompleted = $pipeline->steps->every(fn($s) => $s->status === 'completed');
        if (!$allStepsCompleted) {
            \Log::info("Pipeline {$pipeline->id} has incomplete steps");
        }

        // 5️⃣ Generate suggestions normally
        $hashKey = $this->userModuleService->getHash($user, $module);
        $response = $this->suggestionsService->getSuggestions($module, $hashKey);
        $parent_id = $response->module_id;
        $suggestions = $response->suggestions_json['recommendations'];

        // 6️⃣ Check existing modules in DB
        $existingModules = Module::whereIn('name', collect($suggestions)->pluck('name'))
                                ->pluck('id', 'name');
        $existingIds = $existingModules->values();

        $userAssignedIds = $user->modules()
                                ->whereIn('modules.id', $existingIds)
                                ->pluck('modules.id')
                                ->toArray();

        // 7️⃣ Merge suggestions with existing module info
        $suggestions = collect($suggestions)->map(function ($s) use ($existingModules, $parent_id, $userAssignedIds) {
            $moduleId = $existingModules[$s['name']] ?? null;

            $s['parent_id'] = $parent_id;
            $s['exists'] = $moduleId !== null;
            $s['module_id'] = $moduleId;
            $s['assigned'] = in_array($moduleId, $userAssignedIds);

            return $s;
        });

        \Log::info("Serving next module suggestions for user {$user->id}, pipeline {$pipeline->id}");

        return view('modules.next-module', compact('suggestions'));
    }


    // Create User Selected Module from the suggestions
    public function createSuggested(Request $request)
    {
        $userID = Auth()->id();

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
            'status' => 'preparing',
            'published' => true,
        ]);

        $module->proficiencies()->attach($proficiencyId);

        // Now start the pipeline to generate questions
        $pipeline = Pipeline::create([
            'user_id' => $userID,
            'module_id' => $module->id,
            'type' => 'question_generation',
            'status' => 'running',
        ]);

        $types = ['mcq', 'true_false', 'matching_pairs', 'ordering'];
        foreach ($types as $selectedType) {
            $pipelineStep = PipelineStep::create([
                'pipeline_id' => $pipeline->id,
                'name' => "Generate {$selectedType} Questions",
                'status' => 'pending',
            ]);
            GenerateQuestions::dispatch($selectedType, $module->id, $pipelineStep->id, $userID);
        }

       return redirect()->route('modules.index');
    
    }
}
