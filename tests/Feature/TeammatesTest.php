<?php

namespace Tests\Feature;

use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\RealmDirectory;
use App\Http\Services\TeammatesService;
use App\Jobs\BuildCoachPages;
use App\Models\ArenaRound;
use App\Models\ArenaRoundPlayer;
use App\Models\BattlenetCharacter;
use App\Models\PvpLeaderboardEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Who a player queues with and how it went, from stored games only (TeammatesService). */
class TeammatesTest extends TestCase
{
    use RefreshDatabase;

    private int $match = 0;

    private function player(string $name, string $guid, string $side, bool $logger = false): array
    {
        return ['name' => "{$name}-Frostmourne-US", 'guid' => $guid, 'side' => $side, 'logger' => $logger, 'spec' => 'Feral Druid',
            'specExternalId' => 103, 'classSlug' => 'druid', 'healer' => false];
    }

    private function game(User $user, array $mates, bool $won, string $bracket = '3v3'): void
    {
        $this->match++;
        $players = [$this->player('Me', 'P-me', 'us', true), $this->player('Enemy', 'P-enemy', 'them')];
        foreach ($mates as $mate) {
            $players[] = $this->player($mate, "P-{$mate}", 'us');
        }

        $habits = ['casts' => ['Shred' => 10], 'free' => 120.0, 'control' => 4, 'offTarget' => 2, 'petHits' => 0, 'petOnTarget' => 0, 'kicks' => 0, 'kicked' => []];
        $breakdown = ['alive' => 120.0, 'idle' => 12.0, 'casts' => 50, 'damage' => [['spell' => 'Shred', 'amount' => 900000, 'hits' => 10, 'over' => 0]], 'healing' => [], 'absorbs' => []];
        $guids = array_column($players, 'guid');

        ArenaRound::forceCreate([
            'user_id' => $user->id, 'lobby_id' => 'lobby'.$this->match, 'sequence' => 1, 'roster_key' => 'r'.$this->match,
            'match_id' => 'match'.$this->match, 'bracket' => $bracket, 'played_at' => now()->subMinutes(100 - $this->match),
            'payload' => ['metadata' => ['durationInSeconds' => 120], 'analysis' => [
                'version' => 10, 'won' => $won, 'mmr' => ['us' => 2100, 'them' => 2100], 'players' => $players,
                'habits' => array_fill_keys($guids, $habits), 'breakdown' => array_fill_keys($guids, $breakdown),
                'goes' => [], 'deaths' => [], 'kicks' => [], 'overlaps' => ['us' => 0, 'them' => 0, 'rows' => []],
                'lockout' => array_fill_keys($guids, 0.0),
                'defensives' => ['us' => ['spent' => 0, 'outsideTheirGoes' => 0, 'rows' => []], 'them' => ['spent' => 0, 'outsideTheirGoes' => 0, 'rows' => []]],
            ]],
        ]);
    }

    private function games(User $user, string $mate, int $won, int $lost): void
    {
        for ($i = 0; $i < $won; $i++) {
            $this->game($user, [$mate], true);
        }
        for ($i = 0; $i < $lost; $i++) {
            $this->game($user, [$mate], false);
        }
    }

    public function test_a_small_perfect_record_does_not_outrank_a_real_one(): void
    {
        $user = User::factory()->create();
        $this->games($user, 'Lucky', 3, 0);
        $this->games($user, 'Steady', 14, 6);
        $this->games($user, 'Rough', 4, 8);

        $page = app(TeammatesService::class)->build($user);

        $this->assertSame(['Steady', 'Rough', 'Lucky'], array_column(array_values($page['mates']), 'name'));
        $steady = collect($page['mates'])->firstWhere('name', 'Steady');
        $this->assertSame([20, 14, 6], [$steady['games'], $steady['won'], $steady['lost']]);
        $this->assertFalse($steady['lead']);
        $this->assertTrue(collect($page['mates'])->firstWhere('name', 'Lucky')['lead']);
    }

    public function test_shuffle_is_left_out(): void
    {
        $user = User::factory()->create();
        $this->game($user, ['Friend'], true);
        $this->game($user, ['Friend'], false);
        $this->game($user, ['Stranger'], true, 'Rated Solo Shuffle');

        $page = app(TeammatesService::class)->build($user);

        $this->assertSame(2, $page['overview']['games']);
        $this->assertSame(['Friend'], array_column(array_values($page['mates']), 'name'));
    }

    public function test_the_uploader_is_not_their_own_teammate_and_enemies_are_not_teammates(): void
    {
        $user = User::factory()->create();
        $this->games($user, 'Friend', 2, 0);

        $html = app(TeammatesService::class)->build($user)['overview']['html'];

        $this->assertStringContainsString('Friend', $html);
        $this->assertStringNotContainsString('>Enemy<', $html);
        $this->assertStringNotContainsString('>Me<', $html);
    }

    public function test_a_round_with_too_many_players_on_our_side_is_left_out(): void
    {
        $user = User::factory()->create();
        $this->games($user, 'Friend', 2, 0);
        $this->game($user, ['Friend', 'Other', 'Misplaced'], true);

        $page = app(TeammatesService::class)->build($user);

        $this->assertSame(2, $page['overview']['games']);
        $this->assertStringNotContainsString('Misplaced', $page['overview']['html']);
    }

    public function test_your_games_lists_teammates_in_order(): void
    {
        $user = User::factory()->create();
        $dir = \App\Jobs\BuildCoachPages::dir($user->id);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        file_put_contents("{$dir}/index.json", json_encode(['games' => [], 'teammates' => [
            'b' => ['name' => 'Second', 'games' => 5, 'won' => 3, 'lost' => 2, 'file' => 'mate-b.html', 'order' => 2, 'color' => '#FFFFFF'],
            'all' => ['name' => 'All teammates', 'games' => 9, 'won' => 5, 'lost' => 4, 'file' => 'teammates.html', 'order' => 0],
            'a' => ['name' => 'First', 'games' => 4, 'won' => 2, 'lost' => 2, 'file' => 'mate-a.html', 'order' => 1, 'color' => '#FFFFFF'],
        ]]));

        $this->actingAs($user)->get(route('coach'))->assertOk()
            ->assertSee("Comps You've Faced")
            ->assertSeeInOrder(['All teammates', 'First', 'Second']);

        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }

    public function test_the_app_is_told_the_installer_hash_only_when_it_is_a_real_one(): void
    {
        $dir = dirname(\App\Http\Controllers\DesktopAppController::path());
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        $path = $dir.'/version.json';
        $before = is_file($path) ? file_get_contents($path) : null;

        try {
            file_put_contents($path, json_encode(['version' => '0.6.6', 'sha256' => str_repeat('a', 64)]));
            $this->getJson('/api/coach/app-version')->assertJson(['version' => '0.6.6', 'sha256' => str_repeat('a', 64)]);

            file_put_contents($path, json_encode(['version' => '0.6.6', 'sha256' => 'not a hash']));
            $this->getJson('/api/coach/app-version')->assertJson(['version' => '0.6.6', 'sha256' => null]);
        } finally {
            $before === null ? unlink($path) : file_put_contents($path, $before);
        }
    }

    public function test_one_game_together_gets_no_page_of_its_own(): void
    {
        $user = User::factory()->create();
        $this->games($user, 'Friend', 2, 0);
        $this->game($user, ['Passing'], true);

        $page = app(TeammatesService::class)->build($user);

        $this->assertArrayHasKey('us|frostmourne|friend', $page['mates']);
        $this->assertArrayNotHasKey('us|frostmourne|passing', $page['mates']);
        $this->assertStringContainsString('1 more you', $page['overview']['html']);
    }

    public function test_stored_ratings_and_titles_show_and_blizzard_is_never_asked(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->games($user, 'Rated', 2, 0);
        $this->games($user, 'Boarded', 2, 0);
        BattlenetCharacter::forceCreate([
            'region' => 'us', 'blizzard_character_id' => 1, 'name' => 'Rated', 'realm_slug' => 'frostmourne', 'realm_name' => 'Frostmourne',
            'level' => 90, 'exp_3v3' => 2600,
            'ratings' => [['label' => '3v3', 'current' => true, 'rating' => 2450]],
        ]);
        PvpLeaderboardEntry::forceCreate([
            'region' => 'us', 'season' => 41, 'bracket' => '3v3', 'blizzard_character_id' => 2, 'name' => 'Boarded', 'realm_slug' => 'frostmourne',
            'rank' => 10, 'rating' => 2300, 'won' => 10, 'lost' => 5, 'seen_at' => now(),
        ]);

        $page = app(TeammatesService::class)->build($user);

        Http::assertNothingSent();
        $this->assertStringContainsString('2450', collect($page['mates'])->firstWhere('name', 'Rated')['html']);
        $this->assertStringContainsString('2600 exp', collect($page['mates'])->firstWhere('name', 'Rated')['html']);
        $this->assertStringContainsString('2300', collect($page['mates'])->firstWhere('name', 'Boarded')['html']);
    }

    public function test_the_lookup_fills_in_teammates_once_and_skips_who_we_hold(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->games($user, 'Known', 2, 0);
        $this->games($user, 'Unknown', 2, 0);
        $this->game($user, ['Passing'], true);
        BattlenetCharacter::forceCreate(['region' => 'us', 'blizzard_character_id' => 1, 'name' => 'Known', 'realm_slug' => 'frostmourne',
            'realm_name' => 'Frostmourne', 'level' => 90, 'synced_at' => now()]);

        $looked = [];
        $this->mock(BattlenetCharacterSyncService::class)->shouldReceive('lookupPublic')->andReturnUsing(function ($region, $realm, $name) use (&$looked) {
            $looked[] = $name;

            return new BattlenetCharacter;
        });
        $this->mock(RealmDirectory::class)->shouldReceive('slugOrGuess')->andReturn('frostmourne');

        $this->artisan('wow:lookup-teammates', ['--pause' => 0])->assertSuccessful();

        // The uploader counts as a player we hold nothing on; a teammate we hold, and one game, do not.
        $this->assertEqualsCanonicalizing(['Me', 'Unknown'], $looked);
        Queue::assertPushed(BuildCoachPages::class, fn ($job) => $job->userId === $user->id);
    }

    public function test_the_lookup_skips_china_because_its_api_cannot_be_reached(): void
    {
        $user = User::factory()->create();
        $this->games($user, 'Friend', 2, 0);
        ArenaRoundPlayer::where('name', 'Friend')->update(['region' => 'cn']);
        ArenaRoundPlayer::where('name', 'Me')->update(['region' => 'cn']);

        $this->mock(BattlenetCharacterSyncService::class)->shouldNotReceive('lookupPublic');
        $this->mock(RealmDirectory::class);

        $this->artisan('wow:lookup-teammates', ['--pause' => 0])->assertSuccessful();
    }

    public function test_the_page_build_writes_the_pages_and_lists_them_in_order(): void
    {
        $user = User::factory()->create();
        $this->games($user, 'Friend', 2, 0);
        $dir = storage_path('framework/testing/mates-'.uniqid());
        mkdir($dir, 0777, true);

        $this->artisan('wow:game-cards', ['--dir' => $dir, '--user' => (string) $user->id])->assertSuccessful();

        $index = json_decode(file_get_contents("{$dir}/index.json"), true);
        $this->assertSame('All teammates', $index['teammates']['all']['name']);
        $this->assertSame(0, $index['teammates']['all']['order']);
        $this->assertFileExists("{$dir}/teammates.html");
        $this->assertFileExists($dir.'/'.$index['teammates']['us|frostmourne|friend']['file']);

        // The card cleanup deletes any page it doesn't know, so the pages must survive a second run.
        $this->artisan('wow:game-cards', ['--dir' => $dir, '--user' => (string) $user->id])->assertSuccessful();
        $this->assertFileExists("{$dir}/teammates.html");
        $this->assertFileExists($dir.'/'.$index['teammates']['us|frostmourne|friend']['file']);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}
