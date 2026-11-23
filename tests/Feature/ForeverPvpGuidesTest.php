<?php

namespace Tests\Feature;

use App\Models\PageViewEvent;
use App\Support\ForeverAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WoW Forever's PvP Guides (/wow/forever/pvp/{class}, 2026-11-23): served from the committed
 * data/forever/abilities.json through the real route and layout, counted (rule 28), and on
 * Forever's menu.
 */
class ForeverPvpGuidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_class_page_serves_with_its_abilities_by_category(): void
    {
        $classes = ForeverAbilities::classes();
        $this->assertCount(9, $classes, 'the nine classes of WoW Forever');

        foreach ($classes as $class) {
            $this->get(route('wow.forever.pvp', $class['slug']))->assertOk()
                ->assertSee($class['name'].' Abilities')
                ->assertSee('Active Abilities');
        }

        // what a fight turns on, in retail's categories
        $this->get('/wow/forever/pvp/rogue')->assertOk()
            ->assertSeeInOrder(['Crowd Control', 'Kidney Shot'])
            ->assertSee('Evasion')
            ->assertSee('Kick');
    }

    public function test_it_is_counted_by_class_and_shows_forevers_menu(): void
    {
        $this->get('/wow/forever/pvp/mage')->assertOk()
            ->assertSee(route('wow.forever').'#uses', false)
            ->assertDontSee(route('matchup-lab'), false);

        $this->assertSame('forever', session('nav_game'));
        $this->assertSame(1, PageViewEvent::where('page', 'forever_pvp')->where('slot', 'mage')->count());
    }

    public function test_a_bare_url_opens_on_a_class_and_an_unknown_class_is_not_found(): void
    {
        $this->get('/wow/forever/pvp')->assertOk()->assertSee('Warrior Abilities');
        $this->get('/wow/forever/pvp/deathknight')->assertNotFound();
    }

    public function test_the_forever_menu_leads_to_it(): void
    {
        $this->get('/wow/forever')->assertOk()->assertSee(route('wow.forever.pvp'), false);
    }

    public function test_no_ability_shows_a_raw_token_or_lacks_an_icon(): void
    {
        foreach (ForeverAbilities::classes() as $class) {
            foreach ($class['abilities'] as $a) {
                $this->assertNotNull($a['icon'], "{$class['name']}: {$a['name']} has no icon");
                $this->assertFileExists(storage_path("app/public/forever-icons/{$a['icon']}.jpg"), "{$a['name']}'s icon file");
                $this->assertStringNotContainsString('$', (string) $a['description'], "{$a['name']}'s description");
                $this->assertContains($a['category'], ForeverAbilities::CATEGORIES);
            }
        }
    }
}
