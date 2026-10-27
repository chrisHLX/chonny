<?php

namespace Tests\Feature;

use App\Http\Services\RealmDirectory;
use App\Support\PlayerUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A game card links every player; the realm lookup behind each link must never hold a build up. */
class RealmDirectoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.battlenet.client_id' => 'id', 'services.battlenet.client_secret' => 'secret']);
        Cache::flush();
        RealmDirectory::forget();
    }

    public function test_a_chinese_realm_is_linked_without_asking_blizzard(): void
    {
        Http::fake();

        $url = PlayerUrl::fromLogName('Zhanshi-死亡之翼-CN', absolute: false);

        $this->assertStringStartsWith('/wow/player/cn/', $url);
        Http::assertNothingSent();
    }

    public function test_a_failed_lookup_is_asked_once_not_once_per_player(): void
    {
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't']),
            '*/data/wow/realm/index*' => Http::response([], 503),
        ]);

        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $name) {
            RealmDirectory::forget();   // a fresh process each time, as each queued build is
            $this->assertSame("/wow/player/us/bleeding-hollow/".strtolower($name), PlayerUrl::fromLogName("$name-BleedingHollow-US", absolute: false));
        }

        $asked = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'realm/index'))->count();
        $this->assertSame(1, $asked);
    }
}
