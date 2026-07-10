<?php

use App\Livewire\Collection;
use App\Livewire\QuizRunner;
use App\Models\Category;
use App\Models\Module;
use App\Models\Pipeline;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Livewire\Livewire;

function makeSubjectForCollectionTest(): Subject
{
    static $n = 0;
    $n++;

    $category = Category::create(['name' => "Games {$n}"]);

    return Subject::create(['name' => "World of Warcraft {$n}", 'category_id' => $category->id]);
}

// --- Part A: card system removal --------------------------------------------------------

test('completing a module no longer creates a quiz_completion pipeline', function () {
    $subject = makeSubjectForCollectionTest();
    $user = User::factory()->create();

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Solo Question Module',
        'status'     => 'ready',
        'type'       => 'content',
        'published'  => true,
    ]);

    $question = Question::create([
        'question'   => 'What is 2 + 2?',
        'answer'     => ['correct' => '4', 'options' => ['3', '4', '5']],
        'type'       => 'mcq',
        'difficulty' => 'easy',
    ]);
    $module->questions()->attach($question->id);

    $user->modules()->attach($module->id, ['status' => 'in_progress', 'current_difficulty' => 'easy']);

    // QuizRunner::startQuizInternal() gates the whole quiz behind ai_credits > 5 for
    // authenticated users — a bare User::factory()->create() has no UserCredit row at all
    // (welcome credits are granted via a listener on the real registration flow, which a
    // factory bypasses), so without this the quiz would silently never load any questions
    // and this test would false-pass.
    $user->credits()->create(['ai_credits' => 100]);

    Livewire::actingAs($user)
        ->test(QuizRunner::class, ['moduleId' => $module->id])
        ->set('answer', '4')
        ->call('submit', ['elapsed' => 5]);

    expect(Pipeline::where('type', 'quiz_completion')->count())->toBe(0);
    expect($user->modules()->find($module->id)->pivot->status)->toBe('completed');
});

test('GET /ai_requests does not fatal now that CardGenerationService is no longer injected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/ai_requests')->assertOk();
});

// --- Part B: question flagging -----------------------------------------------------------

test('toggleFlag is a no-op for guests and attaches/detaches for authenticated users', function () {
    $subject = makeSubjectForCollectionTest();
    $user = User::factory()->create();

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Flag Test Module',
        'status'     => 'ready',
        'type'       => 'content',
        'published'  => true,
    ]);

    $question = Question::create([
        'question' => 'Sample question',
        'answer'   => ['correct' => 'A', 'options' => ['A', 'B']],
        'type'     => 'mcq',
        'difficulty' => 'easy',
    ]);
    $module->questions()->attach($question->id);

    // Guest: no-op, pivot table stays empty.
    Livewire::test(QuizRunner::class, ['moduleId' => $module->id])
        ->call('toggleFlag', $question->id);

    expect($user->flaggedQuestions()->count())->toBe(0);

    // Authenticated: toggle attaches, then detaches on a second call.
    Livewire::actingAs($user)
        ->test(QuizRunner::class, ['moduleId' => $module->id])
        ->call('toggleFlag', $question->id);

    expect($user->flaggedQuestions()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(QuizRunner::class, ['moduleId' => $module->id])
        ->call('toggleFlag', $question->id);

    expect($user->fresh()->flaggedQuestions()->count())->toBe(0);
});

test('flagged questions are subject-scoped, not global', function () {
    $subjectA = makeSubjectForCollectionTest();
    $subjectB = makeSubjectForCollectionTest();
    $user = User::factory()->create();

    $moduleA = Module::create([
        'subject_id' => $subjectA->id,
        'name'       => 'Module A',
        'status'     => 'ready',
        'type'       => 'content',
        'published'  => true,
    ]);
    $questionA = Question::create([
        'question' => 'Question in subject A',
        'answer'   => ['correct' => 'A', 'options' => ['A', 'B']],
        'type'     => 'mcq',
    ]);
    $moduleA->questions()->attach($questionA->id);

    $moduleB = Module::create([
        'subject_id' => $subjectB->id,
        'name'       => 'Module B',
        'status'     => 'ready',
        'type'       => 'content',
        'published'  => true,
    ]);
    $questionB = Question::create([
        'question' => 'Question in subject B',
        'answer'   => ['correct' => 'A', 'options' => ['A', 'B']],
        'type'     => 'mcq',
    ]);
    $moduleB->questions()->attach($questionB->id);

    $user->flaggedQuestions()->attach([$questionA->id, $questionB->id]);
    $user->modules()->attach([$moduleA->id, $moduleB->id]);

    // Collection::mount() takes no parameters — let it resolve its own defaults, then
    // explicitly set currentSubjectId to drive the assertion rather than relying on
    // Livewire::test()'s second argument matching a mount() signature that doesn't accept it.
    $component = Livewire::actingAs($user)->test(Collection::class);

    $component->set('currentSubjectId', $subjectA->id);
    $flagged = $component->instance()->flaggedQuestions;
    expect($flagged->pluck('id')->all())->toBe([$questionA->id]);

    $component->set('currentSubjectId', $subjectB->id);
    $flagged = $component->instance()->flaggedQuestions;
    expect($flagged->pluck('id')->all())->toBe([$questionB->id]);
});
