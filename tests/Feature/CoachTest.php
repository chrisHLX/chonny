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
            'classes' => ['Glad-Realm-US' => ['name' => 'Glad', 'spec' => 'Frost Mage', 'specId' => 64, 'class' => 'Mage', 'classSlug' => 'mage', 'color' => '#69CCF0',
                'why' => '9× Glad, best 3000', 'games' => 2, 'won' => 1, 'lost' => 1, 'last' => '2026-10-03', 'file' => 'player-0123456789.html']],
        ]));

        $this->get(route('coach.page', 'abc123.html'))->assertRedirect(route('login'));
        $this->actingAs($me)->get(route('coach'))->assertOk()->assertSee('Skylake')->assertSee('vs Frost Mage')
            // The Classes tab: the strongest player of each spec met, under their class.
            ->assertSee('Classes')->assertSee('player-0123456789.html')->assertSeeInOrder(['Mage', 'Glad', 'Frost Mage · 9× Glad, best 3000 · 2 rounds'], false);
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

    public function test_the_desktop_app_downloads_only_for_a_signed_in_player(): void
    {
        $path = \App\Http\Controllers\DesktopAppController::path();
        $existed = is_file($path);
        $original = $existed ? file_get_contents($path) : null;

        try {
            $this->get('/wow/coach/app')->assertRedirect(route('login'));

            if (! $existed) {
                $this->actingAs(User::factory()->create())->get('/wow/coach/app')->assertNotFound();
                File::ensureDirectoryExists(dirname($path));
                file_put_contents($path, 'MZ test exe');
            }

            $res = $this->actingAs(User::factory()->create())->get('/wow/coach/app')->assertOk();
            $this->assertStringContainsString('MindCollector.exe', $res->headers->get('Content-Disposition'));
        } finally {
            if (! $existed) {
                @unlink($path);
            } elseif ($original !== null) {
                file_put_contents($path, $original);
            }
        }
    }

    public function test_the_app_reads_its_own_pages_back_with_its_key_and_nobody_elses(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerKey = $owner->issueCoachToken();
        $otherKey = $other->issueCoachToken();
        File::ensureDirectoryExists(BuildCoachPages::dir($owner->id));
        File::put(BuildCoachPages::dir($owner->id).'/index.json', json_encode(['games' => ['abc123' => ['bracket' => '3v3']]]));
        File::put(BuildCoachPages::dir($owner->id).'/abc123.html', '<html>owner game</html>');

        $index = $this->withHeader('Authorization', "Bearer {$ownerKey}")->getJson('/api/coach/index')->assertOk();
        $this->assertTrue($index->json('built'));
        $this->assertSame('3v3', $index->json('games.abc123.bracket'));

        $page = $this->withHeader('Authorization', "Bearer {$ownerKey}")->get('/api/coach/page/abc123.html')->assertOk();
        $this->assertStringContainsString('owner game', $page->getContent());
        $etag = $page->headers->get('ETag');
        $this->assertNotEmpty($etag);

        // Asked again with that ETag: unchanged, so no body.
        $again = $this->withHeaders(['Authorization' => "Bearer {$ownerKey}", 'If-None-Match' => $etag])->get('/api/coach/page/abc123.html');
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());
        $this->flushHeaders();

        // Another player's key reads that player's (empty) folder, never this one.
        $this->withHeader('Authorization', "Bearer {$otherKey}")->getJson('/api/coach/index')->assertOk()->assertJson(['built' => false]);
        $this->withHeader('Authorization', "Bearer {$otherKey}")->get('/api/coach/page/abc123.html')->assertNotFound();

        // No key, or a wrong one, reads nothing; a path outside the folder never matches the route.
        // (withHeader persists across requests in a test, so the key is cleared first.)
        $this->flushHeaders()->getJson('/api/coach/index')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer mc_wrong')->get('/api/coach/page/abc123.html')->assertStatus(401);
        $this->withHeader('Authorization', "Bearer {$ownerKey}")->get('/api/coach/page/..%2Findex.json')->assertNotFound();
    }
}
