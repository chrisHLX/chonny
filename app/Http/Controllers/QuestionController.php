<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Http\Request;
use App\Models\Concept;
use App\Models\Unit;
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
        $questions = Question::all();
        return view('questions.quiz.index', compact('questions'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:255',
            'type' => 'required|in:mcq,true_false,open',
            'difficulty' => 'required|in:easy,medium,hard',
            'module_id' => 'required|exists:modules,id',
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
                'text' => $request->input('answer.text'),
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

        // --- AI TAGGING ---
        try {
            $questionText = $request->question;
            $answerText = json_encode($request->input('answer.correct'));

            // Use injected service instead of static calls
            $conceptNames = $this->aiService->tagConcepts($questionText, $answerText, $request->module_id);
            $conceptIds = collect($conceptNames)->map(fn($name) =>
                Concept::firstOrCreate(['name' => $name])->id
            );
            $question->concepts()->sync($conceptIds);

            // Step 1: Get all existing unit names (lowercase => ID map)
            $unitMap = Unit::all()->mapWithKeys(function ($unit) {
                return [strtolower($unit->name) => $unit->id];
            });

            // Step 2: Get AI response
            $unitNames = $this->aiService->tagUnits($questionText, $answerText);

            // Step 3: Normalize and filter only existing units
            $matchedUnitIds = collect($unitNames)
                ->map(fn($name) => strtolower(trim($name)))
                ->filter(fn($name) => isset($unitMap[$name]))
                ->map(fn($name) => $unitMap[$name])
                ->unique()
                ->values(); // Ensure unique and reset keys

            \Log::info('Matched unit IDs', ['matched' => $matchedUnitIds->toArray()]);

            // Step 4: Sync only valid existing units
            $question->units()->sync($matchedUnitIds);


        } catch (\Exception $e) {
            \Log::warning("AI Tagging failed: " . $e->getMessage());
            // Optional: add flash message or silently skip tagging
        }

        return redirect()
            ->route('modules.edit', $request->module_id)
            ->with('success', 'Question created and tagged.');
    }

    public function submitAll(Request $request)
    {
        $answers = $request->input('answers', []);
        $questions = Question::whereIn('id', array_keys($answers))->get();

        $results = [];

        foreach ($questions as $question) {
            $userAnswer = $answers[$question->id];
            $correct = false;

            if ($question->type === 'mcq') {
                $correct = $userAnswer === $question->answer['correct'];
            } elseif ($question->type === 'true_false') {
                $correct = filter_var($userAnswer, FILTER_VALIDATE_BOOLEAN) === $question->answer['correct'];
            } elseif ($question->type === 'open') {
                $keywords = $question->answer['correct_keywords'] ?? [];
                $matched = collect($keywords)->filter(fn($k) => str_contains(strtolower($userAnswer), strtolower($k)));
                $correct = $matched->count() >= ceil(count($keywords) / 2);
            }

            $results[$question->id] = [
                'correct' => $correct,
                'message' => $correct ? '✅ Correct!' : '❌ Try again.',
            ];
        }

        return back()->with('results', $results);
    }
}
