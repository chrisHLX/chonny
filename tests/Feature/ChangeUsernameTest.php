<?php

use App\Models\PreviousUsername;
use App\Models\User;
use App\Models\UserGuide;

test('a player can change their handle, and it is normalised', function () {
    $user = User::factory()->create(['username' => 'oldname']);

    $this->actingAs($user)
        ->patch('/profile/handle', ['username' => '@NewName'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    expect($user->fresh()->username)->toBe('newname');
    expect(PreviousUsername::where('username', 'oldname')->value('user_id'))->toBe($user->id);
});

test('a handle already in use, reserved, or badly formed is refused', function (string $handle) {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create(['username' => 'mine']);

    $this->actingAs($user)
        ->patch('/profile/handle', ['username' => $handle])
        ->assertSessionHasErrors('username');

    expect($user->fresh()->username)->toBe('mine');
})->with(['taken', 'TAKEN', 'admin', 'ab', 'has space', '-dash', 'bad!', str_repeat('a', 31)]);

test('an old handle stays reserved to its owner, who can take it back', function () {
    $alice = User::factory()->create(['username' => 'alice']);
    $alice->changeUsername('alice2');

    $bob = User::factory()->create(['username' => 'bob']);
    $this->actingAs($bob)->patch('/profile/handle', ['username' => 'alice'])->assertSessionHasErrors('username');

    $this->actingAs($alice)->patch('/profile/handle', ['username' => 'alice'])->assertSessionHasNoErrors();
    expect($alice->fresh()->username)->toBe('alice');
    expect(PreviousUsername::where('username', 'alice')->exists())->toBeFalse();
    expect(PreviousUsername::where('username', 'alice2')->exists())->toBeTrue();
});

test('a guide link using an old handle redirects to the current one', function () {
    $user = User::factory()->create(['username' => 'before']);
    $guide = UserGuide::create(['user_id' => $user->id, 'title' => 'RMP opener']);

    $user->changeUsername('after');

    $this->get("/g/before/{$guide->slug}")
        ->assertRedirect(route('guides.show', ['username' => 'after', 'guide' => $guide->slug]))
        ->assertStatus(301);

    $this->get("/g/nobody/{$guide->slug}")->assertNotFound();
});

test('saving name and email does not need or touch the handle', function () {
    $user = User::factory()->create(['username' => 'keepme']);

    $this->actingAs($user)->patch('/profile', ['name' => 'X', 'email' => 'x@example.com'])->assertSessionHasNoErrors();

    expect($user->fresh()->username)->toBe('keepme');
});
