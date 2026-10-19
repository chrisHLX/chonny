<?php

namespace Tests\Feature;

use App\Livewire\Players\Search;
use App\Livewire\Players\Show;
use App\Models\BattlenetAccount;
use App\Models\BattlenetCharacter;
use App\Models\PlayerRatingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The player lookup (/wow/players, /wow/player/{region}/{realm}/{name}, 2026-10-19): any character,
 * stored as it is looked up, asked of Blizzard only by a browser, with a rating history written as
 * ratings change.
 */
class PlayerLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.battlenet.client_id' => 'id', 'services.battlenet.client_secret' => 'secret']);
        Cache::flush();
        \App\Http\Services\RealmDirectory::forget();
    }

    private function fakeBlizzard(int $rating = 1850, bool $exists = true): void
    {
        $c = '*/profile/wow/character/tichondrius/skylake';
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't']),
            '*/data/wow/realm/index*' => Http::response(['realms' => [
                ['name' => 'Tichondrius', 'slug' => 'tichondrius'],
                ['name' => 'Azjol-Nerub', 'slug' => 'azjolnerub'],
            ]]),
            '*/data/wow/pvp-season/index*' => Http::response(['current_season' => ['id' => 40]]),
            $c.'/achievements/statistics*' => Http::response(['categories' => []]),
            $c.'/achievements*' => Http::response(['achievements' => []]),
            $c.'/pvp-summary*' => Http::response(['brackets' => [['href' => 'https://us.api.blizzard.com/profile/wow/character/tichondrius/skylake/pvp-bracket/3v3?namespace=profile-us']]]),
            $c.'/pvp-bracket/3v3*' => Http::response([
                'bracket' => ['type' => 'ARENA_3v3'], 'rating' => $rating, 'season' => ['id' => 40],
                'season_match_statistics' => ['played' => 30, 'won' => 18, 'lost' => 12],
            ]),
            $c.'/specializations*' => Http::response(['specializations' => []]),
            $c.'/equipment*' => Http::response(['equipped_items' => []]),
            $c.'?*' => $exists
                ? Http::response(['id' => 777, 'name' => 'Skylake', 'level' => 80, 'realm' => ['slug' => 'tichondrius', 'name' => 'Tichondrius']])
                : Http::response([], 404),
            '*' => Http::response([], 404),
        ]);
    }

    public function test_searching_leads_to_the_players_page_from_a_name_a_pasted_name_realm_or_a_link(): void
    {
        $this->fakeBlizzard();

        Livewire::test(Search::class)->set('name', 'Skylake')->set('realm', 'Tichondrius')->call('search')
            ->assertRedirect('/wow/player/us/tichondrius/skylake');
        Livewire::test(Search::class)->set('name', 'Skylake-Tichondrius')->call('search')
            ->assertRedirect('/wow/player/us/tichondrius/skylake');
        Livewire::test(Search::class)->set('name', 'Someone')->set('realm', 'AzjolNerub')->call('search')
            ->assertRedirect('/wow/player/us/azjolnerub/someone');
        Livewire::test(Search::class)->set('name', 'Skylake')->set('realm', 'Nowhere')->call('search')
            ->assertHasErrors('realm');

        $this->assertSame(['Skylake', 'Tichondrius', 'us'], Search::parse('https://check-pvp.fr/us/Tichondrius/Skylake', '', 'eu'));
        $this->assertSame(['skylake', 'azjol nerub', 'us'], Search::parse('https://worldofwarcraft.blizzard.com/en-us/character/us/azjol-nerub/skylake', '', 'eu'));
    }

    public function test_a_page_view_asks_blizzard_nothing_until_a_browser_runs_it(): void
    {
        $this->fakeBlizzard();

        $html = $this->get('/wow/player/us/tichondrius/skylake')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html, 'nothing stored is nothing to index');
        Http::assertNothingSent();
        $this->assertSame(0, BattlenetCharacter::count());
    }

    public function test_the_browsers_lookup_stores_the_character_and_its_rating(): void
    {
        $this->fakeBlizzard();

        Livewire::test(Show::class, ['region' => 'us', 'realm' => 'tichondrius', 'name' => 'skylake'])
            ->call('load')
            ->assertSee('Skylake')
            ->assertSee('1850');

        $c = BattlenetCharacter::sole();
        $this->assertNull($c->battlenet_account_id);
        $this->assertNotNull($c->looked_up_at);
        $this->assertSame([1850], array_column(PlayerRatingHistory::sole()->points, 1));

        // Stored and fresh: the page now serves it with no call, and is indexable.
        Http::fake();
        $html = $this->get('/wow/player/us/tichondrius/skylake')->assertOk()->getContent();
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringContainsString('Skylake-Tichondrius US PvP profile', $html);
        Http::assertNothingSent();
    }

    public function test_a_changed_rating_adds_to_the_history_and_an_unchanged_one_does_not(): void
    {
        $this->fakeBlizzard(1800);
        Livewire::test(Show::class, ['region' => 'us', 'realm' => 'tichondrius', 'name' => 'skylake'])->call('load');
        BattlenetCharacter::query()->update(['synced_at' => now()->subDay()]);

        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeBlizzard(1850);
        Livewire::test(Show::class, ['region' => 'us', 'realm' => 'tichondrius', 'name' => 'skylake'])
            ->call('load')
            ->assertSeeInOrder(['Rating this season', '1800', '1850']);
        $this->assertSame([1800, 1850], array_column(PlayerRatingHistory::sole()->points, 1));

        BattlenetCharacter::query()->update(['synced_at' => now()->subDay()]);
        Livewire::test(Show::class, ['region' => 'us', 'realm' => 'tichondrius', 'name' => 'skylake'])->call('load');
        $this->assertCount(2, PlayerRatingHistory::sole()->points, 'the same rating again is not a new point');
    }

    public function test_a_character_blizzard_has_no_profile_for_says_so(): void
    {
        $this->fakeBlizzard(exists: false);

        Livewire::test(Show::class, ['region' => 'us', 'realm' => 'tichondrius', 'name' => 'skylake'])
            ->call('load')
            ->assertSee('Blizzard has no public profile');
        $this->assertSame(0, BattlenetCharacter::count());
    }

    public function test_a_linked_character_is_one_row_and_is_never_listed_unless_someone_looked_it_up(): void
    {
        $user = User::factory()->create();
        $account = BattlenetAccount::create(['user_id' => $user->id, 'battlenet_id' => 1, 'battletag' => 'Owner#1']);
        $linked = BattlenetCharacter::create([
            'battlenet_account_id' => $account->id, 'region' => 'us', 'blizzard_character_id' => 777,
            'name' => 'Skylake', 'realm_slug' => 'tichondrius', 'realm_name' => 'Tichondrius', 'level' => 80, 'synced_at' => now()->subHour(),
        ]);

        $sitemap = $this->get('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString('/wow/player/us/tichondrius/skylake', $sitemap);

        $this->fakeBlizzard();
        Livewire::test(Show::class, ['region' => 'us', 'realm' => 'tichondrius', 'name' => 'skylake'])->call('refresh');

        $this->assertSame(1, BattlenetCharacter::count());
        $this->assertSame($account->id, $linked->fresh()->battlenet_account_id, 'a lookup never takes a character off its account');
        Cache::forget(\App\Http\Controllers\SitemapController::CACHE_KEY);
        $this->assertStringContainsString('/wow/player/us/tichondrius/skylake', $this->get('/sitemap.xml')->getContent());
    }

    public function test_a_mixed_case_address_goes_to_the_one_address(): void
    {
        $this->get('/wow/player/us/Tichondrius/Skylake')->assertRedirect('/wow/player/us/tichondrius/skylake');
        $this->get('/wow/player/xx/tichondrius/skylake')->assertNotFound();
    }
}
