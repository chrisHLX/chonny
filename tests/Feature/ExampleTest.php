<?php

it('serves the MindCollector home at the site root, and the WoW home at /wow', function () {
    // '/' was the WoW front page (App\Livewire\Landing) from 2026-09-16 to 2026-10-17; it is now the
    // platform's home (PlatformController), and WoW's front page is /wow. See PlatformTest.
    $this->get('/')->assertOk()->assertSee('Understand your own games.');
    $this->get('/wow')->assertOk()->assertSee('Build a 3v3 comp');
});
