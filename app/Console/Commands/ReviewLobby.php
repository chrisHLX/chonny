<?php

namespace App\Console\Commands;

use App\Http\Services\LobbyReviewService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Assembles a review for a game in the LOCAL archive, and optionally stores it for a user.
 *
 * SOLO SHUFFLE ONLY for now — see LobbyReviewService::reviewable() for why, the short version
 * being that the 16 oldest matches in the archive are other people's games and a review is one
 * signed-in player's record of their own. One review covers a lobby's six rounds, grouped by
 * `metadata.lobbyId`, written at ingest.
 *
 * THIS IS THE LOCAL PATH, NOT THE PRODUCTION ONE. Real players' games arrive through the browser
 * upload on /wow/game-review, which stores them straight to `arena_reviews` — see
 * ArenaReviewIngestService. This command exists because the archive is here on a dev machine and
 * is gitignored, so it is the only way to review a game already sitting in `data/arena-logs/`.
 * Both routes call LobbyReviewService::assemble(), so a review means the same thing either way.
 *
 * WITHOUT --user IT SAVES NOTHING. A review is owned by somebody; there is no unowned review to
 * write. Run it bare to check what a game looks like, with --user to keep it.
 *
 *   php artisan wow:review-lobby --latest --print
 *   php artisan wow:review-lobby --all --user=you@example.com
 *   php artisan wow:review-lobby <lobbyId> --user=1 --print
 */
class ReviewLobby extends Command
{
    protected $signature = 'wow:review-lobby
        {id? : A lobby id from the local archive}
        {--all : Every reviewable game, not just the most recent}
        {--latest : Only the most recently played game}
        {--print : Print the mirror comparison}
        {--user= : Store the review for this user (id or email). Without it, nothing is saved.}';

    protected $description = 'Assemble a review for an archived game, optionally storing it for a user';

    public function handle(LobbyReviewService $reviews): int
    {
        $targets = $reviews->reviewable();

        if ($targets === []) {
            $this->warn('No Solo Shuffle games in the archive. Run wow:ingest-combatlog first.');

            return self::FAILURE;
        }

        $user = null;

        if ($ref = $this->option('user')) {
            $user = is_numeric($ref)
                ? User::find((int) $ref)
                : User::where('email', $ref)->first();

            if (! $user) {
                $this->error("No user matching '{$ref}'.");

                return self::FAILURE;
            }
        }

        if ($id = $this->argument('id')) {
            $targets = array_values(array_filter($targets, fn ($t) => $t['id'] === $id));

            if ($targets === []) {
                $this->error("No archived game with id {$id}.");

                return self::FAILURE;
            }
        } elseif ($this->option('latest') || ! $this->option('all')) {
            $targets = [$targets[0]];
        }

        $assembled = 0;
        $stored = 0;

        foreach ($targets as $t) {
            $review = $reviews->buildFromArchive($t['id']);

            if ($review === null) {
                $this->warn("  {$t['id']} — could not be assembled, skipped");

                continue;
            }

            $assembled++;

            if ($user !== null) {
                $row = $reviews->store($user, $review);
                $stored++;
            }

            $record = $review['record'];
            $this->line(sprintf(
                '  <fg=green>%s</>  %-18s %d round(s)  %d-%d  %d mirror(s)%s',
                substr($t['id'], 0, 12),
                $review['bracket'],
                count($review['rounds']),
                $record['won'],
                $record['lost'],
                count($review['mirrors']),
                $user !== null
                    ? '  stored for '.$user->email.(isset($row) && $row->battlenet_character_id ? ' (character linked)' : '')
                    : ''
            ));

            foreach ($review['rounds'] as $r) {
                if ($r['unparsedEvents'] > 0) {
                    $this->warn("    round {$r['sequence']}: {$r['unparsedEvents']} event(s) whose amounts did not parse — a client format change?");
                }
            }

            if ($this->option('print')) {
                $this->printMirrors($review);
            }
        }

        $this->newLine();
        $this->info("Assembled {$assembled} review(s).");

        if ($user === null) {
            $this->line('  <fg=gray>Nothing was saved — a review is owned by a user. Pass --user to keep it.</>');
        } else {
            $this->line("  <fg=gray>{$stored} stored for {$user->email}. They are database rows, not files:</>");
            $this->line('  <fg=gray>a review is that player\'s own game and is never committed or deployed.</>');
        }

        return self::SUCCESS;
    }

    private function printMirrors(array $review): void
    {
        foreach ($review['mirrors'] as $m) {
            // Marked per side, not on the line — "vs Someone (you)" reads as if the second player
            // is the one logging, which is the opposite of how mirrors are ordered.
            $nameA = $m['a']['name'].($m['a']['isYou'] ? ' (you)' : '');
            $nameB = $m['b']['name'].($m['b']['isYou'] ? ' (you)' : '');

            $this->newLine();
            $this->line("    <options=bold>{$m['specLabel']} mirror</> — {$nameA} vs {$nameB}");
            $this->line("      opposed in {$m['roundsOpposed']} round(s), same team in {$m['roundsTogether']}");

            $rows = [];
            foreach (['healingEffective' => 'Effective healing', 'absorbDone' => 'Absorbed',
                'healingOverheal' => 'Overheal', 'damageDone' => 'Damage done',
                'damageTaken' => 'Damage taken'] as $k => $label) {
                $rows[] = [$label, number_format($m['a']['totals'][$k]), number_format($m['b']['totals'][$k])];
            }

            $this->table(['', $nameA, $nameB], $rows);
            $this->line("      {$m['primaryMetricLabel']}: {$m['primaryDeltaPercent']}% difference");

            if (! ($m['statDiff']['unavailable'] ?? true)) {
                $statRows = array_map(fn ($r) => [$r['stat'], $r['a'], $r['b']], $m['statDiff']['rows']);
                $this->table(['stat (rating)', $nameA, $nameB], $statRows);
            }

            if (! ($m['gearDiff']['unavailable'] ?? true)) {
                $g = $m['gearDiff'];
                $this->line("      gear median: {$g['a']['median']} vs {$g['b']['median']} (max {$g['a']['max']} vs {$g['b']['max']})");
            }

            $d = $m['talentDiff'];

            if ($d['unavailable'] ?? true) {
                $this->line('      talents: could not be resolved for one side');

                continue;
            }

            $this->line('      only '.$nameA.': '.(collect($d['onlyA'])->pluck('name')->implode(', ') ?: '—'));
            $this->line('      only '.$nameB.': '.(collect($d['onlyB'])->pluck('name')->implode(', ') ?: '—'));

            $p = $m['pvpTalentDiff'];
            $this->line('      pvp — shared: '.(implode(', ', $p['shared']) ?: '—')
                .' | only '.$nameA.': '.(implode(', ', $p['onlyA']) ?: '—')
                .' | only '.$nameB.': '.(implode(', ', $p['onlyB']) ?: '—'));
        }
    }
}
