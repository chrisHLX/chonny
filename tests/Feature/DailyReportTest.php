<?php

namespace Tests\Feature;

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
            ->expectsOutputToContain('MindCollector daily check: all 6 OK')
            ->expectsOutputToContain('A test job ran')
            ->assertSuccessful();
    }

    public function test_a_stale_leaderboard_and_a_down_site_are_named_in_the_subject(): void
    {
        Http::fake(['*' => Http::response('down', 502)]);
        Mail::fake();
        $this->leaderboardSeen('us', now()->subDays(3));
        $this->leaderboardSeen('eu', now()->subHours(3));

        $this->artisan('ops:daily-report', ['--dry-run' => true])
            ->expectsOutputToContain('2 to look at (Website, Leaderboards)')
            ->expectsOutputToContain('The daily cron did not run')
            ->assertSuccessful();
    }

    public function test_a_failed_job_is_reported_without_its_api_key(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->leaderboardSeen('us', now());
        $this->leaderboardSeen('eu', now());
        DB::table('failed_jobs')->insert([
            'uuid' => 'x', 'connection' => 'redis', 'queue' => 'default', 'failed_at' => now(),
            'payload' => json_encode(['displayName' => 'App\\Jobs\\FetchPlayerExperience']),
            'exception' => "Exception: timed out for https://api.example/v1?key=SECRET123&x=1\n#0 trace",
        ]);

        $this->artisan('ops:daily-report', ['--dry-run' => true])
            ->expectsOutputToContain('1x FetchPlayerExperience: Exception: timed out for https://api.example/v1?key=[hidden]&x=1')
            ->doesntExpectOutputToContain('SECRET123')
            ->assertSuccessful();
    }
}
