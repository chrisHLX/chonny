<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every admin page, and every page about a player's own games, serves its real URL.
 *
 * Added with the 2026-10-20 mobile pass, which wrapped several wide tables in a scrolling div and so
 * changed the HTML nesting inside Blade conditionals. Livewire::test() renders a component without
 * its layout and would pass either way, which is how the Matchup Lab once shipped green tests and a
 * 500 (CLAUDE.md, "Looking at a page"); this asks for the route instead. It is a cheap guard against
 * that whole class of error, not a test of anything either page says.
 */
class MobileLayoutSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_page_and_match_page_still_serves(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $routes = [
            'admin.overview', 'admin.page-usage', 'admin.search-pages', 'admin.matches',
            'admin.diagnostic-stats', 'admin.api-usage', 'admin.weak-areas',
            'admin.talent-builds', 'admin.content', 'admin.game-data', 'admin.logs',
            'coach', 'game-review', 'game-review.analysis', 'wow.sample',
        ];

        foreach ($routes as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk($name.' did not serve');
        }

        // The four committed sample pages, which /wow/sample frames for anyone.
        foreach (['game', 'shuffle', 'improve', 'comp'] as $tab) {
            $this->get('/wow/sample/page/'.$tab)->assertOk($tab.' did not serve');
        }
    }
}
