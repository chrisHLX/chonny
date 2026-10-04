<?php

namespace Tests\Feature;

use App\Livewire\Admin\PageUsage;
use App\Models\PageViewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A page view confirmed by a browser running the page (TrackController::seen). Not a declared
 * bot is not proof of a person: on 2-3 Oct 2026, 154 Alibaba Cloud addresses with a browser user
 * agent and a fake Google referrer were counted as visitors, and not one loaded the site's CSS or
 * JavaScript.
 */
class PageViewConfirmTest extends TestCase
{
    use RefreshDatabase;

    /** confirmed_at is set only by TrackController::seen, never mass-assigned, hence forceFill. */
    private function pageView(array $attrs = []): PageViewEvent
    {
        $view = new PageViewEvent;
        $view->forceFill(array_merge(['page' => 'landing', 'session_id' => 's1', 'is_bot' => false], $attrs))->save();

        return $view;
    }

    public function test_the_front_page_carries_a_signed_beacon_that_confirms_its_view(): void
    {
        $response = $this->get('/')->assertOk();

        $view = PageViewEvent::where('page', 'landing')->latest('id')->firstOrFail();
        $this->assertNull($view->confirmed_at, 'serving the page is not proof a browser ran it');
        $response->assertSee("fetch('/track/seen'", false);
        $response->assertSee(PageViewEvent::signature($view->id), false);

        $this->postJson(route('track.seen'), ['id' => $view->id, 'sig' => PageViewEvent::signature($view->id)])->assertNoContent();
        $this->assertNotNull($view->fresh()->confirmed_at);
    }

    public function test_a_wrong_signature_or_an_old_view_confirms_nothing(): void
    {
        $view = $this->pageView();
        $this->postJson(route('track.seen'), ['id' => $view->id, 'sig' => 'not-the-signature'])->assertNoContent();
        $this->assertNull($view->fresh()->confirmed_at);

        $old = $this->pageView();
        DB::table('page_view_events')->where('id', $old->id)->update(['created_at' => now()->subHours(2)]);
        $this->postJson(route('track.seen'), ['id' => $old->id, 'sig' => PageViewEvent::signature($old->id)])->assertNoContent();
        $this->assertNull($old->fresh()->confirmed_at, 'a view is confirmed within the hour or not at all');
    }

    public function test_the_usage_page_counts_only_confirmed_views_as_seen_by_a_browser(): void
    {
        $this->pageView(['confirmed_at' => now()]);
        $this->pageView(['session_id' => 's2']);
        // A declared crawler that runs the page (Googlebot renders scripts) is still a crawler.
        $this->pageView(['session_id' => 's3', 'is_bot' => true, 'confirmed_at' => now()]);

        $usage = Livewire::test(PageUsage::class)->instance();

        $this->assertSame(1, $usage->overview[7]['browser']);
        $this->assertSame(2, $usage->overview[7]['views'], 'both browser-agent views still count as views');
        $this->assertSame(1, $usage->daily->last()['browser']);
        $this->assertSame(1, $usage->topPages->firstWhere('page', 'landing')['browser']);
    }
}
