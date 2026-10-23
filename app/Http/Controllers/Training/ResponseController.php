<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\Response;
use App\Training\Curriculum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Answers to a lesson's exercises. Correctness and feedback come from the lesson file, never the client. */
class ResponseController extends Controller
{
    public function store(Request $request, string $lesson, Curriculum $curriculum)
    {
        $page = $curriculum->page($lesson) ?? abort(404);
        $exercise = $request->validate([
            'exercise' => ['required', Rule::in([...Response::CHOICE, ...Response::WRITTEN, ...Response::SAVED])],
        ])['exercise'];
        $user = $request->user();

        // A reading page saves its worksheet or subject marks as one JSON document.
        if (in_array($exercise, Response::SAVED, true)) {
            abort_unless($page['kind'] === 'reading', 404);
            $data = $request->validate(['data' => ['required', 'array', 'max:40'], 'data.*' => ['nullable', 'string', 'max:2000']])['data'];
            $user->trainingResponses()->updateOrCreate(['lesson_slug' => $lesson, 'exercise' => $exercise], ['body' => json_encode($data)]);

            return response()->json(['saved' => true]);
        }
        abort_unless($page['kind'] === 'lesson', 404);
        $l = $curriculum->lesson($lesson);

        if (in_array($exercise, Response::WRITTEN, true)) {
            $body = $request->validate(['body' => ['required', 'string', 'max:5000']])['body'];
            $user->trainingResponses()->updateOrCreate(['lesson_slug' => $lesson, 'exercise' => $exercise], ['body' => $body]);

            return response()->json(['saved' => true]);
        }

        $question = $curriculum->question($l, $exercise) ?? abort(404);
        $choice = (int) $request->validate([
            'choice' => ['required', 'integer', 'min:0', 'max:'.(count($question['options']) - 1)],
        ])['choice'];

        $previous = $user->trainingResponses()->where(['lesson_slug' => $lesson, 'exercise' => $exercise]);
        if ((clone $previous)->where('correct', true)->exists()) {
            // Already solved: explore the other answers' feedback without adding attempts.
            return response()->json($this->result($question, $choice, (clone $previous)->count(), true));
        }

        $attempt = (clone $previous)->count() + 1;
        $user->trainingResponses()->create([
            'lesson_slug' => $lesson,
            'exercise' => $exercise,
            'choice' => $choice,
            'correct' => (bool) $question['options'][$choice]['correct'],
            'attempt' => $attempt,
        ]);

        return response()->json($this->result($question, $choice, $attempt, false));
    }

    private function result(array $question, int $choice, int $attempt, bool $alreadySolved): array
    {
        return [
            'choice' => $choice,
            'correct' => (bool) $question['options'][$choice]['correct'],
            'feedback' => $question['options'][$choice]['feedback'],
            'attempt' => $attempt,
            'alreadySolved' => $alreadySolved,
        ];
    }
}
