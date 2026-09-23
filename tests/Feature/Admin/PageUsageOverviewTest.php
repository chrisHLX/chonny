<?php

namespace Tests\Feature\Admin;

use App\Models\PageViewEvent;
use App\Models\User;
use App\Support\BotDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bot classification, and the usage page that depends on it.
 *
 * WHY THIS MATTERS. A full-page Livewire component renders server-side on a plain GET, so a
 * crawler fetching /wow/comps logs a page view exactly as a person does. Measured against
 * nginx's own logs on 2026-09-24, 3,559 of 7,766 served pages over a fortnight were
 * self-declared bots — about half of every number this page showed.
 */
class PageUsageOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recognises_the_crawlers_that_were_actually_hitting_the_site(): void
    {
        // Every one of these was in the production logs.
        foreach ([
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1)',
            'Mozilla/5.0 (compatible; ClaudeBot/1.0)',
            'Mozilla/5.0 (compatible; SemrushBot/7~bl)',
            'Twitterbot/1.0',
            'curl/8.5.0',
            'python-requests/2.31.0',
            'Go-http-client/2.0',
        ] as $agent) {
            $this->assertTrue(BotDetector::isBot($agent), "{$agent} should be a bot.");
        }
    }

    public function test_a_missing_user_agent_counts_as_a_bot(): void
    {
        // Every real browser sends one. A request without it is a script.
        $this->assertTrue(BotDetector::isBot(null));
        $this->assertTrue(BotDetector::isBot(''));
        $this->assertTrue(BotDetector::isBot('-'));
    }

    public function test_real_browsers_are_not_flagged(): void
    {
        foreach ([
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Gecko/20100101 Firefox/130.0',
        ] as $agent) {
            $this->assertFalse(BotDetector::isBot($agent), "{$agent} should not be a bot.");
        }
    }

    public function test_own_domain_referrals_are_dropped(): void
    {
        config(['app.url' => 'https://mindcollector.com']);

        // Internal navigation is not a referral, and it was the top "source" at 1,616 of 4,130.
        $this->assertNull(BotDetector::referrerHost('https://mindcollector.com/wow/comps'));
        $this->assertNull(BotDetector::referrerHost('https://www.mindcollector.com/brain'));
        $this->assertNull(BotDetector::referrerHost(null));

        $this->assertSame('google.com', BotDetector::referrerHost('https://www.google.com/search?q=x'));
        $this->assertSame('reddit.com', BotDetector::referrerHost('https://reddit.com/r/worldofpvp/'));
    }

    public function test_the_overview_counts_visitors_and_crawlers_separately(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        PageViewEvent::insert([
            ['page' => 'wow_comps', 'is_bot' => false, 'session_id' => 'a', 'created_at' => now()->subDay()],
            ['page' => 'wow_comps', 'is_bot' => false, 'session_id' => 'b', 'created_at' => now()->subDay()],
            ['page' => 'wow_comps', 'is_bot' => true, 'session_id' => 'c', 'created_at' => now()->subDay()],
            // Logged before the user agent was read. Must NOT be counted as a visitor.
            ['page' => 'wow_comps', 'is_bot' => null, 'session_id' => 'd', 'created_at' => now()->subDay()],
        ]);

        $overview = Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\PageUsage::class)
            ->instance()->overview;

        $this->assertSame(2, $overview[7]['views'], 'Only the two human rows are visitors.');
        $this->assertSame(1, $overview[7]['bots']);
        $this->assertSame(1, $overview[7]['unclassified'], 'Pre-instrumentation rows are shown, never merged in.');
    }
}
