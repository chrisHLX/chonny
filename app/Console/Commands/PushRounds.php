<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Http\Services\RoundAnalysisService;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Sends this PC's games to the player's account on the website, so /wow/coach shows the same pages
 * the desktop app does. The app runs it after each sync once it has a key
 * (`MINDCOLLECTOR_KEY`, made on /wow/coach).
 *
 *   php artisan wow:push-rounds            # what has not been sent yet
 *   php artisan wow:push-rounds --all      # everything again
 *
 * Each round's archived slice of the combat log is sent as it is stored (gzipped), never a measured
 * result: the server measures it with the same code (Api\CoachUploadController). What was sent, and
 * at which RoundAnalysisService::VERSION, is kept in storage/app/pushed-rounds.json; when the version
 * goes up, every round is sent again so the server measures it anew, since it keeps no raw log.
 */
class PushRounds extends Command
{
    protected $signature = 'wow:push-rounds
        {--user= : Whose games (id or email). Defaults to the only user who has any.}
        {--all : Send every round again}
        {--limit=150 : Rounds a run; the rest go next time}
        {--pause=1 : Seconds between rounds, to leave the server (one core) room for its visitors}
        {--minutes=10 : Stop after this long and finish properly; the rest go next time}';

    protected $description = "Send this PC's games to your account on the website (/wow/coach)";

    /** The measure version of every server deployed before /api/coach/version existed. */
    private const BEFORE_VERSION_ENDPOINT = 9;

    /** Under nginx's 1 MB a request, with room for the headers. */
    private const CHUNK = 768 * 1024;

    public function handle(ArenaLogService $archive): int
    {
        $site = rtrim((string) config('services.mindcollector.site'), '/');
        $key = config('services.mindcollector.key');
        if (! $key) {
            $this->warn('No website key: make one on '.$site.'/wow/coach and paste it into the app\'s Settings.');

            return self::SUCCESS;
        }

        $owners = ArenaRound::query()->distinct()->pluck('user_id');
        $ref = $this->option('user');
        $user = $ref ? (is_numeric($ref) ? User::find($ref) : User::where('email', $ref)->first()) : ($owners->count() === 1 ? User::find($owners->first()) : null);
        if (! $user) {
            $this->error('Say whose games with --user=.');

            return self::FAILURE;
        }

        $statePath = storage_path('app/pushed-rounds.json');
        $state = File::exists($statePath) ? (json_decode(File::get($statePath), true) ?: []) : [];
        // The lower of this PC's measure version and the server's: a server still on older code
        // stores older measures, and a round marked at the newer version would never be sent again
        // after the server caught up. A server too old to answer predates version 10.
        $client = fn () => Http::withToken($key)->acceptJson()->timeout(180);
        try {
            $asked = $client()->get("{$site}/api/coach/version");
            $serverVersion = $asked->status() === 401 ? null : (int) ($asked->json('version') ?? self::BEFORE_VERSION_ENDPOINT);
        } catch (\Throwable) {
            $serverVersion = null;
        }
        $version = min(RoundAnalysisService::VERSION, $serverVersion ?? RoundAnalysisService::VERSION);

        $todo = ArenaRound::where('user_id', $user->id)->orderBy('played_at')->get(['match_id', 'lobby_id'])
            ->filter(fn ($r) => $this->option('all') || ($state[$r->match_id] ?? 0) < $version)
            ->values();
        if ($todo->isEmpty()) {
            $this->line('The website has every game.');

            return self::SUCCESS;
        }

        // A fresh request each time ($client above): a PendingRequest keeps the headers added to it,
        // so one shared client sent the first round's X-Match on every round after it (2026-10-05).
        $sent = 0;
        $failed = 0;
        // The server measures each round as it arrives (about 4s on its one core), so a batch is
        // bounded by time too. The desktop app stops any step after 15 minutes; stopping here first
        // still asks the server to build the pages and reports what is left (2026-10-10).
        $batch = $todo->take((int) $this->option('limit'))->values();
        $deadline = microtime(true) + 60 * (float) $this->option('minutes');
        foreach ($batch as $n => $r) {
            if (microtime(true) > $deadline) {
                break;
            }
            $path = $archive->rawLogPath($r->match_id);
            if (! File::exists($path)) {
                // Nothing to send, ever: do not ask again every run.
                $state[$r->match_id] = $version;

                continue;
            }
            $bytes = File::get($path);
            $parts = str_split($bytes, self::CHUNK);
            $result = null;
            foreach ($parts as $i => $part) {
                $result = $this->send($client, $site, $r, $part, $i + 1, count($parts));
                if ($result === 'unauthorised') {
                    $this->error('The website did not accept the key: make a new one on '.$site.'/wow/coach.');

                    return self::FAILURE;
                }
                if ($result === null) {
                    break;
                }
            }
            if ($result === 'stored') {
                $state[$r->match_id] = $version;
                $sent++;
            } else {
                $failed++;
            }
            File::put($statePath, json_encode($state));
            // Read by the desktop app while the step runs, for its status line.
            $this->line(sprintf('  %d of %d sent', $n + 1, $batch->count()));
            usleep((int) ((float) $this->option('pause') * 1e6));
        }

        if ($sent > 0) {
            $client()->post("{$site}/api/coach/done");
        }
        $left = $todo->count() - $sent - $failed;
        $this->info("Sent {$sent} game(s) to the website".($failed ? ", {$failed} not taken" : '').($left > 0 ? ", {$left} left for next time" : '').'.');

        return self::SUCCESS;
    }

    /** One request; the round's status ('stored', 'part', ...), 'unauthorised', or null on failure. */
    private function send(\Closure $client, string $site, ArenaRound $r, string $body, int $part, int $parts): ?string
    {
        for ($try = 0; $try < 3; $try++) {
            try {
                $res = $client()->withHeaders(['X-Match' => $r->match_id, 'X-Lobby' => (string) $r->lobby_id, 'X-Part' => $part, 'X-Parts' => $parts])
                    ->withBody($body, 'application/gzip')
                    ->post("{$site}/api/coach/round");
            } catch (\Throwable $e) {
                $this->warn("  {$r->match_id}: ".$e->getMessage());

                return null;
            }
            if ($res->status() === 401) {
                return 'unauthorised';
            }
            if ($res->status() === 429) {
                sleep(max(1, (int) $res->json('retryAfter', 10)));

                continue;
            }
            if (! $res->successful()) {
                $this->warn("  {$r->match_id}: HTTP {$res->status()} ".($res->json('reason') ?? $res->json('message') ?? ''));

                return null;
            }
            $status = (string) $res->json('status');
            if (! in_array($status, ['stored', 'part'], true)) {
                $this->warn("  {$r->match_id}: ".($res->json('reason') ?? $status));
            }

            return $status;
        }

        return null;
    }
}
