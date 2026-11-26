<?php

namespace Tests\Feature;

use App\Http\Services\ForeverGearFinder;
use App\Models\PageViewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Gear Finder (/wow/forever/gear, 2026-11-27): the player picks the stats, the page filters by level,
 * class, slot and type and ranks by the picked stats (ForeverGearFinder). Items modelled on Chriso's sweep.
 */
class ForeverGearFinderTest extends TestCase
{
    use RefreshDatabase;

    private function item(int $id, string $name, int $req, int $ilvl, string $slot, int $class, int $sub, array $stats, array $lines = [], int $bind = 2, int $q = 2): void
    {
        DB::table('forever_items')->insert([
            'item_id' => $id, 'in_game' => true, 'name' => $name, 'quality' => $q, 'item_level' => $ilvl, 'required_level' => $req,
            'slot' => $slot, 'class_id' => $class, 'subclass_id' => $sub, 'bind' => $bind, 'sell_price' => 100,
            'stats' => json_encode($stats), 'lines' => $lines ? json_encode($lines) : null, 'build' => '1.60.1.71139',
            'user_id' => 1, 'updated_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->item(1, 'Mail Agility Helm', 38, 43, 'INVTYPE_HEAD', 4, 3, ['AGILITY' => 12, 'RESISTANCE0_NAME' => 200]);
        $this->item(2, 'Plate Agility Helm', 38, 43, 'INVTYPE_HEAD', 4, 4, ['AGILITY' => 20]);
        $this->item(3, 'Level 50 Helm', 50, 55, 'INVTYPE_HEAD', 4, 3, ['AGILITY' => 30]);
        // a dungeon set piece: no level requirement, item level 63
        $this->item(4, 'Darkmantle Cap', 0, 63, 'INVTYPE_HEAD', 4, 2, ['AGILITY' => 26], [], 1, 4);
        $this->item(5, 'Crit Helm', 30, 35, 'INVTYPE_HEAD', 4, 3, ['CRIT_RATING' => 14, 'AGILITY' => 2]);
        $this->item(6, 'Rogue Only Helm', 30, 35, 'INVTYPE_HEAD', 4, 2, ['AGILITY' => 15], ['Classes: Rogue']);
        $this->item(7, "Defiler's Mail Girdle", 38, 48, 'INVTYPE_WAIST', 4, 3, ['AGILITY' => 9], ['Requires The Defilers - Honored']);
        $this->item(8, 'Shirt', 1, 5, 'INVTYPE_BODY', 4, 0, ['AGILITY' => 50]);
    }

    private function find(array $f): array
    {
        return app(ForeverGearFinder::class)->find($f + [
            'level' => 40, 'class' => null, 'armour' => [1, 2, 3, 4], 'weapons' => array_keys(ForeverGearFinder::WEAPONS),
            'quality' => 0, 'stats' => ['agi' => 1.0], 'slot' => null, 'far' => false, 'market' => null,
        ]);
    }

    private function names(array $found, string $slot): array
    {
        return array_column($found[$slot] ?? [], 'name');
    }

    public function test_it_ranks_by_the_picked_stats_within_the_level(): void
    {
        $found = $this->find([]);
        $this->assertSame(['Plate Agility Helm', 'Rogue Only Helm', 'Mail Agility Helm', 'Crit Helm'], $this->names($found, 'Head'),
            'the level 50 helm and the far-above set piece are out');
        $this->assertArrayNotHasKey('Shirt', $found, 'shirts are not a slot');
        $this->assertSame(['Requires The Defilers - Honored'], $found['Waist'][0]['requires']);
    }

    public function test_armour_type_class_and_weights_narrow_and_reorder_it(): void
    {
        $found = $this->find(['armour' => [3], 'class' => 'Hunter', 'stats' => ['agi' => 1.0, 'crit' => 20.0]]);
        // crit 14 rating is 1%, times 20, plus 2 Agility: 22, above the mail helm's 12
        $this->assertSame(['Crit Helm', 'Mail Agility Helm'], $this->names($found, 'Head'));
        $this->assertEquals(1.0, $found['Head'][0]['values']['crit']);
        $this->assertSame(['Rogue Only Helm'], array_values(array_intersect(['Rogue Only Helm'], $this->names($this->find(['class' => 'Rogue']), 'Head'))));
    }

    public function test_items_without_a_level_requirement_far_above_are_shown_only_when_asked(): void
    {
        $this->assertNotContains('Darkmantle Cap', $this->names($this->find([]), 'Head'));
        $this->assertSame('Darkmantle Cap', $this->names($this->find(['far' => true]), 'Head')[0]);
        $this->assertContains('Darkmantle Cap', $this->names($this->find(['level' => 55]), 'Head'), 'within ten item levels it shows');
    }

    public function test_the_page_serves_is_tracked_and_reads_the_form(): void
    {
        $this->get(route('wow.forever.gear'))->assertOk()->assertSee('Gear Finder')->assertSee('Pick at least one stat');
        $this->get(route('wow.forever.gear', ['f' => 1, 'level' => 40, 'pick' => ['agi'], 'armour' => [3]]))
            ->assertOk()->assertSee('Mail Agility Helm')->assertDontSee('Plate Agility Helm')->assertSee('Requires The Defilers - Honored');
        $this->assertSame(2, PageViewEvent::where('page', 'forever_gear')->count());
        $this->assertSame('agi', PageViewEvent::where('page', 'forever_gear')->latest('id')->value('slot'));
        $this->actingAs(User::factory()->create())->get(route('wow.forever.crafts'))->assertOk()->assertSee(route('wow.forever.gear'), false);
    }

    public function test_an_empty_item_list_says_so(): void
    {
        DB::table('forever_items')->delete();
        $this->get(route('wow.forever.gear'))->assertOk()->assertSee("The item list hasn't arrived yet", false);
    }
}
