<?php

namespace Tests\Feature;

use App\Http\Services\CompLeaderboardService;
use App\Livewire\CompBoard;
use App\Livewire\WowComps;
use App\Models\ArenaRound;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\PvpLeaderboardEntry;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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

    /** A character's experience as PlayerExperienceService caches it after a lookup. */
    private function experience(string $fullName, int $best3v3): void
    {
        Cache::put('player_experience:v1:'.md5(mb_strtolower($fullName)), ['found' => true, 'exp3v3' => $best3v3], now()->addDay());
    }

    public function test_the_leaderboard_filters_comps_by_the_teams_experience(): void
    {
        $this->game(User::factory()->create(), 'm1', true);
        $this->game(User::factory()->create(), 'm2', true);   // 2-0, so RMP sorts first on the tie in games
        // RMP's players are known, 2500 on average; Jungle's have no profile.
        $this->experience('Rogueguy-Area52-US', 2600);
        $this->experience('Mageguy-Area52-US', 2500);
        $this->experience('Priestguy-Area52-US', 2400);

        $svc = app(CompLeaderboardService::class);
        $all = $svc->board();
        $this->assertSame(['mage-rogue-priest', 'druid-hunter-priest'], array_column($all['comps'], 'key'));
        $this->assertSame([4, 2], [$all['teams'], $all['known']]);

        $top = $svc->board(2400);
        $this->assertSame(['mage-rogue-priest'], array_column($top['comps'], 'key'), 'a team of unknown experience is left out of a filter');
        $this->assertSame([2500, 2, 2400], [$top['comps'][0]['exp'], $top['games'], $top['minExp']]);
        $this->assertCount(2, $svc->board(2401)['comps'], 'a number that is not a band is the whole board');

        $this->get('/wow/leaderboards')->assertOk()->assertSee('RMP')->assertSee('Jungle')->assertSee('Known for 2 of 4 teams');
        $this->get('/wow/leaderboards?exp=2400')->assertOk()->assertSee('RMP')->assertDontSee('Jungle');
        $this->get('/wow/leaderboards?exp=junk')->assertOk()->assertSee('Jungle');
    }

    public function test_the_players_tab_is_blizzards_ladder_with_the_comps_each_player_was_seen_on(): void
    {
        $this->game(User::factory()->create(), 'm1', true);
        $this->game(User::factory()->create(), 'm2', true);
        $row = fn (int $id, string $name, string $realm, int $rank, int $rating, string $bracket = '3v3', ?string $class = null, int $season = 40) => [
            'region' => 'us', 'season' => $season, 'bracket' => $bracket, 'blizzard_character_id' => $id, 'name' => $name, 'realm_slug' => $realm,
            'class_slug' => $class, 'rank' => $rank, 'rating' => $rating, 'won' => 50, 'lost' => 40, 'seen_at' => now(),
        ];
        PvpLeaderboardEntry::query()->insert([
            $row(1, 'Rogueguy', 'area-52', 1, 2850),
            $row(2, 'Stranger', 'stormrage', 2, 2800),
            $row(3, 'Mageguy', 'area-52', 3, 2600),
            $row(2, 'Stranger', 'stormrage', 9, 2400, 'shuffle-warrior-arms', 'warrior'),
            $row(4, 'Druidguy', 'tichondrius', 1, 2900, '3v3', null, 39),   // last season's ladder
        ]);

        $svc = app(CompLeaderboardService::class);
        $ladder = $svc->ladder('us');
        $this->assertSame([3, 2], [$ladder['total'], $ladder['seen']]);
        $this->assertSame(['Rogueguy', 'Stranger', 'Mageguy'], array_column($ladder['players'], 'name'));
        $this->assertSame(['mage-rogue-priest', 2], [$ladder['players'][0]['comps'][0]['key'], $ladder['players'][0]['comps'][0]['games']]);
        $this->assertSame([[], 'warrior', 'rogue'], [$ladder['players'][1]['comps'], $ladder['players'][1]['class'], $ladder['players'][0]['class']]);
        $this->assertSame(['Rogueguy', 'Mageguy'], array_column($svc->ladder('us', true)['players'], 'name'));
        $this->assertNull($svc->ladder('eu')['season']);

        // Each comp names the best-rated ladder players seen on it; last season's ladder does not count.
        $board = collect($svc->board()['comps'])->keyBy('key');
        $this->assertSame([['Rogueguy', 2850], ['Mageguy', 2600]], array_map(fn ($p) => [$p['name'], $p['rating']], $board['mage-rogue-priest']['top']));
        $this->assertSame([], $board['druid-hunter-priest']['top']);

        $this->get('/wow/leaderboards')->assertOk()->assertSee('Played by')->assertSee('/wow/player/us/area-52/rogueguy', false);
        $this->get('/wow/leaderboards?tab=players')->assertOk()->assertSee('Stranger')->assertSee('2 of its 3 players are in an uploaded game')
            ->assertSee('/wow/comps/played/mage-rogue-priest', false);
        $this->get('/wow/leaderboards?tab=players&region=junk')->assertOk()->assertSee('Stranger');
    }

    public function test_the_preview_shows_the_top_comps_then_only_the_picked_one(): void
    {
        $this->game(User::factory()->create(), 'm1', true);

        Livewire::withoutLazyLoading()->test(CompBoard::class)
            ->assertSee('top comps')->assertSee('RMP')->assertSee('Jungle')
            ->assertSee('/wow/leaderboards', false)
            ->call('pick', 'mage-rogue-priest')
            ->assertDispatched('pick-lineup', specs: [63, 256, 259]);
    }

    public function test_the_preview_of_a_picked_comp_is_one_line_with_its_games(): void
    {
        $this->game(User::factory()->create(), 'm1', true);

        // Its own test: only the first Livewire::test() of a component in a process returns full HTML.
        Livewire::withoutLazyLoading()->test(CompBoard::class, ['selected' => 'mage-rogue-priest'])
            ->assertSee('in real games this season')->assertDontSee('Jungle')
            ->assertSee('/wow/comps/played/mage-rogue-priest', false);
    }

    public function test_a_line_up_from_the_preview_fills_the_comps_page_slots_healer_first(): void
    {
        $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
        $spec = function (string $class, string $slug, int $external) use ($game) {
            $c = GameClass::firstOrCreate(['game_id' => $game->id, 'slug' => $class], ['name' => ucfirst($class)]);

            return Specialization::create(['class_id' => $c->id, 'name' => ucfirst($slug), 'slug' => $slug, 'external_spec_id' => $external]);
        };
        $disc = $spec('priest', 'discipline', 256);
        $fire = $spec('mage', 'fire', 63);
        $sin = $spec('rogue', 'assassination', 259);
        $spec('priest', 'holy', 257);

        $page = Livewire::test(WowComps::class)
            ->call('pickLineup', [256, 257, 63])   // two healers: not a line-up
            ->assertSet('slots.0.specId', null)
            ->call('pickLineup', [63, 256, 259])
            ->assertSet('slots.0.specId', $disc->id)
            ->assertSet('slots.1.specId', $fire->id)
            ->assertSet('slots.2.specId', $sin->id);

        $this->assertSame('mage-rogue-priest', $page->instance()->boardKey());
    }
}
