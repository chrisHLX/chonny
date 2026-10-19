<?php

namespace Tests\Feature;

use App\Http\Services\PlayerExperienceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Looking up a player from the log's `Name-Realm-Region` (2026-10-19): the realm slug comes from
 * Blizzard's realm list, and a lookup Blizzard failed to answer is not remembered as "no profile".
 */
class PlayerExperienceLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.battlenet.client_id' => 'id', 'services.battlenet.client_secret' => 'secret']);
        Cache::flush();
        \App\Http\Services\RealmDirectory::forget();
    }

    private function realmList(): array
    {
        return ['realms' => [
            ['name' => 'Azjol-Nerub', 'slug' => 'azjolnerub'],
            ['name' => 'Altar of Storms', 'slug' => 'altar-of-storms'],
            ['name' => "Jubei'Thos", 'slug' => 'jubeithos'],
        ]];
    }

    public function test_the_realm_slug_comes_from_blizzards_realm_list(): void
    {
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't']),
            '*/data/wow/realm/index*' => Http::response($this->realmList()),
        ]);
        $experience = app(PlayerExperienceService::class);

        $this->assertSame('azjolnerub', $experience->realmSlug('AzjolNerub', 'us'));
        $this->assertSame('altar-of-storms', $experience->realmSlug('AltarofStorms', 'us'));
        $this->assertSame('jubeithos', $experience->realmSlug("Jubei'Thos", 'us'));
        // A realm the list does not hold, and no region: the capitals rule.
        $this->assertSame('bleeding-hollow', $experience->realmSlug('BleedingHollow', 'us'));
        $this->assertSame('bleeding-hollow', $experience->realmSlug('BleedingHollow'));
    }

    public function test_a_player_on_a_hyphenated_realm_is_found(): void
    {
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't']),
            '*/data/wow/realm/index*' => Http::response($this->realmList()),
            '*/character/azjolnerub/*/achievements/statistics*' => Http::response(['categories' => []]),
            '*/character/azjolnerub/*/achievements*' => Http::response(['achievements' => []]),
            '*' => Http::response([], 404),
        ]);

        $this->assertTrue(app(PlayerExperienceService::class)->lookup('Fleshoflíght-AzjolNerub-US')['found']);
    }

    public function test_a_failed_lookup_is_not_cached_as_no_profile(): void
    {
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't']),
            '*/data/wow/realm/index*' => Http::response($this->realmList()),
            '*/profile/wow/character/*' => Http::sequence()->push([], 503)->push([], 503)->push([], 404)->push([], 404),
        ]);
        $experience = app(PlayerExperienceService::class);

        $this->assertFalse($experience->lookup('Someone-AltarofStorms-US')['found']);
        $this->assertNull($experience->cached('Someone-AltarofStorms-US'), 'a 503 is asked again next time');

        $this->assertFalse($experience->lookup('Someone-AltarofStorms-US')['found']);
        $this->assertSame(['found' => false], $experience->cached('Someone-AltarofStorms-US'), 'a 404 is no profile, and is remembered');
    }
}
