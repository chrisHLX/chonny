<?php

use App\Livewire\SubjectContextForm;
use App\Models\Category;
use App\Models\Subject;
use App\Models\SubjectContextDimension;
use App\Models\SubjectContextOption;
use App\Models\User;
use App\Models\UserSubjectContext;
use Livewire\Livewire;

function makeFormSubject(): Subject
{
    $category = Category::create(['name' => 'Games']);

    return Subject::create(['name' => 'World of Warcraft: The War Within', 'category_id' => $category->id]);
}

test('renders nothing for a subject with zero dimensions', function () {
    $subject = makeFormSubject();
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(SubjectContextForm::class, ['subjectId' => $subject->id]);

    expect($component->instance()->dimensions)->toBeEmpty();
    $component->assertDontSee('Tell us how you play');
});

test('spec select is empty (effectively disabled) until a class is selected, then filters to that class', function () {
    $subject = makeFormSubject();
    $class = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Class', 'slug' => 'class', 'order' => 0]);
    $spec  = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Spec', 'slug' => 'spec', 'order' => 1, 'parent_dimension_id' => $class->id]);

    $rogue = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $druid = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Druid', 'slug' => 'druid']);
    $assassination = SubjectContextOption::create(['dimension_id' => $spec->id, 'name' => 'Assassination', 'slug' => 'assassination', 'parent_option_id' => $rogue->id]);
    SubjectContextOption::create(['dimension_id' => $spec->id, 'name' => 'Balance', 'slug' => 'balance', 'parent_option_id' => $druid->id]);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(SubjectContextForm::class, ['subjectId' => $subject->id]);

    expect($component->instance()->optionsFor($spec))->toBeEmpty();

    $component->set("selections.{$class->id}", (string) $rogue->id);

    $specOptions = $component->instance()->optionsFor($spec);
    expect($specOptions->pluck('id')->all())->toBe([$assassination->id]);
});

test('changing Class clears an already-selected Spec value in the form', function () {
    $subject = makeFormSubject();
    $class = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Class', 'slug' => 'class', 'order' => 0]);
    $spec  = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Spec', 'slug' => 'spec', 'order' => 1, 'parent_dimension_id' => $class->id]);

    $rogue = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $mage  = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Mage', 'slug' => 'mage']);
    $assassination = SubjectContextOption::create(['dimension_id' => $spec->id, 'name' => 'Assassination', 'slug' => 'assassination', 'parent_option_id' => $rogue->id]);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(SubjectContextForm::class, ['subjectId' => $subject->id])
        ->set("selections.{$class->id}", (string) $rogue->id)
        ->set("selections.{$spec->id}", (string) $assassination->id);

    expect($component->get('selections')[$spec->id] ?? null)->toBe((string) $assassination->id);

    $component->set("selections.{$class->id}", (string) $mage->id);

    expect($component->get('selections')[$spec->id] ?? null)->toBeNull();
});

test('save persists a declaration per dimension via SubjectContextService', function () {
    $subject = makeFormSubject();
    $dimension = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race']);
    $zerg = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Zerg', 'slug' => 'zerg']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubjectContextForm::class, ['subjectId' => $subject->id])
        ->set("selections.{$dimension->id}", (string) $zerg->id)
        ->call('save')
        ->assertSet('saved', true);

    expect(UserSubjectContext::where('user_id', $user->id)->where('dimension_id', $dimension->id)->first()->subject_context_option_id)->toBe($zerg->id);
});

test('save rejects a missing required dimension with a validation error', function () {
    $subject = makeFormSubject();
    SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race', 'required' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubjectContextForm::class, ['subjectId' => $subject->id])
        ->call('save')
        ->assertHasErrors();
});

test('mount prefills existing declarations', function () {
    $subject = makeFormSubject();
    $dimension = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race']);
    $zerg = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Zerg', 'slug' => 'zerg']);
    $user = User::factory()->create();

    app(\App\Http\Services\SubjectContextService::class)->declare($user->id, $dimension->id, $zerg->id);

    Livewire::actingAs($user)
        ->test(SubjectContextForm::class, ['subjectId' => $subject->id])
        ->assertSet("selections.{$dimension->id}", (string) $zerg->id);
});
