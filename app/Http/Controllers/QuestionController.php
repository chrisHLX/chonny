<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Http\Request;
use App\Models\Concept;
use App\Http\Services\AiService;

class QuestionController extends Controller
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function index()
    {
        $questions = Question::with(['units', 'concepts'])->get();
        return view('questions.index', compact('questions'));
    }

    public function quiz()
    {
        return view('questions.quiz.index');
    }

    public function store(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:255',
            'type' => 'required|in:mcq,true_false,open,matching_pairs,ordering',
            'difficulty' => 'required|in:easy,medium,hard',
            'module_id' => 'required|exists:modules,id',
            'concepts' => 'required|array',
            'concepts.*' => 'exists:concepts,id',
            'answer' => 'required|array',
        ]);

        // Format the answer according to type
        $answer = match ($request->type) {
            'mcq' => (function () use ($request) {
                $correct = $request->input('answer.correct');
                $incorrect = array_filter($request->input('answer.incorrect', []));
                $options = array_merge($incorrect, [$correct]);
                shuffle($options); // optional
                return [
                    'correct' => $correct,
                    'options' => $options,
                ];
            })(),

            'true_false' => [
                'correct' => filter_var($request->input('answer.correct'), FILTER_VALIDATE_BOOLEAN),
            ],

            'open' => [
                'ideal_answer' => $request->input('answer.text'),
                'correct_keywords' => $this->aiService->getKeywords($request->input('answer.text')),
            ],

            'matching_pairs' => (function () use ($request) {
                $correct = $request->input('answer.correct', []);

                // Extract keys and values from the "key/value" objects
                $keys = array_column($correct, 'key');     // ["Zergling", "Overlord", ...]
                $values = array_column($correct, 'value'); // ["Worker", "Anti-air", ...]

                // Build the "correct" map where each key maps to its value
                $correctMap = array_combine($keys, $values);

                return [
                    'pairs' => [
                        'keys' => $keys,
                        'values' => $values,
                    ],
                    'correct' => $correctMap,
                ];
            })(),


            'ordering' => [
                'steps' => $request->input('answer.steps', []),
            ],
        };

        // Create question
        $question = Question::create([
            'question' => $request->question,
            'type' => $request->type,
            'difficulty' => $request->difficulty,
            'answer' => $answer,
            'created_by' => auth()->id() ?? 'system',
        ]);

        // Attach question to module
        $question->modules()->attach($request->module_id);

        $question->concepts()->sync($request->concepts ?? []);

        // Only run AI tagging if no concepts were selected
        if (empty($request->concepts)) {
            try {
                $questionText = $request->question;
                $answerText = json_encode($request->input('answer.correct'));

                // Build a concept map to avoid duplicates and feed to AI
                $conceptMap = Concept::all()->pluck('id', 'name');

                // Ask AI to suggest concept names
                $conceptNames = $this->aiService->tagConcepts(
                    $questionText,
                    $answerText,
                    $request->module_id,
                    $conceptMap->keys()->toArray()
                );

                // Convert AI-named concepts into IDs, fallback to “Other”
                $conceptIds = collect($conceptNames)
                    ->map(fn($name) => $conceptMap[$name] ?? $conceptMap['Other'])
                    ->unique()
                    ->values();

                // Sync AI-tagged concepts
                $question->concepts()->sync($conceptIds);

            } catch (\Exception $e) {
                \Log::warning("AI Tagging failed: " . $e->getMessage());
                // Optional: silently skip or flash a warning
            }
        }

        

        return redirect()
            ->route('modules.edit', $request->module_id)
            ->with('success', 'Question created and tagged.');
    }

    public function destroy(Question $question)
    {
        // Authorization check (optional need to add the functionality )
        

        // Detach relationships
        $question->modules()->detach();
        $question->concepts()->detach();
        $question->users()->detach();

        // Delete the question
        $question->delete();

        return redirect()->route('questions.index')->with('success', 'Question deleted successfully.');
    }

    
}
