<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Http\Services\ArenaReviewIngestService;
use App\Http\Services\LobbyReviewService;
use App\Http\Services\PlayerExperienceService;
use App\Models\ArenaReview;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * One command for the whole local loop: read the combat log, review what is new, say what changed.
 *
 * WHY THIS EXISTS RATHER THAN A WATCHER OR A DESKTOP TOOL. The friction was never the work, it was
 * remembering three commands in the right order after playing. Deriving a review needs the game
 * database — the talent resolution alone reads 4,903 entries and runs the median-offset
 * disambiguation for choice nodes — so anything outside PHP would either reimplement every
 * measured field offset in this repo or shell out to this command anyway. It is the second one
 * that is worth having, so this is the thing worth having.
 *
 *   php artisan wow:sync                 # ingest + review, for the only user on this machine
 *   php artisan wow:sync --user=me@x.com # say who, if there is more than one
 *   php artisan wow:sync --fresh         # re-review everything, after a parser change
 *
 * The log path comes from WOW_COMBATLOG_PATH and the archive from ARENA_LOG_ARCHIVE_PATH, so on a
 * set-up machine this takes no arguments at all.
 */
class SyncArena extends Command
{
    protected $signature = 'wow:sync
        {path? : A combat log or a directory of them. Defaults to WOW_COMBATLOG_PATH.}
        {--user= : Whose games these are (id or email). Defaults to the only user, if there is one.}
        {--fresh : Re-review every game, not just the ones without a review}
        {--since= : Ignore games played before this date (e.g. today, 2026-09-25)}
        {--skip-ingest : Only re-review what is already in the archive}
        {--skip-analysis : Do not measure games for "Your analysis" or look up experience}';

    protected $description = 'Read your combat log, review any new games, and report what changed';

    public function handle(LobbyReviewService $reviews): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return self::FAILURE;
        }

        $before = ArenaReview::where('user_id', $user->id)->count();

        if (! $this->option('skip-ingest')) {
            $path = $this->argument('path') ?: config('arena_logs.combatlog_path');

            if (! $path) {
                $this->error('No combat log path. Set WOW_COMBATLOG_PATH in .env, or pass one.');

                return self::FAILURE;
            }

            $this->line("<fg=gray>Reading {$path}</>");

            if ($this->call('wow:ingest-combatlog', ['path' => $path]) !== self::SUCCESS) {
                $this->warn('Nothing was ingested — carrying on with what is already on file.');
            }

            $this->newLine();
        }

        // Only the games that have no review yet, unless asked for all of them. A re-review is
        // cheap per game but there is no reason to redo forty of them after one session.
        $targets = collect($reviews->reviewable());

        // --since is what makes `wow:forget-games` stick. Ingest re-imports anything missing from
        // the archive, and it can see every log file on the machine, so without a floor a sync
        // rebuilds exactly what was just deleted.
        // A remembered `wow:forget-games` cutoff is the default floor. Passing --since overrides
        // it; there is no way to accidentally resurrect what was deliberately deleted.
        $since = $this->option('since') ?: ArenaLogService::forgetCutoff()?->toDateTimeString();

        if ($since) {
            try {
                $floor = \Illuminate\Support\Carbon::parse($since)->startOfDay()->getTimestampMs();
            } catch (\Throwable) {
                $this->error("Could not read '{$since}' as a date.");

                return self::FAILURE;
            }

            $targets = $targets->filter(fn ($t) => ($t['startTime'] ?? 0) >= $floor);

            if (! $this->option('since')) {
                $this->line("  <fg=gray>ignoring games before {$since} (remembered from wow:forget-games)</>");
            }
        }

        // Everything inside the date floor, reviewed or not: "Your analysis" is filled from these
        // separately, so a game reviewed before that step existed still gets analysed.
        $inRange = $targets;

        if (! $this->option('fresh')) {
            $known = ArenaReview::where('user_id', $user->id)->pluck('lobby_id')->flip();
            $targets = $targets->reject(fn ($t) => $known->has($t['id']));
        }

        if ($targets->isEmpty()) {
            $this->info('No new games. '.$before.' already reviewed.');
            $this->analyse($user, $inRange);
            $this->tip();

            return self::SUCCESS;
        }

        $this->line("<fg=gray>Reviewing {$targets->count()} game(s)…</>");

        foreach ($targets as $t) {
            $review = $reviews->buildFromArchive($t['id']);

            if ($review === null) {
                continue;
            }

            $row = $reviews->store($user, $review);
            $you = collect($review['players'])->firstWhere('isYou', true);

            $this->line(sprintf(
                '  <fg=green>%s</>  %-18s %d-%-2d  %-24s %s',
                $row->played_at?->format('d M H:i') ?? '',
                $review['bracket'],
                $review['record']['won'],
                $review['record']['lost'],
                $you['spec']['label'] ?? '?',
                count($review['mirrors']) > 0
                    ? collect($review['mirrors'])->pluck('specLabel')->unique()->implode(', ').' mirror'
                    : ''
            ));
        }

        $after = ArenaReview::where('user_id', $user->id)->count();
        $this->newLine();
        $this->info(($after - $before).' new game(s). '.$after.' reviewed in total.');
        $this->analyse($user, $inRange);
        $this->tip();

        return self::SUCCESS;
    }

    /**
     * Puts every game's rounds on the account for "Your analysis" (/wow/match-analysis), and looks
     * up the experience of everyone in them.
     *
     * A review and an analysis are two different stores: the review is `arena_reviews`, and the
     * analysis reads `arena_rounds.payload['analysis']`, which only the browser upload path writes
     * (ArenaReviewIngestService::ingestRound). Before this step a game synced here had a review and
     * no analysis at all. The archive keeps each round's raw slice, so the upload path is fed that
     * slice, which is exactly what a browser would send.
     *
     * The round is then given the ARCHIVE's lobby id. The upload path works out its own for a
     * shuffle, and the upload page's "assemble" step rebuilds any lobby whose id it has no review
     * for, which would put a second copy of every shuffle on the list.
     *
     * Experience is looked up here, not left to the queued job, because a local machine usually has
     * no worker running and the review page reads only what is cached.
     */
    private function analyse(User $user, Collection $groups): void
    {
        if ($this->option('skip-analysis')) {
            return;
        }

        $have = $this->option('fresh')
            ? collect()
            : ArenaRound::where('user_id', $user->id)->pluck('match_id')->flip();

        $todo = $groups
            ->flatMap(fn ($g) => collect($g['matchIds'])->map(fn ($m) => ['lobby' => $g['id'], 'match' => $m]))
            ->reject(fn ($t) => $have->has($t['match']))
            ->values();

        $ingest = app(ArenaReviewIngestService::class);
        $arena = app(ArenaLogService::class);
        $stored = 0;

        if ($todo->isNotEmpty()) {
            $this->line("<fg=gray>Measuring {$todo->count()} round(s) for Your analysis…</>");
        }

        foreach ($todo as $t) {
            $path = $arena->rawLogPath($t['match']);
            $raw = File::exists($path) ? @gzdecode((string) File::get($path)) : false;

            if ($raw === false || $raw === '') {
                $this->warn("  no raw log for {$t['match']}");

                continue;
            }

            $result = $ingest->ingestRound($user, $raw);

            if ($result['status'] !== 'stored') {
                $this->warn("  {$t['match']}: ".($result['reason'] ?? $result['status']));

                continue;
            }

            ArenaRound::where('user_id', $user->id)->where('match_id', $result['matchId'])->update(['lobby_id' => $t['lobby']]);
            $stored++;
        }

        if ($stored > 0) {
            $this->info("{$stored} round(s) measured.");
        }

        if (! config('services.battlenet.client_id')) {
            return;
        }

        // Everyone on every reviewed game, so the game list's averages fill in too.
        $experience = app(PlayerExperienceService::class);
        $names = ArenaReview::where('user_id', $user->id)->get()
            ->flatMap(fn (ArenaReview $r) => array_column($r->payload['players'] ?? [], 'name'))
            ->unique()
            ->filter(fn ($n) => $experience->cached($n) === null)
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        $this->line("<fg=gray>Looking up experience for {$names->count()} player(s)…</>");

        foreach ($names as $name) {
            $experience->lookup($name);
        }
    }

    private function tip(): void
    {
        $this->line('  <fg=gray>Look at them: php -S 127.0.0.1:8321 -t public  →  http://127.0.0.1:8321/wow/game-review</>');
        $this->line('  <fg=gray>Or just run tools/arena.bat, which does both.</>');
    }

    /**
     * Whose games these are. On a one-user machine, asking every time is pure ceremony — but
     * guessing on a machine with several would file somebody's games under the wrong name, so
     * that case asks.
     */
    private function resolveUser(): ?User
    {
        if ($ref = $this->option('user')) {
            $user = is_numeric($ref) ? User::find((int) $ref) : User::where('email', $ref)->first();

            if (! $user) {
                $this->error("No user matching '{$ref}'.");
            }

            return $user;
        }

        $users = User::query()->orderBy('id')->get();

        if ($users->count() === 1) {
            return $users->first();
        }

        // A local dev database usually has seeded placeholders alongside the real account; if
        // exactly one looks real, that is the one.
        $real = $users->reject(fn (User $u) => str_ends_with($u->email, '@example.com')
            || str_ends_with($u->email, '.internal'));

        if ($real->count() === 1) {
            return $real->first();
        }

        $this->error('More than one user here — say which with --user=');

        foreach ($users as $u) {
            $this->line("  {$u->id}  {$u->email}");
        }

        return null;
    }
}
