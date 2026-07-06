<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\PlayerTrait;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfileEvidence;
use App\Models\UserTraitEvidence;
use Illuminate\Support\Facades\Http;

function fakeRecaptchaSuccess(): void
{
    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'score' => 0.9,
        ]),
    ]);
}

function makeDiagnosticFixtures(): array
{
    $category = Category::create(['name' => 'Games']);
    $subject = Subject::create(['name' => 'World of Warcraft', 'category_id' => $category->id]);
    $module = Module::create(['subject_id' => $subject->id, 'name' => 'WoW Diagnostic']);
    $trait = PlayerTrait::create([
        'key' => 'reactivity',
        'name' => 'Reactivity',
        'description' => 'Responds to pressure rather than planning ahead.',
        'category' => 'tempo',
    ]);
    $traitQuestion = Question::create([
        'question' => 'How do you usually respond under pressure?',
        'answer' => [],
        'type' => 'diagnostic_mcq',
    ]);
    $surveyQuestion = Question::create([
        'question' => 'What is your current highest Arena rating?',
        'answer' => [],
        'type' => 'survey_mcq',
    ]);

    return compact('category', 'subject', 'module', 'trait', 'traitQuestion', 'surveyQuestion');
}

test('guest diagnostic evidence transfers to the new account on registration', function () {
    fakeRecaptchaSuccess();

    ['category' => $category, 'subject' => $subject, 'module' => $module, 'trait' => $trait, 'traitQuestion' => $traitQuestion, 'surveyQuestion' => $surveyQuestion] = makeDiagnosticFixtures();

    $completedAt = now()->toIso8601String();

    $guestResult = [
        'module_id' => $module->id,
        'trait_scores' => ['reactivity' => 8],
        'survey_answers' => ['current_rating' => ['text' => '2400+', 'value' => 5]],
        'question_evidence' => [
            [
                'question_id' => $traitQuestion->id,
                'trait_key' => 'reactivity',
                'points' => 8,
                'selected_answer' => 'I react instantly',
                'selected_option_index' => 0,
            ],
            [
                'type' => 'survey',
                'question_id' => $surveyQuestion->id,
                'question_key' => 'current_rating',
                'answer_text' => '2400+',
                'answer_value' => 5,
            ],
        ],
        'diagnostic_profile' => [
            'profile_title' => 'Strategic Controller',
            'archetype_key' => 'strategic_controller',
            'confidence_level' => 'high',
            'summary' => 'Your answers suggest a controlled, methodical playstyle.',
        ],
        'completed_at' => $completedAt,
    ];

    $response = $this->withSession(['guest_quiz_results' => [$module->id => $guestResult]])
        ->post('/register', [
            'name' => 'Test User',
            'email' => 'diagnostic-guest@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'g-recaptcha-response' => 'test-token',
        ]);

    $this->assertAuthenticated();
    $user = User::where('email', 'diagnostic-guest@example.com')->firstOrFail();

    // Trait evidence persisted
    $traitEvidence = UserTraitEvidence::where([
        'user_id' => $user->id,
        'question_id' => $traitQuestion->id,
        'trait_id' => $trait->id,
    ])->first();
    expect($traitEvidence)->not->toBeNull();
    expect($traitEvidence->module_id)->toBe($module->id);
    expect($traitEvidence->points)->toBe(8);
    expect($traitEvidence->selected_answer)->toBe('I react instantly');

    // Profile (survey) evidence persisted with category/subject context
    $profileEvidence = UserProfileEvidence::where([
        'user_id' => $user->id,
        'question_id' => $surveyQuestion->id,
    ])->first();
    expect($profileEvidence)->not->toBeNull();
    expect($profileEvidence->module_id)->toBe($module->id);
    expect($profileEvidence->category_id)->toBe($category->id);
    expect($profileEvidence->subject_id)->toBe($subject->id);
    expect($profileEvidence->question_key)->toBe('current_rating');
    expect($profileEvidence->answer_text)->toBe('2400+');
    expect($profileEvidence->answer_value)->toBe(5);

    // Diagnostic profile JSON persisted on module_user pivot
    $pivot = $user->modules()->find($module->id)->pivot;
    expect($pivot->status)->toBe('completed');
    expect(json_decode($pivot->diagnostic_profile, true))->toBe($guestResult['diagnostic_profile']);

    // Guest session data cleared only once the transfer succeeded
    $response->assertSessionMissing("guest_quiz_results.{$module->id}");
});

test('guest session data is retained when the diagnostic transfer fails', function () {
    fakeRecaptchaSuccess();

    ['module' => $module, 'trait' => $trait, 'traitQuestion' => $traitQuestion] = makeDiagnosticFixtures();

    // An unparsable completed_at forces Carbon::parse() to throw inside the claim transaction.
    $guestResult = [
        'module_id' => $module->id,
        'trait_scores' => ['reactivity' => 8],
        'survey_answers' => [],
        'question_evidence' => [
            [
                'question_id' => $traitQuestion->id,
                'trait_key' => 'reactivity',
                'points' => 8,
                'selected_answer' => 'I react instantly',
                'selected_option_index' => 0,
            ],
        ],
        'diagnostic_profile' => ['profile_title' => 'Strategic Controller'],
        'completed_at' => 'not-a-real-date',
    ];

    $response = $this->withSession(['guest_quiz_results' => [$module->id => $guestResult]])
        ->post('/register', [
            'name' => 'Test User',
            'email' => 'failed-claim@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'g-recaptcha-response' => 'test-token',
        ]);

    $this->assertAuthenticated();
    $user = User::where('email', 'failed-claim@example.com')->firstOrFail();

    expect(UserTraitEvidence::where('user_id', $user->id)->exists())->toBeFalse();
    expect(UserProfileEvidence::where('user_id', $user->id)->exists())->toBeFalse();
    expect($user->modules()->find($module->id))->toBeNull();

    // Since the transfer never committed, the guest data must remain for a future retry.
    $response->assertSessionHas("guest_quiz_results.{$module->id}");
});
