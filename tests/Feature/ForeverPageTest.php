<?php

namespace Tests\Feature;

use App\Models\PageViewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app's WoW Forever page (/wow/forever, 2026-11-12): it serves through the real route and
 * layout, is counted (rule 28), and the app page leads to it.
 */
class ForeverPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forever_page_serves_and_is_counted(): void
    {
        $this->get('/wow/forever')->assertOk()
            ->assertSee('Crafting, Prices and PvP in WoW Forever')
            ->assertSee('Share my auction house scans')
            ->assertSee('Set up OBS');

        $this->assertSame(1, PageViewEvent::where('page', 'forever_page')->count());
    }

    public function test_both_home_pages_and_the_menu_lead_to_it(): void
    {
        // the site's home (with the menu) and WoW's home (2026-11-12: "reachable from the home page")
        $home = $this->get('/')->assertOk()->assertSee('WoW Forever: crafting, prices and PvP')->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($home, route('wow.forever')), 'the card and the menu');
        $this->get('/wow')->assertOk()->assertSee('Playing WoW Forever?')->assertSee(route('wow.forever'), false);
    }

    public function test_the_app_page_leads_to_it_and_says_what_forever_shares(): void
    {
        $this->get('/wow/app')->assertOk()
            ->assertSee(route('wow.forever'), false)
            ->assertSee('shares your auction house scans');
    }
}
