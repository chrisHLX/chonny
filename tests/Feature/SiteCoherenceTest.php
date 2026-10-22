<?php

use App\Http\Controllers\SampleGameController;
use App\Models\PageViewEvent;

/**
 * The 2026-10-18 pass on how the site reads as one product: a sample game anyone can open, "Your
 * games" sending a visitor there rather than to a bare sign-in box, /wow as the WoW hub, the grouped
 * menu, League of Legends unlisted, and the old cross-game diagnostic retired.
 */
it('shows a real game with the names hidden to anyone', function () {
    $this->get('/wow/sample')->assertOk()->assertSee('Post arena match')->assertSee('3v3 Post-Match Analysis')->assertSee('Sign in to see yours');
    expect(PageViewEvent::where('page', 'wow_sample')->count())->toBe(1);

    foreach (array_keys(SampleGameController::PAGES) as $tab) {
        $html = $this->get("/wow/sample/page/{$tab}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->getContent();
        // Every name was swapped for a label: the player's own characters never appear.
        expect($html)->not->toContain('Skylake')->not->toContain('Crawlordx')->not->toContain('Frostmourne');
    }
    $this->get('/wow/sample/page/secrets')->assertNotFound();
});

it('sends a signed-out visitor from Your games to the sample, not a sign-in box', function () {
    $this->get('/wow/coach')->assertRedirect(route('wow.sample'));
    $this->actingAs(\App\Models\User::factory()->create())->get('/wow/coach')->assertOk()->assertSee('Your games');
});

it('makes /wow a hub that sends people to the sample and the app, not a copy of the app page', function () {
    $html = $this->get('/wow')->assertOk()->getContent();
    expect($html)->toContain(route('wow.sample'))->toContain('Learn the game')->toContain('Plan your games')
        ->not->toContain('Set up in a minute')->not->toContain('what to work on next');
});

it('groups the menu and keeps the lab apart', function () {
    $html = $this->get('/wow/comps')->assertOk()->getContent();
    foreach (['>Plan<', '>Guides<', '>Learn<', '>Lab<', 'Player guides', "Claude's comp guides", 'Class quizzes'] as $needle) {
        expect($html)->toContain($needle);
    }
    expect($html)->not->toContain('Your library');   // a visitor has no library
});

it('lists League of Legends only on its own page and retires the old diagnostic picker', function () {
    expect($this->get('/')->getContent())->not->toContain('League of Legends');
    $this->get('/lol')->assertOk()->assertSee('League of Legends');
    $this->get('/diagnostic')->assertRedirect('/');
});
