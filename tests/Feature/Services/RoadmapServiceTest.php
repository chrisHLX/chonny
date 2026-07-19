<?php

use App\Http\Services\RoadmapService;
use App\Models\Category;
use App\Models\Concept;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserLearningPathStage;

function makeRoadmapFixtures(string $subjectName = 'World of Warcraft: The War Within'): array
{
    $category = Category::create(['name' => 'Games']);
    $subject  = Subject::create(['name' => $subjectName, 'category_id' => $category->id]);

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

test('builds a full roadmap with correct statuses and a real matched module', function () {
    ['diagnosticModule' => $module, 'contentModule' => $contentModule] = makeRoadmapFixtures();

    $profile = [
        'primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => ['Cooldown Tracking']],
    ];
    $surveyAnswers = [
        'current_rating' => ['text' => '1800–2099', 'value' => 3],
        'primary_goal'   => ['text' => 'Push Gladiator or higher', 'value' => 5],
    ];

    $roadmap = app(RoadmapService::class)->buildGuestRoadmap($profile, $surveyAnswers, $module);

    expect($roadmap['title'])->toBe('Your Road to Push Gladiator or higher');
    expect($roadmap['summary'])
        ->toContain('Cooldown Tracking')
        ->toContain('1800–2099')
        ->toContain('Push Gladiator or higher')
        ->toContain('Cooldown Fundamentals');

    $milestones = $roadmap['milestones'];
    expect($milestones)->toHaveCount(7); // WoW-specific config set
    expect($milestones[0]['status'])->toBe('complete');
    expect($milestones[0]['title'])->toBe('Diagnostic Assessment');
    expect($milestones[1]['status'])->toBe('next');
    expect($milestones[1]['title'])->toBe('Cooldown Fundamentals'); // real, grounded module
    expect($milestones[1]['detail'])->toContain('Cooldown Tracking');
    foreach (array_slice($milestones, 2) as $future) {
        expect($future['status'])->toBe('future');
    }
    expect($milestones[6]['title'])->toBe('Reassessment');
});

test('falls back to a generic first-module title when no concept matches any real module', function () {
    ['diagnosticModule' => $module] = makeRoadmapFixtures();

    $profile = [
        'primary_growth_area' => ['name' => 'Nonexistent Concept', 'concepts' => ['Nonexistent Concept']],
    ];

    $roadmap = app(RoadmapService::class)->buildGuestRoadmap($profile, [], $module);

    expect($roadmap['milestones'][1]['title'])->toBe('Your First Training Module');
    expect($roadmap['milestones'][1]['detail'])->toContain('Nonexistent Concept');
});

test('degrades gracefully with no growth area, no rating, and no goal', function () {
    ['diagnosticModule' => $module] = makeRoadmapFixtures();

    $roadmap = app(RoadmapService::class)->buildGuestRoadmap([], [], $module);

    expect($roadmap['title'])->toBe('Your Learning Path');
    expect($roadmap['summary'])
        ->not->toContain('null')
        ->not->toContain('{')
        ->not->toContain('toward your goal');
    expect($roadmap['milestones'][1]['title'])->toBe('Your First Training Module');
    expect($roadmap['milestones'][1]['detail'])->toBe('A starting point tailored to your profile.');
});

test('uses the default milestone set for a subject with no bespoke config', function () {
    ['diagnosticModule' => $module] = makeRoadmapFixtures('Programming');

    $roadmap = app(RoadmapService::class)->buildGuestRoadmap([], [], $module);

    expect($roadmap['milestones'])->toHaveCount(5); // 'default' set in config/roadmap.php
    expect($roadmap['milestones'][0]['title'])->toBe('Diagnostic Assessment');
    expect($roadmap['milestones'][4]['title'])->toBe('Reassessment');
});

test('goal-only summary omits the current-rating clause but still mentions the goal', function () {
    ['diagnosticModule' => $module] = makeRoadmapFixtures();

    $roadmap = app(RoadmapService::class)->buildGuestRoadmap(
        [],
        ['primary_goal' => ['text' => 'Master my class', 'value' => 3]],
        $module
    );

    expect($roadmap['title'])->toBe('Your Road to Master my class');
    expect($roadmap['summary'])->toContain('toward your goal to Master my class');
});

test('persistStagesForUser writes one ordered row per milestone with the real matched module attached', function () {
    ['diagnosticModule' => $module, 'contentModule' => $contentModule, 'concept' => $concept] = makeRoadmapFixtures();
    $user = User::factory()->create();

    $profile = [
        'primary_growth_area' => ['name' => 'Cooldown Tracking', 'concepts' => ['Cooldown Tracking']],
    ];

    app(RoadmapService::class)->persistStagesForUser($user->id, $module, $profile, [], insightId: null);

    $stages = UserLearningPathStage::where('user_id', $user->id)->orderBy('order_index')->get();

    expect($stages)->toHaveCount(7);
    expect($stages[0]->stage_key)->toBe('diagnostic');
    expect($stages[0]->order_index)->toBe(0);
    expect($stages[1]->stage_key)->toBe('first_module');
    expect($stages[1]->module_id)->toBe($contentModule->id);
    expect($stages[1]->concept_id)->toBe($concept->id);
    expect($stages[6]->stage_key)->toBe('reassessment');
});

test('persistStagesForUser replaces old stages instead of accumulating on a second call (e.g. a retake)', function () {
    ['diagnosticModule' => $module] = makeRoadmapFixtures();
    $user = User::factory()->create();

    app(RoadmapService::class)->persistStagesForUser($user->id, $module, [], [], insightId: null);
    app(RoadmapService::class)->persistStagesForUser($user->id, $module, [], [], insightId: null);

    expect(UserLearningPathStage::where('user_id', $user->id)->count())->toBe(7);
});
