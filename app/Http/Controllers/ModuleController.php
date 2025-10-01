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
        return view('modules.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string'
        ]);
        
        $module = Module::create([
            'name' => $request->name,
            'description' => $request->description,
            'created_by' => auth()->id(),
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

    public function generateQuestions()
    {
        // Call the AI service to generate questions
        $content = '
        Guide to JIC Hydraulic Fittings
1. What are JIC Fittings?

JIC (Joint Industry Council) fittings are a type of hydraulic fitting that uses a 37° flare seating surface to create a metal-to-metal seal. They are widely used in hydraulic systems for fluid transfer because they are:

Reliable under high pressure.

Reusable.

Easy to assemble without special tools.

JIC fittings conform to the SAE J514 standard.

2. Key Features

37° Flare Angle: The male fitting has a 37° cone, and the female fitting has a matching flare seat. When tightened, they form a strong seal.

Thread Type: Straight threads (UNF/UN) are used, not tapered. The seal is created at the flare, not in the threads.

Materials: Commonly made from carbon steel, stainless steel, or brass.

3. How JIC Fittings Work

The male fitting has a 37° seat cone.

The female fitting has a matching flare.

When tightened, the two metal surfaces mate and form a seal.

The threads only provide mechanical strength, not sealing.

This design makes them leak-proof, vibration resistant, and reusable.

4. Applications

Hydraulic systems (construction, agriculture, aerospace).

Fuel delivery and gas lines.

Industrial machinery.

Test-point and instrumentation connections.

5. Advantages

High pressure capability (up to ~10,000 psi depending on size/material).

Reusable without damaging sealing surfaces (if not overtightened).

Simple to assemble with standard wrenches.

Wide availability and standardization.

6. Common Sizes

JIC fittings are sized by dash numbers, which correspond to the tubing outside diameter (OD).

Example: -04 = 1/4" OD tube, -08 = 1/2" OD tube.

Dash Size	Tube OD (inches)
-02	1/8"
-04	1/4"
-06	3/8"
-08	1/2"
-10	5/8"
-12	3/4"
-16	1"
7. Identification

To identify a JIC fitting:

Check thread type: UNF straight threads.

Measure flare angle: should be exactly 37°.

Measure tube OD or fitting size.

Compare to SAE J514 size charts.

⚠️ Dont confuse with SAE 45° flare fittings (common in refrigeration). The angle difference makes them incompatible.

8. Installation Tips

Use two wrenches (one on each side) to prevent twisting tubing.

Avoid overtightening — it can damage the sealing surface.

Clean threads and flare surfaces before assembly.

If leaks occur, check for scratches or distortion on the flare seat.

9. JIC vs. Other Fittings

JIC vs. SAE 45° → Different flare angle, not interchangeable.

JIC vs. NPT → NPT seals with thread deformation (tapered + sealant); JIC seals at the flare.

JIC vs. ORFS (O-Ring Face Seal) → ORFS uses an elastomeric O-ring, while JIC is all metal.

10. Safety Notes

Always depressurize hydraulic systems before disconnecting.

Replace damaged fittings immediately.

Never mix JIC with non-compatible flare types.

✅ Summary: JIC hydraulic fittings are robust, versatile, and widely used. Their 37° flare design makes them reliable under high pressure, easy to service, and reusable — which is why they’re a standard choice in hydraulic systems worldwide.
        ';

        $questions = $this->aiService->generateQuestions($content);

        foreach ($questions as $question) {
            \Log::info('Generated question', [
                'question' => $question
            ]);
        }


        // Return the generated questions or redirect to a view
        return view('modules.generated_questions', [            
            'questions' => $questions,
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
