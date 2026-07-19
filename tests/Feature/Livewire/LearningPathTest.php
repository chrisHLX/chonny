<?php

use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Http\Services\RoadmapService;
use App\Http\Services\SubjectContextService;
use App\Livewire\Collection;
use App\Models\Category;
use App\Models\Concept;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SubjectContextDimension;
use App\Models\SubjectContextOption;
use App\Models\User;
use App\Models\UserNextStep;
use Livewire\Livewire;

function makeLearningPathFixtures(): array
{
    $category = Category::create(['name' => 'Games']);
    $subject  = Subject::create(['name' => 'World of Warcraft: The War Within', 'category_id' => $category->id]);

    $diagnosticModule = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Diagnostic',
        'type'       => 'diagnostic',
    ]);

    $concept = Concept::create(['subject_id' => $subject->id, 'name' => 'Cooldown Tracking']);

    $contentModule = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Cooldown Fundamentals',
        'status'     => 'ready',
        'type'       => 'content',
        'published'  => true,
    ]);
    $question = Question::create([
        'question' => 'Sample question',
        'answer'   => ['correct' => 'A', 'options' => ['A', 'B']],
        'type'     => 'mcq',
    ]);
    $contentModule->questions()->attach($question->id);
    $question->concepts()->attach($concept->id);

    return compact('category', 'subject', 'diagnosticModule', 'concept', 'contentModule');
}

test('learning path is empty when the user has no persisted stages for the subject', function () {
    ['subject' => $subject] = makeLearningPathFixtures();
    $user = User::factory()->create();

    $learningPath = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $subject->id)
        ->instance()->learningPath;

    expect($learningPath->isEmpty())->toBeTrue();
});

test('learning path shows the diagnostic as complete and the matched module as next, before completion', function () {
    ['subject' => $subject, 'diagnosticModule' => $module] = makeLearningPathFixtures();
    $user = User::factory()->create();

    $profile = ['primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => ['Cooldown Tracking']]];
    app(RoadmapService::class)->persistStagesForUser($user->id, $module, $profile, [], insightId: null);

    $learningPath = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $subject->id)
        ->instance()->learningPath;

    expect($learningPath)->toHaveCount(6); // 'context_dimensions' omitted — no dimensions seeded
    expect($learningPath[0]['status'])->toBe('complete');
    expect($learningPath[1]['status'])->toBe('next');
    expect($learningPath[1]['title'])->toBe('Cooldown Fundamentals');
    foreach (array_slice($learningPath->all(), 2) as $future) {
        expect($future['status'])->toBe('future');
    }
});

test('the Module stage always mirrors the live UserNextStep, even when it disagrees with the frozen guess made at diagnostic completion', function () {
    ['subject' => $subject, 'diagnosticModule' => $module] = makeLearningPathFixtures();
    $user = User::factory()->create();

    // Frozen guess at persist time: "Cooldown Fundamentals" (see makeLearningPathFixtures()).
    $profile = ['primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => ['Cooldown Tracking']]];
    app(RoadmapService::class)->persistStagesForUser($user->id, $module, $profile, [], insightId: null);

    // The live recommendation engine (NextStepService/RecommendationService) independently
    // settled on something else entirely — reproducing the exact divergence seen in production
    // (roadmap said "Basic PvP Tips", dashboard said "Arena Positioning").
    UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'step_type'        => StepType::Task->value,
        'status'           => NextStepStatus::Pending->value,
        'generated_reason' => 'initial',
        'title'            => 'Arena Positioning',
        'instructions'     => 'Practice holding a defensible position for one full match.',
    ]);

    $learningPath = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $subject->id)
        ->instance()->learningPath;

    expect($learningPath[1]['status'])->toBe('next');
    expect($learningPath[1]['title'])->toBe('Arena Positioning');
    expect($learningPath[1]['title'])->not->toBe('Cooldown Fundamentals');
});

test('the Module stage keeps reflecting the live next-step after completion, and later stages never light up', function () {
    ['subject' => $subject, 'diagnosticModule' => $module, 'contentModule' => $contentModule] = makeLearningPathFixtures();
    $user = User::factory()->create();

    $profile = ['primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => ['Cooldown Tracking']]];
    app(RoadmapService::class)->persistStagesForUser($user->id, $module, $profile, [], insightId: null);

    // The user actually completed the tracked module...
    $user->modules()->syncWithoutDetaching([
        $contentModule->id => ['status' => 'completed', 'completed_at' => now()],
    ]);

    // ...and the live recommendation engine has already moved on to something new (this is what
    // NextStepService::checkAndCompleteModuleStep()/RecommendationService would produce — a fresh
    // module-type step is created directly here rather than exercising that whole chain, since
    // NextStepServiceModuleMatchingTest already covers that regeneration behavior itself).
    UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'step_type'        => StepType::Module->value,
        'status'           => NextStepStatus::Pending->value,
        'generated_reason' => 'module_completed',
        'title'            => 'Advanced Cooldown Chaining',
        'instructions'     => 'Covers the next concept in your queue.',
    ]);

    $learningPath = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $subject->id)
        ->instance()->learningPath;

    expect($learningPath[0]['status'])->toBe('complete');
    expect($learningPath[1]['status'])->toBe('next'); // still 'next', never 'complete' — it's a permanent live slot
    expect($learningPath[1]['title'])->toBe('Advanced Cooldown Chaining');
    // No named milestone (Class Breakdown, Win Conditions, ...) ever lights up — no real content
    // tracks them yet.
    foreach (array_slice($learningPath->all(), 2) as $future) {
        expect($future['status'])->toBe('future');
    }
});

test('the Module stage shows an honest exhausted state instead of the frozen guess once content runs out', function () {
    ['subject' => $subject, 'diagnosticModule' => $module, 'contentModule' => $contentModule] = makeLearningPathFixtures();
    $user = User::factory()->create();

    // Frozen guess at persist time: "Cooldown Fundamentals".
    $profile = ['primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => ['Cooldown Tracking']]];
    app(RoadmapService::class)->persistStagesForUser($user->id, $module, $profile, [], insightId: null);

    // A UserNextStep existed at some point (proving the recommendation engine actually ran) but
    // there is currently nothing pending/attempted — RecommendationService returned null on the
    // last regeneration attempt because there was no more available content, exactly like the
    // production case (SC2, small content bank, exhausted after two modules).
    $user->modules()->syncWithoutDetaching([
        $contentModule->id => ['status' => 'completed', 'completed_at' => now()],
    ]);
    UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'module_id'        => $contentModule->id,
        'step_type'        => StepType::Module->value,
        'status'           => NextStepStatus::Completed->value,
        'generated_reason' => 'module_completed',
        'title'            => 'Cooldown Fundamentals',
        'instructions'     => 'This module covers "Cooldown Tracking" — one of your current growth areas.',
        'completed_at'     => now(),
    ]);

    $learningPath = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $subject->id)
        ->instance()->learningPath;

    expect($learningPath[1]['status'])->toBe('complete');
    expect($learningPath[1]['title'])->not->toBe('Cooldown Fundamentals'); // not the stale frozen guess
    expect($learningPath[1]['title'])->toBe('All available modules completed');
});

test('the context-dimensions milestone shows complete once all required dimensions are declared', function () {
    ['subject' => $subject, 'diagnosticModule' => $module] = makeLearningPathFixtures();
    $user = User::factory()->create();

    $race = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race', 'required' => true]);
    $zerg = SubjectContextOption::create(['dimension_id' => $race->id, 'name' => 'Zerg', 'slug' => 'zerg']);

    app(RoadmapService::class)->persistStagesForUser($user->id, $module, [], [], insightId: null);

    $learningPathBefore = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $subject->id)
        ->instance()->learningPath;

    $contextStageBefore = $learningPathBefore->firstWhere('title', 'Race Breakdown');
    expect($contextStageBefore['status'])->toBe('future'); // 'first_module' still holds the 'next' slot

    app(SubjectContextService::class)->declare($user->id, $race->id, $zerg->id);

    $learningPathAfter = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $subject->id)
        ->instance()->learningPath;

    $contextStageAfter = $learningPathAfter->firstWhere('title', 'Race Breakdown');
    expect($contextStageAfter['status'])->toBe('complete');
});

test('the context-dimensions milestone is absent entirely for a subject with zero dimensions', function () {
    ['diagnosticModule' => $module] = makeLearningPathFixtures(); // no dimensions seeded
    $user = User::factory()->create();

    app(RoadmapService::class)->persistStagesForUser($user->id, $module, [], [], insightId: null);

    $learningPath = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('currentSubjectId', $module->subject_id)
        ->instance()->learningPath;

    expect($learningPath->pluck('title'))->not->toContain('Race Breakdown');
});

test('learning path is subject-scoped, not shown when browsing a different subject', function () {
    ['diagnosticModule' => $module] = makeLearningPathFixtures();
    $user = User::factory()->create();

    app(RoadmapService::class)->persistStagesForUser($user->id, $module, [], [], insightId: null);

    $otherCategory = Category::create(['name' => 'Science']);
    $otherSubject  = Subject::create(['name' => 'Medicine', 'category_id' => $otherCategory->id]);

    $learningPath = Livewire::actingAs($user)
        ->test(Collection::class)
        ->set('categoryId', $otherCategory->id)
        ->set('currentSubjectId', $otherSubject->id)
        ->instance()->learningPath;

    expect($learningPath->isEmpty())->toBeTrue();
});
