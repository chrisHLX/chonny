<?php

use App\Models\Training\Note;
use App\Models\User;
use App\Training\Assessment;
use App\Training\Curriculum;
use App\Training\Progress;
use Illuminate\Support\Carbon;

function trainingAnswerIndexes(string $slug, string $exercise): array
{
    $options = app(Curriculum::class)->lesson($slug)[$exercise]['options'];
    $right = array_search(true, array_column($options, 'correct'), true);

    return [$right, $right === 0 ? 1 : 0];
}

function trainingNote($test, User $user, string $kind = 'confusing', array $extra = []): array
{
    return $test->actingAs($user)->postJson(route('training.notes.store', 'base-rates'), array_merge([
        'block' => 'principle-0', 'quote' => 'out of how many', 'prefix' => 'is always: ', 'suffix' => '?',
        'offset' => 120, 'kind' => $kind, 'body' => 'Why does the denominator matter so much?',
    ], $extra))->assertCreated()->json();
}

test('answers are judged from the lesson file and attempts are counted', function () {
    $user = User::factory()->create();
    [$right, $wrong] = trainingAnswerIndexes('base-rates', 'case');
    $url = route('training.responses.store', 'base-rates');

    $this->actingAs($user)->postJson($url, ['exercise' => 'case', 'choice' => $wrong])->assertOk()->assertJson(['correct' => false, 'attempt' => 1]);
    $this->actingAs($user)->postJson($url, ['exercise' => 'case', 'choice' => $right])->assertOk()->assertJson(['correct' => true, 'attempt' => 2]);
    // After solving, other answers show their feedback but are not recorded.
    $this->actingAs($user)->postJson($url, ['exercise' => 'case', 'choice' => $wrong])->assertOk()->assertJson(['alreadySolved' => true]);
    expect($user->trainingResponses()->count())->toBe(2);

    $this->actingAs($user)->postJson($url, ['exercise' => 'case', 'choice' => 9])->assertUnprocessable();
    $this->actingAs($user)->postJson($url, ['exercise' => 'nonsense'])->assertUnprocessable();
});

test('mastery counts only the first transfer attempt, and a lesson completes with the evidence step', function () {
    $user = User::factory()->create();
    [$right, $wrong] = trainingAnswerIndexes('base-rates', 'transfer');
    $url = route('training.responses.store', 'base-rates');

    $this->actingAs($user)->postJson($url, ['exercise' => 'case', 'choice' => 0]);
    $this->actingAs($user)->postJson($url, ['exercise' => 'transfer', 'choice' => $wrong]);
    $this->actingAs($user)->postJson($url, ['exercise' => 'transfer', 'choice' => $right]);
    $progress = fn () => new Progress(app(Curriculum::class), $user->fresh());

    expect($progress()->mastery()['base-rates'])->toMatchArray(['attempted' => 1, 'firstTry' => 0]);
    expect($progress()->isComplete('base-rates'))->toBeFalse();

    $this->actingAs($user)->postJson($url, ['exercise' => 'evidence', 'body' => 'Thefts per registered car.'])->assertOk();
    expect($progress()->isComplete('base-rates'))->toBeTrue();
});

test('notes come back on a schedule set by their kind', function () {
    Carbon::setTestNow('2026-10-23 10:00');
    $user = User::factory()->create();

    expect(trainingNote($this, $user, 'confusing')['revisitOn'])->toBe('2026-10-24');
    expect(trainingNote($this, $user, 'disagree')['revisitOn'])->toBe('2026-10-26');
    expect(trainingNote($this, $user, 'apply')['revisitOn'])->toBe('2026-10-30');
    expect(trainingNote($this, $user, 'highlight', ['body' => null])['revisitOn'])->toBeNull();
});

test('only the owner can change, delete or revisit a note', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $id = trainingNote($this, $owner)['id'];

    $this->actingAs($other)->patchJson("/training/notes/{$id}", ['body' => 'mine now'])->assertForbidden();
    $this->actingAs($other)->deleteJson("/training/notes/{$id}")->assertForbidden();
    $this->actingAs($other)->post("/training/notes/{$id}/revisits", ['outcome' => 'changed_mind'])->assertForbidden();

    $this->actingAs($owner)->patchJson("/training/notes/{$id}", ['body' => 'edited'])->assertOk()->assertJson(['body' => 'edited']);
    $this->actingAs($owner)->deleteJson("/training/notes/{$id}")->assertNoContent();
});

test('revisit outcomes drive the loop', function () {
    Carbon::setTestNow('2026-10-23 10:00');
    $user = User::factory()->create();
    $note = Note::find(trainingNote($this, $user, 'disagree')['id']);

    $this->actingAs($user)->post("/training/notes/{$note->id}/revisits", ['outcome' => 'still_confused', 'reflection' => 'Still lost']);
    expect($note->fresh()->status)->toBe('open')->and($note->fresh()->revisit_on->toDateString())->toBe('2026-10-25');

    // A view that keeps holding comes back at widening intervals (7, 14, 30 days by revisit count;
    // the "still unclear" above was the first), then retires.
    foreach (['2026-11-06', '2026-11-22', null] as $date) {
        $this->actingAs($user)->post("/training/notes/{$note->id}/revisits", ['outcome' => 'still_holds']);
        expect($note->fresh()->revisit_on?->toDateString())->toBe($date);
    }
    expect($note->fresh()->status)->toBe('resolved');
});

test('a due note shows in the notebook and in the sidebar count', function () {
    Carbon::setTestNow('2026-10-23 10:00');
    $user = User::factory()->create();
    trainingNote($this, $user, 'confusing');

    Carbon::setTestNow('2026-10-24 09:00');
    expect($user->trainingDueCount())->toBe(1);
    $this->actingAs($user)->get('/training/notebook')->assertOk()
        ->assertSee('Why does the denominator matter so much?')->assertSee('Record revisit');
});

test('the reasoning check is scored against the shuffled key, and form B opens later', function () {
    Carbon::setTestNow('2026-10-23 10:00');
    $user = User::factory()->create();
    $answers = fn (string $form, bool $right) => collect(app(Assessment::class)->items($form))
        ->map(fn ($i) => $right ? $i['answer'] : ($i['answer'] + 1) % count($i['options']))->all();

    $this->actingAs($user)->get('/training/check/B')->assertSee('Not open yet');
    $this->actingAs($user)->post('/training/check/B', ['answers' => $answers('B', true)])->assertForbidden();

    $this->actingAs($user)->post('/training/check/A', ['answers' => $answers('A', false)])->assertRedirect('/training/check/A');
    $this->actingAs($user)->get('/training/check/A')->assertSee('Score: 0 / 6');
    $this->actingAs($user)->post('/training/check/A', ['answers' => $answers('A', true)])->assertStatus(409);

    Carbon::setTestNow('2026-11-07 10:00');
    $this->actingAs($user)->post('/training/check/B', ['answers' => $answers('B', true)])->assertRedirect('/training/check/B');
    $this->actingAs($user)->get('/training/progress')->assertSee('0 / 6')->assertSee('6 / 6');
});

test('a claim is examined, comes back, is resolved, and stays private', function () {
    Carbon::setTestNow('2026-10-23 10:00');
    $user = User::factory()->create();

    $this->actingAs($user)->post('/training/claims', [
        'claim' => 'Full openers win more rounds.', 'source' => 'My logs', 'confidence_before' => 80,
        'known' => 'Win rates by opener type.', 'alternatives' => 'We commit fully when they are already low.',
        'would_change' => 'The same comparison at equal enemy cooldowns.', 'confidence_after' => 55, 'revisit_days' => 14,
    ])->assertRedirect();
    $claim = $user->trainingClaims()->first();
    expect($claim->revisit_on->toDateString())->toBe('2026-11-06');

    Carbon::setTestNow('2026-11-06 09:00');
    $this->actingAs($user)->get('/training/claims')->assertSee('Due: what happened?');
    $this->actingAs(User::factory()->create())->get("/training/claims/{$claim->id}")->assertForbidden();

    $this->actingAs($user)->patch("/training/claims/{$claim->id}", ['outcome' => 'failed', 'outcome_note' => 'Confounded.'])->assertRedirect();
    $this->actingAs($user)->get('/training/progress')->assertSee('0 / 1');
});
