<?php

use App\Enums\GeneratedReason;
use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Http\Services\NextStepService;
use App\Mail\NextStepModuleAssigned;
use App\Models\Category;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserNextStep;
use Illuminate\Support\Facades\Mail;

function moduleFixesSubject(): Subject
{
    $category = Category::create(['name' => 'Games '.uniqid()]);

    return Subject::create(['name' => 'Test Subject '.uniqid(), 'category_id' => $category->id]);
}

// completeDiagnosticFor(User, Subject) is defined globally in DashboardNextStepExhaustionTest.php
// — Pest loads all Feature test files into one process, so it's reused here rather than
// redeclared (a second definition with the same name is a fatal error).

// --- Ownership / authorization ---------------------------------------------------------------

test('a non-owner, non-admin user cannot update someone else\'s module', function () {
    $subject = moduleFixesSubject();
    $owner = User::factory()->create();
    $stranger = User::factory()->create(['is_admin' => false]);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Owned Module', 'created_by' => $owner->id]);

    $this->actingAs($stranger)
        ->put(route('modules.update', $module), ['name' => 'Hacked Name'])
        ->assertForbidden();

    expect($module->fresh()->name)->toBe('Owned Module');
});

test('an admin can update a module they did not create', function () {
    $subject = moduleFixesSubject();
    $owner = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Owned Module', 'created_by' => $owner->id]);

    $this->actingAs($admin)
        ->put(route('modules.update', $module), ['name' => 'Updated By Admin'])
        ->assertRedirect();

    expect($module->fresh()->name)->toBe('Updated By Admin');
});

test('the owner can update their own module', function () {
    $subject = moduleFixesSubject();
    $owner = User::factory()->create();

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Owned Module', 'created_by' => $owner->id]);

    $this->actingAs($owner)
        ->put(route('modules.update', $module), ['name' => 'Updated By Owner'])
        ->assertRedirect();

    expect($module->fresh()->name)->toBe('Updated By Owner');
});

// --- Publish toggle + status auto-promotion --------------------------------------------------

test('checking published on the update form actually persists true', function () {
    $subject = moduleFixesSubject();
    $owner = User::factory()->create();

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Draft Module',
        'created_by' => $owner->id,
        'status'     => 'need questions',
        'published'  => false,
    ]);

    $this->actingAs($owner)->put(route('modules.update', $module), [
        'name'      => 'Draft Module',
        'published' => '1',
    ]);

    expect((bool) $module->fresh()->published)->toBeTrue();
});

test('publishing a module stuck at need questions promotes it to ready', function () {
    $subject = moduleFixesSubject();
    $owner = User::factory()->create();

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Draft Module',
        'created_by' => $owner->id,
        'status'     => 'need questions',
        'published'  => false,
    ]);

    $this->actingAs($owner)->put(route('modules.update', $module), [
        'name'      => 'Draft Module',
        'published' => '1',
    ]);

    expect($module->fresh()->status)->toBe('ready');
});

test('attaching a question to a module stuck at need questions promotes it to ready', function () {
    $subject = moduleFixesSubject();
    $owner = User::factory()->create();

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Draft Module',
        'created_by' => $owner->id,
        'status'     => 'need questions',
    ]);

    $question = Question::create([
        'question' => 'A sample question?',
        'answer'   => ['correct' => true],
        'type'     => 'true_false',
    ]);

    $this->actingAs($owner)->put(route('modules.update', $module), [
        'name'         => 'Draft Module',
        'question_ids' => [$question->id],
    ]);

    expect($module->fresh()->status)->toBe('ready');
});

test('unchecking published does not demote a module that already has questions and is ready', function () {
    $subject = moduleFixesSubject();
    $owner = User::factory()->create();

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Ready Module',
        'created_by' => $owner->id,
        'status'     => 'ready',
        'published'  => true,
    ]);

    $question = Question::create([
        'question' => 'A sample question?',
        'answer'   => ['correct' => true],
        'type'     => 'true_false',
    ]);
    $module->questions()->attach($question->id);

    $this->actingAs($owner)->put(route('modules.update', $module), [
        'name'         => 'Ready Module',
        'question_ids' => [$question->id],
        // 'published' omitted -> unchecked
    ]);

    expect($module->fresh()->status)->toBe('ready');
    expect((bool) $module->fresh()->published)->toBeFalse();
});

// --- Assign as next step ----------------------------------------------------------------------

test('an admin can assign a module as a specific user\'s next recommended step', function () {
    $subject = moduleFixesSubject();
    $admin = User::factory()->create(['is_admin' => true]);
    $targetUser = User::factory()->create();
    completeDiagnosticFor($targetUser, $subject);

    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Special Module',
        'status'     => 'ready',
        'published'  => true,
    ]);

    $this->actingAs($admin)
        ->post(route('modules.assign-next-step', $module), ['user_id' => $targetUser->id])
        ->assertRedirect();

    $step = UserNextStep::where('user_id', $targetUser->id)->where('subject_id', $subject->id)
        ->where('step_type', StepType::Module->value)
        ->first();

    expect($step)->not->toBeNull();
    expect($step->module_id)->toBe($module->id);
    expect($step->step_type)->toBe(StepType::Module);
    expect($step->status)->toBe(NextStepStatus::Pending);
    expect($step->generated_reason)->toBe(GeneratedReason::ManualAssignment);
});

test('a non-admin cannot assign a module as another user\'s next step', function () {
    $subject = moduleFixesSubject();
    $nonAdmin = User::factory()->create(['is_admin' => false]);
    $targetUser = User::factory()->create();
    completeDiagnosticFor($targetUser, $subject);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Special Module', 'status' => 'ready', 'published' => true]);

    $this->actingAs($nonAdmin)
        ->post(route('modules.assign-next-step', $module), ['user_id' => $targetUser->id])
        ->assertForbidden();

    expect(UserNextStep::where('user_id', $targetUser->id)->exists())->toBeFalse();
});

test('assigning to a user with no completed diagnostic for the subject is rejected and creates nothing', function () {
    $subject = moduleFixesSubject();
    $admin = User::factory()->create(['is_admin' => true]);
    $targetUser = User::factory()->create(); // no diagnostic completed

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Special Module', 'status' => 'ready', 'published' => true]);

    $response = $this->actingAs($admin)
        ->post(route('modules.assign-next-step', $module), ['user_id' => $targetUser->id]);

    $response->assertSessionHasErrors('user_id');
    expect(UserNextStep::where('user_id', $targetUser->id)->exists())->toBeFalse();
});

test('the assign-next-step user picker only lists users who completed that subject\'s diagnostic', function () {
    $subject = moduleFixesSubject();
    $admin = User::factory()->create(['is_admin' => true]);
    $eligibleUser = User::factory()->create(['name' => 'Eligible User']);
    $ineligibleUser = User::factory()->create(['name' => 'Ineligible User']);
    completeDiagnosticFor($eligibleUser, $subject);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Special Module', 'status' => 'ready', 'published' => true]);

    $response = $this->actingAs($admin)->get(route('modules.edit', $module));

    $users = $response->viewData('users');
    expect($users->pluck('id')->all())->toContain($eligibleUser->id);
    expect($users->pluck('id')->all())->not->toContain($ineligibleUser->id);
});

test('assigning a module to one user does not create or affect a next step for another user', function () {
    $subject = moduleFixesSubject();
    $admin = User::factory()->create(['is_admin' => true]);
    $targetUser = User::factory()->create();
    $otherUser = User::factory()->create();
    completeDiagnosticFor($targetUser, $subject);
    completeDiagnosticFor($otherUser, $subject);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Special Module', 'status' => 'ready', 'published' => true]);

    // otherUser already has their own pending step for this subject — assigning to targetUser
    // must not touch it.
    $preexistingOtherStep = app(NextStepService::class)->assignModuleAsNextStep($module, $otherUser->id);

    $this->actingAs($admin)
        ->post(route('modules.assign-next-step', $module), ['user_id' => $targetUser->id])
        ->assertRedirect();

    expect($preexistingOtherStep->fresh()->status)->toBe(NextStepStatus::Pending);

    $targetStep = UserNextStep::where('user_id', $targetUser->id)->where('module_id', $module->id)->first();
    expect($targetStep)->not->toBeNull();

    expect(UserNextStep::where('user_id', $otherUser->id)->where('module_id', $module->id)->count())->toBe(1);
    expect(UserNextStep::where('user_id', $targetUser->id)->count())->toBe(1);
});

test('assigning a new module as next step supersedes the previously pending one', function () {
    $subject = moduleFixesSubject();
    $targetUser = User::factory()->create();

    $moduleA = Module::create(['subject_id' => $subject->id, 'name' => 'Module A', 'status' => 'ready', 'published' => true]);
    $moduleB = Module::create(['subject_id' => $subject->id, 'name' => 'Module B', 'status' => 'ready', 'published' => true]);

    $service = app(NextStepService::class);
    $firstStep = $service->assignModuleAsNextStep($moduleA, $targetUser->id);
    $secondStep = $service->assignModuleAsNextStep($moduleB, $targetUser->id);

    expect($firstStep->fresh()->status)->toBe(NextStepStatus::Superseded);
    expect($secondStep->status)->toBe(NextStepStatus::Pending);
    expect($secondStep->module_id)->toBe($moduleB->id);
});

// --- Email on assignment ----------------------------------------------------------------------

test('assigning a module queues an email to the target user with the module title and why text', function () {
    Mail::fake();

    $subject = moduleFixesSubject();
    $targetUser = User::factory()->create();
    completeDiagnosticFor($targetUser, $subject);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Special Module', 'status' => 'ready', 'published' => true]);

    $step = app(NextStepService::class)->assignModuleAsNextStep($module, $targetUser->id, 'This closes your biggest gap.');

    Mail::assertQueued(NextStepModuleAssigned::class, function ($mail) use ($targetUser, $step) {
        return $mail->hasTo($targetUser->email)
            && $mail->step->id === $step->id
            && $mail->step->instructions === 'This closes your biggest gap.';
    });
});

test('assigning without a why uses the generic fallback instructions in the emailed step', function () {
    Mail::fake();

    $subject = moduleFixesSubject();
    $targetUser = User::factory()->create();
    completeDiagnosticFor($targetUser, $subject);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Special Module', 'status' => 'ready', 'published' => true]);

    app(NextStepService::class)->assignModuleAsNextStep($module, $targetUser->id);

    Mail::assertQueued(NextStepModuleAssigned::class, function ($mail) use ($targetUser) {
        return $mail->hasTo($targetUser->email)
            && str_contains($mail->step->instructions, 'Recommended for you');
    });
});

test('testing an assignment on your own account first does not block or affect a later assignment to the real user', function () {
    Mail::fake();

    $subject = moduleFixesSubject();
    $admin = User::factory()->create(['is_admin' => true]);
    $realUser = User::factory()->create();
    completeDiagnosticFor($admin, $subject);
    completeDiagnosticFor($realUser, $subject);

    $module = Module::create(['subject_id' => $subject->id, 'name' => 'Special Module', 'status' => 'ready', 'published' => true]);

    $service = app(NextStepService::class);

    // Admin tests it on themselves first.
    $testStep = $service->assignModuleAsNextStep($module, $admin->id, 'testing');

    // Then assigns it to the real intended user.
    $realStep = $service->assignModuleAsNextStep($module, $realUser->id, 'For you specifically.');

    expect($testStep->fresh()->status)->toBe(NextStepStatus::Pending); // untouched by the second, unrelated-user assignment
    expect($realStep->status)->toBe(NextStepStatus::Pending);
    expect($realStep->user_id)->toBe($realUser->id);

    Mail::assertQueued(NextStepModuleAssigned::class, fn ($mail) => $mail->hasTo($admin->email));
    Mail::assertQueued(NextStepModuleAssigned::class, fn ($mail) => $mail->hasTo($realUser->email));
    Mail::assertQueued(NextStepModuleAssigned::class, 2);
});
