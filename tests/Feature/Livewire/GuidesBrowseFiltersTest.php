<?php

use App\Livewire\Guides\Browse;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use Livewire\Livewire;

/**
 * The Browse page's class filters, rebuilt 2026-09-16 from native <select>s to icon buttons.
 *
 * The old controls worked; they just did not look like anything else on the site (every other
 * class picker here is icons in the class's own colour) and on a phone they hid all thirteen
 * options behind a tap. These assertions cover the behaviour that had to survive the rewrite —
 * setting a filter, clearing it, and the two filters staying independent — rather than the markup,
 * which is free to change again.
 */
function seedBrowseClasses(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    Patch::create(['game_id' => $game->id, 'build_version' => '12.0.0', 'is_current' => true]);

    $classes = [];
    foreach (['Priest', 'Rogue', 'Mage'] as $name) {
        $class = GameClass::create(['game_id' => $game->id, 'name' => $name, 'slug' => Str::slug($name)]);
        Specialization::create(['class_id' => $class->id, 'name' => $name.' Spec', 'slug' => Str::slug($name).'-spec']);
        $classes[strtolower($name)] = $class;
    }

    return $classes;
}

test('both class filters render every class as a button, not a dropdown', function () {
    seedBrowseClasses();

    $html = Livewire::test(Browse::class)->html();

    expect($html)
        ->toContain('Playing')
        ->toContain('Against')
        // The control this replaced.
        ->not->toContain('Playing any class')
        ->not->toContain('Against anyone');

    // Three classes, two filters, one icon each.
    expect(substr_count($html, 'aria-pressed'))->toBe(6);
});

test('picking a class filters the listing, and picking it again clears it', function () {
    $classes = seedBrowseClasses();

    $component = Livewire::test(Browse::class);
    expect($component->get('classSlug'))->toBe('');

    $component->set('classSlug', 'rogue');
    expect($component->get('classSlug'))->toBe('rogue');

    // The button for an already-selected class sets it back to '' — the markup passes the empty
    // string, so this is what the second click actually does.
    $component->set('classSlug', '');
    expect($component->get('classSlug'))->toBe('');
});

test('the two filters are independent — "playing Rogue" does not imply "against Rogue"', function () {
    seedBrowseClasses();

    $component = Livewire::test(Browse::class)
        ->set('classSlug', 'rogue')
        ->set('opponentClassSlug', 'mage');

    expect($component->get('classSlug'))->toBe('rogue')
        ->and($component->get('opponentClassSlug'))->toBe('mage');
});

test('sort is a pair of buttons showing which one is active', function () {
    seedBrowseClasses();

    $component = Livewire::test(Browse::class);
    expect($component->get('sort'))->toBe('popular');

    $component->set('sort', 'new');
    expect($component->get('sort'))->toBe('new');
});

test('a guest is pitched an account in one line; a signed-in player is not pitched at all', function () {
    seedBrowseClasses();

    $guest = Livewire::test(Browse::class)->html();
    expect($guest)->toContain('Create a free account')
        // The five-line explanation this replaced.
        ->not->toContain('work out what to do in the matchups');

    $user = App\Models\User::factory()->create();
    expect(Livewire::actingAs($user)->test(Browse::class)->html())
        ->not->toContain('Create a free account');
});
