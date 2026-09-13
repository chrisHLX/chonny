<?php

use App\Livewire\WowComps;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Specialization;
use App\Support\LivewireTampering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The scanner traffic that filled live's error log on 2026-09-12: a real snapshot taken from the
 * homepage, posted back to /livewire/update with junk written into its properties.
 *
 * These go over real HTTP on purpose. Livewire::test() never touches the update route, so it can
 * neither reproduce the attack nor exercise the handler that answers it.
 */
function homepageSnapshot($test): string
{
    $html = $test->get('/')->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
    expect($m)->not->toBeEmpty();

    return html_entity_decode($m[1], ENT_QUOTES);
}

function livewireUpdate($test, string $snapshot, array $updates = [], array $calls = [])
{
    return $test->withHeaders(['X-Livewire' => '1'])->postJson('/livewire/update', [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) $updates, 'calls' => $calls]],
    ]);
}

beforeEach(function () {
    Log::spy();
});

test('writing junk into the comp slots is refused with a 419, not a 500', function () {
    $response = livewireUpdate($this, homepageSnapshot($this), ['slots' => [1, 2, 3]]);

    $response->assertStatus(419);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'tampered Livewire'))->once();
    Log::shouldNotHaveReceived('error');
});

test('writing an array into a true/false flag is refused with a 419, not a 500', function () {
    livewireUpdate($this, homepageSnapshot($this), ['rotationTabLoaded' => ['x' => 1]])->assertStatus(419);
});

test('a snapshot that is not even JSON is refused with a 419, not a 500', function () {
    livewireUpdate($this, 'not-a-snapshot')->assertStatus(419);
});

test('a snapshot whose data was edited fails its checksum and is refused', function () {
    $snapshot = json_decode(homepageSnapshot($this), true);
    $snapshot['data']['rotationTabLoaded'] = true;

    livewireUpdate($this, json_encode($snapshot))->assertStatus(419);
});

test('the page still works normally through its own actions', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $priest = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $disc = Specialization::create(['class_id' => $priest->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    $response = livewireUpdate($this, homepageSnapshot($this), [], [
        ['path' => '', 'method' => 'loadRotationTab', 'params' => []],
        ['path' => '', 'method' => 'selectSpec', 'params' => [0, $priest->id, $disc->id]],
    ]);

    $response->assertOk();
    $data = json_decode($response->json('components.0.snapshot'), true)['data'];
    expect($data['rotationTabLoaded'])->toBeTrue();
});

test('selectSpec ignores a slot that does not exist and a spec from another class', function () {
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $priest = GameClass::create(['game_id' => $game->id, 'name' => 'Priest', 'slug' => 'priest']);
    $warrior = GameClass::create(['game_id' => $game->id, 'name' => 'Warrior', 'slug' => 'warrior']);
    $disc = Specialization::create(['class_id' => $priest->id, 'name' => 'Discipline', 'slug' => 'discipline']);

    $component = Livewire::test(WowComps::class)
        ->call('selectSpec', 7, $priest->id, $disc->id)
        ->call('selectSpec', 0, $warrior->id, $disc->id);

    expect($component->get('slots'))->toHaveCount(3)
        ->and($component->get('slots.0.classId'))->toBeNull();

    $component->call('selectSpec', 0, $priest->id, $disc->id);
    expect($component->get('slots.0.specId'))->toBe($disc->id);
});

test('the slots cannot be written from the browser', function () {
    Livewire::test(WowComps::class)->set('slots', [1, 2, 3]);
})->throws(CannotUpdateLockedPropertyException::class);

test('only failures on the Livewire update route are treated as tampering', function () {
    // Off the update route, even a locked-property violation stays a real, loud error.
    expect(LivewireTampering::matches(new CannotUpdateLockedPropertyException('slots'), Request::create('/wow-comps')))
        ->toBeFalse();
});

test('an error thrown by our own code is never mistaken for tampering', function () {
    // Raised in this file, i.e. outside Livewire's hydration code: a real bug must stay a 500.
    $request = Request::create('/livewire/update', 'POST');
    $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));

    expect(LivewireTampering::matches(new TypeError('boom'), $request))->toBeFalse()
        ->and(LivewireTampering::matches(new CannotUpdateLockedPropertyException('slots'), $request))->toBeTrue();
});
