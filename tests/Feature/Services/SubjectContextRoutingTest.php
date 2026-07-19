<?php

use App\Http\Services\NextStepService;
use App\Http\Services\RecommendationService;
use App\Http\Services\SubjectContextService;
use App\Models\SubjectContextDimension;
use App\Models\SubjectContextOption;
use App\Models\User;
use Illuminate\Support\Str;

// Reuses makeSubject(), makeConcept(), makeContentModule(), makeInsightWithGrowthConcepts()
// (NextStepServiceModuleMatchingTest.php) and fakeOpenAiRecommendation()
// (RecommendationServiceTest.php) — Pest loads every Feature test file, so these globally
// declared helpers are already available here without redeclaring.

function makeContextDimension($subject, string $name): SubjectContextDimension
{
    return SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => $name, 'slug' => Str::slug($name)]);
}

function makeContextOption(SubjectContextDimension $dimension, string $name, ?SubjectContextOption $parent = null): SubjectContextOption
{
    return SubjectContextOption::create([
        'dimension_id'      => $dimension->id,
        'name'              => $name,
        'slug'              => Str::slug($name),
        'parent_option_id'  => $parent?->id,
    ]);
}

// --- NextStepService::findBestModuleForConcepts ----------------------------------------------

test('findBestModuleForConcepts excludes a module tagged with an option the user did not declare', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');

    $race   = makeContextDimension($subject, 'Race');
    $zerg   = makeContextOption($race, 'Zerg');
    $terran = makeContextOption($race, 'Terran');

    $zergModule = makeContentModule($subject, [$concept]);
    $zergModule->contextOptions()->attach($zerg->id);

    app(SubjectContextService::class)->declare($user->id, $race->id, $terran->id);

    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    expect($result)->toBeNull(); // only candidate is Zerg-tagged, user declared Terran
});

test('findBestModuleForConcepts gives an undeclared user only context-free modules', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');

    $race = makeContextDimension($subject, 'Race');
    $zerg = makeContextOption($race, 'Zerg');

    $zergModule = makeContentModule($subject, [$concept]);
    $zergModule->contextOptions()->attach($zerg->id);
    $freeModule = makeContentModule($subject, [$concept]);

    // No declaration made at all.
    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    expect($result->id)->toBe($freeModule->id);
});

test('findBestModuleForConcepts prefers a Spec-tagged module over a Class-tagged one over a context-free one', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');

    $class = makeContextDimension($subject, 'Class');
    $spec  = makeContextDimension($subject, 'Spec');
    $rogue = makeContextOption($class, 'Rogue');
    $assassination = makeContextOption($spec, 'Assassination', $rogue);

    $specModule = makeContentModule($subject, [$concept]);
    $specModule->contextOptions()->attach($assassination->id);

    $classModule = makeContentModule($subject, [$concept]);
    $classModule->contextOptions()->attach($rogue->id);

    makeContentModule($subject, [$concept]); // context-free candidate, should lose to both above

    app(SubjectContextService::class)->declare($user->id, $class->id, $rogue->id);
    app(SubjectContextService::class)->declare($user->id, $spec->id, $assassination->id);

    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    expect($result->id)->toBe($specModule->id);
});

test('a user with Class declared but no Spec still receives Class-tagged modules, not Spec-tagged ones', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');

    $class = makeContextDimension($subject, 'Class');
    $spec  = makeContextDimension($subject, 'Spec');
    $rogue = makeContextOption($class, 'Rogue');
    $assassination = makeContextOption($spec, 'Assassination', $rogue);

    $classModule = makeContentModule($subject, [$concept]);
    $classModule->contextOptions()->attach($rogue->id);

    // A Spec-tagged module exists too, but the user hasn't declared Spec — must be unreachable.
    $specModule = makeContentModule($subject, [$concept]);
    $specModule->contextOptions()->attach($assassination->id);

    app(SubjectContextService::class)->declare($user->id, $class->id, $rogue->id);

    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    expect($result->id)->toBe($classModule->id);
});

test('a context-free module is still reachable for a user who has declared context elsewhere', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');

    $race = makeContextDimension($subject, 'Race');
    $zerg = makeContextOption($race, 'Zerg');

    $freeModule = makeContentModule($subject, [$concept]);

    app(SubjectContextService::class)->declare($user->id, $race->id, $zerg->id);

    $result = app(NextStepService::class)->findBestModuleForConcepts($user->id, $subject->id, [$concept->id]);

    expect($result->id)->toBe($freeModule->id);
});

// --- Same rule enforced via RecommendationService::generateRecommendation() ------------------

test('generateRecommendation never resolves to a context-excluded module', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $race   = makeContextDimension($subject, 'Race');
    $zerg   = makeContextOption($race, 'Zerg');
    $terran = makeContextOption($race, 'Terran');

    $zergModule = makeContentModule($subject, [$concept]);
    $zergModule->contextOptions()->attach($zerg->id);

    app(SubjectContextService::class)->declare($user->id, $race->id, $terran->id);

    fakeOpenAiRecommendation($concept->name);

    $step = app(RecommendationService::class)->generateRecommendation($insight);

    expect($step)->toBeNull(); // the only module for this concept is excluded by declared context
});

test('generateRecommendation resolves to the declared-context module when one exists', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $race = makeContextDimension($subject, 'Race');
    $zerg = makeContextOption($race, 'Zerg');

    $zergModule = makeContentModule($subject, [$concept]);
    $zergModule->contextOptions()->attach($zerg->id);

    app(SubjectContextService::class)->declare($user->id, $race->id, $zerg->id);

    fakeOpenAiRecommendation($concept->name);

    $step = app(RecommendationService::class)->generateRecommendation($insight);

    expect($step)->not->toBeNull();
    expect($step->module_id)->toBe($zergModule->id);
});
