<?php

it('serves the public front page at the site root', function () {
    // '/' redirected to the comp builder until 2026-09-16; it is now the front page
    // (App\Livewire\Landing). See LandingPageTest for the full contract.
    $this->get('/')->assertOk()->assertSee('Build a 3v3 comp');
});
