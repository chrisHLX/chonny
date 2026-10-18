<?php

namespace Tests\Feature;

use App\Models\FunnelEvent;
use App\Models\PageViewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /admin (Admin\Overview, 2026-10-19): the numbers in one place, people only, and the parts adding
 * up to the whole. Plus the admin tab strip and the corrected "engaged" and sign-up counts.
 */
class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function hit(string $session, string $page, array $extra = []): void
    {
        PageViewEvent::create(['page' => $page, 'session_id' => $session, 'is_bot' => false] + $extra);
    }

    public function test_visitors_are_people_and_their_sources_add_up(): void
    {
        // A reader from Google whose browser ran the page.
        $this->hit('reader', 'landing', ['referrer_host' => 'google.com']);
        PageViewEvent::where('session_id', 'reader')->update(['confirmed_at' => now()]);
        // Someone who typed the address and opened the app page.
        $this->hit('typed', 'app_page');
        $this->hit('typed', 'desktop_app');
        PageViewEvent::where('session_id', 'typed')->where('page', 'app_page')->update(['confirmed_at' => now()]);
        // A crawler: two rows from one quiz load, no browser ever ran it. Not a visitor.
        $this->hit('script', 'wow_quiz');
        $this->hit('script', 'wow_quiz', ['slot' => 'a']);

        $html = $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Visitors<\/p>\s*<p[^>]*>\s*2\s*</', $html, 'the two people, not the crawler');
        $this->assertStringContainsString('google.com', $html);
        $this->assertStringContainsString('Direct or an app link', $html);
        $this->assertStringContainsString('1 sessions in which no browser ever ran a page', $html);
    }

    public function test_every_admin_page_carries_the_tab_strip_and_others_are_refused(): void
    {
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get('/admin/page-usage')->assertOk()->getContent();
        foreach (['Overview', 'Page usage', 'Search pages', 'Matches', 'Diagnostic stats', 'Job queue'] as $tab) {
            $this->assertStringContainsString('>'.$tab.'</a>', $html);
        }

        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_engaged_needs_a_browser_and_the_signup_form_counts_people(): void
    {
        $this->hit('script', 'basics_check');
        $this->hit('script', 'basics_check', ['slot' => 'a']);
        $this->hit('person', 'landing');
        PageViewEvent::where('session_id', 'person')->update(['confirmed_at' => now()]);
        FunnelEvent::log('signup_started', 'script');
        FunnelEvent::log('signup_started', 'person');

        $usage = new \App\Livewire\Admin\PageUsage;
        $this->assertSame(1, $usage->overview[7]['engaged'], 'the two-row crawler session is not engaged');

        $funnel = (new \App\Livewire\Admin\DiagnosticStats)->funnel;
        $this->assertSame(2, $funnel['signupStarted']);
        $this->assertSame(1, $funnel['signupStartedPeople']);
    }
}
