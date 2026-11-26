<?php

namespace Tests\Feature;

use App\Http\Services\ForeverCraftsService;
use App\Models\ForeverScan;
use App\Models\PageViewEvent;
use App\Models\User;
use App\Support\ForeverRecipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WoW Forever's Crafting Profits (ForeverCraftsService, /wow/forever/crafts, 2026-11-26).
 *
 * The recipes are the real ones from the addon's Data.lua. Fire Oil (spell 7837) takes 2 Firefin
 * Snapper and 1 Empty Vial and makes 2 Fire Oil; vendors sell the vial for 20 copper. What's worth
 * pinning: the sum (sale after the 5% cut, times what a craft makes, less the mats), a price being
 * the week's median so one odd listing can't move it, a vendor's price winning when it's lower, a
 * recipe with an unpriced material left out and counted, and the cache moving on with a new scan.
 */
class ForeverCraftsTest extends TestCase
{
    use RefreshDatabase;

    private const MARKET = 'US/Horde';

    private const SNAPPER = 6359;

    private const VIAL = 3371;

    private const FIRE_OIL = 6371;

    private const LINEN_BANDAGE = 1251;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** One scan at $hoursAgo, with [item => cheapest unit price]. */
    private function scan(int $hoursAgo, array $prices): void
    {
        $at = now()->subHours($hoursAgo)->startOfHour()->addMinutes(5);
        ForeverScan::create([
            'user_id' => $this->user->id, 'market' => self::MARKET, 'scanned_at' => $at,
            'auctions' => 1000, 'items' => count($prices), 'path' => 'test',
        ]);
        foreach ($prices as $item => $price) {
            DB::table('forever_prices')->insert([
                'market' => self::MARKET, 'item_id' => $item, 'hour' => $at->copy()->startOfHour(), 'scanned_at' => $at,
                'min_unit' => $price, 'quantity' => 10, 'expiring' => null, 'scan_auctions' => 1000, 'sources' => 1,
            ]);
        }
    }

    private function craft(array $result, string $name): ?array
    {
        return collect($result['crafts'])->firstWhere('name', $name);
    }

    public function test_profit_is_the_sale_after_the_cut_times_the_yield_less_the_mats(): void
    {
        // Snapper 500, 500, then one stray 9,000 listing: the median stays 500. The vial lists at 50,
        // but a vendor sells it for 20, so 20 it is.
        $this->scan(3, [self::SNAPPER => 500, self::VIAL => 50, self::FIRE_OIL => 1000, self::LINEN_BANDAGE => 300]);
        $this->scan(2, [self::SNAPPER => 500, self::VIAL => 50, self::FIRE_OIL => 1000]);
        $this->scan(1, [self::SNAPPER => 9000, self::VIAL => 50, self::FIRE_OIL => 1000]);

        $result = app(ForeverCraftsService::class)->forMarket(self::MARKET);
        $oil = $this->craft($result, 'Fire Oil');

        $this->assertNotNull($oil);
        $this->assertSame(2, (int) $oil['yield']);
        $this->assertSame(1020, $oil['cost']);          // 2 x 500 + 20
        $this->assertSame(1900, $oil['earns']);         // 1,000 x 0.95 x 2
        $this->assertSame(880, $oil['profit']);
        $this->assertEqualsWithDelta(880 / 1020, $oil['margin'], 1e-9);
        $this->assertSame(3, $oil['seen']);
        $this->assertSame(['name' => 'Firefin Snapper', 'count' => 2, 'unit' => 500, 'vendor' => false], $oil['mats'][0]);
        $this->assertSame(['name' => 'Empty Vial', 'count' => 1, 'unit' => 20, 'vendor' => true], $oil['mats'][1]);

        // Linen Bandage sells, but Linen Cloth was never seen: left out, and counted.
        $this->assertNull($this->craft($result, 'Linen Bandage'));
        $this->assertGreaterThanOrEqual(1, $result['unpriced']);
        $this->assertSame(3, $result['scans']);
        $this->assertSame(1, $result['players']);
    }

    public function test_a_new_scan_moves_the_cached_result_on(): void
    {
        $this->scan(2, [self::SNAPPER => 500, self::FIRE_OIL => 1000]);
        $service = app(ForeverCraftsService::class);
        $this->assertSame(880, $this->craft($service->forMarket(self::MARKET), 'Fire Oil')['profit']);

        $this->scan(1, [self::SNAPPER => 500, self::FIRE_OIL => 2000]);
        // median of 1,000 and 2,000 is 1,500: 1,500 x 0.95 x 2 - 1,020
        $this->assertSame(1830, $this->craft($service->forMarket(self::MARKET), 'Fire Oil')['profit']);
    }

    public function test_cooldowns_come_from_the_game_data(): void
    {
        $mooncloth = collect(ForeverRecipes::recipes())->firstWhere('spell', 18560);
        $this->assertSame(342000, $mooncloth['cooldown']);
        $this->assertSame('', $mooncloth['cooldownGroup']);

        $arcanite = collect(ForeverRecipes::recipes())->firstWhere('spell', 17187);
        $this->assertSame(169200, $arcanite['cooldown']);
        $this->assertSame('Transmute - Alchemy', $arcanite['cooldownGroup']);

        $this->assertNull(collect(ForeverRecipes::recipes())->firstWhere('spell', 7837)['cooldown']);
    }

    public function test_the_page_serves_with_and_without_scans_and_is_tracked(): void
    {
        $this->get(route('wow.forever.crafts'))->assertOk()->assertSee('No one has shared an auction house scan yet');

        $this->scan(2, [self::SNAPPER => 500, self::VIAL => 50, self::FIRE_OIL => 1000]);
        $this->get(route('wow.forever.crafts', ['market' => self::MARKET]))
            ->assertOk()
            ->assertSee('Crafting Profits')
            ->assertSee('Fire Oil')
            ->assertSee('Firefin Snapper');
        // an unknown market falls back to the one scanned most
        $this->get(route('wow.forever.crafts', ['market' => 'EU/Alliance']))->assertOk()->assertSee('Fire Oil');

        $this->assertSame(3, PageViewEvent::where('page', 'forever_crafts')->count());
    }
}
