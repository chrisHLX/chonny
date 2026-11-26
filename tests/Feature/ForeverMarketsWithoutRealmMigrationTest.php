<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The 2026-11-26 migration that drops the realm from WoW Forever's market keys: two old markets that
 * were one auction house merge, the fuller scan winning an hour both read, as ForeverMarketService
 * merges two players' scans of one hour.
 */
class ForeverMarketsWithoutRealmMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function price(string $market, int $item, string $hour, int $min, int $auctions): void
    {
        DB::table('forever_prices')->insert([
            'market' => $market, 'item_id' => $item, 'hour' => $hour, 'scanned_at' => $hour,
            'min_unit' => $min, 'quantity' => 5, 'expiring' => null, 'scan_auctions' => $auctions, 'sources' => 1,
        ]);
    }

    public function test_two_realms_of_one_house_merge_into_region_and_faction(): void
    {
        $us2 = 'US/CAMWOWUS2Realm2PvP/Horde';
        $au1 = 'US/CAMWOWAU1Realm2PvP/Horde';
        DB::table('forever_scans')->insert([
            ['user_id' => 1, 'market' => $us2, 'scanned_at' => '2026-11-13 13:05:00', 'auctions' => 157617, 'items' => 2, 'path' => 'a'],
            ['user_id' => 2, 'market' => $au1, 'scanned_at' => '2026-11-13 13:17:00', 'auctions' => 156690, 'items' => 2, 'path' => 'b'],
            ['user_id' => 1, 'market' => 'US/CAMWOWUS2Realm2PvP/Neutral', 'scanned_at' => '2026-11-16 11:00:00', 'auctions' => 16000, 'items' => 1, 'path' => 'c'],
        ]);
        $this->price($us2, 7837, '2026-11-13 13:00:00', 3000, 157617);
        $this->price($us2, 7837, '2026-11-13 14:00:00', 3100, 152486);
        $this->price($au1, 7837, '2026-11-13 13:00:00', 2900, 156690);   // same hour: the fuller US2 scan wins
        $this->price($au1, 2589, '2026-11-13 13:00:00', 12, 156690);     // only AU1 read it: kept
        $this->price('US/CAMWOWUS2Realm2PvP/Neutral', 7837, '2026-11-16 11:00:00', 5000, 16000);

        $migration = require database_path('migrations/2026_11_26_000001_forever_markets_without_realm.php');
        $migration->up();

        $this->assertSame(['US/Horde', 'US/Horde', 'US/Neutral'], DB::table('forever_scans')->orderBy('id')->pluck('market')->all());
        $this->assertSame(4, DB::table('forever_prices')->count());
        $hour = DB::table('forever_prices')->where(['market' => 'US/Horde', 'item_id' => 7837])->where('hour', 'like', '2026-11-13 13:%')->first();
        $this->assertSame(3000, (int) $hour->min_unit);
        $this->assertSame(2, (int) $hour->sources);
        $this->assertSame(12, (int) DB::table('forever_prices')->where(['market' => 'US/Horde', 'item_id' => 2589])->value('min_unit'));
        $this->assertSame(5000, (int) DB::table('forever_prices')->where('market', 'US/Neutral')->value('min_unit'));
    }
}
