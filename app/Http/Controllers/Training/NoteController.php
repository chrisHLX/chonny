<?php

namespace App\Http\Controllers\Training;

use App\Enums\Training\NoteKind;
use App\Enums\Training\RevisitOutcome;
use App\Http\Controllers\Controller;
use App\Models\Training\Note;
use App\Training\Curriculum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Notes on lesson passages, and the revisits that close the learning loop. Owner-only throughout. */
class NoteController extends Controller
{
    public function store(Request $request, string $lesson, Curriculum $curriculum)
    {
        $curriculum->page($lesson) ?? abort(404);
        $data = $request->validate([
            'block' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]+-\d+$/'],
            'quote' => ['required', 'string', 'max:2000'],
            'prefix' => ['nullable', 'string', 'max:64'],
            'suffix' => ['nullable', 'string', 'max:64'],
            'offset' => ['required', 'integer', 'min:0'],
            'kind' => ['required', Rule::enum(NoteKind::class)],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);
        $kind = NoteKind::from($data['kind']);

        $note = $request->user()->trainingNotes()->create([
            'lesson_slug' => $lesson,
            'block_id' => $data['block'],
            'quote' => $data['quote'],
            'prefix' => $data['prefix'] ?? '',
            'suffix' => $data['suffix'] ?? '',
            'start_offset' => $data['offset'],
            'kind' => $kind,
            'body' => $data['body'] ?? null,
            'status' => 'open',
            'revisit_on' => ($d = $kind->revisitAfterDays()) === null ? null : today()->addDays($d),
        ]);

        return response()->json($note->loadCount('revisits')->toClient(), 201);
    }

    public function update(Request $request, Note $note)
    {
        abort_unless($note->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'kind' => ['sometimes', Rule::enum(NoteKind::class)],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        if (isset($data['kind']) && $data['kind'] !== $note->kind->value) {
            // Changing what a note is changes when it should come back.
            $kind = NoteKind::from($data['kind']);
            $note->kind = $kind;
            $note->revisit_on = ($d = $kind->revisitAfterDays()) === null ? null : today()->addDays($d);
            $note->status = 'open';
        }
        if (array_key_exists('body', $data)) {
            $note->body = $data['body'];
        }
        $note->save();

        return response()->json($note->loadCount('revisits')->toClient());
    }

    public function destroy(Request $request, Note $note)
    {
        abort_unless($note->user_id === $request->user()->id, 403);
        $note->delete();

        return response()->noContent();
    }

    public function revisit(Request $request, Note $note)
    {
        abort_unless($note->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'outcome' => ['required', Rule::enum(RevisitOutcome::class)],
            'reflection' => ['nullable', 'string', 'max:5000'],
        ]);
        $outcome = RevisitOutcome::from($data['outcome']);

        $previous = $note->revisits()->count();
        $note->revisits()->create(['outcome' => $outcome, 'reflection' => $data['reflection'] ?? null]);
        [$status, $days] = $outcome->next($previous);
        $note->update(['status' => $status, 'revisit_on' => $days === null ? null : today()->addDays($days)]);

        return back()->with('training_status', $status === 'resolved'
            ? 'Recorded. This note is resolved.'
            : 'Recorded. It comes back on '.$note->revisit_on->format('j M').'.');
    }
}
