<?php

use App\Enums\GeneratedReason;
use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Http\Services\NextStepService;
use App\Models\Category;
use App\Models\Concept;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserNextStep;
use App\Models\UserNextStepReflection;
use App\Models\UserProfileInsight;
use Illuminate\Support\Facades\Http;

function makeSubject(): Subject
{
    static $n = 0;
    $n++;

    $category = Category::create(['name' => "Games {$n}"]);

    return Subject::create(['name' => "World of Warcraft {$n}", 'category_id' => $category->id]);
}

function makeConcept(Subject $subject, string $name): Concept
{
    return Concept::create(['subject_id' => $subject->id, 'name' => $name, 'description' => $name]);
}

/**
 * A real, published, ready, non-diagnostic content module whose single question is tagged
 * with every given concept — the matchable shape `findBestModuleForConcepts` looks for.
 */
function makeContentModule(Subject $subject, array $concepts, array $overrides = []): Module
{
    $module = Module::create(array_merge([
        'subject_id' => $subject->id,
        'name'       => 'Module covering '.$concepts[0]->name,
        'status'     => 'ready',
        'type'       => 'content',
        'published'  => true,
    ], $overrides));

    $question = Question::create([
        'question' => 'Sample question',
        'answer'   => ['correct' => 'A', 'options' => ['A', 'B']],
        'type'     => 'mcq',
    ]);

    $module->questions()->attach($question->id);
    $question->concepts()->attach(collect($concepts)->pluck('id'));

    return $module;
}

function makeInsightWithGrowthConcepts(User $user, Subject $subject, array $growthConcepts): UserProfileInsight
{
    $diagnosticModule = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Diagnostic',
        'type'       => 'diagnostic',
        'published'  => true,
    ]);

    $insight = UserProfileInsight::create([
        'user_id'          => $user->id,
        'module_id'        => $diagnosticModule->id,
        'subject_id'       => $subject->id,
        'profile_title'    => 'Test Profile',
        'confidence_level' => 'medium',
        'summary'          => 'Test summary',
        'generated_at'     => now(),
    ]);

    foreach ($growthConcepts as $concept) {
        $insight->concepts()->attach($concept->id, ['role' => 'growth_area']);
    }

    return $insight;
}

function fakeOpenAiTask(string $title = 'Keep practicing', string $instructions = 'Review recent activity.'): void
{
    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'title'        => $title,
                    'instructions' => $instructions,
                    'concept'      => null,
                ])]],
            ],
        ]),
    ]);
}

// --- findBestModuleForConcepts -------------------------------------------------------------

test('findBestModuleForConcepts returns null when concept list is empty', function () {
    $subject = makeSubject();

    $result = app(NextStepService::class)->findBestModuleForConcepts(1, $subject->id, []);

    expect($result)->toBeNull();
});

test('findBestModuleForConcepts excludes a module the user already completed', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);

    $user->modules()->attach($module->id, ['status' => 'completed']);

    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    expect($result)->toBeNull();
});

test('findBestModuleForConcepts excludes wrong-subject, unpublished, non-ready, diagnostic, and child modules', function () {
    $subject = makeSubject();
    $otherSubject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');

    // Tagged with subject A's concept but belongs to a different subject.
    makeContentModule($otherSubject, [$concept]);
    makeContentModule($subject, [$concept], ['published' => false]);
    makeContentModule($subject, [$concept], ['status' => 'preparing']);
    makeContentModule($subject, [$concept], ['type' => 'diagnostic']);
    $someParent = makeContentModule($subject, [$concept]);
    makeContentModule($subject, [$concept], ['parent_id' => $someParent->id]);

    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    // Only $someParent itself is a valid candidate (parent_id null); everything else is excluded.
    expect($result->id)->toBe($someParent->id);
});

test('findBestModuleForConcepts ranks by number of distinct covered growth concepts', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $conceptA = makeConcept($subject, 'Concept A');
    $conceptB = makeConcept($subject, 'Concept B');
    $conceptC = makeConcept($subject, 'Concept C');

    makeContentModule($subject, [$conceptA]);
    $strongModule = makeContentModule($subject, [$conceptA, $conceptB, $conceptC]);

    $result = app(NextStepService::class)->findBestModuleForConcepts(
        $user->id,
        $subject->id,
        [$conceptA->id, $conceptB->id, $conceptC->id]
    );

    expect($result->id)->toBe($strongModule->id);
});

test('findBestModuleForConcepts breaks ties deterministically by lowest id', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Concept A');

    $first = makeContentModule($subject, [$concept]);
    makeContentModule($subject, [$concept]);

    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    expect($result->id)->toBe($first->id);
});

// --- generateInitial --------------------------------------------------------------------

test('generateInitial creates a Module step and makes no AI call when a matching module exists', function () {
    Http::fake();

    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $step = app(NextStepService::class)->generateInitial($insight);

    expect($step->step_type)->toBe(StepType::Module);
    expect($step->module_id)->toBe($module->id);
    expect($step->status)->toBe(NextStepStatus::Pending);
    expect($step->insight_id)->toBe($insight->id);
    Http::assertNothingSent();
});

test('generateInitial falls back to a free-text AI task when no module matches', function () {
    fakeOpenAiTask('Practice cooldown tracking', 'Track enemy cooldowns next session.');

    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    // No content module created — nothing to match.
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $step = app(NextStepService::class)->generateInitial($insight);

    expect($step->step_type)->toBe(StepType::Task);
    expect($step->title)->toBe('Practice cooldown tracking');
});

// --- checkAndCompleteModuleStep ----------------------------------------------------------

test('checkAndCompleteModuleStep no-ops for Task-type steps', function () {
    $subject = makeSubject();
    $user = User::factory()->create();

    $step = UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'step_type'        => StepType::Task,
        'status'           => NextStepStatus::Pending,
        'generated_reason' => GeneratedReason::Initial,
        'title'            => 'Some task',
        'instructions'     => 'Do something',
    ]);

    $result = app(NextStepService::class)->checkAndCompleteModuleStep($step);

    expect($result->id)->toBe($step->id);
    expect($result->status)->toBe(NextStepStatus::Pending);
});

test('checkAndCompleteModuleStep no-ops when the module has not been completed yet', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);

    $step = UserNextStep::create([
        'user_id'          => $user->id,
        'module_id'        => $module->id,
        'subject_id'       => $subject->id,
        'step_type'        => StepType::Module,
        'status'           => NextStepStatus::Pending,
        'generated_reason' => GeneratedReason::Initial,
        'title'            => $module->name,
        'instructions'     => 'Complete this module.',
    ]);

    $result = app(NextStepService::class)->checkAndCompleteModuleStep($step);

    expect($result->id)->toBe($step->id);
    expect($result->status)->toBe(NextStepStatus::Pending);
});

test('checkAndCompleteModuleStep completes the step and generates a replacement when the module is completed', function () {
    fakeOpenAiTask();

    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $step = UserNextStep::create([
        'user_id'          => $user->id,
        'module_id'        => $module->id,
        'subject_id'       => $subject->id,
        'insight_id'       => $insight->id,
        'step_type'        => StepType::Module,
        'status'           => NextStepStatus::Pending,
        'generated_reason' => GeneratedReason::Initial,
        'title'            => $module->name,
        'instructions'     => 'Complete this module.',
    ]);

    $user->modules()->attach($module->id, ['status' => 'completed']);

    $newStep = app(NextStepService::class)->checkAndCompleteModuleStep($step);

    $original = $step->fresh();
    expect($original->status)->toBe(NextStepStatus::Completed);
    expect($original->completed_at)->not->toBeNull();

    expect($newStep->id)->not->toBe($step->id);
    expect($newStep->previous_step_id)->toBe($step->id);
    expect($newStep->generated_reason)->toBe(GeneratedReason::ModuleCompleted);
    // The just-completed module is now excluded from matching, and no other module covers
    // this concept, so it must fall back to the free-text task path.
    expect($newStep->step_type)->toBe(StepType::Task);
});

test('checkAndCompleteModuleStep guards against a duplicate transition from a stale concurrent read', function () {
    fakeOpenAiTask();

    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $step = UserNextStep::create([
        'user_id'          => $user->id,
        'module_id'        => $module->id,
        'subject_id'       => $subject->id,
        'insight_id'       => $insight->id,
        'step_type'        => StepType::Module,
        'status'           => NextStepStatus::Pending,
        'generated_reason' => GeneratedReason::Initial,
        'title'            => $module->name,
        'instructions'     => 'Complete this module.',
    ]);

    $user->modules()->attach($module->id, ['status' => 'completed']);

    $service = app(NextStepService::class);

    // Both calls reuse the same in-memory $step (still "Pending" in this variable), simulating
    // two concurrent requests that both read the row before either one writes to it.
    $service->checkAndCompleteModuleStep($step);
    $service->checkAndCompleteModuleStep($step);

    expect(UserNextStep::where('previous_step_id', $step->id)->count())->toBe(1);
});

// --- DashboardController integration ------------------------------------------------------

test('dashboard load completes an active module step and surfaces the freshly generated next step', function () {
    fakeOpenAiTask();

    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $step = UserNextStep::create([
        'user_id'          => $user->id,
        'module_id'        => $module->id,
        'subject_id'       => $subject->id,
        'insight_id'       => $insight->id,
        'step_type'        => StepType::Module,
        'status'           => NextStepStatus::Pending,
        'generated_reason' => GeneratedReason::Initial,
        'title'            => $module->name,
        'instructions'     => 'Complete this module.',
    ]);

    $user->modules()->attach($module->id, ['status' => 'completed']);

    $response = $this->actingAs($user)->get(route('dashboard', [
        'category_id' => $subject->category_id,
        'subject_id'  => $subject->id,
    ]));

    $response->assertOk();
    expect($step->fresh()->status)->toBe(NextStepStatus::Completed);
    expect(UserNextStep::where('previous_step_id', $step->id)->exists())->toBeTrue();
});

// --- Reflection history context ------------------------------------------------------------

test('regenerateAfterReflection feeds prior-step history into the prompt, not just the immediately previous reflection', function () {
    fakeOpenAiTask('Different approach', 'Try something different this time.');

    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Awareness');
    // No content module created — nothing to match, forces the free-text AI path.
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $step1 = UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'insight_id'       => $insight->id,
        'step_type'        => StepType::Task,
        'status'           => NextStepStatus::Completed,
        'generated_reason' => GeneratedReason::Initial,
        'title'            => 'Track everyone',
        'instructions'     => 'Track teammates and opponents for 5 minutes.',
    ]);

    UserNextStepReflection::create([
        'next_step_id'  => $step1->id,
        'did_try'       => 'yes',
        'how_it_went'   => 'I lost track of my own rotation.',
        'why_reasoning' => 'Too much to focus on at once.',
        'submitted_at'  => now(),
    ]);

    $step2 = UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'insight_id'       => $insight->id,
        'previous_step_id' => $step1->id,
        'step_type'        => StepType::Task,
        'status'           => NextStepStatus::Attempted,
        'generated_reason' => GeneratedReason::Reflection,
        'title'            => 'Track one teammate',
        'instructions'     => 'Track a single teammate for 5 minutes.',
    ]);

    $reflection2 = UserNextStepReflection::create([
        'next_step_id'  => $step2->id,
        'did_try'       => 'partially',
        'how_it_went'   => 'Made me miss other things.',
        'why_reasoning' => 'Same issue as before.',
        'submitted_at'  => now(),
    ]);

    app(NextStepService::class)->regenerateAfterReflection($reflection2);

    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, 'RECENT HISTORY')
            && str_contains($body, 'Track everyone')
            && str_contains($body, 'I lost track of my own rotation');
    });
});
