<?php

use App\Models\Category;
use App\Models\Concept;
use App\Models\Module;
use App\Models\ModulePage;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\UploadedFile;

function uploadFixtureSubject(): Subject
{
    $category = Category::create(['name' => 'Games '.uniqid()]);
    $subject = Subject::create(['name' => 'Test Subject '.uniqid(), 'category_id' => $category->id]);
    Proficiency::create(['subject_id' => $subject->id, 'name' => 'Beginner']);

    return $subject;
}

function uploadContentFile(string $title, string $subjectName, string $proficiencyName = 'Beginner', string $body = "# Body\n\nSome content."): UploadedFile
{
    $raw = <<<MD
---
title: "{$title}"
subject: "{$subjectName}"
proficiency: "{$proficiencyName}"
---

{$body}
MD;

    return UploadedFile::fake()->createWithContent('content.md', $raw);
}

function uploadQuestionsFile(array $questions): UploadedFile
{
    $raw = \Symfony\Component\Yaml\Yaml::dump(['questions' => $questions], 6, 2);

    return UploadedFile::fake()->createWithContent('questions.yaml', $raw);
}

test('a valid upload creates the module, page, and questions', function () {
    $subject = uploadFixtureSubject();
    $concept = Concept::create(['subject_id' => $subject->id, 'name' => 'Role Fundamentals']);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Arena Fundamentals', $subject->name),
        'questions_file' => uploadQuestionsFile([
            [
                'type' => 'true_false',
                'question' => 'Is this a real question?',
                'concepts' => ['Role Fundamentals'],
                'answer' => ['correct' => true],
            ],
        ]),
    ]);

    $response->assertRedirect();

    $module = Module::where('name', 'Arena Fundamentals')->where('subject_id', $subject->id)->first();
    expect($module)->not->toBeNull();
    expect((bool) $module->published)->toBeFalse();
    expect($module->status)->toBe('ready');
    expect($module->created_by)->toBe($user->id);
    expect($module->proficiencies()->pluck('name')->all())->toBe(['Beginner']);

    $page = ModulePage::where('module_id', $module->id)->where('page_number', 1)->first();
    expect($page->content)->toContain('Some content.');

    expect($module->questions)->toHaveCount(1);
    $question = $module->questions->first();
    expect($question->answer)->toBe(['correct' => true]);
    expect($question->concepts->pluck('name')->all())->toBe(['Role Fundamentals']);
});

test('uploading with no questions file creates a knowledge-only module with zero questions', function () {
    $subject = uploadFixtureSubject();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Knowledge Only', $subject->name),
    ])->assertRedirect();

    $module = Module::where('name', 'Knowledge Only')->firstOrFail();
    expect($module->questions)->toHaveCount(0);
    expect($module->status)->toBe('need questions');
});

test('re-uploading the same title+subject updates the module in place, not a duplicate', function () {
    $subject = uploadFixtureSubject();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Repeatable Module', $subject->name, 'Beginner', '# V1'),
    ]);

    $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Repeatable Module', $subject->name, 'Beginner', '# V2 updated'),
    ]);

    expect(Module::where('name', 'Repeatable Module')->count())->toBe(1);
    $module = Module::where('name', 'Repeatable Module')->firstOrFail();
    $page = ModulePage::where('module_id', $module->id)->where('page_number', 1)->first();
    expect($page->content)->toContain('V2 updated');
});

test('an unknown subject name rejects the whole upload and creates nothing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Ghost Module', 'Nonexistent Subject Name'),
    ]);

    $response->assertSessionHasErrors('upload');
    expect(Module::where('name', 'Ghost Module')->exists())->toBeFalse();
});

test('an unknown proficiency name rejects the whole upload', function () {
    $subject = uploadFixtureSubject();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Ghost Module', $subject->name, 'Nonexistent Tier'),
    ]);

    $response->assertSessionHasErrors('upload');
    expect(Module::where('name', 'Ghost Module')->exists())->toBeFalse();
});

test('an unknown concept name on a question rejects the whole upload, nothing partially created', function () {
    $subject = uploadFixtureSubject();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Ghost Module', $subject->name),
        'questions_file' => uploadQuestionsFile([
            [
                'type' => 'true_false',
                'question' => 'Some question?',
                'concepts' => ['Does Not Exist'],
                'answer' => ['correct' => true],
            ],
        ]),
    ]);

    $response->assertSessionHasErrors('upload');
    expect(Module::where('name', 'Ghost Module')->exists())->toBeFalse();
    expect(Question::where('question', 'Some question?')->exists())->toBeFalse();
});

test('an unrecognised question type rejects the whole upload', function () {
    $subject = uploadFixtureSubject();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('modules.upload.store'), [
        'content_file' => uploadContentFile('Ghost Module', $subject->name),
        'questions_file' => uploadQuestionsFile([
            [
                'type' => 'not_a_real_type',
                'question' => 'Some question?',
                'answer' => ['correct' => true],
            ],
        ]),
    ]);

    $response->assertSessionHasErrors('upload');
    expect(Module::where('name', 'Ghost Module')->exists())->toBeFalse();
});
