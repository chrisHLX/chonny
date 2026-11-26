<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WoW Forever weapons, armour and vendors as the Auction House addon reads them in game, sent by the
 * app (ForeverGearService, 2026-11-27). The items are real records from Chriso's first sweep.
 */
class ForeverGearTest extends TestCase
{
    use RefreshDatabase;

    private const LIONHEART = ['Lionheart Helm', 4, 61, 56, 'INVTYPE_HEAD', 4, 4, 2, 21894,
        ['HIT_RATING' => 20, 'RESISTANCE0_NAME' => 565, 'STRENGTH' => 18, 'CRIT_RATING' => 28],
        ['Equip: Improves your chance to hit by 2.0%.', 'Equip: Improves your chance to get a critical strike by 2.0%.'], null];

    private const SCEPTRE = ["Baron Charr's Sceptre", 3, 60, 55, 'INVTYPE_WEAPONMAINHAND', 2, 4, 2, 45184,
        ['DAMAGE_PER_SECOND' => 39.423076629639, 'STRENGTH' => 5], null, [71, 134, 2.6]];

    private const GIRDLE = ["Defiler's Plate Girdle", 3, 53, 48, 'INVTYPE_WAIST', 4, 4, 1, 6599,
        ['STAMINA' => 8, 'CRIT_RATING' => 14, 'STRENGTH' => 10, 'RESISTANCE0_NAME' => 313],
        ['Unique', 'Equip: Improves your chance to get a critical strike by 1.0%.', 'Requires The Defilers - Honored'], null];

    private function send(string $uri, string $key, array $data)
    {
        return $this->call('POST', $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$key,
            'CONTENT_TYPE' => 'application/gzip',
            'HTTP_ACCEPT' => 'application/json',
        ], gzencode(json_encode($data)));
    }

    public function test_a_sweep_is_stored_with_stats_lines_damage_and_refusals(): void
    {
        $key = User::factory()->create()->issueCoachToken();
        $this->send('/api/forever/items', $key, [
            'build' => '1.60.1.71139',
            'items' => ['12640' => self::LIONHEART, '18671' => self::SCEPTRE, '20205' => self::GIRDLE],
            'gone' => [17, 192],
            'variants' => ['15602:12751' => ['Ancient Crown of the Bandit', ['AGILITY' => 6, 'ATTACK_POWER' => 16, 'STAMINA' => 14, 'RESISTANCE0_NAME' => 202]]],
        ])->assertOk()->assertJson(['status' => 'stored', 'items' => 3, 'gone' => 2, 'variants' => 1]);

        $helm = DB::table('forever_items')->where('item_id', 12640)->first();
        $this->assertTrue((bool) $helm->in_game);
        $this->assertSame(['Lionheart Helm', 61, 56, 'INVTYPE_HEAD'], [$helm->name, (int) $helm->item_level, (int) $helm->required_level, $helm->slot]);
        $this->assertSame(['HIT_RATING' => 20, 'RESISTANCE0_NAME' => 565, 'STRENGTH' => 18, 'CRIT_RATING' => 28], json_decode($helm->stats, true));
        $this->assertSame([71, 134, 2.6], json_decode(DB::table('forever_items')->where('item_id', 18671)->value('damage'), true));
        $this->assertContains('Requires The Defilers - Honored', json_decode(DB::table('forever_items')->where('item_id', 20205)->value('lines'), true));
        $this->assertSame(2, DB::table('forever_items')->where('in_game', false)->count());
        $this->assertSame('Ancient Crown of the Bandit', DB::table('forever_item_variants')->where(['item_id' => 15602, 'bonus' => '12751'])->value('name'));
    }

    public function test_a_refusal_never_undoes_an_item_another_sweep_found(): void
    {
        $key = User::factory()->create()->issueCoachToken();
        $this->send('/api/forever/items', $key, ['build' => '1.60.1.71139', 'items' => ['12640' => self::LIONHEART]])->assertOk();
        $this->send('/api/forever/items', $key, ['build' => '1.60.1.71139', 'items' => [], 'gone' => [12640, 17]])->assertOk();

        $this->assertTrue((bool) DB::table('forever_items')->where('item_id', 12640)->value('in_game'));
        $this->assertFalse((bool) DB::table('forever_items')->where('item_id', 17)->value('in_game'));
        // and an item that turns up later (a raid opening) is in the game from then on
        $this->send('/api/forever/items', $key, ['build' => '1.60.2.72000', 'items' => ['17' => self::SCEPTRE]])->assertOk();
        $this->assertTrue((bool) DB::table('forever_items')->where('item_id', 17)->value('in_game'));
    }

    public function test_unreadable_sweeps_are_refused(): void
    {
        $key = User::factory()->create()->issueCoachToken();
        $this->send('/api/forever/items', $key, ['items' => ['12640' => self::LIONHEART]])->assertStatus(422);
        $bad = array_fill(0, 20, ['x']);
        $this->send('/api/forever/items', $key, ['build' => '1.60.1.71139', 'items' => $bad])->assertStatus(422);
        $this->send('/api/forever/items', 'wrong-key', ['build' => '1.60.1.71139', 'items' => []])->assertStatus(401);
        $this->assertSame(0, DB::table('forever_items')->count());
    }

    public function test_a_vendor_list_replaces_the_older_one_and_keeps_costs_and_requirements(): void
    {
        $key = User::factory()->create()->issueCoachToken();
        $list = fn (int $t, array $items) => ['vendors' => [[
            'npc' => 12796, 'name' => 'Raider Bork', 'zone' => 'Orgrimmar', 'sub' => 'Hall of Legends', 'mapID' => 1454,
            'x' => 41.2, 'y' => 69.5, 't' => $t, 'items' => $items,
        ]]];
        $old = now()->subDay()->timestamp;
        $this->send('/api/forever/vendors', $key, $list($old, [[1, 100, 1, null, null, null]]))->assertOk();
        $this->send('/api/forever/vendors', $key, $list(now()->timestamp, [
            [20205, 0, 1, null, [[1200, 'Honor Points'], [3, 20558]], ['Requires The Defilers - Honored']],
            [6948, 25, 5, 10, null, null],
        ]))->assertOk()->assertJson(['vendors' => 1, 'items' => 2]);
        // an older list sent late changes nothing
        $this->send('/api/forever/vendors', $key, $list($old, [[1, 100, 1, null, null, null]]))->assertJson(['vendors' => 0]);

        $rows = DB::table('forever_vendor_items')->where('npc_id', 12796)->orderBy('item_id')->get();
        $this->assertSame([6948, 20205], $rows->pluck('item_id')->map(fn ($i) => (int) $i)->all());
        $girdle = $rows->firstWhere('item_id', 20205);
        $this->assertSame([[1200, 'Honor Points'], [3, 20558]], json_decode($girdle->costs, true));
        $this->assertSame(['Requires The Defilers - Honored'], json_decode($girdle->requires, true));
        $this->assertSame(10, (int) $rows->firstWhere('item_id', 6948)->stock);
    }

    public function test_a_scan_keeps_suffix_versions_apart(): void
    {
        $storage = sys_get_temp_dir().'/forever-gear-test-'.uniqid();
        File::makeDirectory($storage.'/app', 0775, true);
        $this->app->useStoragePath($storage);
        try {
            $key = User::factory()->create()->issueCoachToken();
            $scan = fn (int $t, int $n, array $variants) => [
                'region' => 'US', 'faction' => 'Horde', 't' => $t, 'n' => $n,
                'items' => [[15602, 29900, 3]], 'variants' => $variants,
            ];
            $t = now()->subHours(2)->startOfHour()->addMinutes(5)->timestamp;
            $this->send('/api/forever/scan', $key, $scan($t, 1000, [[15602, '12751', 29900, 1], [15602, '12755', 45000, 2], [99, '1', 5, 1], [15602, '../x', 1, 1]]))->assertOk();
            // a smaller scan in the same hour doesn't move the reading
            $this->send('/api/forever/scan', $key, $scan($t + 300, 500, [[15602, '12751', 100, 1]]))->assertOk();

            $rows = DB::table('forever_variant_prices')->orderBy('bonus')->get();
            $this->assertSame(['12751', '12755'], $rows->pluck('bonus')->all(), 'an item not in the scan and a bad bonus are dropped');
            $this->assertSame(29900, (int) $rows[0]->min_unit);
        } finally {
            File::deleteDirectory($storage);
        }
    }
}
