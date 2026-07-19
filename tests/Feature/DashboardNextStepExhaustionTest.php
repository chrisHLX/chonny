<?php

use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Models\Category;
use App\Models\Module;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserNextStep;

function makeExhaustionFixtures(): array
{
    $category = Category::create(['name' => 'Games']);
    $subject  = Subject::create(['name' => 'StarCraft 2', 'category_id' => $category->id]);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Zerg Basics',
        'status'     => 'ready',
        'type'       => 'content',
        'published'  => true,
    ]);

    return compact('category', 'subject', 'module');
}

/**
 * The Next Experiment card (and the rest of the profile-first dashboard sections) only renders
 * at all when $diagnosticProfile is non-null, which DashboardController derives from a
 * *completed diagnostic module* pivot — not from having any UserNextStep. Every test in this
 * file needs this, or the exhaustion card's branch is never even reached regardless of whether
 * the underlying logic is correct.
 */
function completeDiagnosticFor(User $user, Subject $subject): void
{
    $diagnosticModule = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Diagnostic',
        'type'       => 'diagnostic',
        'status'     => 'ready',
        'published'  => true,
    ]);

    $user->modules()->syncWithoutDetaching([
        $diagnosticModule->id => [
            'status'             => 'completed',
            'completed_at'       => now(),
            'diagnostic_profile' => json_encode(['profile_title' => 'Test Profile', 'next_practice_goal' => '']),
        ],
    ]);
}

test('shows the exhausted-content card, not the generic practice-goal fallback, once the recommendation engine runs out of content', function () {
    ['category' => $category, 'subject' => $subject, 'module' => $module] = makeExhaustionFixtures();
    $user = User::factory()->create();

    completeDiagnosticFor($user, $subject);

    $user->modules()->syncWithoutDetaching([
        $module->id => ['status' => 'completed', 'completed_at' => now()],
    ]);

    // A step existed and completed — nothing regenerated because RecommendationService found no
    // more candidates, exactly the production case (SC2, small content bank).
    UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'module_id'        => $module->id,
        'step_type'        => StepType::Module->value,
        'status'           => NextStepStatus::Completed->value,
        'generated_reason' => 'module_completed',
        'title'            => 'Zerg Basics',
        'instructions'     => 'This module covers a growth area.',
        'completed_at'     => now(),
    ]);

    $response = $this->actingAs($user)->get('/dashboard?category_id=' . $category->id . '&subject_id=' . $subject->id);

    $response->assertOk();
    // false = don't HTML-escape the search string: the blade text is static (never passed
    // through {{ }}), so its apostrophe is a literal ' in the response, not &#039;. assertSee()
    // escapes its search string by default, which would otherwise search for the wrong string.
    $response->assertSee("You've completed everything available here", false);
});

test('does not show the exhausted-content card when no next-step system has ever run for the subject', function () {
    ['category' => $category, 'subject' => $subject] = makeExhaustionFixtures();
    $user = User::factory()->create();

    completeDiagnosticFor($user, $subject);

    $response = $this->actingAs($user)->get('/dashboard?category_id=' . $category->id . '&subject_id=' . $subject->id);

    $response->assertOk();
    $response->assertDontSee("You've completed everything available here", false);
});

test('an active pending step takes priority over the exhausted-content card', function () {
    ['category' => $category, 'subject' => $subject, 'module' => $module] = makeExhaustionFixtures();
    $user = User::factory()->create();

    completeDiagnosticFor($user, $subject);

    UserNextStep::create([
        'user_id'          => $user->id,
        'subject_id'       => $subject->id,
        'module_id'        => $module->id,
        'step_type'        => StepType::Module->value,
        'status'           => NextStepStatus::Pending->value,
        'generated_reason' => 'initial',
        'title'            => 'Zerg Basics',
        'instructions'     => 'Start here.',
    ]);

    $response = $this->actingAs($user)->get('/dashboard?category_id=' . $category->id . '&subject_id=' . $subject->id);

    $response->assertOk();
    $response->assertSee('Zerg Basics');
    $response->assertDontSee("You've completed everything available here", false);
});
