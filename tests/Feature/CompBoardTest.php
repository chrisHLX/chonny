<?php

namespace Tests\Feature;

use App\Http\Services\CompLeaderboardService;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The comp board on /wow/comps (2026-10-19): this season's 3v3 comps from every uploaded game, both
 * teams of each, a game uploaded twice counted once; each comp's card read from the side that faced
 * it with no player named; the card only for an account with the app connected.
 */
class CompBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.battlenet.client_id' => 'id', 'services.battlenet.client_secret' => 'secret']);
        Cache::flush();
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't']),
            '*/pvp-season/index*' => Http::response(['current_season' => ['id' => 42]]),
            '*/pvp-season/42*' => Http::response(['season_start_timestamp' => now()->subDays(30)->getTimestampMs()]),
            '*' => Http::response([], 404),
        ]);
    }

    /** RMP (the uploader's team) against Jungle. */
    private function game(User $uploader, string $match, bool $rmpWon): void
    {
        $team = [
            'us' => [['Rogueguy-Area52-US', 'rogue', 259, false], ['Mageguy-Area52-US', 'mage', 63, false], ['Priestguy-Area52-US', 'priest', 256, true]],
            'them' => [['Druidguy-Tichondrius-US', 'druid', 103, false], ['Hunterguy-Tichondrius-US', 'hunter', 253, false], ['Healguy-Tichondrius-US', 'priest', 257, true]],
        ];
        $players = [];
        foreach ($team as $side => $members) {
            foreach ($members as $i => [$name, $class, $spec, $healer]) {
                $players[] = ['guid' => "P-$side-$i", 'name' => $name, 'spec' => ucfirst($class), 'specExternalId' => $spec, 'classSlug' => $class,
                    'side' => $side, 'healer' => $healer, 'logger' => $side === 'us' && $i === 0, 'ilvl' => 340, 'pvpTalents' => []];
            }
        }

        ArenaRound::create([
            'user_id' => $uploader->id, 'lobby_id' => $match, 'roster_key' => 'r', 'sequence' => 1, 'match_id' => $match,
            'bracket' => '3v3', 'played_at' => now()->subDays(2),
            'payload' => [
                'metadata' => ['durationInSeconds' => 140],
                'throughput' => ['players' => []],
                'analysis' => ['won' => $rmpWon, 'mmr' => ['us' => 2100, 'them' => 2050], 'players' => $players,
                    'goes' => [], 'deaths' => [], 'kicks' => [], 'lockout' => [], 'defensives' => []],
            ],
        ]);
    }

    public function test_the_board_counts_both_teams_once_a_game_by_their_three_classes(): void
    {
        $a = User::factory()->create();
        $this->game($a, 'm1', true);
        $this->game(User::factory()->create(), 'm1', true);   // the same game from a teammate
        $this->game($a, 'm2', false);
        $this->game($a, 'm3', true);

        $board = app(CompLeaderboardService::class)->board();
        $this->assertSame(3, $board['games']);

        $rmp = collect($board['comps'])->firstWhere('key', 'mage-rogue-priest');
        $jungle = collect($board['comps'])->firstWhere('key', 'druid-hunter-priest');
        $this->assertSame(['RMP', 3, 2, 1], [$rmp['nick'], $rmp['games'], $rmp['won'], $rmp['lost']]);
        $this->assertSame(['Jungle', 3, 1, 2], [$jungle['nick'], $jungle['games'], $jungle['won'], $jungle['lost']]);
        $this->assertSame(['druid-hunter-priest', 2, 1], [$rmp['matchups'][0]['key'], $rmp['matchups'][0]['won'], $rmp['matchups'][0]['lost']]);
    }

    public function test_the_card_reads_each_game_from_the_side_that_faced_the_comp_and_names_no_one(): void
    {
        $this->game(User::factory()->create(), 'm1', true);
        $this->game(User::factory()->create(), 'm2', true);

        // RMP is the uploader's own team in both games, so its card turns every game round.
        $html = app(CompLeaderboardService::class)->card('mage-rogue-priest');

        $this->assertStringContainsString('they won 2 and lost 0', $html);
        foreach (['Rogueguy', 'Mageguy', 'Priestguy', 'Druidguy', 'Hunterguy', 'Healguy'] as $name) {
            $this->assertStringNotContainsString($name, $html);
        }
        $this->assertStringNotContainsString('class="you"', $html);
        $this->assertStringNotContainsString('file:///', $html, 'drawn for the website');
    }

    public function test_the_card_needs_an_account_with_the_app_connected(): void
    {
        $this->game(User::factory()->create(), 'm1', true);

        $this->get('/wow/comps/played/mage-rogue-priest')->assertOk()->assertSee('Sign up to open the RMP card')->assertDontSee('played-card');
        $this->get('/wow/comps/played-card/mage-rogue-priest')->assertForbidden();

        $player = User::factory()->create();
        $this->actingAs($player)->get('/wow/comps/played/mage-rogue-priest/druid-hunter-priest')->assertOk()->assertSee('Connect the app to open the RMP vs Jungle card');
        $this->actingAs($player)->get('/wow/comps/played-card/mage-rogue-priest')->assertForbidden();

        $player->forceFill(['coach_token_at' => now()])->save();
        $this->actingAs($player)->get('/wow/comps/played/mage-rogue-priest')->assertOk()->assertSee('played-card/mage-rogue-priest', false);
        $this->actingAs($player)->get('/wow/comps/played-card/mage-rogue-priest')->assertOk()->assertDontSee('Rogueguy');

        $this->get('/wow/comps/played/warrior-warrior-warrior')->assertNotFound();
    }
}
