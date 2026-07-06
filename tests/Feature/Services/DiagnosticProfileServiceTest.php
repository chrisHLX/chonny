<?php

use App\Http\Services\DiagnosticProfileService;
use App\Models\Category;
use App\Models\Concept;
use App\Models\Module;
use App\Models\Subject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('ai returns all valid concept names in both fields - should pass through unchanged', function () {
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'StarCraft II', 'category_id' => $category->id]);

    $concept1 = Concept::create(['subject_id' => $subject->id, 'name' => 'Target Switching']);
    $concept2 = Concept::create(['subject_id' => $subject->id, 'name' => 'Cooldown Management']);
    $concept3 = Concept::create(['subject_id' => $subject->id, 'name' => 'Positioning']);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name' => 'SC2 Diagnostic',
        'type' => 'diagnostic',
    ]);

    $fakeResponse = [
        'profile_title' => 'Mechanical Player',
        'archetype_key' => 'mechanical_grinder',
        'confidence_level' => 'high',
        'summary' => 'Your answers suggest a focus on mechanics.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'aligned', 'comment' => 'Matches self-report.'],
        'likely_in_game_pattern' => 'Fast reactions.',
        'primary_strength' => [
            'name' => 'Micro Excellence',
            'concepts' => ['Target Switching', 'Cooldown Management'],
        ],
        'primary_growth_area' => [
            'name' => 'Strategic Vision',
            'concepts' => ['Positioning'],
        ],
        'recommended_module' => ['module_id' => 1, 'title' => 'Strategy 101', 'reason' => 'Build on vision.'],
        'next_practice_goal' => 'Practice macro.',
    ];

    $this->mock(\App\Http\Services\AiService::class, function ($mock) use ($fakeResponse) {
        $mock->shouldReceive('sendPromptToAi')->andReturn($fakeResponse);
    });

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: ['reactivity' => 8],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: false,
        surveyAnswers: [],
        module: $module
    );

    // All valid concepts should pass through unchanged
    expect($result['primary_strength']['concepts'])->toBe(['Target Switching', 'Cooldown Management']);
    expect($result['primary_growth_area']['concepts'])->toBe(['Positioning']);
});

test('ai returns mix of valid and invalid concept names - should filter invalid only', function () {
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'StarCraft II', 'category_id' => $category->id]);

    Concept::create(['subject_id' => $subject->id, 'name' => 'Target Switching']);
    Concept::create(['subject_id' => $subject->id, 'name' => 'Positioning']);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name' => 'SC2 Diagnostic',
        'type' => 'diagnostic',
    ]);

    $fakeResponse = [
        'profile_title' => 'Mixed Player',
        'archetype_key' => 'adaptive_opportunist',
        'confidence_level' => 'medium',
        'summary' => 'Your answers suggest adaptability.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'partially_aligned', 'comment' => 'Some alignment.'],
        'likely_in_game_pattern' => 'Varies by game state.',
        'primary_strength' => [
            'name' => 'Adaptability',
            'concepts' => ['Target Switching', 'Direct Pressure', 'Positioning'],
        ],
        'primary_growth_area' => [
            'name' => 'Consistency',
            'concepts' => ['Converting Advantages', 'Positioning'],
        ],
        'recommended_module' => ['module_id' => 2, 'title' => 'Consistency Training', 'reason' => 'Improve reliability.'],
        'next_practice_goal' => 'Be consistent.',
    ];

    $this->mock(\App\Http\Services\AiService::class, function ($mock) use ($fakeResponse) {
        $mock->shouldReceive('sendPromptToAi')->andReturn($fakeResponse);
    });

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: ['reactivity' => 5],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: false,
        surveyAnswers: [],
        module: $module
    );

    // Only valid names should remain
    expect($result['primary_strength']['concepts'])->toBe(['Target Switching', 'Positioning']);
    expect($result['primary_growth_area']['concepts'])->toBe(['Positioning']);
});

test('ai returns all invalid concept names - should result in empty array', function () {
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'StarCraft II', 'category_id' => $category->id]);

    Concept::create(['subject_id' => $subject->id, 'name' => 'Real Concept']);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name' => 'SC2 Diagnostic',
        'type' => 'diagnostic',
    ]);

    $fakeResponse = [
        'profile_title' => 'Creative Player',
        'archetype_key' => 'uncertain_beginner',
        'confidence_level' => 'low',
        'summary' => 'Your answers suggest exploration.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'insufficient_data', 'comment' => 'Not enough data.'],
        'likely_in_game_pattern' => 'Experimental approach.',
        'primary_strength' => [
            'name' => 'Creativity',
            'concepts' => ['Invented Concept 1', 'Invented Concept 2'],
        ],
        'primary_growth_area' => [
            'name' => 'Execution',
            'concepts' => ['Another Made Up Concept'],
        ],
        'recommended_module' => ['module_id' => 3, 'title' => 'Fundamentals', 'reason' => 'Learn basics.'],
        'next_practice_goal' => 'Focus on fundamentals.',
    ];

    $this->mock(\App\Http\Services\AiService::class, function ($mock) use ($fakeResponse) {
        $mock->shouldReceive('sendPromptToAi')->andReturn($fakeResponse);
    });

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: [],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: false,
        surveyAnswers: [],
        module: $module
    );

    // All invalid concepts filtered out → empty arrays
    expect($result['primary_strength']['concepts'])->toBe([]);
    expect($result['primary_growth_area']['concepts'])->toBe([]);

    // .name fields should remain untouched
    expect($result['primary_strength']['name'])->toBe('Creativity');
    expect($result['primary_growth_area']['name'])->toBe('Execution');
});

test('ai returns no concepts key in one of the objects - should not crash', function () {
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'StarCraft II', 'category_id' => $category->id]);

    Concept::create(['subject_id' => $subject->id, 'name' => 'Target Switching']);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name' => 'SC2 Diagnostic',
        'type' => 'diagnostic',
    ]);

    // Missing concepts key in primary_growth_area
    $fakeResponse = [
        'profile_title' => 'Partial Player',
        'archetype_key' => 'mechanical_grinder',
        'confidence_level' => 'medium',
        'summary' => 'Your answers suggest focus.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'aligned', 'comment' => 'Matches.'],
        'likely_in_game_pattern' => 'Consistent play.',
        'primary_strength' => [
            'name' => 'Mechanics',
            'concepts' => ['Target Switching'],
        ],
        'primary_growth_area' => [
            'name' => 'Strategy',
            // Missing concepts key
        ],
        'recommended_module' => ['module_id' => 4, 'title' => 'Strategy 201', 'reason' => 'Build strategy.'],
        'next_practice_goal' => 'Learn macro.',
    ];

    $this->mock(\App\Http\Services\AiService::class, function ($mock) use ($fakeResponse) {
        $mock->shouldReceive('sendPromptToAi')->andReturn($fakeResponse);
    });

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: [],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: false,
        surveyAnswers: [],
        module: $module
    );

    // Should not crash
    expect($result['primary_strength']['concepts'])->toBe(['Target Switching']);
    // primary_growth_area should pass through as-is (no concepts key to filter)
    expect(isset($result['primary_growth_area']['concepts']))->toBeFalse();
    expect($result['primary_growth_area']['name'])->toBe('Strategy');
});

test('subject has zero concepts configured - should filter all to empty', function () {
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'StarCraft II', 'category_id' => $category->id]);
    // No concepts created

    $module = Module::create([
        'subject_id' => $subject->id,
        'name' => 'SC2 Diagnostic',
        'type' => 'diagnostic',
    ]);

    $fakeResponse = [
        'profile_title' => 'Empty Subject Player',
        'archetype_key' => 'uncertain_beginner',
        'confidence_level' => 'low',
        'summary' => 'Your answers suggest exploration.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'insufficient_data', 'comment' => 'Unknown.'],
        'likely_in_game_pattern' => 'Exploratory play.',
        'primary_strength' => [
            'name' => 'Discovery',
            'concepts' => ['Any Concept Name'],
        ],
        'primary_growth_area' => [
            'name' => 'Focus',
            'concepts' => ['Another Concept'],
        ],
        'recommended_module' => ['module_id' => 5, 'title' => 'Intro', 'reason' => 'Get started.'],
        'next_practice_goal' => 'Explore more.',
    ];

    $this->mock(\App\Http\Services\AiService::class, function ($mock) use ($fakeResponse) {
        $mock->shouldReceive('sendPromptToAi')->andReturn($fakeResponse);
    });

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: [],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: false,
        surveyAnswers: [],
        module: $module
    );

    // All concepts should be filtered out (concept map is empty)
    expect($result['primary_strength']['concepts'])->toBe([]);
    expect($result['primary_growth_area']['concepts'])->toBe([]);
});

test('module is null - should complete without exception and filter all', function () {
    // No module, no subject, empty concept map
    $fakeResponse = [
        'profile_title' => 'Orphan Profile',
        'archetype_key' => 'uncertain_beginner',
        'confidence_level' => 'low',
        'summary' => 'Your answers suggest mystery.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'insufficient_data', 'comment' => 'Unknown context.'],
        'likely_in_game_pattern' => 'Unknown.',
        'primary_strength' => [
            'name' => 'Something',
            'concepts' => ['Concept A', 'Concept B'],
        ],
        'primary_growth_area' => [
            'name' => 'Something Else',
            'concepts' => ['Concept C'],
        ],
        'recommended_module' => ['module_id' => 6, 'title' => 'Unknown', 'reason' => 'No context.'],
        'next_practice_goal' => 'Figure it out.',
    ];

    $this->mock(\App\Http\Services\AiService::class, function ($mock) use ($fakeResponse) {
        $mock->shouldReceive('sendPromptToAi')->andReturn($fakeResponse);
    });

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: [],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: false,
        surveyAnswers: [],
        module: null  // No module
    );

    // Should complete without exception
    expect($result)->toBeArray();
    // All concept names should be filtered out (empty concept map)
    expect($result['primary_strength']['concepts'])->toBe([]);
    expect($result['primary_growth_area']['concepts'])->toBe([]);
});

test('guest path with gemini call faked - should apply same grounding as auth path', function () {
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'StarCraft II', 'category_id' => $category->id]);

    Concept::create(['subject_id' => $subject->id, 'name' => 'Target Switching']);
    Concept::create(['subject_id' => $subject->id, 'name' => 'Positioning']);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name' => 'SC2 Diagnostic',
        'type' => 'diagnostic',
    ]);

    // Fake Gemini HTTP response
    $fakeResponse = [
        'profile_title' => 'Guest Player',
        'archetype_key' => 'reactive_survivor',
        'confidence_level' => 'medium',
        'summary' => 'Your answers suggest reactivity.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'aligned', 'comment' => 'Matches behavior.'],
        'likely_in_game_pattern' => 'Fast decisions.',
        'primary_strength' => [
            'name' => 'Reaction Speed',
            'concepts' => ['Target Switching', 'Invented Concept', 'Positioning'],
        ],
        'primary_growth_area' => [
            'name' => 'Planning',
            'concepts' => ['Made Up Concept'],
        ],
        'recommended_module' => ['module_id' => 7, 'title' => 'Reaction Training', 'reason' => 'Improve speed.'],
        'next_practice_goal' => 'React faster.',
    ];

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode($fakeResponse)]]]],
            ],
        ]),
    ]);

    config(['services.gemini.key' => 'test-key']);

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: [],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: true,
        surveyAnswers: [],
        module: $module
    );

    // Same grounding should apply to guest path
    // Only valid concepts remain, invalid ones filtered
    expect($result['primary_strength']['concepts'])->toBe(['Target Switching', 'Positioning']);
    expect($result['primary_growth_area']['concepts'])->toBe([]);

    // .name fields still intact
    expect($result['primary_strength']['name'])->toBe('Reaction Speed');
    expect($result['primary_growth_area']['name'])->toBe('Planning');
});

test('invalid concept names are logged as warning', function () {
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'StarCraft II', 'category_id' => $category->id]);

    Concept::create(['subject_id' => $subject->id, 'name' => 'Valid Concept']);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name' => 'SC2 Diagnostic',
        'type' => 'diagnostic',
    ]);

    $fakeResponse = [
        'profile_title' => 'Test',
        'archetype_key' => 'test',
        'confidence_level' => 'medium',
        'summary' => 'Test.',
        'evidence' => [],
        'self_report_check' => ['alignment' => 'aligned', 'comment' => 'Test.'],
        'likely_in_game_pattern' => 'Test.',
        'primary_strength' => [
            'name' => 'Test',
            'concepts' => ['Valid Concept', 'Invalid Concept'],
        ],
        'primary_growth_area' => [
            'name' => 'Test',
            'concepts' => ['Another Invalid'],
        ],
        'recommended_module' => ['module_id' => 8, 'title' => 'Test', 'reason' => 'Test.'],
        'next_practice_goal' => 'Test.',
    ];

    $this->mock(\App\Http\Services\AiService::class, function ($mock) use ($fakeResponse) {
        $mock->shouldReceive('sendPromptToAi')->andReturn($fakeResponse);
    });

    Log::shouldReceive('warning')
        ->twice()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Filtered invalid concept names');
        });

    $service = app(DiagnosticProfileService::class);
    $result = $service->generateProfile(
        traitScores: [],
        axisScores: [],
        conceptScores: [],
        userId: 1,
        isGuest: false,
        surveyAnswers: [],
        module: $module
    );

    expect($result['primary_strength']['concepts'])->toBe(['Valid Concept']);
    expect($result['primary_growth_area']['concepts'])->toBe([]);
});
