<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\PageViewEvent;
use App\Training\Assessment;
use App\Training\Curriculum;
use Illuminate\Http\Request;

/** The reasoning check, form A (before the lessons) and form B (new problems, later). */
class CheckController extends Controller
{
    public function show(Request $request, string $form, Curriculum $curriculum)
    {
        abort_unless(in_array($form, ['A', 'B'], true), 404);
        PageViewEvent::log('training_check');
        $assessment = new Assessment($curriculum);
        $user = $request->user();

        if ($form === 'B' && ! $assessment->formBOpen($user) && ! $assessment->result($user, 'B')['done']) {
            return view('training.check-locked', ['formA' => $assessment->result($user, 'A')]);
        }

        return view('training.check', [
            'form' => $form,
            'items' => $assessment->items($form),
            'result' => $assessment->result($user, $form),
            'other' => $form === 'B' ? $assessment->result($user, 'A') : null,
            'curriculum' => $curriculum,
        ]);
    }

    public function store(Request $request, string $form, Curriculum $curriculum)
    {
        abort_unless(in_array($form, ['A', 'B'], true), 404);
        $assessment = new Assessment($curriculum);
        $user = $request->user();
        abort_if($form === 'B' && ! $assessment->formBOpen($user), 403);
        abort_if($assessment->result($user, $form)['done'], 409, 'This check is already complete.');

        $items = $assessment->items($form);
        $rules = [];
        foreach ($items as $id => $item) {
            $rules['answers.'.$id] = ['required', 'integer', 'min:0', 'max:'.(count($item['options']) - 1)];
        }
        $answers = $request->validate($rules, ['answers.*.required' => 'Answer every question. Pick the closest option if unsure.'])['answers'];

        foreach ($items as $id => $item) {
            $choice = (int) $answers[$id];
            $user->trainingCheckAnswers()->updateOrCreate(
                ['form' => $form, 'item' => $id],
                ['choice' => $choice, 'correct' => $choice === $item['answer']],
            );
        }

        return redirect()->route('training.check', $form);
    }
}
