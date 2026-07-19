<?php

use App\Models\Category;
use App\Models\Concept;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserLearningPathStage;
use App\Models\UserProfileInsight;

function makeBackfillFixtures(): array
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

test('backfill persists learning path stages for a user who completed a diagnostic before the feature existed', function () {
    ['diagnosticModule' => $module, 'contentModule' => $contentModule] = makeBackfillFixtures();
    $user = User::factory()->create();

    // Simulates a pre-feature completion: pivot has diagnostic_profile, but no
    // UserLearningPathStage rows were ever written (the hook didn't exist yet).
    $user->modules()->syncWithoutDetaching([
        $module->id => [
            'status'             => 'completed',
            'completed_at'       => now(),
            'diagnostic_profile' => json_encode([
                'primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => ['Cooldown Tracking']],
            ]),
        ],
    ]);

    expect(UserLearningPathStage::where('user_id', $user->id)->count())->toBe(0);

    $this->artisan('roadmap:backfill')->assertSuccessful();

    $stages = UserLearningPathStage::where('user_id', $user->id)->orderBy('order_index')->get();
    expect($stages)->toHaveCount(6); // 'context_dimensions' omitted — no dimensions seeded in this test
    expect($stages[1]->module_id)->toBe($contentModule->id);
});

test('backfill attaches the matching insight id when one exists', function () {
    ['diagnosticModule' => $module, 'subject' => $subject] = makeBackfillFixtures();
    $user = User::factory()->create();

    $user->modules()->syncWithoutDetaching([
        $module->id => [
            'status'             => 'completed',
            'completed_at'       => now(),
            'diagnostic_profile' => json_encode(['primary_growth_area' => ['name' => '', 'concepts' => []]]),
        ],
    ]);

    $insight = UserProfileInsight::create([
        'user_id'          => $user->id,
        'module_id'        => $module->id,
        'subject_id'       => $subject->id,
        'profile_title'    => 'Strategic Controller',
        'confidence_level' => 'medium',
        'summary'          => 'Summary text.',
        'generated_at'     => now(),
    ]);

    $this->artisan('roadmap:backfill')->assertSuccessful();

    $stage = UserLearningPathStage::where('user_id', $user->id)->first();
    expect($stage->insight_id)->toBe($insight->id);
});

test('backfill is idempotent — running it twice does not duplicate stages', function () {
    ['diagnosticModule' => $module] = makeBackfillFixtures();
    $user = User::factory()->create();

    $user->modules()->syncWithoutDetaching([
        $module->id => [
            'status'             => 'completed',
            'completed_at'       => now(),
            'diagnostic_profile' => json_encode(['primary_growth_area' => ['name' => '', 'concepts' => []]]),
        ],
    ]);

    $this->artisan('roadmap:backfill')->assertSuccessful();
    $this->artisan('roadmap:backfill')->assertSuccessful();

    expect(UserLearningPathStage::where('user_id', $user->id)->count())->toBe(6);
});

test('backfill skips non-diagnostic modules and users with no diagnostic_profile', function () {
    ['contentModule' => $contentModule] = makeBackfillFixtures();
    $user = User::factory()->create();

    // Completed a regular (non-diagnostic) module — no diagnostic_profile at all.
    $user->modules()->syncWithoutDetaching([
        $contentModule->id => ['status' => 'completed', 'completed_at' => now()],
    ]);

    $this->artisan('roadmap:backfill')->assertSuccessful();

    expect(UserLearningPathStage::where('user_id', $user->id)->count())->toBe(0);
});
