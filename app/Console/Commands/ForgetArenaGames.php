<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\ArenaReview;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Removes archived games and their reviews.
 *
 * DRY RUN BY DEFAULT. It prints what it would remove and touches nothing until `--apply`, because
 * this deletes a player's own games and the counts are the only way to notice that a date argument
 * meant something other than what was intended.
 *
 * IT REMOVES THE ARCHIVE ENTRY TOO, NOT JUST THE REVIEW. Deleting only the database row would be
 * undone by the next `wow:sync`: reviews are built from `data/arena-logs/metadata/*`, so a game
 * still in the archive comes straight back. Both halves go, or neither.
 *
 * NOTHING HERE IS PERMANENT WHILE THE COMBAT LOGS EXIST. The archive is derived from
 * `WoWCombatLog-*.txt`, which this never touches — re-running `wow:sync` against those files
 * rebuilds anything removed. That is also the catch: a bare sync re-scans every log file it can
 * see, so use `wow:sync --since=` to stop old games returning.
 *
 *   php artisan wow:forget-games --before=today            # what would go
 *   php artisan wow:forget-games --before=today --apply     # go
 */
class ForgetArenaGames extends Command
{
    protected $signature = 'wow:forget-games
        {--before= : Remove games played before this date (e.g. today, 2026-09-25, "3 months ago")}
        {--user= : Only this user\'s reviews (id or email). The archive is shared, so it always goes.}
        {--include-pulled : Also remove matches pulled from wowarenalogs. Read the warning first.}
        {--apply : Actually delete. Without it, nothing is touched.}';

    protected $description = 'Remove archived arena games and their reviews (dry run unless --apply)';

    public function handle(ArenaLogService $arena): int
    {
        if (! $this->option('before')) {
            $this->error('Say what to remove: --before=today');

            return self::FAILURE;
        }

        try {
            $cutoff = Carbon::parse($this->option('before'))->startOfDay();
        } catch (\Throwable) {
            $this->error("Could not read '{$this->option('before')}' as a date.");

            return self::FAILURE;
        }

        $this->line("Cutoff: anything played before <options=bold>{$cutoff->toDateTimeString()}</>");
        $this->newLine();

        // --- the archive ---
        $doomed = [];
        $kept = 0;
        $protected = 0;

        foreach (File::glob($arena->metadataPath('*')) as $path) {
            $m = json_decode(File::get($path), true);

            if (! is_array($m) || ! isset($m['id'])) {
                continue;
            }

            $startedAt = isset($m['startTime']) ? Carbon::createFromTimestampMs($m['startTime']) : null;

            if ($startedAt === null || $startedAt->gte($cutoff)) {
                $kept++;

                continue;
            }

            // CLAUDE.md rule 13. A match from this machine's own combat log can always be rebuilt
            // — the WoWCombatLog files are still there and this never touches them. A match PULLED
            // from wowarenalogs cannot: that API went SEARCH_DISABLED in September 2026 and then
            // behind a sign-in, so every one still on disk is the last copy in existence. The 2026
            // -09-05 cull of 500 of them is already permanent and already regretted.
            if (($m['source'] ?? null) !== 'local-combatlog' && ! $this->option('include-pulled')) {
                $protected++;

                continue;
            }

            $doomed[] = ['id' => $m['id'], 'at' => $startedAt, 'bracket' => $m['startInfo']['bracket'] ?? '?'];
        }

        usort($doomed, fn ($a, $b) => $a['at'] <=> $b['at']);

        $byBracket = collect($doomed)->countBy('bracket');
        $bytes = 0;

        foreach ($doomed as $d) {
            foreach ([$arena->rawLogPath($d['id']), $arena->metadataPath($d['id'])] as $f) {
                if (File::exists($f)) {
                    $bytes += File::size($f);
                }
            }
        }

        $this->line('<options=bold>Archive</>');
        $this->line(sprintf('  %d round(s) to remove, %d kept, %.1f MB freed', count($doomed), $kept, $bytes / 1048576));

        if ($protected > 0) {
            $this->line(sprintf(
                '  <fg=yellow>%d older match(es) protected</> — pulled from wowarenalogs, which no longer serves us.',
                $protected
            ));
            $this->line('    <fg=gray>Those are the last copies in existence and cannot be re-fetched.</>');
            $this->line('    <fg=gray>--include-pulled removes them anyway, permanently.</>');
        }

        foreach ($byBracket as $bracket => $n) {
            $this->line(sprintf('    %-20s %d', $bracket, $n));
        }

        if ($doomed !== []) {
            $this->line(sprintf('    oldest %s, newest %s',
                $doomed[0]['at']->toDateTimeString(),
                end($doomed)['at']->toDateTimeString()));
        }

        // --- the reviews ---
        $reviewQuery = ArenaReview::query()->where('played_at', '<', $cutoff);
        $roundQuery = ArenaRound::query()->where('played_at', '<', $cutoff);

        if ($ref = $this->option('user')) {
            $user = is_numeric($ref) ? User::find((int) $ref) : User::where('email', $ref)->first();

            if (! $user) {
                $this->error("No user matching '{$ref}'.");

                return self::FAILURE;
            }

            $reviewQuery->where('user_id', $user->id);
            $roundQuery->where('user_id', $user->id);
        }

        $reviewCount = (clone $reviewQuery)->count();
        $roundCount = (clone $roundQuery)->count();
        $keptReviews = ArenaReview::query()->where('played_at', '>=', $cutoff)->count();

        $this->newLine();
        $this->line('<options=bold>Reviews</>');
        $this->line("  {$reviewCount} review(s) and {$roundCount} uploaded round(s) to remove, {$keptReviews} kept");

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Dry run — nothing was touched. Add --apply to do it.');
            $this->line('  <fg=gray>Your WoWCombatLog files are never touched, so this is reversible</>');
            $this->line('  <fg=gray>by re-running wow:sync against them.</>');

            return self::SUCCESS;
        }

        foreach ($doomed as $d) {
            File::delete([$arena->rawLogPath($d['id']), $arena->metadataPath($d['id'])]);
        }

        $reviewQuery->delete();
        $roundQuery->delete();

        $this->newLine();
        $this->info(sprintf('Removed %d round(s) from the archive and %d review(s).', count($doomed), $reviewCount));
        $this->line('  <fg=gray>A bare `wow:sync` re-scans every log file and would bring these back —</>');
        $this->line("  <fg=gray>use `wow:sync --since={$cutoff->toDateString()}` to keep them gone.</>");

        return self::SUCCESS;
    }
}
