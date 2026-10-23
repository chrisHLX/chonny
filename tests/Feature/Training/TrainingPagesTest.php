<?php

use App\Models\User;
use App\Training\Curriculum;

test('training says what it is to a visitor, and the rest needs an account', function () {
    $this->get('/training')->assertOk()->assertSee('Learn to steer by evidence.');
    $this->get('/training/lessons/the-control-loop')->assertRedirect('/login');
    $this->get('/training/notebook')->assertRedirect('/login');
    $this->get('/training/read/steering-by-feedback')->assertRedirect('/login');
});

test('every subject and lesson page renders, through the real route', function () {
    $user = User::factory()->create();
    $curriculum = app(Curriculum::class);

    foreach ($curriculum->subjects() as $slug => $subject) {
        $this->actingAs($user)->get(route('training.subject', $slug))->assertOk()->assertSee($subject['title']);
    }
    foreach ($curriculum->lessons() as $slug => $lesson) {
        $this->actingAs($user)->get(route('training.lesson', $slug))
            ->assertOk()
            ->assertSee(e($lesson['title']), false)
            ->assertSee('data-block="case-0"', false);
    }
    $this->actingAs($user)->get('/training/lessons/no-such-lesson')->assertNotFound();
});

test('a lesson page never ships which answer is right', function () {
    $user = User::factory()->create();
    $lesson = app(Curriculum::class)->lesson('prediction-is-not-cause');
    $html = $this->actingAs($user)->get(route('training.lesson', 'prediction-is-not-cause'))->getContent();

    foreach ($lesson['case']['options'] as $option) {
        expect($html)->not->toContain(e($option['feedback']));
    }
});

test('the other training pages render', function () {
    $user = User::factory()->create();
    foreach (['/training', '/training/notebook', '/training/progress', '/training/claims', '/training/claims/new', '/training/check/A', '/training/check/B'] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
});

test('course feedback is for admins only', function () {
    $this->actingAs(User::factory()->create())->get('/training/course-feedback')->assertForbidden();

    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $this->actingAs($admin)->get('/training/course-feedback')->assertOk();
});

test('every training page is in the page-usage list (rule 28)', function () {
    $pages = (new ReflectionClassConstant(\App\Livewire\Admin\PageUsage::class, 'PAGES'))->getValue();
    foreach (['training_home', 'training_subject', 'training_lesson', 'training_notebook', 'training_progress', 'training_claims', 'training_claim_new', 'training_claim', 'training_check', 'training_feedback'] as $key) {
        expect($pages)->toHaveKey($key);
    }
});

test('every lesson question has exactly one right answer and only known concepts', function () {
    $curriculum = app(Curriculum::class);
    foreach ($curriculum->lessons() as $lesson) {
        foreach (['case', 'transfer'] as $ex) {
            expect(collect($lesson[$ex]['options'])->where('correct', true))->toHaveCount(1);
        }
        foreach ($lesson['concepts'] as $c) {
            expect($curriculum->concept($c))->not->toBeNull();
        }
    }
});

test('lesson text escapes html and allows only bold and italic', function () {
    expect(\App\Training\Prose::inline('<script>x</script> **bold** and *italic*'))
        ->toContain('&lt;script&gt;')->toContain('>bold</b>')->toContain('<i>italic</i>');
    expect(\App\Training\Prose::inline('3 × 4 * 2'))->toBe('3 × 4 * 2');
});

test('answer options are a fixed shuffle per question', function () {
    [$one, $from] = \App\Training\StableShuffle::apply(['a', 'b', 'c', 'd'], 'lesson/case');
    [$two] = \App\Training\StableShuffle::apply(['a', 'b', 'c', 'd'], 'lesson/case');
    expect($one)->toBe($two);
    foreach ($one as $pos => $item) {
        expect(['a', 'b', 'c', 'd'][$from[$pos]])->toBe($item);
    }
});

test('the reading pages render through their real routes, with the original material', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('training.reading', 'steering-by-feedback'))->assertOk()
        ->assertSee('Steering by Feedback')
        ->assertSee('κυβερνήτης', false)
        ->assertSee('data-sim="thermostat"', false)
        ->assertSee('data-sim="polarity"', false)
        ->assertSee('Shifting the burden')
        ->assertSee('The power to transcend paradigms')
        ->assertSee('data-block="m9-0"', false);
    $this->actingAs($user)->get(route('training.reading', 'what-to-learn'))->assertOk()
        ->assertSee('What to learn for the AI era')
        ->assertSee('Specifying and evaluating AI systems');
    $this->actingAs($user)->get('/training/read/nope')->assertNotFound();
});

test('the reading page is in the page-usage list', function () {
    $pages = (new ReflectionClassConstant(\App\Livewire\Admin\PageUsage::class, 'PAGES'))->getValue();
    expect($pages)->toHaveKey('training_reading');
});
