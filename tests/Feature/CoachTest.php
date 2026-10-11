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

    public function test_a_busy_server_asks_for_the_last_part_again_and_the_round_still_arrives_whole(): void
    {
        $user = User::factory()->create();
        $key = $user->issueCoachToken();
        $raw = str_repeat("10/3/2026 12:18:27.62810  SPELL_CAST_SUCCESS,a,b\n", 2000);
        $gz = gzencode($raw);
        [$one, $two] = str_split($gz, (int) ceil(strlen($gz) / 2));

        $ingest = Mockery::mock(ArenaReviewIngestService::class);
        $ingest->shouldReceive('ingestRound')->once()->withArgs(fn ($u, $text) => $text === $raw)
            ->andReturn(['status' => 'stored', 'matchId' => str_repeat('b', 32), 'lobbyId' => str_repeat('b', 32)]);
        $this->app->instance(ArenaReviewIngestService::class, $ingest);
        $send = fn ($body, $part) => $this->call('POST', '/api/coach/round', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$key, 'HTTP_X_MATCH' => str_repeat('b', 32),
            'HTTP_X_PART' => $part, 'HTTP_X_PARTS' => 2, 'CONTENT_TYPE' => 'application/gzip', 'HTTP_ACCEPT' => 'application/json',
        ], $body);

        // Every measuring slot taken by other players' rounds.
        $held = array_map(fn ($i) => tap(\Illuminate\Support\Facades\Cache::lock("coach-measuring:{$i}", 120))->get(), range(0, 2));

        $send($one, 1)->assertOk()->assertJson(['status' => 'part']);
        $send($two, 2)->assertStatus(429)->assertJson(['status' => 'busy', 'retryAfter' => 10]);

        foreach ($held as $lock) {
            $lock->release();
        }
        $send($two, 2)->assertOk()->assertJson(['status' => 'stored']);
    }

    public function test_finishing_a_batch_builds_the_pages_in_the_background(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = $user->issueCoachToken();

        $this->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/coach/done')->assertOk();

        Queue::assertPushed(BuildCoachPages::class, fn ($job) => $job->userId === $user->id);
        Queue::assertPushed(\App\Jobs\RefreshPopulationNorms::class, fn ($job) => $job->delay !== null);
    }

    public function test_pages_read_the_newer_of_the_live_and_the_committed_norms(): void
    {
        $live = \App\Console\Commands\BuildPopulation::livePath();
        $committed = base_path(\App\Console\Commands\BuildPopulation::NORMS);
        $had = File::exists($live) ? File::get($live) : null;
        try {
            File::ensureDirectoryExists(dirname($live));
            File::put($live, "{\n    \"builtAt\": \"2000-01-01T00:00:00+00:00\"\n}\n");
            $this->assertSame($committed, \App\Console\Commands\BuildPopulation::normsPath(), 'an older live copy is ignored');
            File::put($live, "{\n    \"builtAt\": \"2999-01-01T00:00:00+00:00\"\n}\n");
            $this->assertSame($live, \App\Console\Commands\BuildPopulation::normsPath(), 'a newer live copy wins');
        } finally {
            $had === null ? File::delete($live) : File::put($live, $had);
        }
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

    public function test_anyone_can_download_the_desktop_app_from_its_page(): void
    {
        $path = \App\Http\Controllers\DesktopAppController::path();
        $existed = is_file($path);
        $original = $existed ? file_get_contents($path) : null;

        try {
            // Public since 2026-10-11: the app does nothing until it is connected to an account.
            if (! $existed) {
                $this->get('/wow/coach/app')->assertNotFound();
                $this->get('/wow/app')->assertOk()->assertSee("The download isn't up yet", false);
                File::ensureDirectoryExists(dirname($path));
                file_put_contents($path, 'MZ test exe');
            }

            $this->get('/wow/app')->assertOk()->assertSee('Download for Windows')->assertSee('Run anyway');
            $res = $this->get('/wow/coach/app')->assertOk();
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

    public function test_the_app_connects_through_the_browser_and_receives_its_key_once(): void
    {
        $code = str_repeat('ab12', 8);
        $user = User::factory()->create();

        // Not approved yet: the app keeps waiting.
        $this->getJson("/api/coach/connect/{$code}")->assertStatus(202)->assertJson(['status' => 'waiting']);

        // A guest is sent to sign in first, and comes back to the same page after.
        $this->get("/wow/app/connect?code={$code}")->assertRedirect(route('login'));
        $this->actingAs($user)->get("/wow/app/connect?code={$code}")->assertOk()->assertSee('Connect the app on this PC?');
        $this->actingAs($user)->get('/wow/app/connect?code=nonsense')->assertOk()->assertSee('This link has expired');

        $this->actingAs($user)->post('/wow/app/connect', ['code' => $code])->assertOk()->assertSee("You're connected", false);

        $key = $this->getJson("/api/coach/connect/{$code}")->assertOk()->assertJson(['status' => 'connected'])->json('key');
        $this->assertSame($user->id, User::forCoachToken($key)?->id, 'the key belongs to the player who approved it');
        $this->getJson("/api/coach/connect/{$code}")->assertStatus(202);   // handed out once
        $this->getJson('/api/coach/connect/not-a-code')->assertNotFound();
    }

    public function test_looking_up_new_players_rebuilds_the_uploaders_pages(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        File::ensureDirectoryExists(BuildCoachPages::dir($user->id));
        $experience = Mockery::mock(\App\Http\Services\PlayerExperienceService::class);
        $experience->shouldReceive('cachedMany')->andReturn(['New-Realm-US' => null, 'Old-Realm-US' => ['found' => true]]);
        $experience->shouldReceive('lookup')->twice();

        try {
            // The pages were built before the lookups finished (2026-10-11): build them again.
            (new \App\Jobs\FetchPlayerExperience(['New-Realm-US', 'Old-Realm-US'], $user->id))->handle($experience);
            Queue::assertPushed(BuildCoachPages::class, fn ($job) => $job->userId === $user->id);
        } finally {
            File::deleteDirectory(BuildCoachPages::dir($user->id));
        }
    }

    public function test_nothing_new_to_look_up_rebuilds_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $experience = Mockery::mock(\App\Http\Services\PlayerExperienceService::class);
        $experience->shouldReceive('cachedMany')->andReturn(['Old-Realm-US' => ['found' => true]]);
        $experience->shouldReceive('lookup')->once();

        (new \App\Jobs\FetchPlayerExperience(['Old-Realm-US'], $user->id))->handle($experience);
        Queue::assertNotPushed(BuildCoachPages::class);
    }

    public function test_the_website_says_which_version_of_the_app_it_serves(): void
    {
        $path = dirname(\App\Http\Controllers\DesktopAppController::path()).'/version.json';
        $had = is_file($path) ? file_get_contents($path) : null;
        try {
            @unlink($path);
            $this->getJson('/api/coach/app-version')->assertOk()->assertJson(['version' => null]);   // updating off

            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, json_encode(['version' => '0.5.1']));
            $this->getJson('/api/coach/app-version')->assertOk()->assertJson(['version' => '0.5.1']);

            file_put_contents($path, json_encode(['version' => 'not a version']));
            $this->getJson('/api/coach/app-version')->assertOk()->assertJson(['version' => null]);
        } finally {
            $had === null ? @unlink($path) : file_put_contents($path, $had);
        }
    }

    public function test_a_player_can_keep_their_games_out_of_the_shared_averages(): void
    {
        Queue::fake();
        $in = User::factory()->create();
        $out = User::factory()->create();
        foreach ([$in, $out] as $i => $user) {
            \App\Models\ArenaRound::create([
                'user_id' => $user->id, 'match_id' => 'm'.$i, 'lobby_id' => 'm'.$i, 'roster_key' => 'r'.$i,
                'bracket' => '3v3', 'played_at' => now(), 'payload' => ['metadata' => [], 'analysis' => ['version' => 1]],
            ]);
        }

        $this->actingAs($out)->get('/wow/coach')->assertOk()->assertSee('Yours are counted.');
        $this->actingAs($out)->post('/wow/coach/pool', ['share' => '0'])->assertRedirect(route('coach'));
        $this->assertNotNull($out->fresh()->pool_opt_out_at);
        $this->actingAs($out)->get('/wow/coach')->assertSee('Yours are not counted.')->assertSee('Count mine again');
        Queue::assertPushed(\App\Jobs\RefreshPopulationNorms::class);

        // The export never reads an opted-out player's rounds: only one of the two is even looked at.
        $file = storage_path('framework/testing-pool.jsonl.gz');
        $this->artisan('wow:pool-export', ['--out' => $file])->expectsOutputToContain('1 round(s) measured at an older version')->assertSuccessful();
        @unlink($file);

        $this->actingAs($out)->post('/wow/coach/pool', ['share' => '1']);
        $this->assertNull($out->fresh()->pool_opt_out_at);
    }
}
