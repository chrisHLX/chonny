<?php

namespace Tests\Feature;

use App\Http\Services\PlayerExperienceService;
use App\Jobs\BuildCoachPages;
use App\Jobs\FetchPlayerExperience;
use App\Models\ArenaNote;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The desktop app while a player plays (Api\CoachAppController, 2026-10-25): notes and marks, and
 * the experience of the players in the game in progress.
 *
 * The rules worth testing: a note written during a game has no game yet, so it has to reach the
 * cards in the shape attachNotes() files by time (GameCardTest covers the filing); a retry must not
 * store a note twice; and a note naming a game is checked against the player's own games.
 */
class CoachAppTest extends TestCase
{
    use RefreshDatabase;

    private function note(string $key, array $body)
    {
        return $this->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/coach/note', $body);
    }

    public function test_a_mark_from_a_game_in_progress_reaches_the_cards_by_its_time(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = $user->issueCoachToken();

        $this->note($key, ['id' => 'abc', 'kind' => 'mark', 'at' => '2026-10-25 11:44:10', 'roundStart' => '2026-10-25 11:43:47'])
            ->assertOk()->assertJson(['status' => 'stored']);
        // Written about afterwards: the same id updates the mark rather than adding a second.
        $this->note($key, ['id' => 'abc', 'kind' => 'mark', 'text' => 'caught in the fear at the pillar', 'at' => '2026-10-25 11:44:10', 'roundStart' => '2026-10-25 11:43:47'])
            ->assertOk();

        $this->assertSame(1, ArenaNote::count());
        $this->assertSame([[
            'at' => '2026-10-25 11:44:10',
            'roundStart' => '2026-10-25 11:43:47',
            'text' => 'caught in the fear at the pillar',
            'kind' => 'mark',
        ]], ArenaNote::forCards($user->id), 'the wall-clock times, as attachNotes() reads notes.json');
        Queue::assertNotPushed(BuildCoachPages::class, 'the round is not uploaded yet; its upload builds the pages');
    }

    public function test_a_note_on_a_past_game_names_it_and_redraws_the_cards(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = $user->issueCoachToken();
        ArenaRound::create(['user_id' => $user->id, 'lobby_id' => 'lobby-1', 'match_id' => 'm1', 'roster_key' => 'r',
            'bracket' => '3v3', 'sequence' => 1, 'played_at' => '2026-10-25 11:00:00', 'payload' => ['analysis' => []]]);

        $this->note($key, ['id' => 'n1', 'kind' => 'note', 'text' => 'kept Pain Sup too long', 'at' => '2026-10-25 12:30:00', 'lobby' => 'lobby-1'])
            ->assertOk();

        $this->assertSame('lobby-1', ArenaNote::sole()->lobby_id);
        $this->assertSame('lobby-1', ArenaNote::forCards($user->id)[0]['game']);
        Queue::assertPushed(BuildCoachPages::class);
    }

    public function test_a_note_cannot_name_someone_elses_game_or_be_empty(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        ArenaRound::create(['user_id' => $theirs->id, 'lobby_id' => 'their-lobby', 'match_id' => 'm9', 'roster_key' => 'r',
            'bracket' => '3v3', 'sequence' => 1, 'played_at' => '2026-10-25 11:00:00', 'payload' => ['analysis' => []]]);
        $key = $mine->issueCoachToken();

        $this->note($key, ['id' => 'x1', 'kind' => 'note', 'text' => 'hi', 'at' => '2026-10-25 12:00:00', 'lobby' => 'their-lobby'])
            ->assertNotFound()->assertJson(['status' => 'unknown-game']);
        $this->note($key, ['id' => 'x2', 'kind' => 'note', 'text' => '  ', 'at' => '2026-10-25 12:00:00'])->assertStatus(422);
        $this->note($key, ['id' => 'x3', 'kind' => 'note', 'text' => 'hi', 'at' => '25/10/2026'])->assertStatus(422);
        $this->note('mc_wrong', ['id' => 'x4', 'kind' => 'note', 'text' => 'hi', 'at' => '2026-10-25 12:00:00'])->assertStatus(401);
        $this->assertSame(0, ArenaNote::count());
    }

    public function test_players_in_a_game_answer_from_the_cache_and_queue_the_rest(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = $user->issueCoachToken();
        $experience = Mockery::mock(PlayerExperienceService::class);
        $experience->shouldReceive('cachedMany')->once()
            ->with(['Known-Realm-US', 'New-Realm-US'])
            ->andReturn(['Known-Realm-US' => ['found' => true, 'gladSeasons' => 2, 'exp3v3' => 2400], 'New-Realm-US' => null]);
        $this->app->instance(PlayerExperienceService::class, $experience);

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/coach/players', ['players' => ['Known-Realm-US', 'New-Realm-US', 'NoRealm', 'Known-Realm-US']])
            ->assertOk()
            ->assertExactJson([
                'players' => ['Known-Realm-US' => ['found' => true, 'gladSeasons' => 2, 'exp3v3' => 2400]],
                'pending' => ['New-Realm-US'],
            ]);
        Queue::assertPushed(FetchPlayerExperience::class, fn ($job) => $job->players === ['New-Realm-US']);
    }
}
