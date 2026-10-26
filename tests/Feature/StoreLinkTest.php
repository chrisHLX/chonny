<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Microsoft Store link on /wow/app (2026-10-23).
 *
 * The direct download is an unsigned exe, so the page has to coach people past Windows'
 * "protected your PC" box -- which is the step the funnel dies at. A Store listing is signed and
 * removes the warning, so when one is configured it becomes the primary button and the file
 * becomes the fallback.
 *
 * Hidden when unset, the same way BUYMEACOFFEE_URL is: a half-finished listing is never linked,
 * and the page keeps working exactly as it did before.
 */
class StoreLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_store_is_the_main_button_when_a_listing_is_configured(): void
    {
        config(['services.microsoft_store.url' => 'https://apps.microsoft.com/detail/9NTESTTEST12', 'services.microsoft_store.current' => true]);

        $html = $this->get('/wow/app')->assertOk()->getContent();

        $this->assertStringContainsString('https://apps.microsoft.com/detail/9NTESTTEST12', $html);
        $this->assertStringContainsString('Get it from the Microsoft Store', $html);
        $this->assertStringContainsString('without a warning', $html);
        // The Store install raises nothing, so the SmartScreen coaching is now conditional on
        // having taken the file instead, rather than the first thing step 1 says.
        $this->assertStringContainsString('If you took the file instead', $html);
    }

    /**
     * A Store copy does not update itself, and an update waits on Microsoft's review: on 26 Oct the
     * listing held 0.5.5 while the download was 0.5.9, with recording and the Videos tab. Until the
     * Store is marked current, people get the download, the same app the developer runs.
     */
    public function test_a_listing_behind_the_download_is_not_offered(): void
    {
        config(['services.microsoft_store.url' => 'https://apps.microsoft.com/detail/9NTESTTEST12', 'services.microsoft_store.current' => false]);

        $html = $this->get('/wow/app')->assertOk()->getContent();

        $this->assertStringNotContainsString('apps.microsoft.com', $html);
        $this->assertStringNotContainsString('Get it from the Microsoft Store', $html);
        $this->assertStringContainsString('Run anyway', $html);
    }

    public function test_nothing_store_shaped_appears_when_it_is_unset(): void
    {
        config(['services.microsoft_store.url' => null]);

        $html = $this->get('/wow/app')->assertOk()->getContent();

        $this->assertStringNotContainsString('apps.microsoft.com', $html);
        $this->assertStringNotContainsString('Get it from the Microsoft Store', $html);
        // The page it was before: the SmartScreen coaching stated flatly, not as a fallback.
        $this->assertStringContainsString('Run anyway', $html);
        $this->assertStringNotContainsString('If you took the file instead', $html);
    }
}
