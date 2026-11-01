<?php

use App\Models\User;

/**
 * Signing in and out is one tap from any page (2026-11-01). On a phone both used to sit behind the
 * menu, sign out a popup further in, and the sign-up page's way to sign in was small print.
 */
test('a guest can reach sign in and sign up from the page they are on', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('login'), false)
        ->assertSee(route('register'), false)
        ->assertSee('Sign in');
});

test('a signed-in player can sign out without opening a menu', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('action="'.route('logout').'"', false)
        ->assertSee('Sign out');
});

test('the sign-up page leads straight to sign in', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Already have an account?')
        ->assertSee(route('login'), false);
});
