<?php

namespace Tests\Feature;

use App\Models\GameInterest;
use App\Models\PageViewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** MindCollector as a platform (2026-10-17): its home, WoW's home, and the games that are coming. */
class PlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_says_what_mindcollector_is_and_offers_every_game(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Understand your own games.', $html);
        foreach (['World of Warcraft', 'StarCraft II'] as $game) {
            $this->assertStringContainsString($game, $html);
        }
        // League of Legends is unlisted until it has something to open (config/platform.php).
        $this->assertStringNotContainsString('League of Legends', $html);
        $this->assertStringContainsString('href="'.route('wow.home').'"', $html);
        $this->assertStringContainsString('href="'.route('sc2').'"', $html);
        $this->assertSame(1, PageViewEvent::where('page', 'platform_home')->count());

        // Signed in, the home is still the home: a player picks their game from it.
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('Pick your game');
    }

    public function test_a_coming_game_has_its_own_page_and_menu_without_wows(): void
    {
        $html = $this->get('/sc2')->assertOk()->getContent();

        $this->assertStringContainsString('StarCraft II', $html);
        $this->assertStringContainsString('Replay reviews', $html);
        $this->assertStringContainsString('Tell me when it', $html);
        // The sidebar shows the SC2 menu and none of WoW's.
        $this->assertStringContainsString('SC2 home', $html);
        $this->assertStringNotContainsString('Matchup Lab', $html);
        $this->assertSame(1, PageViewEvent::where('page', 'sc2_home')->count());

        $this->get('/lol')->assertOk()->assertSee('League of Legends');
        // And WoW's pages keep WoW's menu.
        $this->get('/wow/app')->assertOk()->assertSee('Matchup Lab');
    }

    public function test_a_visitor_or_a_player_can_ask_to_be_told_when_a_game_opens(): void
    {
        $this->post('/games/sc2/notify', ['email' => 'Fan@Example.com'])->assertRedirect()->assertSessionHas('interest_status');
        $this->post('/games/sc2/notify', ['email' => 'fan@example.com'])->assertRedirect();   // asked twice, kept once
        $this->assertSame(1, GameInterest::where('game', 'sc2')->where('email', 'fan@example.com')->count());

        $this->post('/games/sc2/notify', ['email' => 'not an email'])->assertSessionHasErrors('email');

        $user = User::factory()->create();
        $this->actingAs($user)->post('/games/lol/notify')->assertRedirect();
        $this->assertTrue(GameInterest::where('game', 'lol')->where('user_id', $user->id)->exists());
        $this->actingAs($user)->get('/lol')->assertSee('We will let you know when League of Legends');   // the thanks, once
        $this->actingAs($user)->get('/lol')->assertSee('on the list')->assertDontSee('Tell me when it opens');

        // A form filled in by a script (the hidden field) is thanked and kept nowhere.
        auth()->logout();
        $this->post('/games/sc2/notify', ['email' => 'bot@example.com', 'website' => 'spam'])->assertRedirect();
        $this->assertFalse(GameInterest::where('email', 'bot@example.com')->exists());

        // WoW is open, and an unknown game is no page at all.
        $this->post('/games/wow/notify', ['email' => 'x@example.com'])->assertNotFound();
    }
}
