<?php

use App\Enums\GeneratedReason;
use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Http\Services\RecommendationService;
use App\Models\User;
use App\Models\UserNextStep;
use Illuminate\Support\Facades\Http;

// Reuses makeSubject(), makeConcept(), makeContentModule(), makeInsightWithGrowthConcepts()
// declared as global functions in NextStepServiceModuleMatchingTest.php — Pest requires every
// Feature test file, so they're already available here without redeclaring them.

function fakeOpenAiRecommendation(?string $concept, string $reason = 'Worth exploring next.'): void
{
    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'concept' => $concept,
                    'reason'  => $reason,
                ])]],
            ],
        ]),
    ]);
}

test('generateRecommendation returns null and makes no AI call when the subject has no concept with an available module', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    // No content module created — nothing available to recommend.
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    Http::fake();

    $step = app(RecommendationService::class)->generateRecommendation($insight);

    expect($step)->toBeNull();
    Http::assertNothingSent();
});

test('generateRecommendation excludes a concept whose only module the user already completed', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);
    $user->modules()->attach($module->id, ['status' => 'completed']);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    Http::fake();

    $step = app(RecommendationService::class)->generateRecommendation($insight);

    expect($step)->toBeNull();
    Http::assertNothingSent();
});

test('generateRecommendation picks the AI-chosen concept and resolves its module deterministically', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $conceptA = makeConcept($subject, 'Positioning');
    $conceptB = makeConcept($subject, 'Cooldown Usage');
    makeContentModule($subject, [$conceptA]);
    $moduleB = makeContentModule($subject, [$conceptB]);
    // The insight's own growth-area concept is A — the recommendation still picks B because
    // candidate selection is decoupled from the diagnosis's growth-area grounding entirely.
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$conceptA]);

    fakeOpenAiRecommendation('Cooldown Usage');

    $step = app(RecommendationService::class)->generateRecommendation($insight);

    expect($step)->not->toBeNull();
    expect($step->step_type)->toBe(StepType::Module);
    expect($step->concept_id)->toBe($conceptB->id);
    expect($step->module_id)->toBe($moduleB->id);
    expect($step->status)->toBe(NextStepStatus::Pending);
    expect($step->insight_id)->toBe($insight->id);
    expect($step->generated_reason)->toBe(GeneratedReason::Initial);
    expect($step->instructions)->toBe('Worth exploring next.');
});

test('generateRecommendation falls back to a deterministic pick when the AI returns a concept not on the candidate list', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    $module = makeContentModule($subject, [$concept]);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    fakeOpenAiRecommendation('Something Invented');

    $step = app(RecommendationService::class)->generateRecommendation($insight);

    expect($step)->not->toBeNull();
    expect($step->concept_id)->toBe($concept->id);
    expect($step->module_id)->toBe($module->id);
});

test('generateRecommendation chains previous_step_id and honors the given generated reason', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    makeContentModule($subject, [$concept]);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    $previousStep = UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'step_type'        => StepType::Module,
        'status'           => NextStepStatus::Completed,
        'generated_reason' => GeneratedReason::Initial,
        'title'            => 'Old module',
        'instructions'     => 'Old instructions',
    ]);

    fakeOpenAiRecommendation('Positioning');

    $step = app(RecommendationService::class)->generateRecommendation($insight, $previousStep, GeneratedReason::ModuleCompleted);

    expect($step->previous_step_id)->toBe($previousStep->id);
    expect($step->generated_reason)->toBe(GeneratedReason::ModuleCompleted);
});

test('generateRecommendation never produces a Task-type step', function () {
    $subject = makeSubject();
    $user = User::factory()->create();
    $concept = makeConcept($subject, 'Positioning');
    makeContentModule($subject, [$concept]);
    $insight = makeInsightWithGrowthConcepts($user, $subject, [$concept]);

    fakeOpenAiRecommendation(null);

    $step = app(RecommendationService::class)->generateRecommendation($insight);

    expect($step->step_type)->toBe(StepType::Module);
});
