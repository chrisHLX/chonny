<?php

namespace Tests\Feature;

use App\Http\Services\ArenaReviewIngestService;
use App\Jobs\BuildCoachPages;
use App\Models\User;
use App\Support\DesktopAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * "Your coach": the desktop app's pages on the website. The app sends each round with the player's
 * key (Api\CoachUploadController), the server builds the pages (BuildCoachPages) and serves them
 * only from the player's own folder (CoachController).
 */
class CoachTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (User::pluck('id') as $id) {
            File::deleteDirectory(BuildCoachPages::dir($id));
            File::deleteDirectory(storage_path("app/coach-parts/{$id}"));
        }
        parent::tearDown();
    }

    public function test_a_key_is_shown_once_and_only_its_hash_is_kept(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('coach.key'))->assertRedirect(route('coach'));
        $key = session('coach_key');

        $this->assertMatchesRegularExpression('/^mc_[A-Za-z0-9]{40}$/', $key);
        $this->assertSame(hash('sha256', $key), $user->fresh()->coach_token_hash);
        $this->assertTrue(User::forCoachToken($key)->is($user));
        $this->actingAs($user)->get(route('coach'))->assertOk()->assertSee($key);
        $this->actingAs($user)->get(route('coach'))->assertOk()->assertDontSee($key);
    }

    public function test_an_upload_without_a_good_key_is_refused(): void
    {
        $this->postJson('/api/coach/round')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer mc_wrong')->postJson('/api/coach/done')->assertStatus(401);
    }

    public function test_a_large_round_arrives_in_parts_and_is_measured_whole(): void
    {
        $user = User::factory()->create();
        $key = $user->issueCoachToken();
        $raw = str_repeat("10/3/2026 12:18:27.62810  SPELL_CAST_SUCCESS,a,b\n", 2000);
        $gz = gzencode($raw);
        [$one, $two] = str_split($gz, (int) ceil(strlen($gz) / 2));

        $ingest = Mockery::mock(ArenaReviewIngestService::class);
        $ingest->shouldReceive('ingestRound')->once()->withArgs(fn ($u, $text) => $u->is($user) && $text === $raw)
            ->andReturn(['status' => 'stored', 'matchId' => str_repeat('a', 32), 'lobbyId' => str_repeat('a', 32)]);
        $this->app->instance(ArenaReviewIngestService::class, $ingest);

        $send = fn ($body, $part) => $this->call('POST', '/api/coach/round', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$key, 'HTTP_X_MATCH' => str_repeat('a', 32),
            'HTTP_X_PART' => $part, 'HTTP_X_PARTS' => 2, 'CONTENT_TYPE' => 'application/gzip', 'HTTP_ACCEPT' => 'application/json',
        ], $body);

        $send($one, 1)->assertOk()->assertJson(['status' => 'part']);
        $send($two, 2)->assertOk()->assertJson(['status' => 'stored']);
        $this->assertDirectoryDoesNotExist(storage_path("app/coach-parts/{$user->id}/".str_repeat('a', 32)));
    }

    public function test_finishing_a_batch_builds_the_pages_in_the_background(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = $user->issueCoachToken();

        $this->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/coach/done')->assertOk();

        Queue::assertPushed(BuildCoachPages::class, fn ($job) => $job->userId === $user->id);
    }

    public function test_pages_are_listed_and_served_only_from_the_players_own_folder(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        File::ensureDirectoryExists(BuildCoachPages::dir($me->id));
        File::put(BuildCoachPages::dir($me->id).'/abc123.html', '<p>my game</p>');
        File::put(BuildCoachPages::dir($me->id).'/index.json', json_encode([
            'games' => ['abc123' => ['playedAt' => '2026-10-03 12:18:00', 'bracket' => '3v3', 'notes' => 0, 'sig' => 'x', 'you' => 'Skylake', 'record' => [1, 0], 'against' => ['Frost Mage']]],
            'characters' => [], 'comps' => [],
        ]));

        $this->get(route('coach.page', 'abc123.html'))->assertRedirect(route('login'));
        $this->actingAs($me)->get(route('coach'))->assertOk()->assertSee('Skylake')->assertSee('vs Frost Mage');
        $this->actingAs($me)->get(route('coach.page', 'abc123.html'))->assertOk()->assertSee('my game', false);
        $this->actingAs($other)->get(route('coach.page', 'abc123.html'))->assertNotFound();
    }

    public function test_the_web_build_points_images_at_the_site_not_at_files(): void
    {
        $this->assertStringStartsWith('file:///', DesktopAsset::icon('a.jpg'));
        config(['desktop.web_assets' => true]);
        $this->assertSame('/storage/spell-icons/a.jpg', DesktopAsset::icon('a.jpg'));
    }

    public function test_your_games_has_the_reviews_and_the_upload_and_a_browser_upload_builds_the_pages(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('coach'))
            ->assertOk()
            ->assertSee('Your games')
            ->assertSee('Same spec')
            ->assertSee('Upload matches')
            ->assertSee('window.arenaUpload', false);

        // The browser upload builds the same pages the desktop app's upload does.
        $this->actingAs($user)->postJson(route('game-review.assemble'))->assertOk();
        Queue::assertPushed(BuildCoachPages::class, fn ($job) => $job->userId === $user->id);
    }
}
