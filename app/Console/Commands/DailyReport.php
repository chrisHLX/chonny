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
 * Run by cron at 7am Sydney, the server's own clock (DEPLOY.md; docs/automation.md). Each check is
 * OK, WARN or FAIL; the email lists the ones to look at first and the subject names them. No email at
 * all means the server or its cron is down.
 *
 * 2026-11-24 (Chriso: "improve the prose and presentation"): an HTML email with a plain-text copy,
 * each line a sentence, failed jobs in words with the player they were for, uploads by player.
 *
 *   php artisan ops:daily-report --dry-run     # print it, send nothing
 */
class DailyReport extends Command
{
    protected $signature = 'ops:daily-report
        {--dry-run : Print the report and send nothing}
        {--html : Print the email\'s HTML and send nothing, to look at it in a browser}
        {--to= : Send to this address instead of ADMIN_EMAIL}
        {--wait=90 : Seconds to wait for a queue worker to answer}';

    protected $description = 'Check the jobs that run on their own and email a summary';

    /** What a status is called in the email. */
    private const LABEL = ['FAIL' => 'Failed', 'WARN' => 'Look', 'OK' => 'OK'];

    private const COLOUR = ['FAIL' => '#C0392B', 'WARN' => '#B7791F', 'OK' => '#2F855A'];

    /** Background jobs, in words, for the failed-jobs line. {player} is filled when the job has one. */
    private const JOBS = [
        'BuildCoachPages' => 'building the game pages for {player}',
        'RefreshPopulationNorms' => 'rebuilding the population norms',
        'FetchPlayerExperience' => "looking up players' rating history",
        'SyncBattlenetCharacter' => 'syncing a Battle.net character',
        'QueueHeartbeat' => "this email's own test job",
    ];

    /** @var list<array{name: string, status: string, text: string, items: list<string>}> */
    private array $checks = [];

    /** @var list<array{0: string, 1: string}> label, value */
    private array $notes = [];

    public function handle(): int
    {
        $names = ['site' => 'Website', 'queue' => 'Queue workers', 'failedJobs' => 'Failed jobs', 'leaderboards' => 'Leaderboards',
            'errors' => 'Errors logged', 'disk' => 'Disk space', 'certificate' => 'HTTPS certificate'];
        foreach ($names as $check => $name) {
            try {
                $this->{$check}();
            } catch (Throwable $e) {
                $this->add($name, 'WARN', 'The check itself broke: '.Str::limit($this->clean($e->getMessage()), 200));
            }
        }
        try {
            $this->activity();
        } catch (Throwable $e) {
            $this->notes[] = ['Activity', 'could not be read: '.Str::limit($this->clean($e->getMessage()), 120)];
        }

        // The ones to look at first, failures before warnings; the order otherwise kept.
        $rank = ['FAIL' => 0, 'WARN' => 1, 'OK' => 2];
        $this->checks = collect($this->checks)->sortBy(fn ($c, $i) => $rank[$c['status']] * 100 + $i)->values()->all();

        $problems = collect($this->checks)->where('status', '!=', 'OK');
        $subject = $problems->isEmpty()
            ? 'MindCollector daily check: all clear'
            : 'MindCollector daily check: '.$problems->count().' to look at ('.$problems->pluck('name')->implode(', ').')';
        $text = $this->text($problems->count());

        if ($this->option('html')) {
            $this->getOutput()->write($this->html($problems->count()), false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->line($subject);
        $this->line('');
        foreach (explode("\n", $text) as $line) {
            $this->line($line);
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $to = $this->option('to') ?: config('mail.admin_address');
        if (! $to) {
            $this->error('No ADMIN_EMAIL set and no --to given; nothing sent.');

            return self::FAILURE;
        }

        $html = $this->html($problems->count());

        // The mail host has refused a send now and then ("451 queue file write error", 17 times
        // since June), so a failed send is tried again before giving up.
        for ($try = 1; $try <= 3; $try++) {
            try {
                Mail::send([], [], fn ($m) => $m->to($to)->subject($subject)->html($html)->text($text));
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

    /** @param  list<string>  $items */
    private function add(string $name, string $status, string $text, array $items = []): void
    {
        $this->checks[] = ['name' => $name, 'status' => $status, 'text' => $text, 'items' => $items];
    }

    /** The home page answers, as a visitor sees it. */
    private function site(): void
    {
        $url = rtrim(config('app.url'), '/').'/';
        $started = microtime(true);
        $res = Http::timeout(20)->get($url);
        $seconds = microtime(true) - $started;
        if (! $res->ok()) {
            $this->add('Website', 'FAIL', sprintf('The home page answered with error %d after %.1fs.', $res->status(), $seconds));
        } else {
            $this->add('Website', $seconds > 5 ? 'WARN' : 'OK', sprintf('The home page loaded in %.1fs%s.', $seconds, $seconds > 5 ? ', which is slow' : ''));
        }
    }

    /** A worker takes a job: one is queued and must write its token back. */
    private function queue(): void
    {
        $waiting = 0;
        foreach (['cards', 'default'] as $name) {
            $waiting += Queue::size($name);
        }
        $queued = $waiting === 0 ? 'nothing waiting' : "{$waiting} ".Str::plural('job', $waiting).' waiting';
        $token = Str::random(16);
        Cache::forget(QueueHeartbeat::KEY);
        QueueHeartbeat::dispatch($token)->onQueue('default');

        $wait = (int) $this->option('wait');
        $deadline = microtime(true) + $wait;
        do {
            if (Cache::get(QueueHeartbeat::KEY) === $token) {
                $this->add('Queue workers', 'OK', "Running: a test job went through, {$queued}.");

                return;
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        $this->add('Queue workers', 'FAIL', "A test job didn't run within {$wait}s, so the workers are stopped or far behind ({$queued}). Uploaded games won't be measured until they're back.",
            ['On the server: supervisorctl status']);
    }

    /** Jobs that gave up in the last day, in words, with the player each was for. */
    private function failedJobs(): void
    {
        $rows = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->get(['payload', 'exception']);
        if ($rows->isEmpty()) {
            $this->add('Failed jobs', 'OK', 'No background job gave up in the last 24 hours.');

            return;
        }
        $players = [];
        $kinds = $rows->map(function ($r) use (&$players) {
            $payload = json_decode($r->payload, true) ?: [];
            $job = class_basename($payload['displayName'] ?? '?');
            $player = 'a player';
            if (preg_match('/"userId";i:(\d+)/', $payload['data']['command'] ?? '', $m)) {
                $players[$m[1]] ??= DB::table('users')->where('id', $m[1])->value('name') ?? "user #{$m[1]}";
                $player = $players[$m[1]].' (#'.$m[1].')';
            }
            $what = str_replace('{player}', $player, self::JOBS[$job] ?? $job);

            return $what.': '.$this->why((string) $r->exception);
        })->countBy()->sortDesc();

        $this->add('Failed jobs', 'WARN', $rows->count().' background '.Str::plural('job', $rows->count()).' gave up in the last 24 hours.',
            [...$kinds->take(5)->map(fn ($n, $k) => ($n > 1 ? "{$n}× " : '').Str::ucfirst($k))->values()->all(), 'On the server: php artisan queue:failed']);
    }

    /** Why a job failed, in a few words where the reason is a known one. */
    private function why(string $exception): string
    {
        $first = strtok($exception, "\n") ?: '';
        if (str_contains($first, 'TimeoutExceeded') || str_contains($first, 'has timed out')) {
            return 'ran out of time';
        }
        if (str_contains($first, 'MaxAttemptsExceeded')) {
            return 'tried too many times';
        }

        return Str::limit($this->clean(preg_replace('/ in \/\S+:\d+$/', '', $first)), 140);
    }

    /** The 6pm (Sydney) cron pulled both regions' leaderboards. */
    private function leaderboards(): void
    {
        $last = DB::table('pvp_leaderboard_entries')->select('region', DB::raw('max(seen_at) as seen'))->groupBy('region')->pluck('seen', 'region');
        $stale = [];
        $parts = [];
        foreach (['us', 'eu'] as $region) {
            $seen = isset($last[$region]) ? Carbon::parse($last[$region]) : null;
            $parts[strtoupper($region)] = $seen ? $seen->diffForHumans() : 'never';
            if (! $seen || $seen->lt(now()->subHours(26))) {
                $stale[] = strtoupper($region);
            }
        }
        if (! $stale) {
            $when = count(array_unique($parts)) === 1 ? 'US and EU were both pulled '.reset($parts) : 'US was pulled '.$parts['US'].', EU '.$parts['EU'];
            $this->add('Leaderboards', 'OK', $when.'.');

            return;
        }
        $when = collect($parts)->only($stale)->map(fn ($ago, $r) => $ago === 'never' ? "{$r} has never been pulled" : "{$r} was last pulled {$ago}");
        $this->add('Leaderboards', 'FAIL', Str::ucfirst($when->implode(' and ')).', so the 6pm pull didn\'t run or failed.',
            ['The pull\'s log: storage/logs/leaderboards.log']);
    }

    /** Errors in laravel.log over the last day, the commonest first. */
    private function errors(): void
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            $this->add('Errors logged', 'OK', 'No errors in the last 24 hours (no log file).');

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
            $this->add('Errors logged', 'OK', 'No errors in the last 24 hours.');

            return;
        }
        $top = $recent->countBy()->sortDesc()->take(3)->map(fn ($n, $msg) => ($n > 1 ? "{$n}× " : '').$msg)->values()->all();
        $this->add('Errors logged', $recent->count() >= 20 ? 'WARN' : 'OK',
            $recent->count().' '.Str::plural('error', $recent->count()).' in the last 24 hours. The commonest:', $top);
    }

    /** Room left on the disk. */
    private function disk(): void
    {
        if (app()->runningUnitTests()) {
            return; // the test machine's disk is not the server's
        }
        $free = disk_free_space(base_path());
        $total = disk_total_space(base_path());
        $pct = $free / $total * 100;
        $status = $pct < 5 ? 'FAIL' : ($pct < 15 ? 'WARN' : 'OK');
        $this->add('Disk space', $status, sprintf('%.0f GB free of %.0f GB (%.0f%%)%s.', $free / 1e9, $total / 1e9, $pct,
            $status === 'OK' ? '' : ', time to clear old installers and logs'));
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
            $this->add('HTTPS certificate', 'WARN', "Couldn't connect to read it: {$error}");

            return;
        }
        $cert = openssl_x509_parse(stream_context_get_params($socket)['options']['ssl']['peer_certificate']);
        fclose($socket);
        $days = (int) floor(($cert['validTo_time_t'] - time()) / 86400);
        $status = $days < 3 ? 'FAIL' : ($days < 14 ? 'WARN' : 'OK');
        $this->add('HTTPS certificate', $status, $status === 'OK'
            ? "{$days} days left. It renews itself at 30."
            : "Only {$days} days left, and it should have renewed itself at 30.", $status === 'OK' ? [] : ['On the server: certbot renew --dry-run']);
    }

    /** What came in, for the feel of the day; never a pass or fail. */
    private function activity(): void
    {
        $since = now()->subDay();

        $joined = DB::table('users')->where('created_at', '>=', $since)->orderBy('id')->pluck('name');
        $this->notes[] = ['New accounts', $joined->count().($joined->isEmpty() ? '' : ' ('.$this->names($joined).')')];

        $rounds = DB::table('arena_rounds')->where('arena_rounds.created_at', '>=', $since)
            ->leftJoin('users', 'users.id', '=', 'arena_rounds.user_id')
            ->select('users.name', DB::raw('count(*) as n'))->groupBy('arena_rounds.user_id', 'users.name')->orderByDesc('n')->get();
        $this->notes[] = ['Arena rounds uploaded', $this->byPlayer($rounds)];

        $scans = DB::table('forever_scans')->where('forever_scans.created_at', '>=', $since)
            ->leftJoin('users', 'users.id', '=', 'forever_scans.user_id')
            ->select('users.name', DB::raw('count(*) as n'))->groupBy('forever_scans.user_id', 'users.name')->orderByDesc('n')->get();
        $this->notes[] = ['Forever auction scans', $this->byPlayer($scans)];

        $deployed = storage_path('app/deployed-commit.txt');
        if (is_file($deployed)) {
            $this->notes[] = ['Last deploy', Carbon::createFromTimestamp(filemtime($deployed))->diffForHumans()
                .' ('.Str::limit(trim((string) file_get_contents($deployed)), 7, '').')'];
        }
    }

    /** "9, all from Muroto" or "12: Muroto 9, Sean 3". */
    private function byPlayer(\Illuminate\Support\Collection $rows): string
    {
        $total = (int) $rows->sum('n');

        return match (true) {
            $total === 0 => '0',
            $rows->count() === 1 => $total.', all from '.($rows->first()->name ?: 'unnamed'),
            default => $total.': '.$this->names($rows->map(fn ($r) => ($r->name ?: 'unnamed').' '.$r->n)),
        };
    }

    /** A short list of names: the first five, then how many more. */
    private function names(iterable $names): string
    {
        $all = collect($names)->map(fn ($n) => $n ?: 'unnamed')->values();

        return $all->take(5)->implode(', ').($all->count() > 5 ? ' and '.($all->count() - 5).' more' : '');
    }

    private function headline(int $problems): string
    {
        return $problems === 0
            ? 'Everything that runs on its own ran. All '.count($this->checks).' checks passed.'
            : $problems.' of '.count($this->checks).' checks '.($problems === 1 ? 'needs' : 'need').' a look. '
                .($problems === 1 ? 'It is' : 'They are').' listed first.';
    }

    private const FOOTER = [
        "Not checked here: the desktop app on players' PCs, and backups (none are automated).",
        "If this email doesn't arrive one morning, the server or its schedule is down.",
    ];

    private function text(int $problems): string
    {
        $lines = [$this->headline($problems), ''];
        foreach ($this->checks as $c) {
            $lines[] = str_pad(self::LABEL[$c['status']], 7).$c['name'].': '.$c['text'];
            foreach ($c['items'] as $item) {
                $lines[] = '         - '.$item;
            }
        }
        $lines[] = '';
        $lines[] = 'In the last 24 hours';
        foreach ($this->notes as [$label, $value]) {
            $lines[] = '  '.$label.': '.$value;
        }
        $lines[] = '';
        array_push($lines, ...self::FOOTER);

        return implode("\n", $lines);
    }

    /** Inline styles only: mail clients drop style sheets. */
    private function html(int $problems): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $date = now('Australia/Sydney')->format('l j F');

        $rows = '';
        foreach ($this->checks as $c) {
            $colour = self::COLOUR[$c['status']];
            $items = '';
            foreach ($c['items'] as $item) {
                $code = str_starts_with($item, 'On the server: ') || str_starts_with($item, 'The pull');
                $items .= '<div style="margin-top:4px;font-size:13px;color:#555;">'
                    .($code ? $e(Str::before($item, ': ')).': <code style="font-family:Consolas,monospace;background:#f3f3f3;padding:1px 4px;border-radius:3px;">'.$e(Str::after($item, ': ')).'</code>' : '&bull; '.$e($item))
                    .'</div>';
            }
            $rows .= '<tr>'
                .'<td style="padding:10px 12px 10px 0;vertical-align:top;width:64px;">'
                .'<span style="display:inline-block;min-width:44px;text-align:center;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;background:'.$colour.';">'.self::LABEL[$c['status']].'</span></td>'
                .'<td style="padding:10px 0;vertical-align:top;border-bottom:1px solid #eee;">'
                .'<div style="font-weight:600;color:#111;">'.$e($c['name']).'</div>'
                .'<div style="color:#333;margin-top:2px;">'.$e($c['text']).'</div>'.$items
                .'</td></tr>';
        }

        $notes = '';
        foreach ($this->notes as [$label, $value]) {
            $notes .= '<tr><td style="padding:4px 16px 4px 0;color:#666;vertical-align:top;white-space:nowrap;">'.$e($label).'</td>'
                .'<td style="padding:4px 0;color:#111;">'.$e($value).'</td></tr>';
        }

        $banner = $problems === 0 ? self::COLOUR['OK'] : self::COLOUR[collect($this->checks)->contains('status', 'FAIL') ? 'FAIL' : 'WARN'];

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f5;">'
            .'<div style="max-width:600px;margin:0 auto;padding:24px 16px;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.45;">'
            .'<div style="background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e5e5e5;">'
            .'<div style="height:4px;background:'.$banner.';"></div>'
            .'<div style="padding:20px 24px;">'
            .'<div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#B8860B;font-weight:600;">MindCollector daily check</div>'
            .'<div style="font-size:13px;color:#888;margin-top:2px;">'.$e($date).'</div>'
            .'<p style="font-size:17px;color:#111;margin:14px 0 8px;">'.$e($this->headline($problems)).'</p>'
            .'<table role="presentation" style="width:100%;border-collapse:collapse;">'.$rows.'</table>'
            .'<h3 style="font-size:14px;margin:22px 0 6px;color:#111;">In the last 24 hours</h3>'
            .'<table role="presentation" style="border-collapse:collapse;font-size:14px;">'.$notes.'</table>'
            .'</div></div>'
            .'<p style="font-size:12px;color:#888;margin:14px 4px 0;">'.implode('<br>', array_map($e, self::FOOTER)).'</p>'
            .'</div></body></html>';
    }

    /** API keys appear in some logged URLs (Gemini's ?key=): never mailed. */
    private function clean(string $text): string
    {
        return preg_replace('/([?&](?:key|api_key|token|access_token)=)[^&\s"]+/i', '$1[hidden]', $text);
    }
}
