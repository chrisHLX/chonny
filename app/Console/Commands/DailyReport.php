<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * The morning email (2026-11-23): whether the things that run on their own did run, so a failure is
 * found the next day and not weeks later. The app's local review build failed after every game from
 * 28 Oct to 22 Nov and nothing said so; this is the server's side of that lesson.
 *
 * Run by cron after the leaderboard pull (DEPLOY.md). Each check is OK, WARN or FAIL, and the subject
 * says how many need a look. No email at all means the server or its cron is down.
 *
 *   php artisan ops:daily-report --dry-run     # print it, send nothing
 */
class DailyReport extends Command
{
    protected $signature = 'ops:daily-report
        {--dry-run : Print the report and send nothing}
        {--to= : Send to this address instead of ADMIN_EMAIL}
        {--wait=90 : Seconds to wait for a queue worker to answer}';

    protected $description = 'Check the jobs that run on their own and email a summary';

    /** @var list<array{0: string, 1: string, 2: string}> name, OK|WARN|FAIL, detail */
    private array $checks = [];

    /** @var list<string> */
    private array $notes = [];

    public function handle(): int
    {
        $names = ['site' => 'Website', 'queue' => 'Queue workers', 'failedJobs' => 'Failed jobs', 'leaderboards' => 'Leaderboards',
            'errors' => 'Errors logged', 'disk' => 'Disk', 'certificate' => 'HTTPS certificate'];
        foreach ($names as $check => $name) {
            try {
                $this->{$check}();
            } catch (Throwable $e) {
                $this->add($name, 'WARN', 'The check itself failed: '.Str::limit($this->clean($e->getMessage()), 200));
            }
        }
        $this->activity();

        $problems = collect($this->checks)->where(1, '!=', 'OK');
        $subject = $problems->isEmpty()
            ? 'MindCollector daily check: all '.count($this->checks).' OK'
            : 'MindCollector daily check: '.$problems->count().' to look at ('.$problems->pluck(0)->implode(', ').')';
        $body = $this->body();

        $this->line($subject);
        $this->line('');
        $this->line($body);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $to = $this->option('to') ?: config('mail.admin_address');
        if (! $to) {
            $this->error('No ADMIN_EMAIL set and no --to given; nothing sent.');

            return self::FAILURE;
        }

        // The mail host has refused a send now and then ("451 queue file write error", 17 times
        // since June), so a failed send is tried again before giving up.
        for ($try = 1; $try <= 3; $try++) {
            try {
                Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
                $this->info("Sent to {$to}.");

                return self::SUCCESS;
            } catch (Throwable $e) {
                $this->warn("Send {$try} failed: ".$e->getMessage());
                if ($try < 3) {
                    sleep(app()->runningUnitTests() ? 0 : 60);
                }
            }
        }

        return self::FAILURE;
    }

    private function add(string $name, string $status, string $detail): void
    {
        $this->checks[] = [$name, $status, $detail];
    }

    /** The home page answers, as a visitor sees it. */
    private function site(): void
    {
        $url = rtrim(config('app.url'), '/').'/';
        $started = microtime(true);
        $res = Http::timeout(20)->get($url);
        $seconds = microtime(true) - $started;
        $status = ! $res->ok() ? 'FAIL' : ($seconds > 5 ? 'WARN' : 'OK');
        $this->add('Website', $status, sprintf('%s answered %d in %.1fs', $url, $res->status(), $seconds));
    }

    /** A worker takes a job: one is queued and must write its token back. */
    private function queue(): void
    {
        $waiting = [];
        foreach (['cards', 'default'] as $name) {
            $waiting[] = $name.' '.Queue::size($name);
        }
        $token = Str::random(16);
        Cache::forget(QueueHeartbeat::KEY);
        QueueHeartbeat::dispatch($token)->onQueue('default');

        $wait = (int) $this->option('wait');
        $deadline = microtime(true) + $wait;
        do {
            if (Cache::get(QueueHeartbeat::KEY) === $token) {
                $this->add('Queue workers', 'OK', 'A test job ran. Waiting: '.implode(', ', $waiting).'.');

                return;
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        $this->add('Queue workers', 'FAIL', "A test job did not run within {$wait}s: the workers are stopped or far behind. Waiting: ".implode(', ', $waiting).'. On the server: supervisorctl status.');
    }

    /** Jobs that gave up in the last day, by what went wrong. */
    private function failedJobs(): void
    {
        $rows = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->get(['payload', 'exception']);
        if ($rows->isEmpty()) {
            $this->add('Failed jobs', 'OK', 'None in the last 24 hours.');

            return;
        }
        $kinds = $rows->map(fn ($r) => class_basename(json_decode($r->payload, true)['displayName'] ?? '?')
            .': '.Str::limit($this->clean(strtok((string) $r->exception, "\n")), 140))->countBy()->sortDesc()->take(3);
        $this->add('Failed jobs', 'WARN', $rows->count().' in the last 24 hours. '
            .$kinds->map(fn ($n, $k) => "{$n}x {$k}")->implode('; ').'. On the server: php artisan queue:failed.');
    }

    /** The 18:00 UTC cron pulled both regions' leaderboards. */
    private function leaderboards(): void
    {
        $last = DB::table('pvp_leaderboard_entries')->select('region', DB::raw('max(seen_at) as seen'))->groupBy('region')->pluck('seen', 'region');
        $stale = [];
        $parts = [];
        foreach (['us', 'eu'] as $region) {
            $seen = isset($last[$region]) ? Carbon::parse($last[$region]) : null;
            $parts[] = strtoupper($region).' '.($seen ? $seen->diffForHumans() : 'never');
            if (! $seen || $seen->lt(now()->subHours(26))) {
                $stale[] = strtoupper($region);
            }
        }
        $this->add('Leaderboards', $stale ? 'FAIL' : 'OK', 'Last pulled: '.implode(', ', $parts).'.'
            .($stale ? ' The daily cron did not run or failed: storage/logs/leaderboards.log.' : ''));
    }

    /** Errors in laravel.log over the last day, the commonest first. */
    private function errors(): void
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            $this->add('Errors logged', 'OK', 'No log file.');

            return;
        }
        // The log grows for months: only its end is read.
        $handle = fopen($path, 'r');
        $size = filesize($path);
        fseek($handle, max(0, $size - 8 * 1048576));
        $tail = stream_get_contents($handle);
        fclose($handle);

        $since = now()->subDay()->format('Y-m-d H:i:s');
        preg_match_all('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $tail, $m, PREG_SET_ORDER);
        $recent = collect($m)->filter(fn ($x) => $x[1] >= $since)
            ->map(fn ($x) => Str::limit($this->clean(preg_replace('/ \{"exception".*$/', '', $x[3])), 120));
        if ($recent->isEmpty()) {
            $this->add('Errors logged', 'OK', 'None in the last 24 hours.');

            return;
        }
        $top = $recent->countBy()->sortDesc()->take(3)->map(fn ($n, $msg) => "{$n}x {$msg}")->implode("\n    ");
        $this->add('Errors logged', $recent->count() >= 20 ? 'WARN' : 'OK', $recent->count()." in the last 24 hours. Commonest:\n    ".$top);
    }

    /** Room left on the disk. */
    private function disk(): void
    {
        $free = disk_free_space(base_path());
        $total = disk_total_space(base_path());
        $pct = $free / $total * 100;
        $status = $pct < 5 ? 'FAIL' : ($pct < 15 ? 'WARN' : 'OK');
        $this->add('Disk', $status, sprintf('%.1f GB free of %.0f GB (%.0f%%).', $free / 1e9, $total / 1e9, $pct));
    }

    /** The HTTPS certificate is renewing (certbot): days until it runs out. */
    private function certificate(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        if (parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https' || ! $host) {
            return; // local and tests: nothing to check
        }
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = stream_socket_client("ssl://{$host}:443", $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
        if (! $socket) {
            $this->add('HTTPS certificate', 'WARN', "Could not connect to read it: {$error}");

            return;
        }
        $cert = openssl_x509_parse(stream_context_get_params($socket)['options']['ssl']['peer_certificate']);
        fclose($socket);
        $days = (int) floor(($cert['validTo_time_t'] - time()) / 86400);
        $status = $days < 3 ? 'FAIL' : ($days < 14 ? 'WARN' : 'OK');
        $this->add('HTTPS certificate', $status, "{$days} days left".($days < 14 ? '; certbot should renew at 30: certbot renew --dry-run.' : '.'));
    }

    /** What came in, for the feel of the day; never a pass or fail. */
    private function activity(): void
    {
        $since = now()->subDay();
        $this->notes[] = 'New accounts: '.DB::table('users')->where('created_at', '>=', $since)->count();
        $this->notes[] = 'Arena rounds uploaded by the app: '.DB::table('arena_rounds')->where('created_at', '>=', $since)->count();
        $this->notes[] = 'Forever auction scans received: '.DB::table('forever_scans')->where('created_at', '>=', $since)->count();
        $deployed = storage_path('app/deployed-commit.txt');
        if (is_file($deployed)) {
            $this->notes[] = 'Last deploy: '.Carbon::createFromTimestamp(filemtime($deployed))->diffForHumans()
                .' ('.Str::limit(trim((string) file_get_contents($deployed)), 7, '').')';
        }
    }

    private function body(): string
    {
        $lines = [];
        foreach ($this->checks as [$name, $status, $detail]) {
            $lines[] = str_pad($status, 5).$name.': '.$detail;
        }
        $lines[] = '';
        $lines[] = 'The last 24 hours:';
        foreach ($this->notes as $note) {
            $lines[] = '  '.$note;
        }
        $lines[] = '';
        $lines[] = 'Not covered: the desktop app on players\' PCs, and backups (none are automated).';
        $lines[] = 'No email tomorrow means the server or its cron is down.';

        return implode("\n", $lines);
    }

    /** API keys appear in some logged URLs (Gemini's ?key=): never mailed. */
    private function clean(string $text): string
    {
        return preg_replace('/([?&](?:key|api_key|token|access_token)=)[^&\s"]+/i', '$1[hidden]', $text);
    }
}
