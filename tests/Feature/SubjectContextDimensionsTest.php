<?php

use App\Http\Services\SubjectContextService;
use App\Models\Category;
use App\Models\Subject;
use App\Models\SubjectContextDimension;
use App\Models\SubjectContextOption;
use App\Models\User;
use App\Models\UserSubjectContext;
use Database\Seeders\SubjectContextSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Database\QueryException;

function makeContextSubject(string $name = 'World of Warcraft: The War Within'): Subject
{
    $category = Category::create(['name' => 'Games']);

    return Subject::create(['name' => $name, 'category_id' => $category->id]);
}

// --- Models / relationships / unique constraints -------------------------------------------

test('dimension and option relationships resolve, including self-referencing hierarchy', function () {
    $subject = makeContextSubject();

    $class = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Class', 'slug' => 'class', 'order' => 0]);
    $spec  = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Spec', 'slug' => 'spec', 'order' => 1, 'parent_dimension_id' => $class->id]);

    $rogue        = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $assassination = SubjectContextOption::create(['dimension_id' => $spec->id, 'name' => 'Assassination', 'slug' => 'assassination', 'parent_option_id' => $rogue->id]);

    expect($spec->parentDimension->id)->toBe($class->id);
    expect($class->childDimensions->pluck('id')->all())->toBe([$spec->id]);
    expect($assassination->parentOption->id)->toBe($rogue->id);
    expect($rogue->childOptions->pluck('id')->all())->toBe([$assassination->id]);
    expect($subject->contextDimensions->pluck('id')->sort()->values()->all())->toBe([$class->id, $spec->id]);
    expect($rogue->depth())->toBe(0);
    expect($assassination->depth())->toBe(1);
});

test('a module with no context tags is related but empty, and gets tags via the pivot', function () {
    $subject = makeContextSubject();
    $dimension = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race']);
    $option = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Zerg', 'slug' => 'zerg']);
    $module = \App\Models\Module::create(['subject_id' => $subject->id, 'name' => 'Zerg Basics', 'type' => 'content']);

    expect($module->contextOptions)->toHaveCount(0);

    $module->contextOptions()->attach($option->id);

    expect($module->fresh()->contextOptions->pluck('id')->all())->toBe([$option->id]);
    expect($option->modules->pluck('id')->all())->toBe([$module->id]);
});

test('unique(subject_id, slug) rejects a duplicate dimension slug for the same subject', function () {
    $subject = makeContextSubject();
    SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race']);

    expect(fn () => SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race Again', 'slug' => 'race']))
        ->toThrow(QueryException::class);
});

test('unique(user_id, dimension_id) rejects a second declaration for the same dimension', function () {
    $subject = makeContextSubject();
    $dimension = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race']);
    $terran = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Terran', 'slug' => 'terran']);
    $zerg   = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Zerg', 'slug' => 'zerg']);
    $user = User::factory()->create();

    UserSubjectContext::create(['user_id' => $user->id, 'dimension_id' => $dimension->id, 'subject_context_option_id' => $terran->id]);

    expect(fn () => UserSubjectContext::create(['user_id' => $user->id, 'dimension_id' => $dimension->id, 'subject_context_option_id' => $zerg->id]))
        ->toThrow(QueryException::class);
});

// --- Seeder ---------------------------------------------------------------------------------

test('SubjectContextSeeder is idempotent and correctly parents WoW spec options to their class', function () {
    // $this->seed() (not `new X()->run()`) — SubjectSeeder calls $this->command->info(...)
    // without a null-safe operator, which throws "Call to a member function info() on null"
    // when a seeder is instantiated directly outside a real Artisan context. $this->seed()
    // runs it through the console kernel, giving it a proper command binding like the real
    // DatabaseSeeder chain has. Also confirms SubjectSeeder looks up categories via
    // Category::where(...)->first() rather than creating them — it assumes CategorySeeder
    // already ran, same as the real DatabaseSeeder chain does.
    $this->seed(\Database\Seeders\CategorySeeder::class);
    $this->seed(SubjectSeeder::class);
    $this->seed(SubjectContextSeeder::class);

    $countAfterFirstRun = SubjectContextOption::count();

    $this->seed(SubjectContextSeeder::class);

    expect(SubjectContextOption::count())->toBe($countAfterFirstRun);

    $wow = Subject::where('name', 'World of Warcraft: The War Within')->firstOrFail();
    $spec = SubjectContextDimension::where('subject_id', $wow->id)->where('slug', 'spec')->firstOrFail();
    $class = SubjectContextDimension::where('subject_id', $wow->id)->where('slug', 'class')->firstOrFail();
    $rogue = SubjectContextOption::where('dimension_id', $class->id)->where('slug', 'rogue')->firstOrFail();

    $assassination = SubjectContextOption::where('dimension_id', $spec->id)->where('slug', 'assassination')->firstOrFail();
    expect($assassination->parent_option_id)->toBe($rogue->id);

    $sc2 = Subject::where('name', 'StarCraft 2')->firstOrFail();
    expect(SubjectContextDimension::where('subject_id', $sc2->id)->count())->toBe(1);
    expect(SubjectContextOption::whereIn('dimension_id', SubjectContextDimension::where('subject_id', $sc2->id)->pluck('id'))->count())->toBe(3);

    // Poker deliberately gets zero dimensions.
    $poker = Subject::where('name', 'Poker')->first();
    if ($poker) {
        expect(SubjectContextDimension::where('subject_id', $poker->id)->count())->toBe(0);
    }
});

// --- Declaration service ---------------------------------------------------------------------

test('declare() writes a new declaration and hasDeclaredAllRequiredDimensions() reflects it', function () {
    $subject = makeContextSubject();
    $dimension = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race', 'required' => true]);
    $zerg = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Zerg', 'slug' => 'zerg']);
    $user = User::factory()->create();

    $service = app(SubjectContextService::class);

    expect($service->hasDeclaredAllRequiredDimensions($user->id, $subject->id))->toBeFalse();

    $service->declare($user->id, $dimension->id, $zerg->id);

    expect($service->hasDeclaredAllRequiredDimensions($user->id, $subject->id))->toBeTrue();
    expect(UserSubjectContext::where('user_id', $user->id)->where('dimension_id', $dimension->id)->first()->subject_context_option_id)->toBe($zerg->id);
});

test('declare() replaces a prior declaration for the same dimension rather than creating a second row', function () {
    $subject = makeContextSubject();
    $dimension = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race']);
    $terran = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Terran', 'slug' => 'terran']);
    $zerg   = SubjectContextOption::create(['dimension_id' => $dimension->id, 'name' => 'Zerg', 'slug' => 'zerg']);
    $user = User::factory()->create();

    $service = app(SubjectContextService::class);
    $service->declare($user->id, $dimension->id, $terran->id);
    $service->declare($user->id, $dimension->id, $zerg->id);

    expect(UserSubjectContext::where('user_id', $user->id)->where('dimension_id', $dimension->id)->count())->toBe(1);
    expect(UserSubjectContext::where('user_id', $user->id)->where('dimension_id', $dimension->id)->first()->subject_context_option_id)->toBe($zerg->id);
});

test('declaring a new option for Class clears the existing Spec declaration', function () {
    $subject = makeContextSubject();
    $class = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Class', 'slug' => 'class']);
    $spec  = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Spec', 'slug' => 'spec', 'parent_dimension_id' => $class->id]);

    $rogue = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $mage  = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Mage', 'slug' => 'mage']);
    $assassination = SubjectContextOption::create(['dimension_id' => $spec->id, 'name' => 'Assassination', 'slug' => 'assassination', 'parent_option_id' => $rogue->id]);

    $user = User::factory()->create();
    $service = app(SubjectContextService::class);

    $service->declare($user->id, $class->id, $rogue->id);
    $service->declare($user->id, $spec->id, $assassination->id);

    expect(UserSubjectContext::where('user_id', $user->id)->where('dimension_id', $spec->id)->exists())->toBeTrue();

    // Switching Class to Mage should clear the now-meaningless Rogue-parented Spec declaration.
    $service->declare($user->id, $class->id, $mage->id);

    expect(UserSubjectContext::where('user_id', $user->id)->where('dimension_id', $spec->id)->exists())->toBeFalse();
    expect(UserSubjectContext::where('user_id', $user->id)->where('dimension_id', $class->id)->first()->subject_context_option_id)->toBe($mage->id);
});

test('a subject with zero dimensions stores no declarations and never reports all-required-declared', function () {
    $subject = makeContextSubject('Poker');
    $user = User::factory()->create();

    $service = app(SubjectContextService::class);

    expect($service->hasDeclaredAllRequiredDimensions($user->id, $subject->id))->toBeFalse();
    expect($service->declarationsForSubject($user->id, $subject->id))->toBeEmpty();
});

test('declare() rejects an option that does not belong to the given dimension', function () {
    $subject = makeContextSubject();
    $race = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Race', 'slug' => 'race']);
    $role = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Role', 'slug' => 'role']);
    $zerg = SubjectContextOption::create(['dimension_id' => $race->id, 'name' => 'Zerg', 'slug' => 'zerg']);
    $user = User::factory()->create();

    expect(fn () => app(SubjectContextService::class)->declare($user->id, $role->id, $zerg->id))
        ->toThrow(InvalidArgumentException::class);
});

test('hasDeclaredAllRequiredDimensions is true only once every required dimension is declared, not just some', function () {
    $subject = makeContextSubject();
    $class = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Class', 'slug' => 'class', 'required' => true]);
    $spec  = SubjectContextDimension::create(['subject_id' => $subject->id, 'name' => 'Spec', 'slug' => 'spec', 'required' => true, 'parent_dimension_id' => $class->id]);
    $rogue = SubjectContextOption::create(['dimension_id' => $class->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $assassination = SubjectContextOption::create(['dimension_id' => $spec->id, 'name' => 'Assassination', 'slug' => 'assassination', 'parent_option_id' => $rogue->id]);
    $user = User::factory()->create();

    $service = app(SubjectContextService::class);
    $service->declare($user->id, $class->id, $rogue->id);

    expect($service->hasDeclaredAllRequiredDimensions($user->id, $subject->id))->toBeFalse();

    $service->declare($user->id, $spec->id, $assassination->id);

    expect($service->hasDeclaredAllRequiredDimensions($user->id, $subject->id))->toBeTrue();
});
