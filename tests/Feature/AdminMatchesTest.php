<?php

namespace Tests\Feature;

use App\Jobs\BuildCoachPages;
use App\Livewire\Admin\Matches;
use App\Models\ArenaReview;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** /admin/matches: every uploaded game, for an admin only (Admin\Matches). */
class AdminMatchesTest extends TestCase
{
    use RefreshDatabase;

    /** Card files this test wrote, removed afterwards; a real player's folder is never touched. */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            File::delete($path);
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Boss']);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function player(string $name, string $spec, string $class, string $side, bool $you = false): array
    {
        return [
            'name' => $name, 'isYou' => $you, 'teamByRound' => ['1' => $side], 'roundsPlayed' => 1,
            'spec' => ['label' => $spec, 'classSlug' => $class],
            'gear' => ['median' => 345.4], 'totals' => ['damageDone' => 2500000, 'healingEffective' => 1000000, 'deaths' => 0],
        ];
    }

    private function game(User $uploader, string $lobby, string $bracket, int $won, int $lost, array $players, int $mmrUs = 2100): ArenaReview
    {
        ArenaRound::create([
            'user_id' => $uploader->id, 'lobby_id' => $lobby, 'roster_key' => md5($lobby), 'sequence' => 1,
            'match_id' => $lobby, 'bracket' => $bracket, 'played_at' => now()->subHour(),
            'payload' => ['metadata' => ['durationInSeconds' => 95], 'analysis' => ['mmr' => ['us' => $mmrUs, 'them' => 2050]]],
        ]);

        return ArenaReview::create([
            'user_id' => $uploader->id, 'lobby_id' => $lobby, 'character_name' => collect($players)->firstWhere('isYou', true)['name'],
            'bracket' => $bracket, 'played_at' => now()->subHour(), 'rounds' => $won + $lost, 'rounds_won' => $won, 'rounds_lost' => $lost,
            'payload' => [
                'players' => $players,
                'rounds' => [['sequence' => 1, 'matchId' => $lobby, 'durationSeconds' => 95, 'result' => $won > $lost ? 'won' : 'lost', 'killedName' => 'Wobble-Stormrage-US']],
                'record' => ['won' => $won, 'lost' => $lost],
            ],
        ]);
    }

    private function twoGames(User $uploader): ArenaReview
    {
        $this->game($uploader, str_repeat('b', 32), 'Rated Solo Shuffle', 4, 2, [
            $this->player('Healy-Frostmourne-US', 'Holy Paladin', 'paladin', 'side1', true),
            $this->player('Zorp-Illidan-US', 'Fire Mage', 'mage', 'side2'),
        ]);

        return $this->game($uploader, str_repeat('a', 32), '3v3', 1, 0, [
            $this->player('Skylake-Frostmourne-US', 'Discipline Priest', 'priest', 'side1', true),
            $this->player('Marciral-Illidan-US', 'Unholy Death Knight', 'deathknight', 'side1'),
            $this->player('Dogcarryme-Stormrage-US', 'Balance Druid', 'druid', 'side2'),
        ], 2129);
    }

    public function test_only_an_admin_sees_the_matches(): void
    {
        $this->get('/admin/matches')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/matches')->assertForbidden();
    }

    public function test_an_admin_sees_every_uploader_and_every_game_with_both_teams(): void
    {
        $uploader = User::factory()->create(['name' => 'Jake']);
        $this->twoGames($uploader);
        $waiting = User::factory()->create(['name' => 'Waiting Wendy']);
        $waiting->forceFill(['coach_token_at' => now()])->save();

        $this->actingAs($this->admin())->get('/admin/matches')
            ->assertOk()
            ->assertSee('Jake')
            ->assertSee('Waiting Wendy')   // connected the app, nothing uploaded yet
            ->assertSee('nothing yet')
            ->assertSee('Skylake')
            ->assertSee('Unholy Death Knight')   // with
            ->assertSee('Balance Druid')         // against
            ->assertSee('2129')
            ->assertSee('1:35')
            ->assertSee('4&ndash;2', false);    // the shuffle's record
    }

    public function test_the_filters_narrow_the_games(): void
    {
        $this->twoGames(User::factory()->create());
        $admin = $this->admin();

        // Opponents' specs appear only in game rows (the uploaders table lists characters, not them).
        $this->actingAs($admin)->get('/admin/matches?bracket=3v3')->assertSee('Balance Druid')->assertDontSee('Fire Mage');
        $this->actingAs($admin)->get('/admin/matches?result=won&q=Zorp')->assertSee('Fire Mage')->assertDontSee('Balance Druid');
        // A spell or spec in the payload is not a player: the search is anchored on names.
        $this->actingAs($admin)->get('/admin/matches?q=Balance')->assertSee('No games match these filters.');
    }

    public function test_a_game_opens_to_its_rounds_and_the_uploaders_card(): void
    {
        $uploader = User::factory()->create();
        $game = $this->twoGames($uploader);
        $card = BuildCoachPages::dir($uploader->id).'/'.$game->lobby_id.'.html';
        File::ensureDirectoryExists(dirname($card));
        File::put($card, '<html><body>The card itself</body></html>');
        $this->written[] = $card;

        $admin = $this->admin();
        Livewire::actingAs($admin)->test(Matches::class)
            ->call('toggle', $game->id)
            ->assertSee('Open the game card')
            ->assertSee('Wobble')        // who died
            ->assertSee('345');          // item level

        $url = route('admin.matches.card', ['user' => $uploader->id, 'file' => $game->lobby_id.'.html']);
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('The card itself');
        $this->actingAs($uploader)->get($url)->assertForbidden();
        $this->actingAs($admin)->get(route('admin.matches.card', ['user' => $uploader->id, 'file' => str_repeat('c', 32).'.html']))->assertNotFound();
    }
}
