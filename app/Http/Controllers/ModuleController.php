<?php

namespace App\Http\Controllers;


use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
use App\Http\Services\ResearchService;

use App\Jobs\GenerateQuestions;
use App\Jobs\GenerateModuleContentJob;
use App\Jobs\ExploreModuleJob;

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

    public function manage()
    {
        $modules = Module::where('created_by', Auth::id())
            ->with(['subject', 'proficiencies', 'questions', 'modulePages'])
            ->latest()
            ->get();

        return view('modules.manage', compact('modules'));
    }

    public function create()
    {
        $categories = \App\Models\Category::orderBy('name')->get(['id', 'name']);
        return view('modules.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'subject_id' => 'required|exists:subjects,id',
            'proficiency_id' => 'required|exists:proficiencies,id',
        ]);
        
        try {
           // Assign the result of the transaction to $module
            $module = DB::transaction(function () use ($request) {
                $newModule = Module::create([
                    'name' => $request->name,
                    'description' => $request->description,
                    'created_by' => auth()->id(),
                    'subject_id' => $request->subject_id,
                    'status' => 'need questions',
                ]);

                // Note: Use the validated key 'proficiency_id' (with underscore) 
                // as defined in your validation rules
                $newModule->proficiencies()->attach($request->proficiency_id);

                // Return the object so it can be captured outside
                return $newModule; 
            });

            // Now $module is available here!
            return redirect()->route('modules.edit', $module)
                            ->with('success', 'Module created!');
            
        } catch (\Throwable $e) {
            dd('error', $e);
        }
        
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

        // Delete the pages (but what about questions? we need to use an if statement to check if the questions are already attached to other modules then we cant delete them but if they are not we can)
        $module->modulePages()->delete();
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
        $modulePages = ModulePage::where('module_id', $module->id)
                   ->orderBy('page_number')
                   ->get();

        $subjectID  = $module->subject_id;
        $categoryId = $module->subject->category_id;

        // Only surface questions whose concepts belong to subjects in the same category
        $subjectIds  = Subject::where('category_id', $categoryId)->pluck('id');
        $conceptIds  = Concept::whereIn('subject_id', $subjectIds)->pluck('id');
        $allQuestions = Question::whereHas('concepts', fn ($q) => $q->whereIn('concepts.id', $conceptIds))
                                ->orderBy('question')
                                ->get();

        $conceptsList = Concept::where('subject_id', $subjectID)->get();

        $pipeline = Pipeline::where('module_id', $module->id)
            ->where('type', 'question_generation')
            ->where('status', 'running')
            ->latest('id')
            ->first();

        $subjectResearch = \App\Models\SubjectContent::where('subject_id', $module->subject_id)
            ->latest()
            ->take(10)
            ->get();

        $subjectResearchCount = \App\Models\SubjectContent::where('subject_id', $module->subject_id)->count();
        $modulePageCount = $modulePages->count();
        $moduleResearch = \App\Models\SubjectContent::where('module_id', $module->id)->first();

        return view('modules.edit', compact('module', 'allQuestions', 'modulePages', 'conceptsList', 'pipeline', 'subjectResearch', 'subjectResearchCount', 'modulePageCount', 'moduleResearch'));
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

    public function savePage(Request $request, Module $module)
    {
        $request->validate([
            'content'    => 'required|string|max:50000',
            'page_title' => 'nullable|string|max:255',
            'page_id'    => 'nullable|integer',
        ]);

        if ($request->filled('page_id')) {
            $page = ModulePage::where('id', $request->page_id)
                ->where('module_id', $module->id)
                ->firstOrFail();

            $page->update([
                'content'    => $request->content,
                'title'      => $request->page_title ?: $page->title,
                'updated_by' => auth()->id(),
            ]);
        } else {
            $nextNumber = (ModulePage::where('module_id', $module->id)->max('page_number') ?? 0) + 1;

            ModulePage::create([
                'module_id'   => $module->id,
                'page_number' => $nextNumber,
                'title'       => $request->page_title ?: $module->name,
                'content'     => $request->content,
                'created_by'  => auth()->id(),
                'updated_by'  => auth()->id(),
            ]);
        }

        return redirect()->route('modules.edit', $module)->with('success', 'Page saved.');
    }

    public function createLandingPage(Request $request, Module $module)
    {
        // Log the full request data
        Log::info('Landing page request data:', $request->all());

        // Optional: log only the description if you prefer
        Log::info('Description content:', ['description' => $request->input('description')]);
        

        $request->validate([
            'description' => 'nullable|string|max:9000',
        ]);

        $description = $request->input('description', '');
        // $formattedDescription = $this->formatter->format($description);
        
        ModulePage::updateOrCreate([
            'module_id'   => $module->id,
            'title'       => $module->name,
            'page_number' => 1, // landing page
        ],
        [
            'content'     => $description,
            'created_by'  => auth()->id(),
            'updated_by'  => auth()->id(),
        ]);

        return redirect()->route('modules.page', $module);
    }

    
    public function generateLandingPage(Module $module)
    {      
        // Call the AI service to generate the landing page content
        $content = $this->aiService->createLandingPage($module);

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

        $research = \App\Models\SubjectContent::where('subject_id', $module->subject_id)
            ->latest()
            ->take(5)
            ->get();

        return view('modules.page', compact('module', 'pages', 'research'));
    }

    public function explore(Request $request)
    {
        $request->validate([
            'intent'         => 'required|string|max:500',
            'instructions'   => 'nullable|string|max:1000',
            'subject_id'     => 'required|exists:subjects,id',
            'proficiency_id' => 'required|exists:proficiencies,id',
            'source_url'     => 'nullable|url|max:2000',
            'youtube_mode'   => 'nullable|in:transcript,video',
        ]);

        $user         = auth()->user();
        $subjectId    = $request->subject_id;
        $subject      = Subject::findOrFail($subjectId);
        $instructions = trim($request->input('instructions', ''));
        $sourceUrl    = trim($request->input('source_url', ''));
        $youtubeMode  = $request->input('youtube_mode', 'transcript');

        $proficiency = Proficiency::where('id', $request->proficiency_id)
            ->where('subject_id', $subjectId)
            ->firstOrFail();

        $intent = trim($request->intent);
        $name   = 'Exploring: ' . \Illuminate\Support\Str::limit($intent, 60);

        $module = Module::create([
            'name'        => $name,
            'description' => $intent,
            'subject_id'  => $subjectId,
            'status'      => 'preparing',
            'created_by'  => $user->id,
            'published'   => false,
        ]);

        $module->proficiencies()->attach($proficiency->id);

        $user->modules()->attach($module->id, [
            'status'             => 'not_started',
            'current_difficulty' => 'easy',
            'last_activity_at'   => now(),
        ]);

        $pipeline = Pipeline::create([
            'user_id'   => $user->id,
            'module_id' => $module->id,
            'type'      => 'explore_generation',
            'status'    => 'running',
        ]);

        $contentStep = PipelineStep::create([
            'pipeline_id' => $pipeline->id,
            'name'        => 'Generate Content',
            'status'      => 'pending',
        ]);

        $mcqStep = PipelineStep::create([
            'pipeline_id' => $pipeline->id,
            'name'        => 'Generate MCQ Questions',
            'status'      => 'pending',
        ]);

        $trueFalseStep = PipelineStep::create([
            'pipeline_id' => $pipeline->id,
            'name'        => 'Generate True/False Questions',
            'status'      => 'pending',
        ]);

        ExploreModuleJob::dispatch(
            $module->id,
            $pipeline->id,
            $contentStep->id,
            $mcqStep->id,
            $trueFalseStep->id,
            $user->id,
            $intent,
            $instructions,
            $sourceUrl,
            $youtubeMode
        );

        app(\App\Http\Services\CreditService::class)->rewardLearnedCredits($user->id, 10, 'Guide generated');

        return redirect()->route('modules.building', ['module' => $module->slug, 'pipeline' => $pipeline->id]);
    }

    public function export(Module $module)
    {
        $module->load([
            'subject.category.axes',
            'proficiencies',
            'modulePages' => fn ($q) => $q->orderBy('page_number'),
            'questions.concepts.axes',
        ]);

        $proficiency = $module->proficiencies->first();

        $questions = $module->questions->map(function (Question $q) {
            $concepts = $q->concepts->map(fn ($c) => [
                'id'   => $c->id,
                'name' => $c->name,
                'axes' => $c->axes->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
            ])->values()->all();

            return [
                'id'               => $q->id,
                'question'         => $q->question,
                'type'             => $q->type,
                'difficulty'       => $q->difficulty,
                'skill_type'       => $q->skill_type?->value ?? $q->skill_type,
                'concepts'         => $concepts,
                'concepts_missing' => empty($concepts),
            ];
        });

        // Diagnostics
        $totalQuestions          = $questions->count();
        $missingConcepts         = $questions->filter(fn ($q) => $q['concepts_missing'])->count();
        $conceptsMissingAxisMap  = $module->questions->flatMap(fn ($q) => $q->concepts)
            ->unique('id')
            ->filter(fn ($c) => $c->axes->isEmpty())
            ->count();

        $skillDist = $questions->groupBy('skill_type')
            ->map(fn ($g) => $g->count())
            ->all();

        $diffDist = $questions->groupBy('difficulty')
            ->map(fn ($g) => $g->count())
            ->all();

        $conceptCoverage = $module->questions->flatMap(fn ($q) => $q->concepts)
            ->groupBy('id')
            ->map(function ($group) {
                $concept = $group->first();
                return [
                    'concept'        => $concept->name,
                    'question_count' => $group->count(),
                    'axes'           => $concept->axes->pluck('name')->values()->all(),
                ];
            })
            ->values()
            ->sortByDesc('question_count')
            ->values()
            ->all();

        $payload = [
            'module' => [
                'id'        => $module->id,
                'name'      => $module->name,
                'status'    => $module->status,
                'published' => (bool) $module->published,
                'subject'   => $module->subject ? [
                    'id'       => $module->subject->id,
                    'name'     => $module->subject->name,
                    'category' => $module->subject->category ? [
                        'id'   => $module->subject->category->id,
                        'name' => $module->subject->category->name,
                        'axes' => $module->subject->category->axes->map(fn ($a) => [
                            'id'          => $a->id,
                            'name'        => $a->name,
                            'description' => $a->description ?? null,
                        ])->values()->all(),
                    ] : null,
                ] : null,
                'proficiency'   => $proficiency ? [
                    'id'          => $proficiency->id,
                    'name'        => $proficiency->name,
                    'index'       => $proficiency->index ?? null,
                    'description' => $proficiency->description ?? null,
                    'outcomes'    => $proficiency->outcomes ?: null,
                ] : null,
                'content_pages' => $module->modulePages->map(fn ($p) => [
                    'page_number' => $p->page_number,
                    'title'       => $p->title,
                    'content'     => $p->content ?: null,
                ])->values()->all(),
                'questions'   => $questions->values()->all(),
                'diagnostics' => [
                    'total_questions'               => $totalQuestions,
                    'questions_missing_concepts'    => $missingConcepts,
                    'concepts_missing_axis_mapping' => $conceptsMissingAxisMap,
                    'proficiency_has_outcomes'      => $proficiency && ! empty($proficiency->outcomes),
                    'has_content_pages'             => $module->modulePages->isNotEmpty(),
                    'skill_type_distribution'       => $skillDist,
                    'difficulty_distribution'       => $diffDist,
                    'concept_coverage'              => $conceptCoverage,
                ],
            ],
        ];

        $filename = "module-{$module->id}-export.json";
        $json     = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response($json, 200, [
            'Content-Type'        => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // We can avoid the generate questions job for editing and creating modules by just calling the ai service function
    public function generateQuestions(Request $request, Module $module)
    {
        $content = $module->modulePages()->pluck('content')->implode("\n\n");
        if (empty($content)) {
            return back()->with('error', 'Add module content before generating questions.');
        }

        $validTypes = ['mcq', 'true_false', 'matching_pairs', 'ordering'];
        $selectedTypes = $request->has('types')
            ? array_values(array_intersect((array) $request->input('types'), $validTypes))
            : $validTypes;

        if (empty($selectedTypes)) {
            return back()->with('error', 'Select at least one question type.');
        }

        $difficultyFocus = in_array($request->input('difficulty'), ['easy', 'medium', 'hard'])
            ? $request->input('difficulty')
            : null;

        $modelOverride = $request->input('model');
        if ($modelOverride !== null && ! array_key_exists($modelOverride, config('ai_models', []))) {
            return back()->with('error', 'Invalid model selection.');
        }

        $userID = auth()->id();
        $mode   = 'edit';

        $pipeline = Pipeline::create([
            'user_id'   => $userID,
            'module_id' => $module->id,
            'type'      => 'question_generation',
            'status'    => 'running',
        ]);

        foreach ($selectedTypes as $type) {
            $pipelineStep = PipelineStep::create([
                'pipeline_id' => $pipeline->id,
                'name'        => "Generate {$type} Questions",
                'status'      => 'pending',
            ]);
            GenerateQuestions::dispatch($type, $module->id, $pipelineStep->id, $userID, $mode, $difficultyFocus, $modelOverride);
        }

        $typeLabels = implode(', ', $selectedTypes);
        $difficultyLabel = $difficultyFocus ? " ({$difficultyFocus} difficulty)" : '';

        return back()->with('status', "Generating {$typeLabels} questions{$difficultyLabel}. Check back in a few moments.");
    }

    public function research(Request $request, Module $module, ResearchService $researchService): \Illuminate\Http\JsonResponse
    {
        $topic = $module->name . ' — ' . $module->subject->name;
        $userPrompt = $request->input('user_prompt');
        $sourceUrl   = $request->input('source_url', '');
        $youtubeMode = $request->input('youtube_mode', 'transcript');

        $existingResearch = \App\Models\SubjectContent::where('module_id', $module->id)->first();
        $attachedResearch = $existingResearch?->content ?? '';

        $attachedPages = '';
        if ($request->boolean('attach_pages')) {
            $attachedPages = $module->modulePages()
                ->orderBy('page_number')
                ->pluck('content')
                ->implode("\n\n---\n\n");
        }

        $result = $researchService->fetchLatestMaterial(
            $topic,
            $module->subject->name,
            auth()->id(),
            $module->subject_id,
            $module->id,
            $userPrompt,
            $attachedResearch,
            $attachedPages,
            $sourceUrl,
            $youtubeMode
        );

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 500);
        }

        return response()->json($result);
    }

    public function synthesise(Request $request, Module $module): \Illuminate\Http\JsonResponse
    {
        $research = \App\Models\SubjectContent::where('module_id', $module->id)->first();

        if (! $research) {
            return response()->json(['error' => 'No research found. Run the research tool first.'], 422);
        }

        $content = $this->aiService->synthesiseContent(
            $module,
            $research->content,
            $request->input('user_prompt', ''),
            auth()->id()
        );

        ModulePage::updateOrCreate(
            ['module_id' => $module->id, 'page_number' => 1],
            [
                'title'      => $module->name,
                'content'    => $content,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]
        );

        return response()->json(['success' => true]);
    }
}
