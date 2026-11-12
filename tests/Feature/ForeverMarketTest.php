<?php

namespace Tests\Feature;

use App\Models\ForeverScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WoW Forever auction scans pooled from every app user (ForeverMarketService, 2026-11-12).
 *
 * The rules worth testing: two players' scans of the same hour make one reading, the more complete
 * scan winning; the pooled view gives each item its newest reading and the one before it with its
 * time-left bands, which is what tells selling from expiry; a resent scan is stored once; and a scan
 * that doesn't fit the market is refused, so one bad upload can't move everyone's prices.
 */
class ForeverMarketTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/forever-market-test-'.uniqid();
        File::makeDirectory($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function send(string $key, array $scan)
    {
        return $this->call('POST', '/api/forever/scan', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$key,
            'CONTENT_TYPE' => 'application/gzip',
            'HTTP_ACCEPT' => 'application/json',
        ], gzencode(json_encode($scan)));
    }

    private function scan(int $t, int $auctions, array $items): array
    {
        return ['region' => 'US', 'realm' => 'Arugal', 'faction' => 'Horde', 't' => $t, 'n' => $auctions, 'items' => $items];
    }

    public function test_two_players_scans_pool_and_the_view_tells_selling_from_expiry(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $keyA = $a->issueCoachToken();
        $keyB = $b->issueCoachToken();
        $hour1 = now()->subHours(3)->startOfHour()->addMinutes(5)->timestamp;
        $hour2 = now()->subHour()->startOfHour()->addMinutes(5)->timestamp;

        // Hour 1: Fire Oil (7837) 40 listed, 30 of them with under 30 minutes left.
        $this->send($keyA, $this->scan($hour1, 1000, [[7837, 3000, 40, 30, 5, 5, 0], [2589, 12, 500, 0, 0, 0, 500]]))
            ->assertOk()->assertJson(['status' => 'stored', 'items' => 2]);
        // Hour 2: player B's small scan, then player A's fuller one in the same hour, which wins.
        $this->send($keyB, $this->scan($hour2, 900, [[7837, 2500, 99, 0, 0, 0, 99]]))->assertOk();
        $this->send($keyA, $this->scan($hour2 + 600, 1200, [[7837, 3100, 12, 0, 0, 2, 10], [2589, 11, 480, 0, 0, 0, 480]]))->assertOk();

        $this->assertSame(3, ForeverScan::count());
        $row = DB::table('forever_prices')->where('item_id', 7837)->orderByDesc('hour')->first();
        $this->assertSame(3100, (int) $row->min_unit, 'the scan with more auctions wins the hour');
        $this->assertSame(2, (int) $row->sources, 'both scans of that hour counted');

        $pool = $this->withHeader('Authorization', 'Bearer '.$keyB)
            ->getJson('/api/forever/market?region=US&realm=Arugal&faction=Horde')
            ->assertOk()->json();
        $this->assertSame('US/Arugal/Horde', $pool['market']);
        $this->assertSame(2, $pool['players']);
        $this->assertSame($hour2 + 600, $pool['t']);
        // [t, min, listed, prev t, prev min, prev listed, e0..e3 of prev, lowest in 7 days, scans]
        $this->assertSame([$hour2 + 600, 3100, 12, $hour1, 3000, 40, 30, 5, 5, 0, 3000, 2], $pool['items']['7837']);
        // 28 fewer listed over two hours, and 35 of the 40 had under 2 hours left: no proof of a sale.
    }

    public function test_a_neutral_auction_house_scan_never_reaches_a_factions_prices(): void
    {
        // Booty Bay's goblin auction house has its own listings (Chriso, 2026-11-12): a Horde player
        // scanning it sends faction "Neutral", which is its own market.
        $key = User::factory()->create()->issueCoachToken();
        $t = now()->subMinutes(30)->timestamp;
        $this->send($key, $this->scan($t, 1000, [[7837, 3000, 40]]))->assertOk();
        $this->send($key, ['faction' => 'Neutral'] + $this->scan($t + 60, 300, [[7837, 99999, 2]]))->assertOk()->assertJson(['status' => 'stored']);

        $horde = $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/forever/market?region=US&realm=Arugal&faction=Horde')->json();
        $neutral = $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/forever/market?region=US&realm=Arugal&faction=Neutral')->json();
        $this->assertSame(3000, $horde['items']['7837'][1]);
        $this->assertSame(99999, $neutral['items']['7837'][1]);
    }

    public function test_a_resent_scan_is_stored_once(): void
    {
        $key = User::factory()->create()->issueCoachToken();
        $scan = $this->scan(now()->subMinutes(20)->timestamp, 500, [[2589, 12, 500]]);

        $this->send($key, $scan)->assertOk()->assertJson(['status' => 'stored']);
        $this->send($key, $scan)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, ForeverScan::count());
    }

    public function test_scans_that_do_not_fit_the_market_are_refused(): void
    {
        $key = User::factory()->create()->issueCoachToken();
        $this->send($key, $this->scan(now()->subMinutes(30)->timestamp, 2000, [[2589, 12, 500]]))->assertOk();

        // Ten times the market's biggest recent scan.
        $this->send($key, $this->scan(now()->subMinutes(10)->timestamp, 20000, [[2589, 1, 500]]))
            ->assertStatus(422)->assertJson(['status' => 'rejected']);
        // From the future.
        $this->send($key, $this->scan(now()->addHour()->timestamp, 2000, [[2589, 12, 500]]))->assertStatus(422);
        // Mostly unreadable items.
        $this->send($key, $this->scan(now()->subMinutes(5)->timestamp, 2000, [[2589, -5, 500], ['x', 1, 1]]))->assertStatus(422);
        // A market name that could reach another market's files.
        $this->send($key, ['region' => 'US', 'realm' => '../x', 'faction' => 'Horde', 't' => now()->timestamp, 'n' => 10, 'items' => [[1, 1, 1]]])
            ->assertStatus(422);

        $this->assertSame(1, ForeverScan::count());
        $this->send('wrong-key', $this->scan(now()->timestamp, 10, [[1, 1, 1]]))->assertStatus(401);
    }

    public function test_an_empty_market_answers_with_no_items(): void
    {
        $key = User::factory()->create()->issueCoachToken();
        $this->withHeader('Authorization', 'Bearer '.$key)
            ->getJson('/api/forever/market?region=EU&realm=Nowhere&faction=Alliance')
            ->assertOk()->assertJson(['status' => 'ok', 'players' => 0, 'items' => []]);
    }
}
