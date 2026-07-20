<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\Subject;
use App\Models\User;

function moduleShowGuestSubject(): Subject
{
    $category = Category::create(['name' => 'Games '.uniqid()]);

    return Subject::create(['name' => 'Test Subject '.uniqid(), 'category_id' => $category->id]);
}

test('a guest visiting an unpublished module is redirected to login, not 404d', function () {
    $subject = moduleShowGuestSubject();
    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Unpublished Module',
        'status'     => 'ready',
        'published'  => false,
    ]);

    $response = $this->get(route('modules.show', $module));

    $response->assertRedirect(route('login'));
});

test('the intended URL is remembered so login sends the guest back to the module', function () {
    $subject = moduleShowGuestSubject();
    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Unpublished Module',
        'status'     => 'ready',
        'published'  => false,
    ]);

    $this->get(route('modules.show', $module));

    expect(session('url.intended'))->toBe(route('modules.show', $module));
});

test('a guest can still view a published module without being redirected', function () {
    $subject = moduleShowGuestSubject();
    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Published Module',
        'status'     => 'ready',
        'published'  => true,
    ]);

    $this->get(route('modules.show', $module))->assertOk();
});

test('a logged-in user can view an unpublished module without being redirected', function () {
    $subject = moduleShowGuestSubject();
    $user = User::factory()->create();
    $module = Module::create([
        'subject_id' => $subject->id,
        'name'       => 'Unpublished Module',
        'status'     => 'ready',
        'published'  => false,
    ]);

    $this->actingAs($user)->get(route('modules.show', $module))->assertOk();
});
