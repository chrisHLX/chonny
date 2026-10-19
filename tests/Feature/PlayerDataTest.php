<?php

namespace Tests\Feature;

use App\Http\Services\RealmDirectory;
use App\Livewire\Players\Search;
use App\Models\ArenaRound;
use App\Models\ArenaRoundPlayer;
use App\Models\PlayerRatingHistory;
use App\Models\PvpLeaderboardEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a player page knows without asking Blizzard (2026-10-19): the uploaded games a player was
 * in, whoever uploaded them; their places on the daily-pulled leaderboards and the rating history
 * those build; and the names the search box suggests from both.
 */
class PlayerDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.battlenet.client_id' => 'id', 'services.battlenet.client_secret' => 'secret']);
        Cache::flush();
        RealmDirectory::forget();
        // No realm list: links fall back to splitting the log's realm at capitals.
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function round(User $uploader, string $match, bool $won, array $us, array $them): ArenaRound
    {
        $players = [];
        $throughput = [];
        foreach ([['us', $us], ['them', $them]] as [$side, $names]) {
            foreach ($names as $i => [$name, $spec]) {
                $guid = 'Player-'.$side.'-'.$i.'-'.$name;
                $players[] = ['guid' => $guid, 'name' => $name, 'spec' => 'x', 'specExternalId' => $spec, 'classSlug' => 'mage', 'side' => $side, 'healer' => false, 'logger' => $side === 'us' && $i === 0, 'ilvl' => 340, 'pvpTalents' => ['Ice Wall']];
                $throughput[$guid] = ['damageDone' => 1500000, 'healingEffective' => 20000, 'damageTaken' => 900000, 'deaths' => 0];
            }
        }

        return ArenaRound::create([
            'user_id' => $uploader->id, 'lobby_id' => $match, 'roster_key' => 'r', 'sequence' => 1, 'match_id' => $match,
            'bracket' => '3v3', 'played_at' => now(),
            'payload' => [
                'metadata' => ['durationInSeconds' => 125],
                'throughput' => ['players' => $throughput],
                'analysis' => ['won' => $won, 'mmr' => ['us' => 2100, 'them' => 2050], 'players' => $players],
            ],
        ]);
    }

    public function test_a_players_page_shows_every_uploaded_game_they_were_in_once_each(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $us = [['Skylake-Frostmourne-US', 256], ['Crawlordx-Frostmourne-US', 103], ['Env-Frostmourne-US', 63]];
        $them = [['Relaxbum-Area52-US', 63], ['Other-AzjolNerub-US', 62], ['Third-Area52-US', 64]];

        $this->round($a, 'm1', true, $us, $them);
        // The same match from a teammate's upload: one game, not two.
        $this->round($b, 'm1', true, [$us[1], $us[0], $us[2]], $them);
        $this->round($a, 'm2', false, $us, $them);

        $this->assertSame(18, ArenaRoundPlayer::count(), 'six players a round, indexed as the round is saved');

        // Relaxbum was on the other side: their side lost m1 and won m2.
        $html = $this->get('/wow/player/us/area52/relaxbum')->assertOk()->getContent();
        $this->assertStringContainsString('In 2 games uploaded to MindCollector', $html);
        $this->assertStringContainsString('(1&ndash;1)', $html);
        $this->assertStringContainsString('/wow/player/us/frostmourne/skylake', $html, 'everyone in the game links to their page');
        $this->assertStringContainsString('/wow/player/us/azjol-nerub/other', $html);
        $this->assertStringNotContainsString('noindex', $html, 'a player we have seen play is a page worth indexing');
        $this->assertStringContainsString('Ice Wall', $html);

        // Partners: Skylake played both 3v3s with Crawlordx and Env.
        $html = $this->get('/wow/player/us/frostmourne/skylake')->getContent();
        $this->assertMatchesRegularExpression('/Plays 2v2 and 3v3 with.*Crawlordx.*2 &middot; 1&ndash;1/s', $html);
    }

    public function test_re_measuring_a_round_reindexes_its_players(): void
    {
        $round = $this->round(User::factory()->create(), 'm1', true, [['A-Realm-US', 63]], [['B-Realm-US', 64]]);
        $payload = $round->payload;
        $payload['analysis']['won'] = false;
        $round->update(['payload' => $payload]);

        $this->assertSame(2, ArenaRoundPlayer::count());
        $this->assertFalse(ArenaRoundPlayer::where('name', 'A')->sole()->won);
        $this->assertTrue(ArenaRoundPlayer::where('name', 'B')->sole()->won);
    }

    private function fakeBoards(int $rating): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't']),
            '*/pvp-season/index*' => Http::response(['current_season' => ['id' => 42]]),
            '*/pvp-leaderboard/index*' => Http::response(['leaderboards' => [['name' => '3v3'], ['name' => 'shuffle-overall'], ['name' => 'shuffle-mage-frost']]]),
            '*/pvp-leaderboard/3v3*' => Http::response(['entries' => [
                ['character' => ['name' => 'Petkick', 'id' => 5, 'realm' => ['slug' => 'tichondrius']], 'faction' => ['type' => 'HORDE'], 'rank' => 1, 'rating' => $rating, 'season_match_statistics' => ['played' => 10, 'won' => 8, 'lost' => 2]],
            ]]),
            '*/pvp-leaderboard/shuffle-mage-frost*' => Http::response(['entries' => [
                ['character' => ['name' => 'Petkick', 'id' => 5, 'realm' => ['slug' => 'tichondrius']], 'rank' => 40, 'rating' => 2400, 'season_match_statistics' => ['played' => 60, 'won' => 35, 'lost' => 25]],
            ]]),
            '*' => Http::response([], 404),
        ]);
    }

    public function test_the_daily_pull_gives_a_leaderboard_player_a_page_and_a_history(): void
    {
        $this->fakeBoards(2900);
        $this->artisan('wow:pvp-leaderboards', ['--region' => ['us']])->assertSuccessful();

        $this->assertSame(['3v3', 'shuffle-mage-frost'], PvpLeaderboardEntry::orderBy('bracket')->pluck('bracket')->all(), 'the overall board repeats the spec boards and is skipped');

        $this->fakeBoards(2950);
        $this->artisan('wow:pvp-leaderboards', ['--region' => ['us']])->assertSuccessful();
        $this->assertSame([2900, 2950], array_column(PlayerRatingHistory::where('bracket', '3v3')->sole()->points, 1));
        $this->assertCount(1, PlayerRatingHistory::where('bracket', 'shuffle-mage-frost')->sole()->points, 'an unchanged rating adds no point');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake();
        $html = $this->get('/wow/player/us/tichondrius/petkick')->assertOk()->getContent();
        $this->assertStringContainsString('On the leaderboards', $html);
        $this->assertStringContainsString('#1', $html);
        $this->assertStringContainsString('2950', $html);
        $this->assertMatchesRegularExpression('/3v3.*2900.*&rarr;.*2950/s', $html);
        $this->assertStringNotContainsString('noindex', $html);
        Http::assertNothingSent();
    }

    public function test_the_search_box_suggests_leaderboard_players_and_players_seen_in_games(): void
    {
        $this->fakeBoards(2900);
        $this->artisan('wow:pvp-leaderboards', ['--region' => ['us']])->assertSuccessful();
        $this->round(User::factory()->create(), 'm1', true, [['Petal-Frostmourne-US', 63]], [['Pebble-Area52-US', 64]]);

        $suggestions = Livewire::test(Search::class)->set('name', 'pe')->instance()->suggestions;
        $this->assertSame('Petkick', $suggestions[0]['name'], 'the leaderboard first');
        $this->assertSame('/wow/player/us/tichondrius/petkick', $suggestions[0]['url']);
        $this->assertEqualsCanonicalizing(['Petkick', 'Petal', 'Pebble'], array_column($suggestions, 'name'));

        $this->assertSame(['Pebble'], array_column(Livewire::test(Search::class)->set('name', 'Pe-Area')->instance()->suggestions, 'name'), 'Name-Realm narrows by realm');
        $this->assertSame([], Livewire::test(Search::class)->set('name', 'p')->instance()->suggestions, 'two letters before suggesting');
    }
}
