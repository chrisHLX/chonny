<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
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
