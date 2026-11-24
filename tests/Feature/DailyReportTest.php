<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The errors check reads storage/logs/laravel.log: an empty storage, so the PC's own log
        // can't change the result.
        $dir = sys_get_temp_dir().'/daily-report-test-'.getmypid();
        @mkdir($dir.'/logs', 0777, true);
        @mkdir($dir.'/app', 0777, true);
        @unlink($dir.'/logs/laravel.log');
        $this->app->useStoragePath($dir);
    }

    private function leaderboardSeen(string $region, \DateTimeInterface $at): void
    {
        DB::table('pvp_leaderboard_entries')->insert([
            'region' => $region, 'season' => 42, 'bracket' => '3v3', 'blizzard_character_id' => random_int(1, 1e9),
            'name' => 'Someone', 'realm_slug' => 'realm', 'rank' => 1, 'rating' => 2400, 'seen_at' => $at,
        ]);
    }

    public function test_all_ok_sends_a_summary(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        Mail::fake();
        $this->leaderboardSeen('us', now()->subHours(3));
        $this->leaderboardSeen('eu', now()->subHours(3));

        $this->artisan('ops:daily-report', ['--to' => 'me@example.test'])
            ->expectsOutputToContain('MindCollector daily check: all clear')
            ->expectsOutputToContain('All 5 checks passed')
            ->expectsOutputToContain('a test job went through')
            ->expectsOutputToContain('US and EU were both pulled 3 hours ago')
            ->assertSuccessful();
    }

    public function test_a_stale_leaderboard_and_a_down_site_are_named_in_the_subject_and_listed_first(): void
    {
        Http::fake(['*' => Http::response('down', 502)]);
        Mail::fake();
        $this->leaderboardSeen('us', now()->subDays(3));
        $this->leaderboardSeen('eu', now()->subHours(3));

        $this->artisan('ops:daily-report', ['--dry-run' => true])
            ->expectsOutputToContain('2 to look at (Website, Leaderboards)')
            ->expectsOutputToContain('2 of 5 checks need a look')
            ->expectsOutputToContain("the 6pm pull didn't run or failed")
            ->assertSuccessful();
    }

    public function test_a_failed_job_is_reported_in_words_without_its_api_key(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->leaderboardSeen('us', now());
        $this->leaderboardSeen('eu', now());
        DB::table('failed_jobs')->insert([
            'uuid' => 'x', 'connection' => 'redis', 'queue' => 'default', 'failed_at' => now(),
            'payload' => json_encode(['displayName' => 'App\Jobs\FetchPlayerExperience']),
            'exception' => "Exception: timed out for https://api.example/v1?key=SECRET123&x=1\n#0 trace",
        ]);

        $this->artisan('ops:daily-report', ['--dry-run' => true])
            ->expectsOutputToContain("Looking up players' rating history: Exception: timed out for https://api.example/v1?key=[hidden]&x=1")
            ->doesntExpectOutputToContain('SECRET123')
            ->assertSuccessful();
    }

    public function test_a_timed_out_page_build_names_its_player_and_uploads_are_counted_by_player(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->leaderboardSeen('us', now());
        $this->leaderboardSeen('eu', now());
        $player = User::factory()->create(['name' => 'Muroto']);
        DB::table('failed_jobs')->insert([
            'uuid' => 'y', 'connection' => 'redis', 'queue' => 'default', 'failed_at' => now(),
            'payload' => json_encode(['displayName' => 'App\Jobs\BuildCoachPages',
                'data' => ['command' => 'O:24:"App\Jobs\BuildCoachPages":2:{s:4:"part";i:2;s:6:"userId";i:'.$player->id.';}']]),
            'exception' => "Illuminate\Queue\TimeoutExceededException: App\Jobs\BuildCoachPages has timed out. in /var/www/x.php:12\n#0 trace",
        ]);

        $this->artisan('ops:daily-report', ['--dry-run' => true])
            ->expectsOutputToContain("Building the game pages for Muroto (#{$player->id}): ran out of time")
            ->expectsOutputToContain('New accounts: 1 (Muroto)')
            ->expectsOutputToContain('Arena rounds uploaded: 0')
            ->assertSuccessful();
    }
}
