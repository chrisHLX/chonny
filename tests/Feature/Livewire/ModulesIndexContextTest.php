<?php

use App\Livewire\Modules\Index;
use App\Models\Category;
use App\Models\Subject;
use Livewire\Livewire;

/**
 * /modules resolving its category context.
 *
 * `categoryId` is bound to the query string, so its value is whatever the URL says. Until
 * 2026-09-16 an id matching no row was loaded as null and handed straight to a view reading
 * `->name` on it — a 500 anyone could trigger with ?category_id=999999 on a page the nav links to.
 * Production logged 24 of them on 2026-09-15.
 */
test('an unknown category id falls back to a real one instead of throwing', function () {
    $real = Category::create(['name' => 'Games']);
    Subject::create(['category_id' => $real->id, 'name' => 'World of Warcraft']);

    $component = Livewire::test(Index::class, ['categoryId' => 999999]);

    expect($component->get('categoryId'))->toBe($real->id);
    $component->assertOk()->assertSee('Games');
});

test('no category id at all still resolves, and the page renders', function () {
    $real = Category::create(['name' => 'Games']);
    Subject::create(['category_id' => $real->id, 'name' => 'World of Warcraft']);

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('Games');
});

test('an empty database renders a heading rather than a 500', function () {
    Livewire::test(Index::class)->assertOk();
});

test('a subject from another category is not carried over by a bad category id', function () {
    $games = Category::create(['name' => 'Games']);
    $wow = Subject::create(['category_id' => $games->id, 'name' => 'World of Warcraft']);

    $arts = Category::create(['name' => 'Arts']);
    $music = Subject::create(['category_id' => $arts->id, 'name' => 'Music']);

    // Session holds a subject belonging to a DIFFERENT category than the one that resolves.
    session(['context.subject_id' => $music->id]);

    $component = Livewire::test(Index::class, ['categoryId' => 999999]);

    // Falls back to the first category, so the subject must fall back with it rather than scoping
    // every query to a subject that is not in the resolved category.
    expect($component->get('categoryId'))->toBe($games->id)
        ->and($component->get('currentSubjectId'))->toBe($wow->id);
});
